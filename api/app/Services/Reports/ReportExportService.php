<?php

namespace App\Services\Reports;

use App\Support\LocalTime;
use App\Support\Reports\ReportFilters;
use App\Support\Spreadsheet\Cell;
use App\Support\Spreadsheet\XlsxSheet;
use App\Support\Spreadsheet\XlsxWorkbook;

/**
 * Lays report data out as a multi-sheet Excel workbook.
 *
 * Every figure comes from {@see ReportDataService}, the same source the JSON
 * endpoints read, so an exported workbook always reconciles with the screen.
 * Money is written as real numbers with a currency format — never pre-formatted
 * strings — so the sheets stay summable and pivotable once opened.
 */
class ReportExportService
{
    public const REPORT_SALES = 'sales';

    public const REPORT_REVENUE = 'revenue';

    public const REPORT_STOCK = 'stock';

    public const REPORT_DEVICES = 'devices';

    public const REPORT_THEFT = 'theft';

    public const REPORT_SUBSCRIPTIONS = 'subscriptions';

    public const REPORT_USERS = 'users';

    public const REPORT_STAFF_TIME = 'staff_time';

    private const CURRENCY = 'MWK';

    public function __construct(
        private readonly ReportDataService $reports,
    ) {}

    /**
     * @return list<string>
     */
    public static function reportTypes(): array
    {
        return [
            self::REPORT_SALES,
            self::REPORT_REVENUE,
            self::REPORT_STOCK,
            self::REPORT_DEVICES,
            self::REPORT_THEFT,
            self::REPORT_SUBSCRIPTIONS,
            self::REPORT_USERS,
            self::REPORT_STAFF_TIME,
        ];
    }

    public static function isValidReport(string $report): bool
    {
        return in_array($report, self::reportTypes(), true);
    }

    public function build(string $report, ReportFilters $filters): XlsxWorkbook
    {
        $book = new XlsxWorkbook('Mufassah Device Tracking');

        return match ($report) {
            self::REPORT_SALES => $this->buildSales($book, $filters),
            self::REPORT_REVENUE => $this->buildRevenue($book, $filters),
            self::REPORT_STOCK => $this->buildStock($book, $filters),
            self::REPORT_DEVICES => $this->buildDevices($book, $filters),
            self::REPORT_THEFT => $this->buildTheft($book, $filters),
            self::REPORT_SUBSCRIPTIONS => $this->buildSubscriptions($book, $filters),
            self::REPORT_USERS => $this->buildUsers($book, $filters),
            self::REPORT_STAFF_TIME => $this->buildStaffTime($book, $filters),
        };
    }

    public function filename(string $report, ReportFilters $filters): string
    {
        $meta = $this->reports->filtersMeta($filters);
        $scope = $filters->isGlobal() ? null : $meta['scope_label'];

        return sprintf(
            'mufassah-%s-report_%s.xlsx',
            $report,
            $filters->fileLabel($scope)
        );
    }

    private function buildSales(XlsxWorkbook $book, ReportFilters $filters): XlsxWorkbook
    {
        $data = $this->reports->sales($filters);
        $summary = $data['summary'];

        $this->summarySheet($book, 'Sales report', $filters, [
            'POS sales (transactions)' => Cell::number($summary['pos_sales_count']),
            'POS revenue ('.$this->currency().')' => Cell::boldMoney($summary['pos_revenue']),
            'POS gross profit ('.$this->currency().')' => Cell::boldMoney($summary['pos_gross_profit']),
            'Layby returns (transactions)' => Cell::number($summary['layby_return_count']),
            'Layby return value ('.$this->currency().')' => Cell::money($summary['layby_return_value']),
            'POS value after layby returns ('.$this->currency().')' => Cell::money($summary['pos_value_after_layby_returns']),
            'Units sold' => Cell::number($summary['units_sold']),
            'Average sale value ('.$this->currency().')' => Cell::money($summary['average_sale_value']),
            'Administrative sales (transactions)' => Cell::number($summary['administrative_sales_count']),
            'Administrative sale value ('.$this->currency().')' => Cell::money($summary['administrative_sale_value']),
            'Administrative sale profit ('.$this->currency().')' => Cell::money($summary['administrative_sale_profit']),
            'Combined revenue ('.$this->currency().')' => Cell::boldMoney($summary['combined_revenue']),
            'Combined gross profit ('.$this->currency().')' => Cell::boldMoney($summary['combined_gross_profit']),
        ]);

        $byBranch = $book->addSheet('By branch');
        $byBranch->setColumnWidths([30, 10, 14, 18, 18, 18, 20, 20]);
        $byBranch->addHeaderRow([
            'Branch', 'Code', 'POS sales', 'POS revenue', 'POS profit',
            'Admin sale value', 'Combined revenue', 'Combined profit',
        ]);
        foreach ($data['by_branch'] as $row) {
            $byBranch->addRow([
                Cell::text($row['branch_name']),
                Cell::text($row['branch_code']),
                Cell::number($row['pos_sales_count']),
                Cell::money($row['pos_revenue']),
                Cell::money($row['pos_gross_profit']),
                Cell::money($row['administrative_sale_value']),
                Cell::money($row['combined_revenue']),
                Cell::money($row['combined_gross_profit']),
            ]);
        }
        $byBranch->addRow([
            Cell::bold('Total'), null, null,
            Cell::boldMoney(collect($data['by_branch'])->sum('pos_revenue')),
            Cell::boldMoney(collect($data['by_branch'])->sum('pos_gross_profit')),
            Cell::boldMoney(collect($data['by_branch'])->sum('administrative_sale_value')),
            Cell::boldMoney(collect($data['by_branch'])->sum('combined_revenue')),
            Cell::boldMoney(collect($data['by_branch'])->sum('combined_gross_profit')),
        ]);

        $byProduct = $book->addSheet('By product');
        $byProduct->setColumnWidths([38, 20, 10, 18, 18]);
        $byProduct->addHeaderRow(['Product', 'Category', 'Units', 'Revenue', 'Gross profit']);
        foreach ($data['by_product'] as $row) {
            $byProduct->addRow([
                Cell::text($row['product']),
                Cell::text($row['category']),
                Cell::number($row['units_sold']),
                Cell::money($row['revenue']),
                Cell::money($row['gross_profit']),
            ]);
        }
        $byProduct->addRow([
            Cell::bold('Total'), null,
            Cell::bold(collect($data['by_product'])->sum('units_sold')),
            Cell::boldMoney(collect($data['by_product'])->sum('revenue')),
            Cell::boldMoney(collect($data['by_product'])->sum('gross_profit')),
        ]);

        $byDay = $book->addSheet('By day');
        $byDay->setColumnWidths([14, 14, 18, 20, 20]);
        $byDay->addHeaderRow(['Date', 'POS sales', 'POS revenue', 'Admin sale value', 'Combined revenue']);
        foreach ($data['by_day'] as $row) {
            $byDay->addRow([
                Cell::date($row['date']),
                Cell::number($row['pos_sales_count']),
                Cell::money($row['pos_revenue']),
                Cell::money($row['administrative_sale_value']),
                Cell::money($row['combined_revenue']),
            ]);
        }

        $detail = $book->addSheet('Sales detail');
        $detail->setColumnWidths([20, 13, 22, 24, 18, 10, 16, 16, 16, 16, 16, 16]);
        $detail->addHeaderRow([
            'Sale #', 'Date', 'Branch', 'Customer', 'Sold by', 'Items',
            'Subtotal', 'Discount', 'Tax', 'Total', 'Gross profit', 'Payment',
        ]);
        foreach ($this->reports->salesDetail($filters) as $row) {
            $detail->addRow([
                Cell::text($row['sale_number']),
                Cell::date($row['sale_date']),
                Cell::text($row['branch_name']),
                Cell::text($row['customer_name']),
                Cell::text($row['sold_by']),
                Cell::number($row['items_count']),
                Cell::money($row['subtotal']),
                Cell::money($row['discount_amount']),
                Cell::money($row['tax_amount']),
                Cell::money($row['total_amount']),
                Cell::money($row['gross_profit']),
                Cell::text($row['payment_method'].' / '.$row['payment_status']),
            ]);
        }

        if ($summary['layby_return_count'] > 0) {
            $returns = $book->addSheet('Layby returns');
            $returns->setColumnWidths([30, 14, 14, 20]);
            $returns->addHeaderRow(['Layby reference', 'Return date', 'Branch ID', 'Return value']);
            foreach ($this->reports->laybyReturns($filters)->orderBy('returned_at')->get() as $row) {
                $returns->addRow([
                    Cell::text($row->reference),
                    Cell::date($row->returned_at->toLocalDateString()),
                    Cell::number($row->branch_id),
                    Cell::money($row->total_minor / 100),
                ]);
            }
        }

        $administrative = $book->addSheet('Administrative sales');
        $administrative->setColumnWidths([14, 24, 20, 34, 10, 18, 18]);
        $administrative->addHeaderRow(['Date', 'Branch', 'Reference', 'Description', 'Qty', 'Value', 'Profit']);
        foreach ($this->reports->administrativeSalesDetail($filters) as $row) {
            $administrative->addRow([
                Cell::date($row['date']),
                Cell::text($row['branch_name']),
                Cell::text($row['reference']),
                Cell::text($row['description']),
                Cell::number($row['quantity']),
                Cell::money($row['total_amount']),
                Cell::money($row['profit_amount']),
            ]);
        }

        return $book;
    }

    private function buildRevenue(XlsxWorkbook $book, ReportFilters $filters): XlsxWorkbook
    {
        $data = $this->reports->revenue($filters);
        $summary = $data['summary'];

        $this->summarySheet($book, 'Revenue report', $filters, [
            'Total revenue ('.$this->currency().')' => Cell::boldMoney($summary['total_revenue']),
            'Transactions' => Cell::number($summary['total_transactions']),
            'Average transaction ('.$this->currency().')' => Cell::money($summary['average_transaction']),
        ]);

        $byPeriod = $book->addSheet('By period');
        $byPeriod->setColumnWidths([16, 14, 20]);
        $byPeriod->addHeaderRow(['Period', 'Transactions', 'Revenue']);
        foreach ($data['by_period'] as $row) {
            $byPeriod->addRow([
                Cell::text($row['period']),
                Cell::number($row['transactions']),
                Cell::money($row['revenue']),
            ]);
        }

        $byMethod = $book->addSheet('By payment method');
        $byMethod->setColumnWidths([26, 14, 20]);
        $byMethod->addHeaderRow(['Payment method', 'Transactions', 'Revenue']);
        foreach ($data['by_method'] as $row) {
            $byMethod->addRow([
                Cell::text($row['label']),
                Cell::number($row['count']),
                Cell::money($row['total']),
            ]);
        }

        $byPlan = $book->addSheet('By plan');
        $byPlan->setColumnWidths([30, 14, 20]);
        $byPlan->addHeaderRow(['Plan', 'Transactions', 'Revenue']);
        foreach ($data['by_plan'] as $plan => $row) {
            $byPlan->addRow([
                Cell::text($plan),
                Cell::number($row['count']),
                Cell::money($row['total']),
            ]);
        }

        return $book;
    }

    private function buildStock(XlsxWorkbook $book, ReportFilters $filters): XlsxWorkbook
    {
        $data = $this->reports->stock($filters);
        $summary = $data['summary'];

        $this->summarySheet($book, 'Stock report', $filters, [
            'Devices on record' => Cell::number($summary['current_total']),
            'In stock' => Cell::number($summary['current_in_stock']),
            'Reserved' => Cell::number($summary['current_reserved']),
            'Sold' => Cell::number($summary['current_sold']),
            'Returned' => Cell::number($summary['current_returned']),
            'Faulty' => Cell::number($summary['current_faulty']),
            'Received in period' => Cell::number($summary['received_in_range']),
            'Sold in period' => Cell::number($summary['sold_in_range']),
            'Stock value ('.$this->currency().')' => Cell::boldMoney($summary['stock_value']),
            'POS revenue ('.$this->currency().')' => Cell::money($summary['pos_revenue']),
            'POS gross profit ('.$this->currency().')' => Cell::money($summary['pos_gross_profit']),
            'Administrative sale value ('.$this->currency().')' => Cell::money($summary['administrative_sale_value']),
            'Combined realized value ('.$this->currency().')' => Cell::boldMoney($summary['combined_inventory_realized_value']),
            'Combined gross profit ('.$this->currency().')' => Cell::boldMoney($summary['combined_gross_profit_loss']),
        ]);

        $byBranch = $book->addSheet('By branch');
        $byBranch->setColumnWidths([30, 10, 12, 12, 12, 12, 12, 14, 14]);
        $byBranch->addHeaderRow([
            'Branch', 'Code', 'Current', 'In stock', 'Reserved', 'Sold', 'Faulty',
            'Received in period', 'Sold in period',
        ]);
        foreach ($data['by_branch'] as $row) {
            $byBranch->addRow([
                Cell::text($row['branch_name']),
                Cell::text($row['branch_code']),
                Cell::number($row['current_total']),
                Cell::number($row['current_in_stock']),
                Cell::number($row['current_reserved']),
                Cell::number($row['current_sold']),
                Cell::number($row['current_faulty']),
                Cell::number($row['received_in_range']),
                Cell::number($row['sold_in_range']),
            ]);
        }

        $byProduct = $book->addSheet('By product');
        $byProduct->setColumnWidths([34, 18, 12, 12, 12, 12, 12, 18]);
        $byProduct->addHeaderRow([
            'Product', 'Category', 'Current', 'In stock', 'Reserved', 'Sold', 'Faulty', 'Total cost',
        ]);
        foreach ($data['by_product'] as $row) {
            $label = trim(implode(' ', array_filter([$row['brand'], $row['model'], $row['color']]))) ?: 'Unspecified';
            $byProduct->addRow([
                Cell::text($label),
                Cell::text($row['category']),
                Cell::number($row['current_total']),
                Cell::number($row['current_in_stock']),
                Cell::number($row['current_reserved']),
                Cell::number($row['current_sold']),
                Cell::number($row['current_faulty']),
                Cell::money($row['total_cost']),
            ]);
        }

        $byBatch = $book->addSheet('By batch');
        $byBatch->setColumnWidths([20, 24, 30, 14, 12, 12, 12, 12, 16, 16, 16, 16, 16]);
        $byBatch->addHeaderRow([
            'Batch', 'Branch', 'Product', 'Purchased', 'Received', 'In stock', 'Reserved', 'Sold',
            'Buying price', 'Target price', 'POS revenue', 'Admin value', 'Combined P/L',
        ]);
        foreach ($data['by_batch'] as $row) {
            $byBatch->addRow([
                Cell::text($row['batch_number']),
                Cell::text($row['branch_name']),
                Cell::text($row['product']),
                Cell::date($row['purchase_date']),
                Cell::number($row['quantity_received']),
                Cell::number($row['in_stock']),
                Cell::number($row['reserved']),
                Cell::number($row['sold']),
                Cell::money($row['buying_price']),
                Cell::money($row['target_selling_price']),
                Cell::money($row['pos_revenue']),
                Cell::money($row['administrative_sale_value']),
                Cell::money($row['combined_gross_profit_loss']),
            ]);
        }

        return $book;
    }

    private function buildDevices(XlsxWorkbook $book, ReportFilters $filters): XlsxWorkbook
    {
        $data = $this->reports->devices($filters);
        $summary = $data['summary'];

        $this->summarySheet($book, 'Device report', $filters, [
            'Total devices' => Cell::number($summary['total']),
            'Active' => Cell::number($summary['active']),
            'Stolen' => Cell::number($summary['stolen']),
            'Recovered' => Cell::number($summary['recovered']),
            'With IoT module' => Cell::number($data['iot_coverage']['with_module']),
            'Without IoT module' => Cell::number($data['iot_coverage']['without_module']),
            'IoT coverage' => Cell::percent($data['iot_coverage']['percentage']),
        ]);

        $byStatus = $book->addSheet('By status');
        $byStatus->setColumnWidths([24, 14]);
        $byStatus->addHeaderRow(['Status', 'Devices']);
        foreach ($data['by_status'] as $status => $count) {
            $byStatus->addRow([Cell::text(ucfirst(str_replace('_', ' ', (string) $status))), Cell::number($count)]);
        }

        $byCategory = $book->addSheet('By category');
        $byCategory->setColumnWidths([30, 14]);
        $byCategory->addHeaderRow(['Category', 'Devices']);
        foreach ($data['by_category'] as $category => $count) {
            $byCategory->addRow([Cell::text($category), Cell::number($count)]);
        }

        $trend = $book->addSheet('Registration trend');
        $trend->setColumnWidths([16, 14]);
        $trend->addHeaderRow(['Month', 'New devices']);
        foreach ($data['registration_trend'] as $month => $count) {
            $trend->addRow([Cell::text($month), Cell::number($count)]);
        }

        return $book;
    }

    private function buildTheft(XlsxWorkbook $book, ReportFilters $filters): XlsxWorkbook
    {
        $data = $this->reports->theft($filters);
        $summary = $data['summary'];

        $this->summarySheet($book, 'Theft report', $filters, [
            'Total reports' => Cell::number($summary['total']),
            'Recovered' => Cell::number($summary['recovered']),
            'Recovery rate' => Cell::percent($summary['recovery_rate']),
            'Average resolution (days)' => $summary['average_resolution_days'] === null
                ? Cell::text('N/A')
                : Cell::number($summary['average_resolution_days']),
        ]);

        $byStatus = $book->addSheet('By status');
        $byStatus->setColumnWidths([24, 14]);
        $byStatus->addHeaderRow(['Status', 'Reports']);
        foreach ($summary['by_status'] as $status => $count) {
            $byStatus->addRow([Cell::text(ucfirst(str_replace('_', ' ', (string) $status))), Cell::number($count)]);
        }

        $byMonth = $book->addSheet('By month');
        $byMonth->setColumnWidths([16, 14, 14]);
        $byMonth->addHeaderRow(['Month', 'Reports', 'Recovered']);
        foreach ($data['by_month'] as $row) {
            $byMonth->addRow([Cell::text($row['month']), Cell::number($row['total']), Cell::number($row['recovered'])]);
        }

        $reports = $book->addSheet('Reports');
        $reports->setColumnWidths([22, 16, 30, 28, 18, 16]);
        $reports->addHeaderRow(['Report #', 'Date', 'Device', 'Reported by', 'Status', 'Resolution days']);
        foreach ($data['reports'] as $report) {
            $reports->addRow([
                Cell::text($report->report_number ?? ('#'.$report->id)),
                Cell::date($report->created_at?->toLocalDateString()),
                Cell::text($report->device?->name ?? '—'),
                Cell::text($report->reporter?->name ?? '—'),
                Cell::text($report->status?->label() ?? $report->status?->value ?? '—'),
                Cell::number($report->created_at?->diffInDays($report->updated_at)),
            ]);
        }

        return $book;
    }

    private function buildSubscriptions(XlsxWorkbook $book, ReportFilters $filters): XlsxWorkbook
    {
        $data = $this->reports->subscriptions($filters);
        $summary = $data['summary'];

        $this->summarySheet($book, 'Subscription report', $filters, [
            'Total subscriptions' => Cell::number($summary['total']),
            'Active' => Cell::number($summary['active']),
            'Churn rate' => Cell::percent($summary['churn_rate']),
        ]);

        $byStatus = $book->addSheet('By status');
        $byStatus->setColumnWidths([24, 16]);
        $byStatus->addHeaderRow(['Status', 'Subscriptions']);
        foreach ($data['by_status'] as $status => $count) {
            $byStatus->addRow([Cell::text(ucfirst(str_replace('_', ' ', (string) $status))), Cell::number($count)]);
        }

        $byPlan = $book->addSheet('By plan');
        $byPlan->setColumnWidths([30, 16]);
        $byPlan->addHeaderRow(['Plan', 'Subscriptions']);
        foreach ($data['by_plan'] as $plan => $count) {
            $byPlan->addRow([Cell::text($plan), Cell::number($count)]);
        }

        $trend = $book->addSheet('Trend');
        $trend->setColumnWidths([16, 20, 16]);
        $trend->addHeaderRow(['Month', 'New subscriptions', 'Cancelled']);
        foreach ($data['trend'] as $row) {
            $trend->addRow([Cell::text($row['month']), Cell::number($row['new_subscriptions']), Cell::number($row['cancelled'])]);
        }

        return $book;
    }

    private function buildUsers(XlsxWorkbook $book, ReportFilters $filters): XlsxWorkbook
    {
        $data = $this->reports->users($filters);
        $summary = $data['summary'];

        $this->summarySheet($book, 'User report', $filters, [
            'Total users' => Cell::number($summary['total']),
            'Email verified' => Cell::number($summary['verified']),
            'With active subscription' => Cell::number($summary['with_active_subscription']),
            'With devices' => Cell::number($summary['with_devices']),
        ]);

        $byRole = $book->addSheet('By role');
        $byRole->setColumnWidths([26, 14]);
        $byRole->addHeaderRow(['Role', 'Users']);
        foreach ($data['by_role'] as $role => $count) {
            $byRole->addRow([Cell::text(ucfirst(str_replace('_', ' ', (string) $role))), Cell::number($count)]);
        }

        $trend = $book->addSheet('Registration trend');
        $trend->setColumnWidths([16, 14]);
        $trend->addHeaderRow(['Month', 'New users']);
        foreach ($data['registration_trend'] as $month => $count) {
            $trend->addRow([Cell::text($month), Cell::number($count)]);
        }

        return $book;
    }

    /**
     * Staff time: scheduled, break and worked minutes per employee and per day.
     *
     * Durations are written as plain numbers of minutes, not as "8h 30m"
     * strings, for the same reason money is written as numbers — so the sheets
     * stay summable and pivotable after export.
     */
    private function buildStaffTime(XlsxWorkbook $book, ReportFilters $filters): XlsxWorkbook
    {
        $data = $this->reports->staffTime($filters);
        $summary = $data['summary'];

        $this->summarySheet($book, 'Staff time report', $filters, [
            'Shifts recorded' => Cell::number($summary['total_shifts']),
            'Staff' => Cell::number($summary['staff_count']),
            'Breaks taken' => Cell::number($summary['total_break_count']),
            'Scheduled time (minutes)' => Cell::bold($summary['total_scheduled_minutes']),
            'Break time (minutes)' => Cell::number($summary['total_break_minutes']),
            'Worked time (minutes)' => Cell::bold($summary['total_worked_minutes']),
            'Break share of scheduled time' => Cell::percent($summary['break_percentage']),
            'Average shift (minutes)' => Cell::number($summary['average_shift_minutes']),
            'Average break per shift (minutes)' => Cell::number($summary['average_break_minutes']),
            'Longest single break (minutes)' => Cell::number($summary['longest_break_minutes']),
        ]);

        $byStaff = $book->addSheet('By staff');
        $byStaff->setColumnWidths([28, 26, 10, 12, 16, 14, 14, 18, 18]);
        $byStaff->addHeaderRow([
            'Staff', 'Branch', 'Shifts', 'Breaks', 'Scheduled (min)',
            'Break (min)', 'Worked (min)', 'Avg shift (min)', 'Break share',
        ]);
        foreach ($data['by_staff'] as $row) {
            $byStaff->addRow([
                Cell::text($row['staff_name']),
                Cell::text($row['branch_name']),
                Cell::number($row['shifts']),
                Cell::number($row['break_count']),
                Cell::number($row['scheduled_minutes']),
                Cell::number($row['break_minutes']),
                Cell::number($row['worked_minutes']),
                Cell::number($row['average_shift_minutes']),
                Cell::percent($row['break_percentage']),
            ]);
        }
        $byStaff->addRow([
            Cell::bold('Total'), null,
            Cell::bold(collect($data['by_staff'])->sum('shifts')),
            Cell::bold(collect($data['by_staff'])->sum('break_count')),
            Cell::bold(collect($data['by_staff'])->sum('scheduled_minutes')),
            Cell::bold(collect($data['by_staff'])->sum('break_minutes')),
            Cell::bold(collect($data['by_staff'])->sum('worked_minutes')),
            null, null,
        ]);

        $byDay = $book->addSheet('By day');
        $byDay->setColumnWidths([14, 12, 12, 18, 14, 16]);
        $byDay->addHeaderRow(['Date', 'Shifts', 'Staff', 'Scheduled (min)', 'Break (min)', 'Worked (min)']);
        foreach ($data['by_day'] as $row) {
            $byDay->addRow([
                Cell::date($row['date']),
                Cell::number($row['shifts']),
                Cell::number($row['staff']),
                Cell::number($row['scheduled_minutes']),
                Cell::number($row['break_minutes']),
                Cell::number($row['worked_minutes']),
            ]);
        }

        $detail = $this->reports->staffTimeDetail($filters);

        $shiftDetail = $book->addSheet('Shift detail');
        $shiftDetail->setColumnWidths([12, 13, 26, 24, 20, 20, 10, 16, 14, 14]);
        $shiftDetail->addHeaderRow([
            'Shift #', 'Date', 'Staff', 'Branch', 'Started', 'Ended',
            'Breaks', 'Scheduled (min)', 'Break (min)', 'Worked (min)',
        ]);
        foreach ($detail['shift_rows'] as $row) {
            $shiftDetail->addRow([
                Cell::number($row['shift_id']),
                Cell::date($row['date']),
                Cell::text($row['staff_name']),
                Cell::text($row['branch_name']),
                Cell::text($row['started_at'] ? LocalTime::instantUtc($row['started_at'])->toLocalDateTimeString() : null),
                Cell::text($row['ended_at'] ? LocalTime::instantUtc($row['ended_at'])->toLocalDateTimeString() : null),
                Cell::number($row['break_count']),
                Cell::number($row['scheduled_minutes']),
                Cell::number($row['break_minutes']),
                Cell::number($row['worked_minutes']),
            ]);
        }

        $breaks = $book->addSheet('Breaks');
        $breaks->setColumnWidths([12, 13, 26, 24, 14, 20, 20, 14, 30]);
        $breaks->addHeaderRow([
            'Shift #', 'Date', 'Staff', 'Branch', 'Type', 'Started', 'Ended', 'Minutes', 'Notes',
        ]);
        foreach ($detail['break_rows'] as $row) {
            $breaks->addRow([
                Cell::number($row['shift_id']),
                Cell::date($row['date']),
                Cell::text($row['staff_name']),
                Cell::text($row['branch_name']),
                Cell::text($row['type']),
                Cell::text($row['started_at'] ? LocalTime::instantUtc($row['started_at'])->toLocalDateTimeString() : null),
                Cell::text($row['ended_at'] ? LocalTime::instantUtc($row['ended_at'])->toLocalDateTimeString() : null),
                Cell::number($row['minutes']),
                Cell::text($row['notes']),
            ]);
        }

        return $book;
    }

    /**
     * Every workbook opens with the same shape: what it is, what it covers, and
     * the headline numbers — so a spreadsheet that gets emailed on still explains
     * its own scope without the sender having to.
     *
     * @param  array<string, Cell|string|int|float|null>  $metrics
     */
    private function summarySheet(XlsxWorkbook $book, string $title, ReportFilters $filters, array $metrics): XlsxSheet
    {
        $meta = $this->reports->filtersMeta($filters);

        $sheet = $book->addSheet('Summary');
        $sheet->setColumnWidths([40, 32]);
        $sheet->addTitleRow($title);
        $sheet->addBlankRow();
        $sheet->addPairRows([
            'Report' => Cell::text($title),
            'Branch scope' => Cell::text($meta['scope_label']),
            'Period' => Cell::text($meta['period_label']),
            'Generated' => Cell::date(now()->toLocalDateString()),
        ]);
        $sheet->addBlankRow();
        $sheet->addPairRows($metrics);

        return $sheet;
    }

    private function currency(): string
    {
        return self::CURRENCY;
    }
}
