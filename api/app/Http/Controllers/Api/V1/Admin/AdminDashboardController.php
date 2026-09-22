<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DeviceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TheftReportStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\InventoryMovement;
use App\Models\IotModule;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\TheftReport;
use App\Models\User;
use App\Models\WarrantyClaim;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * @group Admin Dashboard
 *
 * APIs for admin dashboard - overview statistics and detailed metrics.
 */
class AdminDashboardController extends Controller
{
    /**
     * Get main dashboard overview with key metrics.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateDashboardFilters($request);
        $branchId = $this->resolveBranchId($request);
        [$salesFrom, $salesTo] = $this->resolveSalesDateRange($validated);
        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $startOfLastMonth = $now->copy()->subMonth()->startOfMonth();
        $endOfLastMonth = $now->copy()->subMonth()->endOfMonth();

        $paymentQuery = $this->paymentsQuery($branchId);
        $monthlyRevenue = (float) (clone $paymentQuery)
            ->completed()
            ->where('paid_at', '>=', $startOfMonth)
            ->sum('amount');
        $lastMonthRevenue = (float) (clone $paymentQuery)
            ->completed()
            ->whereBetween('paid_at', [$startOfLastMonth, $endOfLastMonth])
            ->sum('amount');

        $administrativeSaleQuery = $this->inventoryMovementsQuery($branchId)
            ->where('movement_type', InventoryMovement::TYPE_ADMINISTRATIVE_SALE);
        $administrativeSales = (float) (clone $administrativeSaleQuery)->sum('total_amount');
        $administrativeSalesThisMonth = (float) (clone $administrativeSaleQuery)
            ->where('created_at', '>=', $startOfMonth)
            ->sum('total_amount');
        $administrativeSalesLastMonth = (float) (clone $administrativeSaleQuery)
            ->whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])
            ->sum('total_amount');
        $salesSummary = $this->buildSalesSummary($branchId, $salesFrom, $salesTo);

        return response()->json([
            'success' => true,
            'data' => [
                'users' => [
                    'total' => $this->usersQuery($branchId)->count(),
                    'new_this_month' => $this->usersQuery($branchId)->where('created_at', '>=', $startOfMonth)->count(),
                    'new_last_month' => $this->usersQuery($branchId)->whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])->count(),
                ],
                'devices' => [
                    'total' => $this->devicesQuery($branchId)->count(),
                    'active' => $this->devicesQuery($branchId)->where('status', DeviceStatus::ACTIVE)->count(),
                    'stolen' => $this->devicesQuery($branchId)->where('status', DeviceStatus::STOLEN)->count(),
                    'recovered' => $this->devicesQuery($branchId)->where('status', DeviceStatus::RECOVERED)->count(),
                    'with_iot_module' => $this->devicesQuery($branchId)->whereNotNull('iot_module_id')->count(),
                ],
                'subscriptions' => [
                    'active' => $this->subscriptionsQuery($branchId)->where('status', SubscriptionStatus::Active)->count(),
                    'total' => $this->subscriptionsQuery($branchId)->count(),
                    'new_this_month' => $this->subscriptionsQuery($branchId)->where('created_at', '>=', $startOfMonth)->count(),
                ],
                'revenue' => [
                    'total' => (float) (clone $paymentQuery)->completed()->sum('amount'),
                    'this_month' => $monthlyRevenue,
                    'last_month' => $lastMonthRevenue,
                    'month_over_month_change_percentage' => $this->calculatePercentageChange(
                        $monthlyRevenue,
                        $lastMonthRevenue,
                    ),
                ],
                'sales' => $salesSummary,
                'administrative_sales' => [
                    'total' => $administrativeSales,
                    'this_month' => $administrativeSalesThisMonth,
                    'last_month' => $administrativeSalesLastMonth,
                    'selected_range_total' => $salesSummary['administrative_sale_amount'],
                    'selected_range_transactions' => $salesSummary['administrative_sale_transactions'],
                    'selected_range_change_percentage' => $salesSummary['administrative_sale_change_percentage'],
                    'month_over_month_change_percentage' => $this->calculatePercentageChange(
                        $administrativeSalesThisMonth,
                        $administrativeSalesLastMonth,
                    ),
                ],
                'filters' => [
                    'date_from' => $salesSummary['date_from'],
                    'date_to' => $salesSummary['date_to'],
                    'branch_id' => $branchId,
                    'all_branches' => $branchId === null,
                ],
                'theft_reports' => [
                    'total' => $this->theftReportsQuery($branchId)->count(),
                    'active' => $this->theftReportsQuery($branchId)->whereIn('status', [
                        TheftReportStatus::Reported,
                        TheftReportStatus::Investigating,
                    ])->count(),
                    'recovered' => $this->theftReportsQuery($branchId)->where('status', TheftReportStatus::Recovered)->count(),
                    'recovery_rate' => $this->calculateRecoveryRate($branchId),
                ],
                'iot_modules' => [
                    'total' => $this->iotModulesQuery($branchId)->count(),
                    'active' => $this->iotModulesQuery($branchId)->where('status', 'active')->count(),
                    'linked' => $this->devicesQuery($branchId)->whereNotNull('iot_module_id')->count(),
                ],
            ],
        ]);
    }

    /**
     * Get detailed user statistics.
     */
    public function users(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request);
        $period = $request->input('period', 'month');
        $usersByRole = DB::table('users')
            ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', User::class)
            ->when($branchId, fn ($query) => $query->where('users.branch_id', $branchId))
            ->groupBy('roles.name')
            ->select('roles.name as role', DB::raw('count(*) as count'))
            ->get()
            ->pluck('count', 'role');

        $userGrowth = $this->getUserGrowthData($period, $branchId);

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $this->usersQuery($branchId)->count(),
                'by_role' => $usersByRole,
                'with_subscriptions' => $this->usersQuery($branchId)->whereHas('subscriptions', function ($q) {
                    $q->where('status', SubscriptionStatus::Active);
                })->count(),
                'with_devices' => $this->usersQuery($branchId)->whereHas('devices')->count(),
                'verified' => $this->usersQuery($branchId)->whereNotNull('email_verified_at')->count(),
                'growth' => $userGrowth,
            ],
        ]);
    }

    /**
     * Get detailed device statistics.
     */
    public function devices(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request);
        $devicesByStatus = $this->devicesQuery($branchId)
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as count'))
            ->get()
            ->mapWithKeys(fn ($item) => [$item->status->value => $item->count]);

        $devicesByCategory = $this->devicesQuery($branchId)
            ->join('device_categories', 'devices.category_id', '=', 'device_categories.id')
            ->groupBy('device_categories.id', 'device_categories.name')
            ->select('device_categories.name', DB::raw('count(*) as count'))
            ->get()
            ->pluck('count', 'name');

        $recentlyActive = $this->devicesQuery($branchId)->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subDay())
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $this->devicesQuery($branchId)->count(),
                'by_status' => $devicesByStatus,
                'by_category' => $devicesByCategory,
                'with_iot_module' => $this->devicesQuery($branchId)->whereNotNull('iot_module_id')->count(),
                'recently_active' => $recentlyActive,
                'categories' => DeviceCategory::withCount([
                    'devices' => fn ($query) => $query->when($branchId, fn ($deviceQuery) => $deviceQuery->where('branch_id', $branchId)),
                ])->get(['id', 'name', 'devices_count']),
            ],
        ]);
    }

    /**
     * Get revenue and payment statistics.
     */
    public function revenue(Request $request): JsonResponse
    {
        $validated = $this->validateDashboardFilters($request);
        $branchId = $this->resolveBranchId($request);
        $period = $request->input('period', 'month');
        [$salesFrom, $salesTo] = $this->resolveSalesDateRange($validated);

        $paymentsByStatus = $this->paymentsQuery($branchId)
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as count'), DB::raw('sum(amount) as total'))
            ->get()
            ->mapWithKeys(fn ($item) => [
                $item->status->value => [
                    'count' => $item->count,
                    'total' => (float) $item->total,
                ],
            ]);

        $paymentsByMethod = $this->paymentsQuery($branchId)->completed()
            ->groupBy('payment_method')
            ->select('payment_method', DB::raw('count(*) as count'), DB::raw('sum(amount) as total'))
            ->get()
            ->mapWithKeys(fn ($item) => [
                $item->payment_method->value => [
                    'count' => $item->count,
                    'total' => (float) $item->total,
                ],
            ]);

        $revenueByPlan = $this->paymentsQuery($branchId)
            ->where('payments.status', PaymentStatus::Completed)
            ->join('subscriptions', 'payments.subscription_id', '=', 'subscriptions.id')
            ->join('subscription_plans', 'subscriptions.plan_id', '=', 'subscription_plans.id')
            ->groupBy('subscription_plans.id', 'subscription_plans.name')
            ->select('subscription_plans.name', DB::raw('sum(payments.amount) as total'))
            ->get()
            ->pluck('total', 'name')
            ->map(fn ($v) => (float) $v);

        $revenueTrend = $this->getRevenueTrendData($period, $branchId);
        $salesSummary = $this->buildSalesSummary($branchId, $salesFrom, $salesTo);

        return response()->json([
            'success' => true,
            'data' => [
                'total_revenue' => (float) $this->paymentsQuery($branchId)->completed()->sum('amount'),
                'total_transactions' => $this->paymentsQuery($branchId)->completed()->count(),
                'by_status' => $paymentsByStatus,
                'by_method' => $paymentsByMethod,
                'by_plan' => $revenueByPlan,
                'trend' => $revenueTrend,
                'sales' => $salesSummary,
                'filters' => [
                    'date_from' => $salesSummary['date_from'],
                    'date_to' => $salesSummary['date_to'],
                    'branch_id' => $branchId,
                    'all_branches' => $branchId === null,
                ],
            ],
        ]);
    }

    /**
     * Get theft report statistics.
     */
    public function theftReports(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request);
        $reportsByStatus = $this->theftReportsQuery($branchId)
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as count'))
            ->get()
            ->mapWithKeys(fn ($item) => [$item->status->value => $item->count]);

        $activeReports = $this->theftReportsQuery($branchId)
            // `name` is an appended accessor (brand + model), not a column — selecting
            // it here raised MySQL error 1054. Request only real columns; the accessor
            // supplies `name` when the model is serialised.
            ->with(['device:id,brand,model', 'reporter:id,name'])
            ->whereIn('status', [TheftReportStatus::Reported, TheftReportStatus::Investigating])
            ->latest()
            ->take(10)
            // Column is `reported_by` (TheftReport::reporter() belongsTo on that FK).
            // `reporter_id` does not exist — it raised MySQL error 1054, and because the
            // real FK was missing from this select list the `reporter` relation could
            // never resolve, even on SQLite.
            ->get(['id', 'device_id', 'reported_by', 'status', 'theft_date', 'created_at']);

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $this->theftReportsQuery($branchId)->count(),
                'by_status' => $reportsByStatus,
                'recovery_rate' => $this->calculateRecoveryRate($branchId),
                'average_resolution_days' => $this->calculateAverageResolutionDays($branchId),
                'recent_active' => $activeReports,
                'this_month' => $this->theftReportsQuery($branchId)->where('created_at', '>=', now()->startOfMonth())->count(),
            ],
        ]);
    }

    /**
     * Get subscription statistics.
     */
    public function subscriptions(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request);
        $subscriptionsByStatus = $this->subscriptionsQuery($branchId)
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as count'))
            ->get()
            ->mapWithKeys(fn ($item) => [$item->status->value => $item->count]);

        $subscriptionsByPlan = $this->subscriptionsQuery($branchId)
            ->join('subscription_plans', 'subscriptions.plan_id', '=', 'subscription_plans.id')
            ->where('subscriptions.status', SubscriptionStatus::Active)
            ->groupBy('subscription_plans.id', 'subscription_plans.name')
            ->select('subscription_plans.name', DB::raw('count(*) as count'))
            ->get()
            ->pluck('count', 'name');

        $plans = SubscriptionPlan::withCount(['subscriptions' => function ($q) use ($branchId) {
            $q->where('status', SubscriptionStatus::Active);
            if ($branchId) {
                $q->whereHas('user', fn ($userQuery) => $userQuery->where('branch_id', $branchId));
            }
        }])->get(['id', 'name', 'price_monthly', 'price_yearly']);

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $this->subscriptionsQuery($branchId)->count(),
                'active' => $this->subscriptionsQuery($branchId)->where('status', SubscriptionStatus::Active)->count(),
                'by_status' => $subscriptionsByStatus,
                'by_plan' => $subscriptionsByPlan,
                'auto_renew_enabled' => $this->subscriptionsQuery($branchId)->where('auto_renew', true)->count(),
                'expiring_soon' => $this->subscriptionsQuery($branchId)->where('status', SubscriptionStatus::Active)
                    ->where('ends_at', '<=', now()->addDays(7))
                    ->count(),
                'plans' => $plans,
            ],
        ]);
    }

    /**
     * Get support statistics (warranty claims).
     */
    public function support(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request);
        $claimsByStatus = $this->warrantyClaimsQuery($branchId)
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as count'))
            ->get()
            ->mapWithKeys(fn ($item) => [$item->status->value => $item->count]);

        $pendingClaims = $this->warrantyClaimsQuery($branchId)
            ->with(['warranty.device:id,name,brand,owner_id', 'warranty.device.owner:id,name'])
            ->where('status', 'pending')
            ->latest()
            ->take(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total_claims' => $this->warrantyClaimsQuery($branchId)->count(),
                'by_status' => $claimsByStatus,
                'pending_count' => $this->warrantyClaimsQuery($branchId)->where('status', 'pending')->count(),
                'recent_pending' => $pendingClaims,
                'this_month' => $this->warrantyClaimsQuery($branchId)->where('created_at', '>=', now()->startOfMonth())->count(),
            ],
        ]);
    }

    /**
     * Calculate theft recovery rate.
     */
    private function calculateRecoveryRate(?int $branchId): float
    {
        $resolved = $this->theftReportsQuery($branchId)->whereIn('status', [
            TheftReportStatus::Recovered,
            TheftReportStatus::Closed,
            TheftReportStatus::FalseAlarm,
        ])->count();

        $recovered = $this->theftReportsQuery($branchId)
            ->where('status', TheftReportStatus::Recovered)
            ->count();

        if ($resolved === 0) {
            return 0;
        }

        return round(($recovered / $resolved) * 100, 2);
    }

    /**
     * Calculate the month-over-month percentage change.
     *
     * A null result represents a new metric with no prior-month baseline.
     */
    private function calculatePercentageChange(float $current, float $previous): ?float
    {
        if ($previous === 0.0) {
            return $current === 0.0 ? 0.0 : null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }

    /**
     * Validate filters shared by the dashboard overview and revenue endpoints.
     * The dashboard defaults to year-to-date sales when no range is supplied.
     */
    private function validateDashboardFilters(Request $request): array
    {
        return $request->validate([
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
    }

    /** @param array<string, mixed> $validated */
    private function resolveSalesDateRange(array $validated): array
    {
        $from = ! empty($validated['date_from'])
            ? Carbon::parse($validated['date_from'])->startOfDay()
            : now()->startOfYear();
        $to = ! empty($validated['date_to'])
            ? Carbon::parse($validated['date_to'])->endOfDay()
            : now()->endOfDay();

        return [$from, $to];
    }

    /**
     * Build the POS and administrative-sale totals for one selected period.
     * POS totals deliberately use completed Sale records; subscription payments
     * remain in the separate payment-based revenue metric.
     */
    private function buildSalesSummary(?int $branchId, Carbon $from, Carbon $to): array
    {
        $posQuery = $this->salesQuery($branchId)
            ->where('status', SaleStatus::COMPLETED)
            ->whereBetween('sale_date', [$from, $to]);
        $administrativeQuery = $this->administrativeSalesQuery($branchId)
            ->whereBetween('created_at', [$from, $to]);

        $posTotal = (float) (clone $posQuery)->sum('total_amount');
        $posTransactions = (int) (clone $posQuery)->count();
        $administrativeTotal = (float) (clone $administrativeQuery)->sum('total_amount');
        $administrativeTransactions = (int) (clone $administrativeQuery)->count();

        $rangeDays = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $previousTo = $from->copy()->startOfDay()->subSecond();
        $previousFrom = $previousTo->copy()->subDays($rangeDays - 1)->startOfDay();
        $previousPosTotal = $this->completedSalesQuery($branchId, $previousFrom, $previousTo)->sum('total_amount');
        $previousAdministrativeTotal = $this->administrativeSalesQuery($branchId)
            ->whereBetween('created_at', [$previousFrom, $previousTo])
            ->sum('total_amount');

        return [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'total_amount' => round($posTotal, 2),
            'total_transactions' => $posTransactions,
            'average_amount' => $posTransactions > 0 ? round($posTotal / $posTransactions, 2) : 0.0,
            'previous_total_amount' => round((float) $previousPosTotal, 2),
            'change_percentage' => $this->calculatePercentageChange($posTotal, (float) $previousPosTotal),
            'administrative_sale_amount' => round($administrativeTotal, 2),
            'administrative_sale_transactions' => $administrativeTransactions,
            'previous_administrative_sale_amount' => round((float) $previousAdministrativeTotal, 2),
            'administrative_sale_change_percentage' => $this->calculatePercentageChange(
                $administrativeTotal,
                (float) $previousAdministrativeTotal,
            ),
            'combined_total_amount' => round($posTotal + $administrativeTotal, 2),
            'by_branch' => $this->buildSalesBranchBreakdown($branchId, $from, $to),
            'trend' => $this->getSalesTrendData($branchId, $from, $to),
            'combined_trend' => $this->getCombinedSalesTrendData($branchId, $from, $to),
            'daily_breakdown' => $this->buildDailySalesBreakdown($branchId, $from, $to),
        ];
    }

    private function completedSalesQuery(?int $branchId, Carbon $from, Carbon $to): Builder
    {
        return $this->salesQuery($branchId)
            ->where('status', SaleStatus::COMPLETED)
            ->whereBetween('sale_date', [$from, $to]);
    }

    private function salesQuery(?int $branchId): Builder
    {
        return Sale::query()->when($branchId, fn ($query) => $query->where('branch_id', $branchId));
    }

    private function administrativeSalesQuery(?int $branchId): Builder
    {
        return InventoryMovement::query()
            ->where('movement_type', InventoryMovement::TYPE_ADMINISTRATIVE_SALE)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId));
    }

    private function buildSalesBranchBreakdown(?int $branchId, Carbon $from, Carbon $to): array
    {
        $posByBranch = $this->completedSalesQuery($branchId, $from, $to)
            ->select('branch_id', DB::raw('COUNT(*) as transactions'), DB::raw('COALESCE(SUM(total_amount), 0) as total_amount'))
            ->groupBy('branch_id')
            ->get()
            ->keyBy(fn ($row) => $row->branch_id === null ? 'unassigned' : (string) $row->branch_id);

        $administrativeByBranch = $this->administrativeSalesQuery($branchId)
            ->whereBetween('created_at', [$from, $to])
            ->select('branch_id', DB::raw('COUNT(*) as transactions'), DB::raw('COALESCE(SUM(total_amount), 0) as total_amount'))
            ->groupBy('branch_id')
            ->get()
            ->keyBy(fn ($row) => $row->branch_id === null ? 'unassigned' : (string) $row->branch_id);

        $branches = Branch::query()
            ->when($branchId, fn ($query) => $query->whereKey($branchId))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $breakdown = $branches->map(function (Branch $branch) use ($posByBranch, $administrativeByBranch): array {
            $pos = $posByBranch->get((string) $branch->id);
            $administrative = $administrativeByBranch->get((string) $branch->id);
            $posAmount = (float) ($pos?->total_amount ?? 0);
            $administrativeAmount = (float) ($administrative?->total_amount ?? 0);

            return [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'branch_code' => $branch->code,
                'pos_total_amount' => round($posAmount, 2),
                'pos_transactions' => (int) ($pos?->transactions ?? 0),
                'administrative_sale_amount' => round($administrativeAmount, 2),
                'administrative_sale_transactions' => (int) ($administrative?->transactions ?? 0),
                'combined_total_amount' => round($posAmount + $administrativeAmount, 2),
            ];
        })->values()->all();

        if ($branchId === null && ($posByBranch->has('unassigned') || $administrativeByBranch->has('unassigned'))) {
            $pos = $posByBranch->get('unassigned');
            $administrative = $administrativeByBranch->get('unassigned');
            $posAmount = (float) ($pos?->total_amount ?? 0);
            $administrativeAmount = (float) ($administrative?->total_amount ?? 0);
            array_unshift($breakdown, [
                'branch_id' => null,
                'branch_name' => 'Unassigned',
                'branch_code' => null,
                'pos_total_amount' => round($posAmount, 2),
                'pos_transactions' => (int) ($pos?->transactions ?? 0),
                'administrative_sale_amount' => round($administrativeAmount, 2),
                'administrative_sale_transactions' => (int) ($administrative?->transactions ?? 0),
                'combined_total_amount' => round($posAmount + $administrativeAmount, 2),
            ]);
        }

        return $breakdown;
    }

    private function getSalesTrendData(?int $branchId, Carbon $from, Carbon $to): array
    {
        $format = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) > 62 ? 'Y-m' : 'Y-m-d';

        return $this->completedSalesQuery($branchId, $from, $to)
            ->get(['sale_date', 'total_amount'])
            ->groupBy(fn ($sale) => $sale->sale_date->format($format))
            ->map(fn ($group) => round((float) $group->sum('total_amount'), 2))
            ->sortKeys()
            ->toArray();
    }

    private function getCombinedSalesTrendData(?int $branchId, Carbon $from, Carbon $to): array
    {
        $format = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) > 62 ? 'Y-m' : 'Y-m-d';
        $trend = [];

        $this->completedSalesQuery($branchId, $from, $to)
            ->get(['sale_date', 'total_amount'])
            ->each(function (Sale $sale) use (&$trend, $format): void {
                $key = Carbon::parse($sale->sale_date)->format($format);
                $trend[$key] = ($trend[$key] ?? 0) + (float) $sale->total_amount;
            });

        $this->administrativeSalesQuery($branchId)
            ->whereBetween('created_at', [$from, $to])
            ->get(['created_at', 'total_amount'])
            ->each(function (InventoryMovement $movement) use (&$trend, $format): void {
                $key = Carbon::parse($movement->created_at)->format($format);
                $trend[$key] = ($trend[$key] ?? 0) + (float) $movement->total_amount;
            });

        ksort($trend);

        return collect($trend)
            ->map(fn ($amount) => round((float) $amount, 2))
            ->toArray();
    }

    /**
     * Return one row per day in the selected range, including zero-value days.
     * Each row contains both sale channels and the same branch breakdown used
     * by the period summary, so the UI can show daily and branch totals from a
     * single branch-scoped response.
     */
    private function buildDailySalesBreakdown(?int $branchId, Carbon $from, Carbon $to): array
    {
        $daily = [];
        $day = $from->copy()->startOfDay();
        $lastDay = $to->copy()->startOfDay();

        while ($day->lessThanOrEqualTo($lastDay)) {
            $date = $day->toDateString();
            $daily[$date] = [
                'date' => $date,
                'pos_total_amount' => 0.0,
                'pos_transactions' => 0,
                'administrative_sale_amount' => 0.0,
                'administrative_sale_transactions' => 0,
                'combined_total_amount' => 0.0,
                'by_branch' => [],
            ];
            $day->addDay();
        }

        $branchIds = collect();
        $posSales = $this->completedSalesQuery($branchId, $from, $to)
            ->get(['branch_id', 'sale_date', 'total_amount']);
        $administrativeSales = $this->administrativeSalesQuery($branchId)
            ->whereBetween('created_at', [$from, $to])
            ->get(['branch_id', 'created_at', 'total_amount']);

        $branchIds = $branchIds
            ->merge($posSales->pluck('branch_id'))
            ->merge($administrativeSales->pluck('branch_id'))
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $branches = Branch::query()
            ->when($branchId, fn ($query) => $query->whereKey($branchId))
            ->when($branchIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $branchIds->all()))
            ->get(['id', 'name', 'code'])
            ->keyBy('id');

        foreach ($posSales as $sale) {
            $date = Carbon::parse($sale->sale_date)->toDateString();
            if (! isset($daily[$date])) {
                continue;
            }

            $this->addDailySaleAmount(
                $daily[$date],
                $sale->branch_id,
                (float) $sale->total_amount,
                true,
                $branches->get((int) $sale->branch_id),
            );
        }

        foreach ($administrativeSales as $movement) {
            $date = Carbon::parse($movement->created_at)->toDateString();
            if (! isset($daily[$date])) {
                continue;
            }

            $this->addDailySaleAmount(
                $daily[$date],
                $movement->branch_id,
                (float) $movement->total_amount,
                false,
                $branches->get((int) $movement->branch_id),
            );
        }

        return collect($daily)
            ->map(function (array $summary): array {
                $summary['by_branch'] = array_values($summary['by_branch']);

                return $summary;
            })
            ->values()
            ->all();
    }

    private function addDailySaleAmount(
        array &$day,
        ?int $branchId,
        float $amount,
        bool $isPos,
        ?Branch $branch,
    ): void {
        $amount = round($amount, 2);
        $channelAmountKey = $isPos ? 'pos_total_amount' : 'administrative_sale_amount';
        $channelTransactionsKey = $isPos ? 'pos_transactions' : 'administrative_sale_transactions';

        $day[$channelAmountKey] = round($day[$channelAmountKey] + $amount, 2);
        $day[$channelTransactionsKey]++;
        $day['combined_total_amount'] = round($day['combined_total_amount'] + $amount, 2);

        $branchKey = $branchId === null ? 'unassigned' : (string) $branchId;
        if (! isset($day['by_branch'][$branchKey])) {
            $day['by_branch'][$branchKey] = [
                'branch_id' => $branch?->id,
                'branch_name' => $branch?->name ?? 'Unassigned',
                'branch_code' => $branch?->code,
                'pos_total_amount' => 0.0,
                'pos_transactions' => 0,
                'administrative_sale_amount' => 0.0,
                'administrative_sale_transactions' => 0,
                'combined_total_amount' => 0.0,
            ];
        }

        $branchSummary = &$day['by_branch'][$branchKey];
        $branchSummary[$channelAmountKey] = round($branchSummary[$channelAmountKey] + $amount, 2);
        $branchSummary[$channelTransactionsKey]++;
        $branchSummary['combined_total_amount'] = round($branchSummary['combined_total_amount'] + $amount, 2);
        unset($branchSummary);
    }

    /**
     * Calculate average theft report resolution time in days.
     */
    private function calculateAverageResolutionDays(?int $branchId): ?float
    {
        $resolvedReports = $this->theftReportsQuery($branchId)->whereIn('status', [
            TheftReportStatus::Recovered,
            TheftReportStatus::Closed,
        ])
            ->whereNotNull('updated_at')
            ->get(['created_at', 'updated_at']);

        if ($resolvedReports->isEmpty()) {
            return null;
        }

        $totalDays = $resolvedReports->sum(function ($report) {
            return $report->created_at->diffInDays($report->updated_at);
        });

        return round($totalDays / $resolvedReports->count(), 1);
    }

    /**
     * Get user growth data for the specified period.
     */
    private function getUserGrowthData(string $period, ?int $branchId): array
    {
        $days = $period === 'year' ? 365 : 30;
        $format = $period === 'year' ? 'Y-m' : 'Y-m-d';

        $users = $this->usersQuery($branchId)
            ->where('created_at', '>=', now()->subDays($days))
            ->get(['created_at']);

        return $users->groupBy(fn ($user) => $user->created_at->format($format))
            ->map->count()
            ->sortKeys()
            ->toArray();
    }

    /**
     * Get revenue trend data for the specified period.
     */
    private function getRevenueTrendData(string $period, ?int $branchId): array
    {
        $days = $period === 'year' ? 365 : 30;
        $format = $period === 'year' ? 'Y-m' : 'Y-m-d';

        $payments = $this->paymentsQuery($branchId)->completed()
            ->where('paid_at', '>=', now()->subDays($days))
            ->get(['paid_at', 'amount']);

        return $payments->groupBy(fn ($payment) => $payment->paid_at->format($format))
            ->map(fn ($group) => (float) $group->sum('amount'))
            ->sortKeys()
            ->toArray();
    }

    private function resolveBranchId(Request $request): ?int
    {
        if ($request->user()->hasRole('super_admin')) {
            if ($request->boolean('all_branches')) {
                return null;
            }

            return $request->filled('branch_id') ? $request->integer('branch_id') : null;
        }

        return $request->user()->requiredBranchScopeId();
    }

    private function usersQuery(?int $branchId): Builder
    {
        return User::query()->when($branchId, fn ($query) => $query->where('branch_id', $branchId));
    }

    private function devicesQuery(?int $branchId): Builder
    {
        return Device::query()->when($branchId, fn ($query) => $query->where('branch_id', $branchId));
    }

    private function paymentsQuery(?int $branchId): Builder
    {
        return Payment::query()->when(
            $branchId,
            fn ($query) => $query->whereHas('user', fn ($userQuery) => $userQuery->where('branch_id', $branchId))
        );
    }

    private function subscriptionsQuery(?int $branchId): Builder
    {
        return Subscription::query()->when(
            $branchId,
            fn ($query) => $query->whereHas('user', fn ($userQuery) => $userQuery->where('branch_id', $branchId))
        );
    }

    private function theftReportsQuery(?int $branchId): Builder
    {
        return TheftReport::query()->when(
            $branchId,
            fn ($query) => $query->whereHas('device', fn ($deviceQuery) => $deviceQuery->where('branch_id', $branchId))
        );
    }

    private function inventoryMovementsQuery(?int $branchId): Builder
    {
        return InventoryMovement::query()->when($branchId, function ($query) use ($branchId) {
            $query->where(function ($branchQuery) use ($branchId) {
                $branchQuery->where('branch_id', $branchId)
                    ->orWhere('from_branch_id', $branchId)
                    ->orWhere('to_branch_id', $branchId);
            });
        });
    }

    private function iotModulesQuery(?int $branchId): Builder
    {
        return IotModule::query()->when(
            $branchId,
            fn ($query) => $query->whereHas('device', fn ($deviceQuery) => $deviceQuery->where('branch_id', $branchId))
        );
    }

    private function warrantyClaimsQuery(?int $branchId): Builder
    {
        return WarrantyClaim::query()->when(
            $branchId,
            fn ($query) => $query->whereHas(
                'warranty.device',
                fn ($deviceQuery) => $deviceQuery->where('branch_id', $branchId)
            )
        );
    }
}
