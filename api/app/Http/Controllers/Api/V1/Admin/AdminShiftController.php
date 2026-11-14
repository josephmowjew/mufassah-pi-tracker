<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Support\LikePattern;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\ShiftResource;
use App\Models\Shift;
use App\Support\LocalTime;
use App\Traits\SortsLists;
use App\Traits\PaginatesLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminShiftController extends BaseController
{
    use PaginatesLists;

    use SortsLists;

    /** @var array<int, string> */
    private const SORTABLE = ['started_at', 'ended_at', 'created_at'];

    /**
     * List all shifts with filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $branchId = $this->resolveBranchId($request);
        // `breaks` is eager-loaded because ShiftResource reads break_count,
        // break_minutes and worked_minutes for every row; without it the list
        // issues one break query per shift.
        $query = Shift::with(['user', 'branch', 'sales', 'breaks'])
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
        if ($request->filled('date_from')) {
            $query->where('started_at', '>=', LocalTime::startOfDayUtc($request->date_from));
        }
        if ($request->filled('date_to')) {
            $query->where('started_at', '<=', LocalTime::endOfDayUtc($request->date_to));
        }

        // Search by user name or email
        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->whereRaw('name LIKE ? '.LikePattern::SQL_ESCAPE, [LikePattern::contains($search)])
                    ->orWhereRaw('email LIKE ? '.LikePattern::SQL_ESCAPE, [LikePattern::contains($search)]);
            });
        }

        // Sort
        $this->applySort($query, $request, self::SORTABLE, ['started_at', 'desc']);

        // Paginate
        $perPage = $this->perPage($request);
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
            'breaks',
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
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $branchId = $this->resolveBranchId($request);
        $query = Shift::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId));

        // Apply date filters
        if ($request->filled('date_from')) {
            $query->where('started_at', '>=', LocalTime::startOfDayUtc($request->date_from));
        }
        if ($request->filled('date_to')) {
            $query->where('started_at', '<=', LocalTime::endOfDayUtc($request->date_to));
        }
        if ($request->has('branch_id') && $request->user()->hasRole('super_admin')) {
            $query->where('branch_id', $request->branch_id);
        }

        // Compute status counts in a single grouped query instead of loading
        // every shift row and iterating in PHP.
        $statusCounts = (clone $query)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $totalShifts = array_sum($statusCounts);

        // Compute aggregate revenue and sales count from the sales table in
        // SQL, joining only shifts that match the same filters.
        $saleAggregates = \App\Models\Sale::query()
            ->whereIn('shift_id', (clone $query)->select('id'))
            ->completedForReporting()
            ->selectRaw('COUNT(*) as sales_count, COALESCE(SUM(total_amount), 0) as revenue')
            ->first();

        // Average duration for completed shifts (in minutes)
        $avgDuration = (clone $query)
            ->whereNotNull('ended_at')
            ->where('status', 'completed')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, started_at, ended_at)) as avg_duration')
            ->value('avg_duration') ?? 0;

        $stats = [
            'total_shifts' => $totalShifts,
            'active_shifts' => $statusCounts['active'] ?? 0,
            'completed_shifts' => $statusCounts['completed'] ?? 0,
            'cancelled_shifts' => $statusCounts['cancelled'] ?? 0,
            'total_revenue' => (float) ($saleAggregates?->revenue ?? 0),
            'total_sales' => (int) ($saleAggregates?->sales_count ?? 0),
            'average_shift_duration' => (float) $avgDuration,
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
