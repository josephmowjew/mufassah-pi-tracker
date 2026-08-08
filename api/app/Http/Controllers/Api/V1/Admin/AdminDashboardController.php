<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DeviceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TheftReportStatus;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\IotModule;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\TheftReport;
use App\Models\User;
use App\Models\WarrantyClaim;
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
    public function index(): JsonResponse
    {
        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $startOfLastMonth = $now->copy()->subMonth()->startOfMonth();
        $endOfLastMonth = $now->copy()->subMonth()->endOfMonth();

        $monthlyRevenue = (float) Payment::completed()
            ->where('paid_at', '>=', $startOfMonth)
            ->sum('amount');
        $lastMonthRevenue = (float) Payment::completed()
            ->whereBetween('paid_at', [$startOfLastMonth, $endOfLastMonth])
            ->sum('amount');

        $administrativeSaleQuery = InventoryMovement::query()
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
                    'total' => User::count(),
                    'new_this_month' => User::where('created_at', '>=', $startOfMonth)->count(),
                    'new_last_month' => User::whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])->count(),
                ],
                'devices' => [
                    'total' => Device::count(),
                    'active' => Device::where('status', DeviceStatus::ACTIVE)->count(),
                    'stolen' => Device::where('status', DeviceStatus::STOLEN)->count(),
                    'recovered' => Device::where('status', DeviceStatus::RECOVERED)->count(),
                    'with_iot_module' => Device::whereNotNull('iot_module_id')->count(),
                ],
                'subscriptions' => [
                    'active' => Subscription::where('status', SubscriptionStatus::Active)->count(),
                    'total' => Subscription::count(),
                    'new_this_month' => Subscription::where('created_at', '>=', $startOfMonth)->count(),
                ],
                'revenue' => [
                    'total' => (float) Payment::completed()->sum('amount'),
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
                    'total' => TheftReport::count(),
                    'active' => TheftReport::whereIn('status', [
                        TheftReportStatus::Reported,
                        TheftReportStatus::Investigating,
                    ])->count(),
                    'recovered' => TheftReport::where('status', TheftReportStatus::Recovered)->count(),
                    'recovery_rate' => $this->calculateRecoveryRate(),
                ],
                'iot_modules' => [
                    'total' => IotModule::count(),
                    'active' => IotModule::where('status', 'active')->count(),
                    'linked' => Device::whereNotNull('iot_module_id')->count(),
                ],
            ],
        ]);
    }

    /**
     * Get detailed user statistics.
     */
    public function users(Request $request): JsonResponse
    {
        $period = $request->input('period', 'month');
        $usersByRole = DB::table('users')
            ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', User::class)
            ->groupBy('roles.name')
            ->select('roles.name as role', DB::raw('count(*) as count'))
            ->get()
            ->pluck('count', 'role');

        $userGrowth = $this->getUserGrowthData($period);

        return response()->json([
            'success' => true,
            'data' => [
                'total' => User::count(),
                'by_role' => $usersByRole,
                'with_subscriptions' => User::whereHas('subscriptions', function ($q) {
                    $q->where('status', SubscriptionStatus::Active);
                })->count(),
                'with_devices' => User::whereHas('devices')->count(),
                'verified' => User::whereNotNull('email_verified_at')->count(),
                'growth' => $userGrowth,
            ],
        ]);
    }

    /**
     * Get detailed device statistics.
     */
    public function devices(Request $request): JsonResponse
    {
        $devicesByStatus = Device::query()
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as count'))
            ->get()
            ->mapWithKeys(fn ($item) => [$item->status->value => $item->count]);

        $devicesByCategory = Device::query()
            ->join('device_categories', 'devices.category_id', '=', 'device_categories.id')
            ->groupBy('device_categories.id', 'device_categories.name')
            ->select('device_categories.name', DB::raw('count(*) as count'))
            ->get()
            ->pluck('count', 'name');

        $recentlyActive = Device::whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subDay())
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => Device::count(),
                'by_status' => $devicesByStatus,
                'by_category' => $devicesByCategory,
                'with_iot_module' => Device::whereNotNull('iot_module_id')->count(),
                'recently_active' => $recentlyActive,
                'categories' => DeviceCategory::withCount('devices')->get(['id', 'name', 'devices_count']),
            ],
        ]);
    }

    /**
     * Get revenue and payment statistics.
     */
    public function revenue(Request $request): JsonResponse
    {
        $period = $request->input('period', 'month');

        $paymentsByStatus = Payment::query()
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as count'), DB::raw('sum(amount) as total'))
            ->get()
            ->mapWithKeys(fn ($item) => [
                $item->status->value => [
                    'count' => $item->count,
                    'total' => (float) $item->total,
                ],
            ]);

        $paymentsByMethod = Payment::completed()
            ->groupBy('payment_method')
            ->select('payment_method', DB::raw('count(*) as count'), DB::raw('sum(amount) as total'))
            ->get()
            ->mapWithKeys(fn ($item) => [
                $item->payment_method->value => [
                    'count' => $item->count,
                    'total' => (float) $item->total,
                ],
            ]);

        $revenueByPlan = Payment::query()
            ->where('payments.status', PaymentStatus::Completed)
            ->join('subscriptions', 'payments.subscription_id', '=', 'subscriptions.id')
            ->join('subscription_plans', 'subscriptions.plan_id', '=', 'subscription_plans.id')
            ->groupBy('subscription_plans.id', 'subscription_plans.name')
            ->select('subscription_plans.name', DB::raw('sum(payments.amount) as total'))
            ->get()
            ->pluck('total', 'name')
            ->map(fn ($v) => (float) $v);

        $revenueTrend = $this->getRevenueTrendData($period);

        return response()->json([
            'success' => true,
            'data' => [
                'total_revenue' => (float) Payment::completed()->sum('amount'),
                'total_transactions' => Payment::count(),
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
        $reportsByStatus = TheftReport::query()
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as count'))
            ->get()
            ->mapWithKeys(fn ($item) => [$item->status->value => $item->count]);

        $activeReports = TheftReport::with(['device:id,name,brand,model', 'reporter:id,name'])
            ->whereIn('status', [TheftReportStatus::Reported, TheftReportStatus::Investigating])
            ->latest()
            ->take(10)
            ->get(['id', 'device_id', 'reporter_id', 'status', 'theft_date', 'created_at']);

        return response()->json([
            'success' => true,
            'data' => [
                'total' => TheftReport::count(),
                'by_status' => $reportsByStatus,
                'recovery_rate' => $this->calculateRecoveryRate(),
                'average_resolution_days' => $this->calculateAverageResolutionDays(),
                'recent_active' => $activeReports,
                'this_month' => TheftReport::where('created_at', '>=', now()->startOfMonth())->count(),
            ],
        ]);
    }

    /**
     * Get subscription statistics.
     */
    public function subscriptions(Request $request): JsonResponse
    {
        $subscriptionsByStatus = Subscription::query()
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as count'))
            ->get()
            ->mapWithKeys(fn ($item) => [$item->status->value => $item->count]);

        $subscriptionsByPlan = Subscription::query()
            ->join('subscription_plans', 'subscriptions.plan_id', '=', 'subscription_plans.id')
            ->where('subscriptions.status', SubscriptionStatus::Active)
            ->groupBy('subscription_plans.id', 'subscription_plans.name')
            ->select('subscription_plans.name', DB::raw('count(*) as count'))
            ->get()
            ->pluck('count', 'name');

        $plans = SubscriptionPlan::withCount(['subscriptions' => function ($q) {
            $q->where('status', SubscriptionStatus::Active);
        }])->get(['id', 'name', 'price_monthly', 'price_yearly']);

        return response()->json([
            'success' => true,
            'data' => [
                'total' => Subscription::count(),
                'active' => Subscription::where('status', SubscriptionStatus::Active)->count(),
                'by_status' => $subscriptionsByStatus,
                'by_plan' => $subscriptionsByPlan,
                'auto_renew_enabled' => Subscription::where('auto_renew', true)->count(),
                'expiring_soon' => Subscription::where('status', SubscriptionStatus::Active)
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
        $claimsByStatus = WarrantyClaim::query()
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as count'))
            ->get()
            ->mapWithKeys(fn ($item) => [$item->status->value => $item->count]);

        $pendingClaims = WarrantyClaim::with(['warranty.device:id,name,brand', 'warranty.user:id,name'])
            ->where('status', 'pending')
            ->latest()
            ->take(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total_claims' => WarrantyClaim::count(),
                'by_status' => $claimsByStatus,
                'pending_count' => WarrantyClaim::where('status', 'pending')->count(),
                'recent_pending' => $pendingClaims,
                'this_month' => WarrantyClaim::where('created_at', '>=', now()->startOfMonth())->count(),
            ],
        ]);
    }

    /**
     * Calculate theft recovery rate.
     */
    private function calculateRecoveryRate(): float
    {
        $resolved = TheftReport::whereIn('status', [
            TheftReportStatus::Recovered,
            TheftReportStatus::Closed,
            TheftReportStatus::FalseAlarm,
        ])->count();

        $recovered = TheftReport::where('status', TheftReportStatus::Recovered)->count();

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
    private function calculateAverageResolutionDays(): ?float
    {
        $resolvedReports = TheftReport::whereIn('status', [
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
    private function getUserGrowthData(string $period): array
    {
        $days = $period === 'year' ? 365 : 30;
        $format = $period === 'year' ? 'Y-m' : 'Y-m-d';

        $users = User::query()
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
    private function getRevenueTrendData(string $period): array
    {
        $days = $period === 'year' ? 365 : 30;
        $format = $period === 'year' ? 'Y-m' : 'Y-m-d';

        $payments = Payment::completed()
            ->where('paid_at', '>=', now()->subDays($days))
            ->get(['paid_at', 'amount']);

        return $payments->groupBy(fn ($payment) => $payment->paid_at->format($format))
            ->map(fn ($group) => (float) $group->sum('amount'))
            ->sortKeys()
            ->toArray();
    }
}
