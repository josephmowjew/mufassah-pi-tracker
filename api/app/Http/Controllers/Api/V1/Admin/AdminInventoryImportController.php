<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Api\V1\Admin\ImportInventoryRequest;
use App\Http\Resources\InventoryReceiptResource;
use App\Models\ActivityLog;
use App\Models\InventoryReceipt;
use App\Services\InventoryImportService;
use App\Services\InventoryImportTemplate;
use App\Support\Inventory\InventoryBranchGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Stock intake from a spreadsheet.
 *
 * Three endpoints, all behind `permission:manage-inventory`:
 *   GET  /admin/inventory/import/template  — the sample file (csv or xlsx)
 *   POST /admin/inventory/import/preview   — parse and validate, write nothing
 *   POST /admin/inventory/import           — parse, validate and receive
 *
 * Preview and commit are separate calls that each re-parse the upload, so a
 * client can never "confirm" a stale analysis: whatever is committed was
 * validated in the same request that writes it.
 *
 * The write itself is not here — {@see InventoryImportService} translates rows
 * into the same intake calls the manual receipt form uses, which is what keeps
 * batches, price inheritance and the movement ledger identical across both
 * entry points.
 */
class AdminInventoryImportController extends BaseController
{
    public function __construct(
        private readonly InventoryImportService $importer,
        private readonly InventoryImportTemplate $template,
        private readonly InventoryBranchGuard $branchGuard,
    ) {}

    /**
     * Validate an upload and describe exactly what it would create.
     */
    public function preview(ImportInventoryRequest $request): JsonResponse
    {
        $report = $this->analyse($request);

        return $this->success($report, $this->describe($report));
    }

    /**
     * Validate an upload and receive the stock.
     */
    public function store(ImportInventoryRequest $request): JsonResponse
    {
        $report = $this->analyse($request);

        if (! $report['can_import']) {
            // 422 with the full report attached: the operator needs the row-level
            // detail to fix the sheet, and a bare "validation failed" would make
            // them guess which of two hundred rows is wrong.
            return response()->json([
                'success' => false,
                'message' => $this->describe($report),
                'data' => $report,
            ], 422);
        }

        try {
            $result = $this->importer->commit($report, $request->user());
        } catch (ValidationException $exception) {
            // Intake rejected a line on a business rule (a price it could not
            // resolve). Keep the documented 422 rather than flattening it.
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Inventory import failed', [
                'error' => $exception->getMessage(),
                'file' => $report['filename'] ?? null,
                'rows' => $report['summary']['rows'] ?? null,
                'branches' => count($report['branches'] ?? []),
            ]);

            return $this->error('The stock could not be imported and nothing was saved. Please try again.', 500);
        }

        foreach ($result['receipts'] as $receipt) {
            $this->logImport($receipt, $request, $report, $result);
        }

        $receipts = collect($result['receipts']);

        return $this->success([
            'receipts' => InventoryReceiptResource::collection($receipts),
            'units' => $result['units'],
            'batches' => $result['batches'],
            'devices' => $result['devices'],
            'branches' => $receipts->map(fn (InventoryReceipt $receipt): array => [
                'branch_id' => $receipt->branch_id,
                'branch' => $receipt->branch()->value('name'),
                'receipt_number' => $receipt->receipt_number,
            ])->values(),
        ], sprintf(
            '%s unit(s) received across %d product line(s) and %d branch(es).',
            number_format($result['units']),
            $result['batches'],
            $receipts->count(),
        ), 201);
    }

    /**
     * Download the sample file.
     *
     * Defaults to `.xlsx` because it carries the instructions sheet; `?format=csv`
     * is there for the workflow in the requirement — prepare in Excel, save as
     * CSV, upload the CSV.
     */
    public function template(Request $request): StreamedResponse
    {
        $format = strtolower($request->string('format')->toString()) === 'csv' ? 'csv' : 'xlsx';
        $contents = $format === 'csv' ? $this->template->csv() : $this->template->xlsx();

        return response()->streamDownload(
            function () use ($contents): void {
                echo $contents;
            },
            $this->template->filename($format),
            [
                'Content-Type' => $this->template->mimeType($format),
                'Content-Length' => (string) strlen($contents),
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function analyse(ImportInventoryRequest $request): array
    {
        $file = $request->file('file');
        $fallbackBranchId = $request->filled('branch_id')
            ? (int) $request->input('branch_id')
            : null;

        // A store admin with no branch, or asking for someone else's, is refused
        // before a single row is parsed.
        $fallbackBranchId = $this->branchGuard->writeBranchId($request->user(), $fallbackBranchId);

        return $this->importer->analyse(
            (string) $file->getRealPath(),
            $file->getClientOriginalName(),
            $fallbackBranchId,
            $request->user(),
        );
    }

    /**
     * One sentence the UI can show as-is.
     *
     * @param  array<string, mixed>  $report
     */
    private function describe(array $report): string
    {
        $summary = $report['summary'];
        $fileErrors = $report['errors'];

        if ($fileErrors !== []) {
            return (string) $fileErrors[0];
        }

        if ($summary['rows'] === 0) {
            return 'The file has no stock rows to import.';
        }

        if ($summary['invalid_rows'] > 0) {
            return sprintf(
                '%d of %d row(s) need attention before this file can be imported. Nothing has been saved.',
                $summary['invalid_rows'],
                $summary['rows'],
            );
        }

        return sprintf(
            '%d row(s) ready: %s unit(s) across %d branch(es), costing %s.',
            $summary['valid_rows'],
            number_format($summary['units']),
            count($report['branches']),
            'MWK '.number_format((float) $summary['total_cost'], 2),
        );
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $result
     */
    private function logImport(InventoryReceipt $receipt, Request $request, array $report, array $result): void
    {
        try {
            ActivityLog::create([
                'log_name' => 'inventory',
                'description' => 'Inventory imported from spreadsheet',
                'subject_type' => InventoryReceipt::class,
                'subject_id' => $receipt->id,
                'causer_type' => $request->user() ? get_class($request->user()) : null,
                'causer_id' => $request->user()?->id,
                'properties' => [
                    'filename' => $report['filename'] ?? null,
                    'rows_in_file' => $report['summary']['rows'] ?? null,
                    'units_in_file' => $report['summary']['units'] ?? null,
                    'receipt_units' => $result['units'] ?? null,
                    'batches' => $result['batches'] ?? null,
                ],
                'event' => 'inventory_import',
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            // The stock is already committed; a failed audit write must not turn
            // a successful import into a 500 the operator will retry.
            Log::warning('Failed to write inventory import activity log', [
                'receipt_id' => $receipt->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
