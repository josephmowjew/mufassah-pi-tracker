<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DeviceStatus;
use App\Enums\InventoryStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TheftReportStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Device;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\SaleItem;
use App\Models\Subscription;
use App\Models\TheftReport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group Admin Reports
 *
 * APIs for generating detailed reports - theft, revenue, devices, subscriptions, and users.
 */
class AdminReportController extends Controller
{
    /**
     * Generate theft reports summary.
     */
    public function theft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'string'],
        ]);

        $query = TheftReport::query()
            ->with(['device:id,name,brand,model', 'reporter:id,name'])
            ->when(isset($validated['from']), fn ($q) => $q->where('created_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($q) => $q->where('created_at', '<=', $validated['to']))
            ->when(isset($validated['status']), fn ($q) => $q->where('status', $validated['status']));

        $reports = $query->latest()->get();

        $summary = [
            'total' => $reports->count(),
            'by_status' => $reports->groupBy(fn ($r) => $r->status->value)->map->count(),
            'recovered' => $reports->where('status', TheftReportStatus::Recovered)->count(),
            'recovery_rate' => $this->calculateRecoveryRate($reports),
            'average_resolution_days' => $this->calculateAverageResolution($reports),
        ];

        $byMonth = $reports->groupBy(fn ($r) => $r->created_at->format('Y-m'))
            ->map(fn ($group) => [
                'month' => $group->first()->created_at->format('Y-m'),
                'total' => $group->count(),
                'recovered' => $group->where('status', TheftReportStatus::Recovered)->count(),
            ])
            ->sortKeys()
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $summary,
                'by_month' => $byMonth,
                'reports' => $reports->take(100),
            ],
        ]);
    }

    /**
     * Generate revenue reports.
     */
    public function revenue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'group_by' => ['nullable', 'string', 'in:day,week,month,year'],
        ]);

        $groupBy = $validated['group_by'] ?? 'month';
        $dateFormat = match ($groupBy) {
            'day' => 'Y-m-d',
            'week' => 'Y-W',
            'month' => 'Y-m',
            'year' => 'Y',
        };

        $payments = Payment::completed()
            ->when(isset($validated['from']), fn ($q) => $q->where('paid_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($q) => $q->where('paid_at', '<=', $validated['to']))
            ->get();

        $totalRevenue = (float) $payments->sum('amount');
        $totalTransactions = $payments->count();

        $revenueByPeriod = $payments->groupBy(fn ($p) => $p->paid_at->format($dateFormat))
            ->map(fn ($group) => [
                'period' => $group->first()->paid_at->format($dateFormat),
                'transactions' => $group->count(),
                'revenue' => (float) $group->sum('amount'),
            ])
            ->sortKeys()
            ->values();

        $revenueByMethod = Payment::completed()
            ->when(isset($validated['from']), fn ($q) => $q->where('paid_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($q) => $q->where('paid_at', '<=', $validated['to']))
            ->groupBy('payment_method')
            ->select('payment_method', DB::raw('count(*) as count'), DB::raw('sum(amount) as total'))
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->payment_method->value => [
                    'count' => $row->count,
                    'total' => (float) $row->total,
                ],
            ]);

        $revenueByPlan = Payment::query()
            ->where('payments.status', PaymentStatus::Completed)
            ->join('subscriptions', 'payments.subscription_id', '=', 'subscriptions.id')
            ->join('subscription_plans', 'subscriptions.plan_id', '=', 'subscription_plans.id')
            ->when(isset($validated['from']), fn ($q) => $q->where('payments.paid_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($q) => $q->where('payments.paid_at', '<=', $validated['to']))
            ->groupBy('subscription_plans.id', 'subscription_plans.name')
            ->select('subscription_plans.name', DB::raw('count(*) as count'), DB::raw('sum(payments.amount) as total'))
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->name => [
                    'count' => $row->count,
                    'total' => (float) $row->total,
                ],
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'total_revenue' => $totalRevenue,
                    'total_transactions' => $totalTransactions,
                    'average_transaction' => $totalTransactions > 0 ? round($totalRevenue / $totalTransactions, 2) : 0,
                ],
                'by_period' => $revenueByPeriod,
                'by_method' => $revenueByMethod,
                'by_plan' => $revenueByPlan,
            ],
        ]);
    }

    /**
     * Generate device reports.
     */
    public function devices(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $query = Device::query()
            ->when(isset($validated['from']), fn ($q) => $q->where('created_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($q) => $q->where('created_at', '<=', $validated['to']));

        $total = $query->count();

        $byStatus = Device::query()
            ->when(isset($validated['from']), fn ($q) => $q->where('created_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($q) => $q->where('created_at', '<=', $validated['to']))
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as count'))
            ->get()
            ->mapWithKeys(fn ($row) => [$row->status->value => $row->count]);

        $byCategory = Device::query()
            ->join('device_categories', 'devices.category_id', '=', 'device_categories.id')
            ->when(isset($validated['from']), fn ($q) => $q->where('devices.created_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($q) => $q->where('devices.created_at', '<=', $validated['to']))
            ->groupBy('device_categories.id', 'device_categories.name')
            ->select('device_categories.name', DB::raw('count(*) as count'))
            ->get()
            ->pluck('count', 'name');

        $devices = Device::query()
            ->when(isset($validated['from']), fn ($q) => $q->where('created_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($q) => $q->where('created_at', '<=', $validated['to']))
            ->get(['created_at']);

        $registrationTrend = $devices->groupBy(fn ($d) => $d->created_at->format('Y-m'))
            ->map->count()
            ->sortKeys();

        $iotCoverage = [
            'with_module' => Device::whereNotNull('iot_module_id')->count(),
            'without_module' => Device::whereNull('iot_module_id')->count(),
            'percentage' => $total > 0 ? round((Device::whereNotNull('iot_module_id')->count() / $total) * 100, 2) : 0,
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'total' => $total,
                    'active' => $byStatus[DeviceStatus::ACTIVE->value] ?? 0,
                    'stolen' => $byStatus[DeviceStatus::STOLEN->value] ?? 0,
                    'recovered' => $byStatus[DeviceStatus::RECOVERED->value] ?? 0,
                ],
                'by_status' => $byStatus,
                'by_category' => $byCategory,
                'registration_trend' => $registrationTrend,
                'iot_coverage' => $iotCoverage,
            ],
        ]);
    }

    /**
     * Generate stock report summary by branch, product, and batch.
     */
    public function stock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : null;
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : null;
        $branchId = $this->resolveReportBranchId($request, $validated['branch_id'] ?? null);

        $deviceScope = Device::query()
            ->with('branch:id,name,code')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        $currentByStatus = (clone $deviceScope)
            ->select('inventory_status', DB::raw('count(*) as count'))
            ->groupBy('inventory_status')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->inventory_status->value => (int) $row->count]);

        $receivedScope = InventoryBatch::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($from, fn ($q) => $q->whereDate('purchase_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('purchase_date', '<=', $to->toDateString()));

        $soldScope = (clone $deviceScope)
            ->where('inventory_status', InventoryStatus::SOLD)
            ->when($from, fn ($q) => $q->where('sold_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('sold_at', '<=', $to));

        $totalReceived = (int) (clone $receivedScope)->sum('quantity_received');
        $totalSold = (int) (clone $soldScope)->count();
        $stockValue = round((float) (clone $deviceScope)
            ->whereIn('inventory_status', [InventoryStatus::IN_STOCK, InventoryStatus::RESERVED, InventoryStatus::RETURNED])
            ->sum('purchase_price'), 2);

        $administrativeSaleScope = InventoryMovement::query()
            ->where('movement_type', InventoryMovement::TYPE_ADMINISTRATIVE_SALE)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));
        $administrativeSaleValue = round((float) (clone $administrativeSaleScope)->sum('total_amount'), 2);
        $administrativeSaleProfit = round((float) (clone $administrativeSaleScope)->sum('profit_amount'), 2);

        $posSaleItemScope = SaleItem::query()->whereHas('sale', function ($q) use ($branchId, $from, $to) {
            $q->where('status', \App\Enums\SaleStatus::COMPLETED)
                ->when($branchId, fn ($saleQuery) => $saleQuery->where('branch_id', $branchId))
                ->when($from, fn ($saleQuery) => $saleQuery->where('sale_date', '>=', $from))
                ->when($to, fn ($saleQuery) => $saleQuery->where('sale_date', '<=', $to));
        });
        $posRevenue = round((float) (clone $posSaleItemScope)->sum('total_price'), 2);
        $posProfit = round((float) (clone $posSaleItemScope)->sum('profit_amount'), 2);

        $byBranch = Branch::query()
            ->when($branchId, fn ($q) => $q->whereKey($branchId))
            ->withCount([
                'devices as current_total',
                'devices as current_in_stock' => fn ($q) => $q->where('inventory_status', InventoryStatus::IN_STOCK),
                'devices as current_reserved' => fn ($q) => $q->where('inventory_status', InventoryStatus::RESERVED),
                'devices as current_sold' => fn ($q) => $q->where('inventory_status', InventoryStatus::SOLD),
                'devices as current_faulty' => fn ($q) => $q->where('inventory_status', InventoryStatus::FAULTY),
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(function (Branch $branch) use ($from, $to) {
                $received = InventoryBatch::query()
                    ->where('branch_id', $branch->id)
                    ->when($from, fn ($q) => $q->whereDate('purchase_date', '>=', $from->toDateString()))
                    ->when($to, fn ($q) => $q->whereDate('purchase_date', '<=', $to->toDateString()))
                    ->sum('quantity_received');

                $sold = Device::query()
                    ->where('branch_id', $branch->id)
                    ->where('inventory_status', InventoryStatus::SOLD)
                    ->when($from, fn ($q) => $q->where('sold_at', '>=', $from))
                    ->when($to, fn ($q) => $q->where('sold_at', '<=', $to))
                    ->count();

                return [
                    'branch_id' => $branch->id,
                    'branch_name' => $branch->name,
                    'branch_code' => $branch->code,
                    'current_total' => (int) $branch->current_total,
                    'current_in_stock' => (int) $branch->current_in_stock,
                    'current_reserved' => (int) $branch->current_reserved,
                    'current_sold' => (int) $branch->current_sold,
                    'current_faulty' => (int) $branch->current_faulty,
                    'received_in_range' => (int) $received,
                    'sold_in_range' => (int) $sold,
                ];
            });

        $byProduct = (clone $deviceScope)
            ->leftJoin('device_categories', 'devices.category_id', '=', 'device_categories.id')
            ->groupBy('devices.category_id', 'device_categories.name', 'devices.brand', 'devices.model', 'devices.color')
            ->select(
                'device_categories.name as category',
                'devices.brand',
                'devices.model',
                'devices.color',
                DB::raw('count(*) as current_total'),
                DB::raw("SUM(CASE WHEN devices.inventory_status = 'in_stock' THEN 1 ELSE 0 END) as current_in_stock"),
                DB::raw("SUM(CASE WHEN devices.inventory_status = 'reserved' THEN 1 ELSE 0 END) as current_reserved"),
                DB::raw("SUM(CASE WHEN devices.inventory_status = 'sold' THEN 1 ELSE 0 END) as current_sold"),
                DB::raw("SUM(CASE WHEN devices.inventory_status = 'faulty' THEN 1 ELSE 0 END) as current_faulty"),
                DB::raw('SUM(COALESCE(devices.purchase_price, 0)) as total_cost')
            )
            ->orderBy('device_categories.name')
            ->orderBy('devices.brand')
            ->orderBy('devices.model')
            ->limit(100)
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category ?? 'Uncategorized',
                'brand' => $row->brand,
                'model' => $row->model,
                'color' => $row->color,
                'current_total' => (int) $row->current_total,
                'current_in_stock' => (int) $row->current_in_stock,
                'current_reserved' => (int) $row->current_reserved,
                'current_sold' => (int) $row->current_sold,
                'current_faulty' => (int) $row->current_faulty,
                'total_cost' => round((float) $row->total_cost, 2),
            ]);

        $byBatch = InventoryBatch::query()
            ->with(['branch:id,name,code', 'category:id,name', 'devices:id,inventory_batch_id,inventory_status,sold_at'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($from, fn ($q) => $q->whereDate('purchase_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('purchase_date', '<=', $to->toDateString()))
            ->latest('purchase_date')
            ->limit(50)
            ->get()
            ->map(function (InventoryBatch $batch) use ($from, $to) {
                $summary = $batch->summary();
                $soldInRange = $batch->devices
                    ->where('inventory_status', InventoryStatus::SOLD)
                    ->filter(fn (Device $device) => $device->sold_at
                        && (! $from || $device->sold_at->greaterThanOrEqualTo($from))
                        && (! $to || $device->sold_at->lessThanOrEqualTo($to)))
                    ->count();

                return [
                    'id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'branch_name' => $batch->branch?->name,
                    'category' => $batch->category?->name,
                    'product' => trim("{$batch->brand} {$batch->model} {$batch->color}"),
                    'purchase_date' => $batch->purchase_date?->toDateString(),
                    'quantity_received' => (int) $batch->quantity_received,
                    'in_stock' => $summary['in_stock'],
                    'reserved' => $summary['reserved'],
                    'sold' => $summary['sold'],
                    'sold_in_range' => $soldInRange,
                    'buying_price' => (float) $batch->buying_price,
                    'target_selling_price' => (float) $batch->target_selling_price,
                    'total_cost' => $summary['total_cost'],
                    'realized_revenue' => $summary['realized_revenue'],
                    'realized_gross_profit' => $summary['realized_gross_profit'],
                    'pos_revenue' => $summary['pos_revenue'],
                    'pos_gross_profit' => $summary['pos_gross_profit'],
                    'administrative_sale_value' => $summary['administrative_sale_value'],
                    'administrative_sale_profit' => $summary['administrative_sale_profit'],
                    'combined_inventory_realized_value' => $summary['combined_inventory_realized_value'],
                    'combined_gross_profit_loss' => $summary['combined_gross_profit_loss'],
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'filters' => [
                    'from' => $from?->toDateString(),
                    'to' => $to?->toDateString(),
                    'branch_id' => $branchId,
                ],
                'summary' => [
                    'current_total' => (int) (clone $deviceScope)->count(),
                    'current_in_stock' => (int) ($currentByStatus[InventoryStatus::IN_STOCK->value] ?? 0),
                    'current_reserved' => (int) ($currentByStatus[InventoryStatus::RESERVED->value] ?? 0),
                    'current_sold' => (int) ($currentByStatus[InventoryStatus::SOLD->value] ?? 0),
                    'current_returned' => (int) ($currentByStatus[InventoryStatus::RETURNED->value] ?? 0),
                    'current_faulty' => (int) ($currentByStatus[InventoryStatus::FAULTY->value] ?? 0),
                    'received_in_range' => $totalReceived,
                    'sold_in_range' => $totalSold,
                    'stock_value' => $stockValue,
                    'pos_revenue' => $posRevenue,
                    'pos_gross_profit' => $posProfit,
                    'administrative_sale_value' => $administrativeSaleValue,
                    'administrative_sale_profit' => $administrativeSaleProfit,
                    'combined_inventory_realized_value' => round($posRevenue + $administrativeSaleValue, 2),
                    'combined_gross_profit_loss' => round($posProfit + $administrativeSaleProfit, 2),
                ],
                'by_branch' => $byBranch,
                'by_product' => $byProduct,
                'by_batch' => $byBatch,
            ],
        ]);
    }

    /**
     * Generate subscription reports.
     */
    public function subscriptions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $byStatus = Subscription::query()
            ->when(isset($validated['from']), fn ($q) => $q->where('created_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($q) => $q->where('created_at', '<=', $validated['to']))
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as count'))
            ->get()
            ->mapWithKeys(fn ($row) => [$row->status->value => $row->count]);

        $byPlan = Subscription::query()
            ->join('subscription_plans', 'subscriptions.plan_id', '=', 'subscription_plans.id')
            ->when(isset($validated['from']), fn ($q) => $q->where('subscriptions.created_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($q) => $q->where('subscriptions.created_at', '<=', $validated['to']))
            ->groupBy('subscription_plans.id', 'subscription_plans.name')
            ->select('subscription_plans.name', DB::raw('count(*) as count'))
            ->get()
            ->pluck('count', 'name');

        $subscriptions = Subscription::query()
            ->when(isset($validated['from']), fn ($q) => $q->where('created_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($q) => $q->where('created_at', '<=', $validated['to']))
            ->get(['created_at', 'status']);

        $subscriptionTrend = $subscriptions->groupBy(fn ($s) => $s->created_at->format('Y-m'))
            ->map(fn ($group) => [
                'month' => $group->first()->created_at->format('Y-m'),
                'new_subscriptions' => $group->count(),
                'cancelled' => $group->where('status', SubscriptionStatus::Cancelled)->count(),
            ])
            ->sortKeys()
            ->values();

        $churnRate = $this->calculateChurnRate();

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'total' => Subscription::count(),
                    'active' => Subscription::where('status', SubscriptionStatus::Active)->count(),
                    'churn_rate' => $churnRate,
                ],
                'by_status' => $byStatus,
                'by_plan' => $byPlan,
                'trend' => $subscriptionTrend,
            ],
        ]);
    }

    /**
     * Generate user reports.
     */
    public function users(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $total = User::query()
            ->when(isset($validated['from']), fn ($q) => $q->where('created_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($q) => $q->where('created_at', '<=', $validated['to']))
            ->count();

        $byRole = DB::table('users')
            ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', User::class)
            ->when(isset($validated['from']), fn ($q) => $q->where('users.created_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($q) => $q->where('users.created_at', '<=', $validated['to']))
            ->groupBy('roles.name')
            ->select('roles.name as role', DB::raw('count(*) as count'))
            ->get()
            ->pluck('count', 'role');

        $users = User::query()
            ->when(isset($validated['from']), fn ($q) => $q->where('created_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($q) => $q->where('created_at', '<=', $validated['to']))
            ->get(['created_at']);

        $registrationTrend = $users->groupBy(fn ($u) => $u->created_at->format('Y-m'))
            ->map->count()
            ->sortKeys();

        $withSubscriptions = User::whereHas('subscriptions', function ($q) {
            $q->where('status', SubscriptionStatus::Active);
        })->count();

        $withDevices = User::whereHas('devices')->count();

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'total' => $total,
                    'verified' => User::whereNotNull('email_verified_at')->count(),
                    'with_active_subscription' => $withSubscriptions,
                    'with_devices' => $withDevices,
                ],
                'by_role' => $byRole,
                'registration_trend' => $registrationTrend,
            ],
        ]);
    }

    /**
     * Calculate recovery rate from collection.
     */
    private function calculateRecoveryRate($reports): float
    {
        $resolved = $reports->whereIn('status', [
            TheftReportStatus::Recovered,
            TheftReportStatus::Closed,
            TheftReportStatus::FalseAlarm,
        ])->count();

        $recovered = $reports->where('status', TheftReportStatus::Recovered)->count();

        if ($resolved === 0) {
            return 0;
        }

        return round(($recovered / $resolved) * 100, 2);
    }

    private function resolveReportBranchId(Request $request, ?int $requestedBranchId): ?int
    {
        $user = $request->user();

        if ($user?->hasRole('super_admin')) {
            return $requestedBranchId;
        }

        return $user?->branch_id ? (int) $user->branch_id : $requestedBranchId;
    }

    /**
     * Calculate average resolution time from collection.
     */
    private function calculateAverageResolution($reports): ?float
    {
        $resolved = $reports->whereIn('status', [
            TheftReportStatus::Recovered,
            TheftReportStatus::Closed,
        ]);

        if ($resolved->isEmpty()) {
            return null;
        }

        $totalDays = $resolved->sum(fn ($r) => $r->created_at->diffInDays($r->updated_at));

        return round($totalDays / $resolved->count(), 1);
    }

    /**
     * Calculate subscription churn rate.
     */
    private function calculateChurnRate(): float
    {
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        $activeAtStart = Subscription::where('status', SubscriptionStatus::Active)
            ->where('created_at', '<', $startOfMonth)
            ->count();

        $cancelledThisMonth = Subscription::where('status', SubscriptionStatus::Cancelled)
            ->whereBetween('updated_at', [$startOfMonth, $endOfMonth])
            ->count();

        if ($activeAtStart === 0) {
            return 0;
        }

        return round(($cancelledThisMonth / $activeAtStart) * 100, 2);
    }
}
