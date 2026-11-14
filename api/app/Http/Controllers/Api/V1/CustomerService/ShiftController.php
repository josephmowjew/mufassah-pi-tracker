<?php

namespace App\Http\Controllers\Api\V1\CustomerService;

use App\Enums\ShiftBreakType;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\ShiftResource;
use App\Models\CommunicationSettings;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShiftEndSummaryNotification;
use App\Services\ShiftClosingService;
use App\Support\LocalTime;
use App\Traits\SortsLists;
use App\Traits\PaginatesLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ShiftController extends BaseController
{
    use PaginatesLists;

    use SortsLists;

    /** @var array<int, string> */
    private const SORTABLE = ['started_at', 'ended_at', 'created_at'];

    /**
     * Get the current active shift for the user.
     */
    public function current(Request $request, ShiftClosingService $closing): JsonResponse
    {
        $shift = $request->user()->activeShift()->with(['branch', 'breaks'])->first();

        if (! $shift) {
            return $this->success(null, 'No active shift');
        }

        if ($shift->isDueToEnd(now())) {
            $closing->closeIfDue($shift);

            return $this->success(null, 'Shift ended automatically');
        }

        // Load sales relationship to ensure stats are calculated
        $shift->load('sales');

        return $this->success(new ShiftResource($shift));
    }

    /**
     * Start a new shift for the user.
     */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'opening_cash' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($request, $validated) {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $activeShift = $user->activeShift()->lockForUpdate()->first();
            $startedAt = now();

            if ($activeShift?->isDueToEnd($startedAt)) {
                $activeShift->complete(['ended_at' => $activeShift->automaticEndAt()]);
                $activeShift = null;
            }

            if ($activeShift) {
                return $this->error('You already have an active shift. Please end it before starting a new one.', 400);
            }

            if ($startedAt->greaterThanOrEqualTo(Shift::closingTime($startedAt))) {
                return $this->error('Shifts cannot start at or after 6:00 pm Malawi time.', 400);
            }

            $shift = $user->shifts()->create([
                'branch_id' => $user->requiredBranchScopeId(),
                'started_at' => $startedAt,
                'status' => 'active',
                'opening_cash' => $validated['opening_cash'] ?? 0,
                'notes' => $validated['notes'] ?? null,
            ]);

            return $this->created(new ShiftResource($shift->load(['branch', 'breaks'])), 'Shift started successfully');
        });
    }

    /**
     * End the current active shift.
     *
     * Any break still running is closed at the same instant the shift ends, so
     * a shift finished mid-break cannot leave a break open forever.
     */
    public function end(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'closing_cash' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $shift = DB::transaction(function () use ($user, $validated) {
                $shift = $user->activeShift()->lockForUpdate()->first();
                if ($shift) {
                    $shift->complete([
                        'ended_at' => now()->min($shift->automaticEndAt()),
                        'closing_cash' => $validated['closing_cash'] ?? null,
                        'notes' => $validated['notes'] ?? null,
                    ]);
                }

                return $shift;
            });
        } catch (\Exception $e) {
            // The exception never reaches Laravel's handler, so it is not reported
            // automatically — log it here or the failure is invisible in production.
            Log::error('Failed to end shift', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to end shift. Please try again.', 500);
        }

        if (! $shift) {
            return $this->error('No active shift found', 404);
        }

        // Send notification to admin emails if enabled
        $settings = CommunicationSettings::current();
        if ($settings->shift_summary_notifications_enabled && ! empty($settings->getEmailsArray())) {
            foreach ($settings->getEmailsArray() as $email) {
                Notification::route('mail', $email)->notify(new ShiftEndSummaryNotification($shift));
            }
        }

        return $this->success(
            new ShiftResource($shift->load(['branch', 'sales', 'breaks'])),
            'Shift ended successfully'
        );
    }

    /**
     * Cancel the current active shift.
     */
    public function cancel(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($user, $validated) {
            $shift = $user->activeShift()->lockForUpdate()->first();
            if (! $shift) {
                return $this->error('No active shift found', 404);
            }

            $endedAt = now();
            if ($shift->isDueToEnd($endedAt)) {
                $shift->complete(['ended_at' => $shift->automaticEndAt()]);

                return $this->success(new ShiftResource($shift->load(['branch', 'breaks'])), 'Shift ended automatically');
            }

            $shift->cancel($endedAt);
            if (! empty($validated['notes'])) {
                $shift->update(['notes' => $validated['notes']]);
            }

            return $this->success(new ShiftResource($shift->load(['branch', 'breaks'])), 'Shift cancelled');
        });
    }

    /**
     * Start a break inside the current shift.
     *
     * A break never changes `shifts.status`. `User::activeShift()` is scoped to
     * `status = 'active'`, so a dedicated `on_break` status would make `current`
     * report no shift, `end` return 404 and `start` create a second concurrent
     * shift for the same person.
     */
    public function startBreak(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::enum(ShiftBreakType::class)],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();

        try {
            $shift = DB::transaction(function () use ($user, $validated) {
                // Locked so two taps in the same second cannot both pass the
                // "no open break" check and create two concurrent breaks.
                $shift = Shift::query()
                    ->where('user_id', $user->id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

                $startedAt = now();
                if (! $shift || $shift->isDueToEnd($startedAt)) {
                    abort(404, 'No active shift found');
                }

                if ($shift->breaks()->whereNull('ended_at')->exists()) {
                    abort(409, 'You are already on a break. End the current break first.');
                }

                $shift->breaks()->create([
                    'type' => $validated['type'] ?? ShiftBreakType::OTHER,
                    'started_at' => $startedAt,
                    'notes' => $validated['notes'] ?? null,
                ]);

                return $shift;
            });
        } catch (HttpExceptionInterface $e) {
            // Re-thrown so the 404/409 above keeps its status. A broad catch
            // here would turn both into a 500 and hide the reason from staff.
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Failed to start shift break', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to start break. Please try again.', 500);
        }

        return $this->created(
            new ShiftResource($shift->load(['branch', 'breaks'])),
            'Break started'
        );
    }

    /**
     * End the break that is currently running.
     */
    public function endBreak(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();

        try {
            $shift = DB::transaction(function () use ($user, $validated) {
                $shift = Shift::query()
                    ->where('user_id', $user->id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

                $now = now();
                if (! $shift || $shift->isDueToEnd($now)) {
                    abort(404, 'No active shift found');
                }

                $break = $shift->breaks()->whereNull('ended_at')->first();

                if (! $break) {
                    abort(409, 'You are not on a break.');
                }

                // Guard against a backwards clock making the break negative.
                $endedAt = $now->max($break->started_at);

                $break->update([
                    'ended_at' => $endedAt,
                    'notes' => $validated['notes'] ?? $break->notes,
                ]);

                return $shift;
            });
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Failed to end shift break', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to end break. Please try again.', 500);
        }

        return $this->success(
            new ShiftResource($shift->load(['branch', 'breaks'])),
            'Break ended'
        );
    }

    /**
     * List all shifts for the current user.
     */
    public function history(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $user = $request->user();

        $query = $user->shifts()->with(['branch', 'sales', 'breaks']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->where('started_at', '>=', LocalTime::startOfDayUtc($request->date_from));
        }
        if ($request->filled('date_to')) {
            $query->where('started_at', '<=', LocalTime::endOfDayUtc($request->date_to));
        }

        // Sort
        $this->applySort($query, $request, self::SORTABLE, ['started_at', 'desc']);

        // Paginate
        $perPage = $this->perPage($request);
        $shifts = $query->paginate($perPage);

        return $this->paginated(ShiftResource::collection($shifts));
    }

    /**
     * Get a specific shift with details.
     */
    public function show(Request $request, Shift $shift): JsonResponse
    {
        $user = $request->user();

        // Ensure the shift belongs to the user
        if ($shift->user_id !== $user->id) {
            return $this->forbidden('You do not have permission to view this shift');
        }

        $shift->load(['branch', 'breaks', 'sales.customer', 'sales.items.device', 'sales.soldBy']);

        return $this->success(new ShiftResource($shift));
    }
}
