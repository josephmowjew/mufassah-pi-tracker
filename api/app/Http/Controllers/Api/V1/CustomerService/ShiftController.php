<?php

namespace App\Http\Controllers\Api\V1\CustomerService;

use App\Enums\ShiftBreakType;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\ShiftResource;
use App\Models\CommunicationSettings;
use App\Models\Shift;
use App\Notifications\ShiftEndSummaryNotification;
use App\Support\LocalTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ShiftController extends BaseController
{
    /**
     * Get the current active shift for the user.
     */
    public function current(Request $request): JsonResponse
    {
        $shift = $request->user()->activeShift()->with(['branch', 'breaks'])->first();

        if (! $shift) {
            return $this->success(null, 'No active shift');
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
        $user = $request->user();
        // Reload user from database to ensure branch_id is loaded
        $user = \App\Models\User::with('branch')->find($user->id);

        // Check if user already has an active shift
        $activeShift = $user->activeShift()->first();
        if ($activeShift) {
            return $this->error(
                'You already have an active shift. Please end it before starting a new one.',
                400
            );
        }

        $validated = $request->validate([
            'opening_cash' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $shift = Shift::create([
            'user_id' => $user->id,
            'branch_id' => $user->requiredBranchScopeId(),
            'started_at' => now(),
            'status' => 'active',
            'opening_cash' => $validated['opening_cash'] ?? 0,
            'notes' => $validated['notes'] ?? null,
        ]);

        return $this->created(new ShiftResource($shift->load(['branch', 'breaks'])), 'Shift started successfully');
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

        $shift = $user->activeShift()->first();
        if (! $shift) {
            return $this->error('No active shift found', 404);
        }

        $validated = $request->validate([
            'closing_cash' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        DB::beginTransaction();
        try {
            $shift->complete([
                'closing_cash' => $validated['closing_cash'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            // The exception never reaches Laravel's handler, so it is not reported
            // automatically — log it here or the failure is invisible in production.
            Log::error('Failed to end shift', [
                'user_id' => $user->id,
                'shift_id' => $shift->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to end shift. Please try again.', 500);
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

        $shift = $user->activeShift()->first();
        if (! $shift) {
            return $this->error('No active shift found', 404);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $shift->cancel();

        if (! empty($validated['notes'])) {
            $shift->update(['notes' => $validated['notes']]);
        }

        return $this->success(
            new ShiftResource($shift->load(['branch', 'breaks'])),
            'Shift cancelled'
        );
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

                if (! $shift) {
                    abort(404, 'No active shift found');
                }

                if ($shift->breaks()->whereNull('ended_at')->exists()) {
                    abort(409, 'You are already on a break. End the current break first.');
                }

                $shift->breaks()->create([
                    'type' => $validated['type'] ?? ShiftBreakType::OTHER,
                    'started_at' => now(),
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

                if (! $shift) {
                    abort(404, 'No active shift found');
                }

                $break = $shift->breaks()->whereNull('ended_at')->first();

                if (! $break) {
                    abort(409, 'You are not on a break.');
                }

                // Guard against a backwards clock making the break negative.
                $endedAt = now()->lessThan($break->started_at) ? $break->started_at->copy() : now();

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
        $sortField = $request->get('sort', 'started_at');
        $sortDirection = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        // Paginate
        $perPage = $request->get('per_page', 15);
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
