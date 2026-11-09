<?php

namespace App\Services\Reports;

use App\Enums\DeviceStatus;
use App\Enums\InventoryStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TheftReportStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\Layby;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\ShiftBreak;
use App\Models\Subscription;
use App\Models\TheftReport;
use App\Models\User;
use App\Support\LocalTime;
use App\Support\Reports\ReportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes every admin report from a single {@see ReportFilters} scope.
 *
 * Both the JSON API and the Excel exporter read from here, so a number on screen
 * and the same number in a spreadsheet are produced by the same code path.
 *
 * Where a report spans a related table, queries go through `whereHas`/relations
 * rather than raw joins: `Sale`, `Payment`, `Device`, `InventoryMovement` and
 * `Subscription` are all soft-deletable, and a raw join would silently resurrect
 * trashed rows because Eloquent scopes only apply to the model's own table.
 */
class ReportDataService
{
    /** Rows returned inline by the JSON endpoints; exports are uncapped. */
    private const INLINE_ROW_LIMIT = 100;

    public function filtersMeta(ReportFilters $filters): array
    {
        return [
            'from' => $filters->fromDate(),
            'to' => $filters->toDate(),
            'branch_id' => $filters->branchId,
            'all_branches' => $filters->isGlobal(),
            'scope_label' => $filters->scopeLabel($this->branchName($filters->branchId)),
            'period_label' => $filters->periodLabel(),
        ];
    }

    /**
     * Sales report: completed POS device sales plus administrative sales.
     *
     * These are deliberately reported together but kept separable — "POS revenue"
     * is money taken at a till, "administrative sale" is stock disposed of outside
     * the POS. Subscription income is a different thing again and lives in
     * {@see self::revenue()}.
     */
    public function sales(ReportFilters $filters): array
    {
        $sales = $this->completedSales($filters);
        $administrative = $this->administrativeSales($filters);
        $returns = $this->laybyReturns($filters);
        $returnValue = (int) (clone $returns)->sum('total_minor') / 100;

        $posRevenue = round((float) $sales->sum(fn (Sale $sale) => $sale->items->sum('total_price')), 2);
        $posProfit = round((float) $sales->sum(fn (Sale $sale) => $sale->items->sum('profit_amount')), 2);
        $administrativeValue = round((float) $administrative->sum('total_amount'), 2);
        $administrativeProfit = round((float) $administrative->sum('profit_amount'), 2);

        $posCount = $sales->count();
        $administrativeCount = $administrative->count();

        return [
            'filters' => $this->filtersMeta($filters),
            'summary' => [
                'pos_sales_count' => $posCount,
                'pos_revenue' => $posRevenue,
                'pos_gross_profit' => $posProfit,
                'layby_return_count' => $returns->count(),
                'layby_return_value' => $returnValue,
                'pos_value_after_layby_returns' => round($posRevenue - $returnValue, 2),
                'administrative_sales_count' => $administrativeCount,
                'administrative_sale_value' => $administrativeValue,
                'administrative_sale_profit' => $administrativeProfit,
                'combined_revenue' => round($posRevenue + $administrativeValue, 2),
                'combined_gross_profit' => round($posProfit + $administrativeProfit, 2),
                'average_sale_value' => $posCount > 0 ? round($posRevenue / $posCount, 2) : 0,
                'units_sold' => (int) $sales->sum(fn (Sale $sale) => $sale->items->count()),
            ],
            'by_branch' => $this->salesByBranch($sales, $administrative),
            'by_product' => $this->salesByProduct($sales),
            'by_day' => $this->salesByDay($sales, $administrative),
            'recent_sales' => $this->saleRows($sales)->take(self::INLINE_ROW_LIMIT)->values(),
        ];
    }

    /**
     * Full, uncapped sale rows for the exporter.
     *
     * @return list<array<string, mixed>>
     */
    public function salesDetail(ReportFilters $filters): array
    {
        return $this->saleRows($this->completedSales($filters))->values()->all();
    }

    public function laybyReturns(ReportFilters $filters): Builder
    {
        return Layby::query()->whereNotNull('returned_at')
            ->when($filters->branchId, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->when($filters->from, fn ($q) => $q->where('returned_at', '>=', $filters->from))
            ->when($filters->to, fn ($q) => $q->where('returned_at', '<=', $filters->to));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function administrativeSalesDetail(ReportFilters $filters): array
    {
        return $this->administrativeSales($filters)
            ->map(fn (InventoryMovement $movement) => [
                'date' => $movement->created_at?->toLocalDateString(),
                'branch_name' => $movement->branch?->name ?? 'Unassigned',
                'reference' => $movement->movement_number ?? ('MOV-'.$movement->id),
                'description' => trim(implode(' ', array_filter([$movement->brand, $movement->model, $movement->color])))
                    ?: ($movement->reason ?: 'Administrative sale'),
                'quantity' => (int) ($movement->quantity ?? 1),
                'total_amount' => round((float) $movement->total_amount, 2),
                'profit_amount' => round((float) $movement->profit_amount, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * Subscription revenue. Device-sale money is in {@see self::sales()}.
     */
    public function revenue(ReportFilters $filters): array
    {
        $payments = $this->payments($filters)->completed()->get();

        $totalRevenue = (float) $payments->sum('amount');
        $totalTransactions = $payments->count();

        $byPeriod = $payments
            ->filter(fn (Payment $payment) => $payment->paid_at !== null)
            ->groupBy(fn (Payment $payment) => $payment->paid_at->toLocalFormat($filters->dateFormat()))
            ->map(fn (Collection $group) => [
                'period' => $group->first()->paid_at->toLocalFormat($filters->dateFormat()),
                'transactions' => $group->count(),
                'revenue' => round((float) $group->sum('amount'), 2),
            ])
            ->sortKeys()
            ->values();

        $byMethod = $payments
            ->groupBy(fn (Payment $payment) => $payment->payment_method?->value ?? 'unknown')
            ->map(fn (Collection $group, string $method) => [
                'count' => $group->count(),
                'total' => round((float) $group->sum('amount'), 2),
                'label' => $group->first()->payment_method?->label() ?? ucfirst($method),
            ])
            ->all();

        $byPlan = $this->payments($filters)
            ->where('payments.status', PaymentStatus::Completed)
            ->join('subscriptions', 'payments.subscription_id', '=', 'subscriptions.id')
            ->join('subscription_plans', 'subscriptions.plan_id', '=', 'subscription_plans.id')
            ->whereNull('subscriptions.deleted_at')
            ->whereNull('subscription_plans.deleted_at')
            ->when($filters->from, fn ($q) => $q->where('payments.paid_at', '>=', $filters->from))
            ->when($filters->to, fn ($q) => $q->where('payments.paid_at', '<=', $filters->to))
            ->groupBy('subscription_plans.id', 'subscription_plans.name')
            ->select('subscription_plans.name', DB::raw('count(*) as count'), DB::raw('sum(payments.amount) as total'))
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->name => ['count' => (int) $row->count, 'total' => round((float) $row->total, 2)],
            ])
            ->all();

        return [
            'filters' => $this->filtersMeta($filters),
            'summary' => [
                'total_revenue' => round($totalRevenue, 2),
                'total_transactions' => $totalTransactions,
                'average_transaction' => $totalTransactions > 0 ? round($totalRevenue / $totalTransactions, 2) : 0,
            ],
            'by_period' => $byPeriod,
            'by_method' => $byMethod,
            'by_plan' => $byPlan,
        ];
    }

    public function stock(ReportFilters $filters): array
    {
        $branchId = $filters->branchId;
        $from = $filters->from;
        $to = $filters->to;

        $deviceScope = fn () => Device::query()->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        $currentByStatus = $deviceScope()
            ->select('inventory_status', DB::raw('count(*) as count'))
            ->groupBy('inventory_status')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->inventory_status->value => (int) $row->count]);

        $receivedScope = fn () => InventoryBatch::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($from, fn ($q) => $q->whereDate('purchase_date', '>=', $filters->fromDate()))
            ->when($to, fn ($q) => $q->whereDate('purchase_date', '<=', $filters->toDate()));

        $soldScope = fn () => $deviceScope()
            ->where('inventory_status', InventoryStatus::SOLD)
            ->when($from, fn ($q) => $q->where('sold_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('sold_at', '<=', $to));

        $administrativeScope = fn () => InventoryMovement::query()
            ->where('movement_type', InventoryMovement::TYPE_ADMINISTRATIVE_SALE)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

        $posItemScope = fn () => \App\Models\SaleItem::query()->whereHas('sale', function ($q) use ($branchId, $from, $to) {
            $q->completedForReporting()
                ->when($branchId, fn ($sale) => $sale->where('branch_id', $branchId))
                ->when($from, fn ($sale) => $sale->where('sale_date', '>=', $from))
                ->when($to, fn ($sale) => $sale->where('sale_date', '<=', $to));
        });

        $posRevenue = round((float) $posItemScope()->sum('total_price'), 2);
        $posProfit = round((float) $posItemScope()->sum('profit_amount'), 2);
        $administrativeValue = round((float) $administrativeScope()->sum('total_amount'), 2);
        $administrativeProfit = round((float) $administrativeScope()->sum('profit_amount'), 2);

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
            ->map(function (Branch $branch) use ($from, $to, $filters) {
                $received = InventoryBatch::query()
                    ->where('branch_id', $branch->id)
                    ->when($from, fn ($q) => $q->whereDate('purchase_date', '>=', $filters->fromDate()))
                    ->when($to, fn ($q) => $q->whereDate('purchase_date', '<=', $filters->toDate()))
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

        $byProduct = $deviceScope()
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
            ->when($from, fn ($q) => $q->whereDate('purchase_date', '>=', $filters->fromDate()))
            ->when($to, fn ($q) => $q->whereDate('purchase_date', '<=', $filters->toDate()))
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

        return [
            'filters' => $this->filtersMeta($filters),
            'summary' => [
                'current_total' => (int) $deviceScope()->count(),
                'current_in_stock' => (int) ($currentByStatus[InventoryStatus::IN_STOCK->value] ?? 0),
                'current_reserved' => (int) ($currentByStatus[InventoryStatus::RESERVED->value] ?? 0),
                'current_sold' => (int) ($currentByStatus[InventoryStatus::SOLD->value] ?? 0),
                'current_returned' => (int) ($currentByStatus[InventoryStatus::RETURNED->value] ?? 0),
                'current_faulty' => (int) ($currentByStatus[InventoryStatus::FAULTY->value] ?? 0),
                'received_in_range' => (int) $receivedScope()->sum('quantity_received'),
                'sold_in_range' => (int) $soldScope()->count(),
                'stock_value' => round((float) $deviceScope()
                    ->whereIn('inventory_status', [InventoryStatus::IN_STOCK, InventoryStatus::RESERVED, InventoryStatus::RETURNED])
                    ->sum('purchase_price'), 2),
                'pos_revenue' => $posRevenue,
                'pos_gross_profit' => $posProfit,
                'administrative_sale_value' => $administrativeValue,
                'administrative_sale_profit' => $administrativeProfit,
                'combined_inventory_realized_value' => round($posRevenue + $administrativeValue, 2),
                'combined_gross_profit_loss' => round($posProfit + $administrativeProfit, 2),
            ],
            'by_branch' => $byBranch,
            'by_product' => $byProduct,
            'by_batch' => $byBatch,
        ];
    }

    public function devices(ReportFilters $filters): array
    {
        $scope = fn () => $this->devicesQuery($filters);

        $total = $scope()->count();

        $byStatus = $scope()
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as count'))
            ->get()
            ->mapWithKeys(fn ($row) => [$row->status->value => (int) $row->count]);

        $byCategory = $scope()
            ->leftJoin('device_categories', 'devices.category_id', '=', 'device_categories.id')
            ->groupBy('device_categories.id', 'device_categories.name')
            ->select('device_categories.name', DB::raw('count(*) as count'))
            ->get()
            ->mapWithKeys(fn ($row) => [($row->name ?? 'Uncategorized') => (int) $row->count]);

        $registrationTrend = $scope()
            ->get(['created_at'])
            ->groupBy(fn (Device $device) => $device->created_at->toLocalFormat('Y-m'))
            ->map->count()
            ->sortKeys();

        $withModule = $scope()->whereNotNull('iot_module_id')->count();

        return [
            'filters' => $this->filtersMeta($filters),
            'summary' => [
                'total' => $total,
                'active' => (int) ($byStatus[DeviceStatus::ACTIVE->value] ?? 0),
                'stolen' => (int) ($byStatus[DeviceStatus::STOLEN->value] ?? 0),
                'recovered' => (int) ($byStatus[DeviceStatus::RECOVERED->value] ?? 0),
            ],
            'by_status' => $byStatus,
            'by_category' => $byCategory,
            'registration_trend' => $registrationTrend,
            'iot_coverage' => [
                'with_module' => $withModule,
                'without_module' => $total - $withModule,
                'percentage' => $total > 0 ? round(($withModule / $total) * 100, 2) : 0,
            ],
        ];
    }

    public function theft(ReportFilters $filters): array
    {
        $reports = $this->theftReportsQuery($filters)
            ->with(['device:id,brand,model', 'reporter:id,name'])
            ->latest()
            ->get();

        $byMonth = $reports
            ->groupBy(fn (TheftReport $report) => $report->created_at->toLocalFormat('Y-m'))
            ->map(fn (Collection $group) => [
                'month' => $group->first()->created_at->toLocalFormat('Y-m'),
                'total' => $group->count(),
                'recovered' => $group->where('status', TheftReportStatus::Recovered)->count(),
            ])
            ->sortKeys()
            ->values();

        return [
            'filters' => $this->filtersMeta($filters),
            'summary' => [
                'total' => $reports->count(),
                'by_status' => $reports->groupBy(fn (TheftReport $report) => $report->status->value)->map->count(),
                'recovered' => $reports->where('status', TheftReportStatus::Recovered)->count(),
                'recovery_rate' => $this->recoveryRate($reports),
                'average_resolution_days' => $this->averageResolutionDays($reports),
            ],
            'by_month' => $byMonth,
            'reports' => $reports->take(self::INLINE_ROW_LIMIT)->values(),
        ];
    }

    public function subscriptions(ReportFilters $filters): array
    {
        $scope = fn () => $this->subscriptionsQuery($filters);

        $byStatus = $scope()
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as count'))
            ->get()
            ->mapWithKeys(fn ($row) => [$row->status->value => (int) $row->count]);

        $byPlan = $scope()
            ->leftJoin('subscription_plans', 'subscriptions.plan_id', '=', 'subscription_plans.id')
            ->whereNull('subscription_plans.deleted_at')
            ->groupBy('subscription_plans.id', 'subscription_plans.name')
            ->select('subscription_plans.name', DB::raw('count(*) as count'))
            ->get()
            ->pluck('count', 'name');

        $trend = $scope()
            ->get(['created_at', 'status'])
            ->groupBy(fn (Subscription $subscription) => $subscription->created_at->toLocalFormat('Y-m'))
            ->map(fn (Collection $group) => [
                'month' => $group->first()->created_at->toLocalFormat('Y-m'),
                'new_subscriptions' => $group->count(),
                'cancelled' => $group->where('status', SubscriptionStatus::Cancelled)->count(),
            ])
            ->sortKeys()
            ->values();

        return [
            'filters' => $this->filtersMeta($filters),
            'summary' => [
                'total' => $scope()->count(),
                'active' => (int) ($byStatus[SubscriptionStatus::Active->value] ?? 0),
                'churn_rate' => $this->churnRate($filters->branchId),
            ],
            'by_status' => $byStatus,
            'by_plan' => $byPlan,
            'trend' => $trend,
        ];
    }

    public function users(ReportFilters $filters): array
    {
        $scope = fn () => $this->usersQuery($filters);

        $total = $scope()->count();

        $byRole = DB::table('users')
            ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', User::class)
            ->whereNull('users.deleted_at')
            ->when($filters->branchId, fn ($q) => $q->where('users.branch_id', $filters->branchId))
            ->when($filters->from, fn ($q) => $q->where('users.created_at', '>=', $filters->from))
            ->when($filters->to, fn ($q) => $q->where('users.created_at', '<=', $filters->to))
            ->groupBy('roles.name')
            ->select('roles.name as role', DB::raw('count(*) as count'))
            ->get()
            ->pluck('count', 'role');

        $registrationTrend = $scope()
            ->get(['created_at'])
            ->groupBy(fn (User $user) => $user->created_at->toLocalFormat('Y-m'))
            ->map->count()
            ->sortKeys();

        return [
            'filters' => $this->filtersMeta($filters),
            'summary' => [
                'total' => $total,
                'verified' => $scope()->whereNotNull('email_verified_at')->count(),
                'with_active_subscription' => $scope()->whereHas('subscriptions', fn ($q) => $q->where('status', SubscriptionStatus::Active))->count(),
                'with_devices' => $scope()->whereHas('devices')->count(),
            ],
            'by_role' => $byRole,
            'registration_trend' => $registrationTrend,
        ];
    }

    /**
     * Staff time report: how long each employee was on the clock, how much of
     * that was break, and how much was actual work.
     *
     * Completed shifts only. An active shift has no end, so counting it would
     * inflate the period's worked total with time that has not finished
     * happening; a cancelled shift is not time at work at all.
     *
     * Durations are summed in PHP from the models rather than with a
     * `TIMESTAMPDIFF` aggregate: the suite runs on SQLite, where that function
     * does not exist, and a MySQL-only expression would ship untested.
     */
    public function staffTime(ReportFilters $filters): array
    {
        $data = $this->shiftTimeData($filters);
        $shifts = $data['shifts'];

        $scheduled = round((float) $shifts->sum(fn (Shift $shift) => (float) $shift->duration), 2);
        $breakMinutes = round((float) $shifts->sum(fn (Shift $shift) => $shift->break_minutes), 2);
        $worked = round(max(0, $scheduled - $breakMinutes), 2);
        $shiftCount = $shifts->count();

        $byStaff = $shifts
            ->groupBy('user_id')
            ->map(function (Collection $group) {
                $scheduled = round((float) $group->sum(fn (Shift $shift) => (float) $shift->duration), 2);
                $breakMinutes = round((float) $group->sum(fn (Shift $shift) => $shift->break_minutes), 2);
                $worked = round(max(0, $scheduled - $breakMinutes), 2);
                $shiftCount = $group->count();
                $first = $group->first();

                return [
                    'user_id' => $first->user_id,
                    'staff_name' => $first->user?->name ?? 'Unknown staff',
                    // A staff member can work more than one branch in a period,
                    // so this is a list rather than whichever branch came first.
                    'branch_name' => $group->pluck('branch.name')->filter()->unique()->implode(', ') ?: 'Unassigned',
                    'shifts' => $shiftCount,
                    'break_count' => (int) $group->sum(fn (Shift $shift) => $shift->break_count),
                    'scheduled_minutes' => $scheduled,
                    'break_minutes' => $breakMinutes,
                    'worked_minutes' => $worked,
                    'average_shift_minutes' => $shiftCount > 0 ? round($scheduled / $shiftCount, 2) : 0,
                    'average_break_minutes' => $shiftCount > 0 ? round($breakMinutes / $shiftCount, 2) : 0,
                    'break_percentage' => $scheduled > 0 ? round(($breakMinutes / $scheduled) * 100, 2) : 0,
                ];
            })
            ->sortByDesc('worked_minutes')
            ->values()
            ->all();

        $byDay = $shifts
            ->groupBy(fn (Shift $shift) => $shift->started_at?->toLocalDateString() ?? 'undated')
            ->map(function (Collection $group, string $date) {
                $scheduled = round((float) $group->sum(fn (Shift $shift) => (float) $shift->duration), 2);
                $breakMinutes = round((float) $group->sum(fn (Shift $shift) => $shift->break_minutes), 2);

                return [
                    'date' => $date,
                    'shifts' => $group->count(),
                    'staff' => $group->pluck('user_id')->unique()->count(),
                    'scheduled_minutes' => $scheduled,
                    'break_minutes' => $breakMinutes,
                    'worked_minutes' => round(max(0, $scheduled - $breakMinutes), 2),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();

        return [
            'filters' => $this->filtersMeta($filters),
            'summary' => [
                'total_shifts' => $shiftCount,
                'staff_count' => $shifts->pluck('user_id')->unique()->count(),
                'total_break_count' => (int) $shifts->sum(fn (Shift $shift) => $shift->break_count),
                'total_scheduled_minutes' => $scheduled,
                'total_break_minutes' => $breakMinutes,
                'total_worked_minutes' => $worked,
                'break_percentage' => $scheduled > 0 ? round(($breakMinutes / $scheduled) * 100, 2) : 0,
                'average_shift_minutes' => $shiftCount > 0 ? round($scheduled / $shiftCount, 2) : 0,
                'average_break_minutes' => $shiftCount > 0 ? round($breakMinutes / $shiftCount, 2) : 0,
                'longest_break_minutes' => $this->longestBreakMinutes($data['break_rows']),
            ],
            'by_staff' => $byStaff,
            'by_day' => $byDay,
            'breaks' => array_slice($data['break_rows'], 0, self::INLINE_ROW_LIMIT),
            'recent_shifts' => array_slice($data['shift_rows'], 0, self::INLINE_ROW_LIMIT),
        ];
    }

    /**
     * Full, uncapped shift and break rows for the exporter.
     *
     * @return array{shift_rows: list<array<string, mixed>>, break_rows: list<array<string, mixed>>}
     */
    public function staffTimeDetail(ReportFilters $filters): array
    {
        $data = $this->shiftTimeData($filters);

        return [
            'shift_rows' => $data['shift_rows'],
            'break_rows' => $data['break_rows'],
        ];
    }

    /**
     * Load the completed shifts in scope once, and derive both the per-shift
     * and per-break row sets from that single eager-loaded collection.
     *
     * @return array{shifts: Collection<int, Shift>, shift_rows: list<array<string, mixed>>, break_rows: list<array<string, mixed>>}
     */
    private function shiftTimeData(ReportFilters $filters): array
    {
        $shifts = Shift::query()
            ->where('status', 'completed')
            ->when($filters->branchId, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->when($filters->from, fn ($q) => $q->where('started_at', '>=', $filters->from))
            ->when($filters->to, fn ($q) => $q->where('started_at', '<=', $filters->to))
            ->with(['user:id,name', 'branch:id,name,code', 'breaks'])
            ->orderBy('started_at')
            ->get();

        $shiftRows = $shifts->map(fn (Shift $shift) => [
            'shift_id' => $shift->id,
            'date' => $shift->started_at?->toLocalDateString(),
            'staff_name' => $shift->user?->name ?? 'Unknown staff',
            'branch_name' => $shift->branch?->name ?? 'Unassigned',
            'started_at' => $shift->started_at?->toLocalIso8601String(),
            'ended_at' => $shift->ended_at?->toLocalIso8601String(),
            'break_count' => $shift->break_count,
            'scheduled_minutes' => round((float) $shift->duration, 2),
            'break_minutes' => $shift->break_minutes,
            'worked_minutes' => $shift->worked_minutes,
        ])->values()->all();

        $breakRows = $shifts->flatMap(fn (Shift $shift) => $shift->breaks->map(fn (ShiftBreak $break) => [
            'shift_id' => $shift->id,
            'date' => $shift->started_at?->toLocalDateString(),
            'staff_name' => $shift->user?->name ?? 'Unknown staff',
            'branch_name' => $shift->branch?->name ?? 'Unassigned',
            'type' => $break->type?->label() ?? 'Break',
            'started_at' => $break->started_at?->toLocalIso8601String(),
            'ended_at' => $break->ended_at?->toLocalIso8601String(),
            'minutes' => round((float) ($break->duration_minutes ?? 0), 2),
            'notes' => $break->notes,
        ]))->values()->all();

        return [
            'shifts' => $shifts,
            'shift_rows' => $shiftRows,
            'break_rows' => $breakRows,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $breakRows
     */
    private function longestBreakMinutes(array $breakRows): float
    {
        if ($breakRows === []) {
            return 0;
        }

        return round((float) max(array_column($breakRows, 'minutes')), 2);
    }

    /**
     * @return Collection<int, Sale>
     */
    private function completedSales(ReportFilters $filters): Collection
    {
        return Sale::query()
            ->completedForReporting()
            ->when($filters->branchId, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->when($filters->from, fn ($q) => $q->where('sale_date', '>=', $filters->from))
            ->when($filters->to, fn ($q) => $q->where('sale_date', '<=', $filters->to))
            ->with(['branch:id,name,code', 'customer:id,name', 'soldBy:id,name', 'items.device.category:id,name', 'layby.entries'])
            ->orderBy('sale_date')
            ->get();
    }

    /**
     * @return Collection<int, InventoryMovement>
     */
    private function administrativeSales(ReportFilters $filters): Collection
    {
        return InventoryMovement::query()
            ->where('movement_type', InventoryMovement::TYPE_ADMINISTRATIVE_SALE)
            ->when($filters->branchId, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->when($filters->from, fn ($q) => $q->where('created_at', '>=', $filters->from))
            ->when($filters->to, fn ($q) => $q->where('created_at', '<=', $filters->to))
            ->with('branch:id,name,code')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @param  Collection<int, Sale>  $sales
     */
    private function saleRows(Collection $sales): \Illuminate\Support\Collection
    {
        return $sales->map(fn (Sale $sale) => [
            'sale_number' => $sale->sale_number,
            'sale_date' => $sale->sale_date?->toLocalDateString(),
            'branch_name' => $sale->branch?->name ?? 'Unassigned',
            'customer_name' => $sale->customer?->name ?? 'Walk-in',
            'sold_by' => $sale->soldBy?->name ?? '—',
            'items_count' => $sale->items->count(),
            'subtotal' => round((float) $sale->subtotal, 2),
            'discount_amount' => round((float) $sale->discount_amount, 2),
            'tax_amount' => round((float) $sale->tax_amount, 2),
            'total_amount' => round((float) $sale->total_amount, 2),
            'gross_profit' => round((float) $sale->items->sum('profit_amount'), 2),
            'payment_method' => $sale->tenderLabel().collect($sale->tenderBreakdown())->map(
                fn ($minor, $tender) => ' · '.ucwords(str_replace('_', ' ', $tender)).' MWK '.number_format($minor / 100, 2)
            )->implode(''),
            'payment_status' => $sale->payment_status?->label() ?? '—',
        ]);
    }

    /**
     * @param  Collection<int, Sale>  $sales
     * @param  Collection<int, InventoryMovement>  $administrative
     * @return list<array<string, mixed>>
     */
    private function salesByBranch(Collection $sales, Collection $administrative): array
    {
        $rows = [];

        foreach ($sales as $sale) {
            $key = $sale->branch_id ?? 0;
            $rows[$key] ??= $this->emptyBranchRow($sale->branch_id, $sale->branch?->name, $sale->branch?->code);
            $rows[$key]['pos_sales_count']++;
            $rows[$key]['pos_revenue'] += (float) $sale->items->sum('total_price');
            $rows[$key]['pos_gross_profit'] += (float) $sale->items->sum('profit_amount');
        }

        foreach ($administrative as $movement) {
            $key = $movement->branch_id ?? 0;
            $rows[$key] ??= $this->emptyBranchRow($movement->branch_id, $movement->branch?->name, $movement->branch?->code);
            $rows[$key]['administrative_sales_count']++;
            $rows[$key]['administrative_sale_value'] += (float) $movement->total_amount;
            $rows[$key]['administrative_sale_profit'] += (float) $movement->profit_amount;
        }

        return collect($rows)
            ->map(function (array $row) {
                $row['combined_revenue'] = round($row['pos_revenue'] + $row['administrative_sale_value'], 2);
                $row['combined_gross_profit'] = round($row['pos_gross_profit'] + $row['administrative_sale_profit'], 2);
                $row['pos_revenue'] = round($row['pos_revenue'], 2);
                $row['pos_gross_profit'] = round($row['pos_gross_profit'], 2);
                $row['administrative_sale_value'] = round($row['administrative_sale_value'], 2);
                $row['administrative_sale_profit'] = round($row['administrative_sale_profit'], 2);

                return $row;
            })
            ->sortBy('branch_name')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyBranchRow(?int $branchId, ?string $name, ?string $code): array
    {
        return [
            'branch_id' => $branchId,
            'branch_name' => $name ?? 'Unassigned',
            'branch_code' => $code,
            'pos_sales_count' => 0,
            'pos_revenue' => 0.0,
            'pos_gross_profit' => 0.0,
            'administrative_sales_count' => 0,
            'administrative_sale_value' => 0.0,
            'administrative_sale_profit' => 0.0,
            'combined_revenue' => 0.0,
            'combined_gross_profit' => 0.0,
        ];
    }

    /**
     * @param  Collection<int, Sale>  $sales
     * @return list<array<string, mixed>>
     */
    private function salesByProduct(Collection $sales): array
    {
        $rows = [];

        foreach ($sales as $sale) {
            foreach ($sale->items as $item) {
                $device = $item->device;
                $label = trim(implode(' ', array_filter([$device?->brand, $device?->model, $device?->color])));

                if ($label === '') {
                    $label = trim((string) ($item->item_description ?? '')) ?: 'Unspecified';
                }

                $key = mb_strtolower($label);
                $rows[$key] ??= [
                    'product' => $label,
                    'category' => $device?->category?->name ?? 'Uncategorized',
                    'units_sold' => 0,
                    'revenue' => 0.0,
                    'gross_profit' => 0.0,
                ];

                $rows[$key]['units_sold']++;
                $rows[$key]['revenue'] += (float) $item->total_price;
                $rows[$key]['gross_profit'] += (float) $item->profit_amount;
            }
        }

        return collect($rows)
            ->map(function (array $row) {
                $row['revenue'] = round($row['revenue'], 2);
                $row['gross_profit'] = round($row['gross_profit'], 2);

                return $row;
            })
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Sale>  $sales
     * @param  Collection<int, InventoryMovement>  $administrative
     * @return list<array<string, mixed>>
     */
    private function salesByDay(Collection $sales, Collection $administrative): array
    {
        $rows = [];

        foreach ($sales as $sale) {
            $key = $sale->sale_date?->toLocalDateString() ?? 'undated';
            $rows[$key] ??= ['date' => $key, 'pos_sales_count' => 0, 'pos_revenue' => 0.0, 'administrative_sale_value' => 0.0, 'combined_revenue' => 0.0];
            $rows[$key]['pos_sales_count']++;
            $rows[$key]['pos_revenue'] += (float) $sale->items->sum('total_price');
        }

        foreach ($administrative as $movement) {
            $key = $movement->created_at?->toLocalDateString() ?? 'undated';
            $rows[$key] ??= ['date' => $key, 'pos_sales_count' => 0, 'pos_revenue' => 0.0, 'administrative_sale_value' => 0.0, 'combined_revenue' => 0.0];
            $rows[$key]['administrative_sale_value'] += (float) $movement->total_amount;
        }

        return collect($rows)
            ->map(function (array $row) {
                $row['pos_revenue'] = round($row['pos_revenue'], 2);
                $row['administrative_sale_value'] = round($row['administrative_sale_value'], 2);
                $row['combined_revenue'] = round($row['pos_revenue'] + $row['administrative_sale_value'], 2);

                return $row;
            })
            ->sortBy('date')
            ->values()
            ->all();
    }

    private function recoveryRate(Collection $reports): float
    {
        $resolved = $reports->whereIn('status', [
            TheftReportStatus::Recovered,
            TheftReportStatus::Closed,
            TheftReportStatus::FalseAlarm,
        ])->count();

        if ($resolved === 0) {
            return 0;
        }

        return round(($reports->where('status', TheftReportStatus::Recovered)->count() / $resolved) * 100, 2);
    }

    private function averageResolutionDays(Collection $reports): ?float
    {
        $resolved = $reports->whereIn('status', [
            TheftReportStatus::Recovered,
            TheftReportStatus::Closed,
        ]);

        if ($resolved->isEmpty()) {
            return null;
        }

        return round($resolved->sum(fn (TheftReport $report) => $report->created_at->diffInDays($report->updated_at)) / $resolved->count(), 1);
    }

    private function churnRate(?int $branchId): float
    {
        $startOfMonth = LocalTime::now()->startOfMonth()->utc();
        $endOfMonth = LocalTime::now()->endOfMonth()->utc();

        $activeAtStart = Subscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->where('created_at', '<', $startOfMonth)
            ->when($branchId, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('branch_id', $branchId)))
            ->count();

        if ($activeAtStart === 0) {
            return 0;
        }

        $cancelledThisMonth = Subscription::query()
            ->where('status', SubscriptionStatus::Cancelled)
            ->whereBetween('updated_at', [$startOfMonth, $endOfMonth])
            ->when($branchId, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('branch_id', $branchId)))
            ->count();

        return round(($cancelledThisMonth / $activeAtStart) * 100, 2);
    }

    private function usersQuery(ReportFilters $filters): Builder
    {
        return User::query()
            ->when($filters->branchId, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->when($filters->from, fn ($q) => $q->where('created_at', '>=', $filters->from))
            ->when($filters->to, fn ($q) => $q->where('created_at', '<=', $filters->to));
    }

    private function devicesQuery(ReportFilters $filters): Builder
    {
        // Columns are table-qualified because `by_category` joins
        // `device_categories`, which also has `created_at`.
        return Device::query()
            ->when($filters->branchId, fn ($q) => $q->where('devices.branch_id', $filters->branchId))
            ->when($filters->from, fn ($q) => $q->where('devices.created_at', '>=', $filters->from))
            ->when($filters->to, fn ($q) => $q->where('devices.created_at', '<=', $filters->to));
    }

    private function payments(ReportFilters $filters): Builder
    {
        return Payment::query()
            ->when($filters->branchId, fn ($q) => $q->whereHas('user', fn ($user) => $user->where('branch_id', $filters->branchId)))
            ->when($filters->from, fn ($q) => $q->where('paid_at', '>=', $filters->from))
            ->when($filters->to, fn ($q) => $q->where('paid_at', '<=', $filters->to));
    }

    private function subscriptionsQuery(ReportFilters $filters): Builder
    {
        // Qualified for the same reason as devicesQuery: `by_plan` joins
        // `subscription_plans`.
        return Subscription::query()
            ->when($filters->branchId, fn ($q) => $q->whereHas('user', fn ($user) => $user->where('branch_id', $filters->branchId)))
            ->when($filters->from, fn ($q) => $q->where('subscriptions.created_at', '>=', $filters->from))
            ->when($filters->to, fn ($q) => $q->where('subscriptions.created_at', '<=', $filters->to));
    }

    private function theftReportsQuery(ReportFilters $filters): Builder
    {
        return TheftReport::query()
            ->when($filters->branchId, fn ($q) => $q->whereHas('device', fn ($device) => $device->where('branch_id', $filters->branchId)))
            ->when($filters->from, fn ($q) => $q->where('created_at', '>=', $filters->from))
            ->when($filters->to, fn ($q) => $q->where('created_at', '<=', $filters->to));
    }

    private function branchName(?int $branchId): ?string
    {
        if ($branchId === null) {
            return null;
        }

        return Branch::query()->whereKey($branchId)->value('name');
    }
}
