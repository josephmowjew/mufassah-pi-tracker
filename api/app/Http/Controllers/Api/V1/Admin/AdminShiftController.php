<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\ShiftResource;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminShiftController extends BaseController
{
    /**
     * List all shifts with filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request);
        $query = Shift::with(['user', 'branch', 'sales'])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId));

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by user
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by branch
        if ($request->has('branch_id') && $request->user()->hasRole('super_admin')) {
            $query->where('branch_id', $request->branch_id);
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->whereDate('started_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->whereDate('started_at', '<=', $request->date_to);
        }

        // Search by user name or email
        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
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
     * Get a specific shift with full details.
     */
    public function show(Request $request, Shift $shift): JsonResponse
    {
        abort_unless(
            $request->user()->canAccessBranch($shift->branch_id),
            403,
            'You cannot access shifts for another branch.'
        );

        $shift->load([
            'user',
            'branch',
            'sales.customer',
            'sales.items.device.category',
            'sales.soldBy',
        ]);

        return $this->success(new ShiftResource($shift));
    }

    /**
     * Get shift statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request);
        $query = Shift::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId));

        // Apply date filters
        if ($request->has('date_from')) {
            $query->whereDate('started_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->whereDate('started_at', '<=', $request->date_to);
        }
        if ($request->has('branch_id') && $request->user()->hasRole('super_admin')) {
            $query->where('branch_id', $request->branch_id);
        }

        $shifts = $query->get();

        $stats = [
            'total_shifts' => $shifts->count(),
            'active_shifts' => $shifts->where('status', 'active')->count(),
            'completed_shifts' => $shifts->where('status', 'completed')->count(),
            'cancelled_shifts' => $shifts->where('status', 'cancelled')->count(),
            'total_revenue' => (float) $shifts->sum(fn ($shift) => $shift->revenue),
            'total_sales' => $shifts->sum(fn ($shift) => $shift->sales_count),
            'average_shift_duration' => $shifts
                ->where('status', 'completed')
                ->avg(fn ($shift) => $shift->duration) ?? 0,
        ];

        return $this->success($stats);
    }

    private function resolveBranchId(Request $request): ?int
    {
        if ($request->user()->hasRole('super_admin')) {
            return null;
        }

        return $request->user()->requiredBranchScopeId();
    }
}
