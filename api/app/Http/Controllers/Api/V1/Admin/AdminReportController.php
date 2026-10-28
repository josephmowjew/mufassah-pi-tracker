<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportDataService;
use App\Support\Reports\ReportFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Admin Reports
 *
 * Read-only reporting. Every endpoint accepts the same scope parameters:
 *
 * - `from`, `to` — inclusive date range (the `to` day is included in full).
 * - `branch_id` — pin one branch. Super admins only; other admin roles are
 *   always scoped to their own branch regardless of what they send.
 * - `all_branches` — force the global scope across every branch.
 *
 * All figures are computed by {@see ReportDataService}, which the Excel exporter
 * also uses, so an export always reconciles with what is on screen.
 */
class AdminReportController extends Controller
{
    public function __construct(
        private readonly ReportDataService $reports,
    ) {}

    /**
     * Sales report — completed POS device sales plus administrative sales.
     */
    public function sales(Request $request): JsonResponse
    {
        return $this->respond($this->reports->sales(ReportFilters::fromRequest($request)));
    }

    /**
     * Subscription revenue report.
     */
    public function revenue(Request $request): JsonResponse
    {
        return $this->respond($this->reports->revenue(
            ReportFilters::fromRequest($request, ReportFilters::groupByRules())
        ));
    }

    /**
     * Stock report by branch, product and batch.
     */
    public function stock(Request $request): JsonResponse
    {
        return $this->respond($this->reports->stock(ReportFilters::fromRequest($request)));
    }

    /**
     * Device report.
     */
    public function devices(Request $request): JsonResponse
    {
        return $this->respond($this->reports->devices(ReportFilters::fromRequest($request)));
    }

    /**
     * Theft report.
     */
    public function theft(Request $request): JsonResponse
    {
        return $this->respond($this->reports->theft(ReportFilters::fromRequest($request)));
    }

    /**
     * Subscription report.
     */
    public function subscriptions(Request $request): JsonResponse
    {
        return $this->respond($this->reports->subscriptions(ReportFilters::fromRequest($request)));
    }

    /**
     * User report.
     */
    public function users(Request $request): JsonResponse
    {
        return $this->respond($this->reports->users(ReportFilters::fromRequest($request)));
    }

    /**
     * Staff time report — scheduled, break and worked minutes per employee.
     */
    public function staffTime(Request $request): JsonResponse
    {
        return $this->respond($this->reports->staffTime(ReportFilters::fromRequest($request)));
    }

    private function respond(array $data): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
