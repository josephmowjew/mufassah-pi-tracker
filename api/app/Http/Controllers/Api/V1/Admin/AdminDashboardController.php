<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DeviceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TheftReportStatus;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\InventoryMovement;
use App\Models\IotModule;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\TheftReport;
use App\Models\User;
use App\Models\WarrantyClaim;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $branchId = $this->resolveBranchId($request);
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
                'administrative_sales' => [
                    'total' => $administrativeSales,
                    'this_month' => $administrativeSalesThisMonth,
                    'last_month' => $administrativeSalesLastMonth,
                    'month_over_month_change_percentage' => $this->calculatePercentageChange(
                        $administrativeSalesThisMonth,
                        $administrativeSalesLastMonth,
                    ),
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
        $branchId = $this->resolveBranchId($request);
        $period = $request->input('period', 'month');

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

        return response()->json([
            'success' => true,
            'data' => [
                'total_revenue' => (float) $this->paymentsQuery($branchId)->completed()->sum('amount'),
                'total_transactions' => $this->paymentsQuery($branchId)->count(),
                'by_status' => $paymentsByStatus,
                'by_method' => $paymentsByMethod,
                'by_plan' => $revenueByPlan,
                'trend' => $revenueTrend,
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
            ->with(['device:id,name,brand,model', 'reporter:id,name'])
            ->whereIn('status', [TheftReportStatus::Reported, TheftReportStatus::Investigating])
            ->latest()
            ->take(10)
            ->get(['id', 'device_id', 'reporter_id', 'status', 'theft_date', 'created_at']);

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
            return null;
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
