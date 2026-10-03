<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportExportService;
use App\Support\Reports\ReportFilters;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams an admin report as a multi-sheet Excel workbook.
 *
 * Accepts the same scope parameters as the JSON report endpoints (`from`, `to`,
 * `branch_id`, `all_branches`) so an export is always reproducible from the URL
 * that produced the on-screen figures.
 */
class AdminReportExportController extends Controller
{
    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private readonly ReportExportService $exporter,
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $request->validate([
            'report' => ['required', 'string', Rule::in(ReportExportService::reportTypes())],
        ]);

        $filters = ReportFilters::fromRequest($request);
        $report = (string) $request->query('report');

        $contents = $this->exporter->build($report, $filters)->toString();

        return response()->streamDownload(
            function () use ($contents): void {
                echo $contents;
            },
            $this->exporter->filename($report, $filters),
            [
                'Content-Type' => self::XLSX_MIME,
                'Content-Length' => (string) strlen($contents),
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
