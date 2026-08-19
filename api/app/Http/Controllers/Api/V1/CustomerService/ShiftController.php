<?php

namespace App\Http\Controllers\Api\V1\CustomerService;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\ShiftResource;
use App\Models\CommunicationSettings;
use App\Models\Shift;
use App\Notifications\ShiftEndSummaryNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class ShiftController extends BaseController
{
    /**
     * Get the current active shift for the user.
     */
    public function current(Request $request): JsonResponse
    {
        $shift = $request->user()->activeShift()->with('branch')->first();

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

        return $this->created(new ShiftResource($shift->load('branch')), 'Shift started successfully');
    }

    /**
     * End the current active shift.
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

            return $this->error('Failed to end shift: '.$e->getMessage(), 500);
        }

        // Send notification to admin emails if enabled
        $settings = CommunicationSettings::current();
        if ($settings->shift_summary_notifications_enabled && ! empty($settings->getEmailsArray())) {
            foreach ($settings->getEmailsArray() as $email) {
                Notification::route('mail', $email)->notify(new ShiftEndSummaryNotification($shift));
            }
        }

        return $this->success(new ShiftResource($shift->load('branch', 'sales')), 'Shift ended successfully');
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

        return $this->success(new ShiftResource($shift->load('branch')), 'Shift cancelled');
    }

    /**
     * List all shifts for the current user.
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = $user->shifts()->with(['branch', 'sales']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->whereDate('started_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->whereDate('started_at', '<=', $request->date_to);
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

        $shift->load(['branch', 'sales.customer', 'sales.items.device', 'sales.soldBy']);

        return $this->success(new ShiftResource($shift));
    }
}
