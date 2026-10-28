import { useMemo, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  AlertTriangle,
  CheckCircle2,
  Download,
  FileSpreadsheet,
  Info,
  Loader2,
  Upload,
  X,
} from 'lucide-react'
import { toast } from 'sonner'
import adminApi from '@/api/admin'
import { getApiErrorMessage } from '@/lib/utils'
import { useAuthStore } from '@/stores/auth-store'
import type { Branch, StockImportReport, StockImportResult } from '@/types'

interface ImportStockModalProps {
  branches?: Branch[]
  /** Mirrors the page's branch filter so the default matches what is on screen. */
  defaultBranchId?: string
  onClose: () => void
}

const MAX_FILE_BYTES = 5 * 1024 * 1024

/** Rows rendered in the preview table; the rest are summarised in a note. */
const PREVIEW_ROW_LIMIT = 200

const money = (value: number | null | undefined) =>
  value === null || value === undefined
    ? '-'
    : `MWK ${Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const isSpreadsheet = (name: string) => /\.(csv|txt|xlsx)$/i.test(name)

/**
 * Upload a stock delivery as a spreadsheet.
 *
 * Two calls, not one: the file is validated first and nothing is written until
 * the operator confirms what the preview shows. The server re-parses the upload
 * on the confirming call, so what is committed is always what was just
 * validated — the preview is a safety net, never the source of truth.
 */
export default function ImportStockModal({ branches, defaultBranchId, onClose }: ImportStockModalProps) {
  const queryClient = useQueryClient()
  const isSuperAdmin = useAuthStore((state) => state.isSuperAdmin())
  const currentUser = useAuthStore((state) => state.user)

  const inputRef = useRef<HTMLInputElement>(null)
  const [file, setFile] = useState<File | null>(null)
  const [branchId, setBranchId] = useState(defaultBranchId ?? '')
  const [report, setReport] = useState<StockImportReport | null>(null)
  const [result, setResult] = useState<StockImportResult | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [isDragging, setIsDragging] = useState(false)
  const [downloading, setDownloading] = useState<'csv' | 'xlsx' | null>(null)

  const previewMutation = useMutation({
    mutationFn: ({ nextFile, nextBranchId }: { nextFile: File; nextBranchId: string }) =>
      adminApi.previewStockImport(nextFile, nextBranchId ? Number(nextBranchId) : null),
    onSuccess: (nextReport) => {
      setReport(nextReport)
      setError(null)
    },
    onError: (mutationError) => {
      setReport(null)
      setError(getApiErrorMessage(mutationError, 'The file could not be read.'))
    },
  })

  const importMutation = useMutation({
    mutationFn: ({ nextFile, nextBranchId }: { nextFile: File; nextBranchId: string }) =>
      adminApi.importStock(nextFile, nextBranchId ? Number(nextBranchId) : null),
    onSuccess: (outcome) => {
      if (outcome.status === 'rejected') {
        // The server refused the file; show the same detail the preview would.
        setReport(outcome.report)
        // Clear any earlier transport error, or the red box from a previous
        // attempt sits beside the report and contradicts it.
        setError(null)
        toast.error('Nothing was imported. Fix the rows marked in red and try again.')
        return
      }

      setResult(outcome.result)
      setReport(null)
      setError(null)

      for (const key of [
        'inventory',
        'inventory-grouped',
        'inventory-stats',
        'inventory-batches',
        'inventory-batch',
        'inventory-receipts',
        'admin-inventory',
        'stock-movements',
        'product-options',
        'category-stock',
        'catalog-brands',
        'catalog-models',
      ]) {
        queryClient.invalidateQueries({ queryKey: [key] })
      }

      toast.success(
        `${outcome.result.units.toLocaleString()} unit(s) received across ${outcome.result.branches.length} branch(es)`
      )
    },
    onError: (mutationError) => {
      setError(getApiErrorMessage(mutationError, 'The stock could not be imported.'))
    },
  })

  const isBusy = previewMutation.isPending || importMutation.isPending

  const acceptFile = (nextFile: File | null) => {
    if (!nextFile) return

    if (!isSpreadsheet(nextFile.name)) {
      setError('Choose a .csv or .xlsx file. If Excel saved it as .xls, use "Save As" and pick CSV or Excel Workbook.')
      setReport(null)
      setFile(null)
      return
    }

    if (nextFile.size > MAX_FILE_BYTES) {
      setError('That file is larger than 5 MB. Split it into smaller sheets.')
      setReport(null)
      setFile(null)
      return
    }

    setFile(nextFile)
    setResult(null)
    setError(null)
    previewMutation.mutate({ nextFile, nextBranchId: branchId })
  }

  const downloadTemplate = async (format: 'csv' | 'xlsx') => {
    setDownloading(format)

    try {
      const blob = await adminApi.downloadStockImportTemplate(format)
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `mufassah-stock-import-sample.${format}`
      document.body.appendChild(link)
      link.click()
      link.remove()
      URL.revokeObjectURL(url)
    } catch (downloadError) {
      toast.error(getApiErrorMessage(downloadError, 'The sample file could not be downloaded.'))
    } finally {
      setDownloading(null)
    }
  }

  const previewRows = useMemo(() => report?.rows.slice(0, PREVIEW_ROW_LIMIT) ?? [], [report])
  const hiddenRows = report ? Math.max(0, report.rows.length - previewRows.length) : 0

  const branchLabel = !isSuperAdmin
    ? currentUser?.branch?.name ?? 'Your branch'
    : branchId
      ? branches?.find((branch) => String(branch.id) === branchId)?.name ?? 'Selected branch'
      : 'Named in each row'

  const title = result
    ? 'Stock imported'
    : report
      ? 'Review the file'
      : 'Import stock from a spreadsheet'

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="import-stock-title"
        className="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl bg-white shadow-xl"
      >
        <div className="flex items-start justify-between gap-4 border-b border-slate-200 p-5">
          <div>
            <h2 id="import-stock-title" className="text-lg font-semibold text-slate-900">
              {title}
            </h2>
            <p className="mt-1 text-sm text-slate-500">
              {result
                ? 'The units are in stock and each branch has its own receipt.'
                : 'Receive many products for one or more branches from a single file.'}
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
            aria-label="Close"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="flex-1 overflow-y-auto p-5">
          {result ? (
            <ResultPanel result={result} />
          ) : (
            <div className="space-y-5">
              <section className="rounded-lg border border-slate-200 bg-slate-50 p-4">
                <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                  <Info className="h-4 w-4 text-slate-500" />
                  How this works
                </h3>
                <ol className="mt-2 list-decimal space-y-1 pl-5 text-sm text-slate-600">
                  <li>Download the sample file below — it has the exact column headers the system expects.</li>
                  <li>Fill in one row per product per branch, then delete the example rows.</li>
                  <li>Save it as CSV, or upload the Excel workbook as it is.</li>
                  <li>Upload it here, check the preview, then confirm.</li>
                </ol>
                <p className="mt-2 text-xs text-slate-500">
                  Columns are matched by name, not position, so you can reorder them. Renaming or adding a
                  column stops the upload rather than importing the wrong figures. Nothing is saved until you
                  confirm, and a file with any bad row is refused completely.
                </p>
                <div className="mt-3 flex flex-wrap gap-2">
                  <button
                    type="button"
                    onClick={() => downloadTemplate('xlsx')}
                    disabled={downloading !== null}
                    className="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50 disabled:opacity-50"
                  >
                    {downloading === 'xlsx' ? (
                      <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                      <FileSpreadsheet className="h-4 w-4 text-emerald-600" />
                    )}
                    Download Excel sample
                  </button>
                  <button
                    type="button"
                    onClick={() => downloadTemplate('csv')}
                    disabled={downloading !== null}
                    className="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50 disabled:opacity-50"
                  >
                    {downloading === 'csv' ? (
                      <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                      <Download className="h-4 w-4 text-slate-500" />
                    )}
                    Download CSV sample
                  </button>
                </div>
              </section>

              <div className="grid gap-4 sm:grid-cols-2">
                <div>
                  <label htmlFor="import-default-branch" className="mb-1 block text-sm font-medium text-slate-700">
                    Branch for rows that leave the column blank
                  </label>
                  {isSuperAdmin ? (
                    <select
                      id="import-default-branch"
                      value={branchId}
                      onChange={(event) => {
                        setBranchId(event.target.value)
                        // Re-run the preview so branch errors and price
                        // inheritance reflect the new default immediately.
                        if (file) previewMutation.mutate({ nextFile: file, nextBranchId: event.target.value })
                      }}
                      className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    >
                      <option value="">Named in each row</option>
                      {(branches ?? []).map((branch) => (
                        <option key={branch.id} value={branch.id}>
                          {branch.name}
                        </option>
                      ))}
                    </select>
                  ) : (
                    <p className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                      {branchLabel}
                    </p>
                  )}
                </div>
                <div>
                  <p className="mb-1 block text-sm font-medium text-slate-700">Stock will be received at</p>
                  <p className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                    {branchLabel}
                  </p>
                </div>
              </div>

              <div
                onDragOver={(event) => {
                  event.preventDefault()
                  setIsDragging(true)
                }}
                onDragLeave={() => setIsDragging(false)}
                onDrop={(event) => {
                  event.preventDefault()
                  setIsDragging(false)
                  acceptFile(event.dataTransfer.files?.[0] ?? null)
                }}
                className={`rounded-xl border-2 border-dashed p-6 text-center transition-colors ${
                  isDragging ? 'border-blue-400 bg-blue-50' : 'border-slate-300 bg-white'
                }`}
              >
                <input
                  ref={inputRef}
                  type="file"
                  accept=".csv,.txt,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                  className="hidden"
                  onChange={(event) => {
                    acceptFile(event.target.files?.[0] ?? null)
                    // Allow re-selecting the same file after a fix.
                    event.target.value = ''
                  }}
                />
                {previewMutation.isPending ? (
                  <div className="flex flex-col items-center gap-2 text-sm text-slate-600">
                    <Loader2 className="h-6 w-6 animate-spin text-blue-500" />
                    Reading {file?.name}…
                  </div>
                ) : (
                  <>
                    <Upload className="mx-auto h-7 w-7 text-slate-400" />
                    <p className="mt-2 text-sm text-slate-700">
                      Drop your spreadsheet here, or{' '}
                      <button
                        type="button"
                        onClick={() => inputRef.current?.click()}
                        className="font-medium text-blue-600 hover:underline"
                      >
                        choose a file
                      </button>
                    </p>
                    <p className="mt-1 text-xs text-slate-500">CSV or Excel workbook, up to 5 MB.</p>
                    {file && <p className="mt-2 text-xs font-medium text-slate-600">{file.name}</p>}
                  </>
                )}
              </div>

              {error && (
                <div className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                  <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                  <span>{error}</span>
                </div>
              )}

              {report && <ReportPanel report={report} previewRows={previewRows} hiddenRows={hiddenRows} />}
            </div>
          )}
        </div>

        <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 p-4">
          {result ? (
            <button
              type="button"
              onClick={onClose}
              className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
            >
              Done
            </button>
          ) : (
            <>
              <button
                type="button"
                onClick={onClose}
                className="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100"
              >
                Cancel
              </button>
              {report && (
                <button
                  type="button"
                  onClick={() => {
                    setReport(null)
                    setFile(null)
                    setError(null)
                  }}
                  className="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100"
                >
                  Choose another file
                </button>
              )}
              <button
                type="button"
                disabled={!report?.can_import || isBusy || !file}
                onClick={() => {
                  if (!file) return
                  importMutation.mutate({ nextFile: file, nextBranchId: branchId })
                }}
                className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {importMutation.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
                {report
                  ? `Import ${report.summary.units.toLocaleString()} unit(s)`
                  : 'Import'}
              </button>
            </>
          )}
        </div>
      </div>
    </div>
  )
}

function ReportPanel({
  report,
  previewRows,
  hiddenRows,
}: {
  report: StockImportReport
  previewRows: StockImportReport['rows']
  hiddenRows: number
}) {
  const { summary } = report

  // The table renders only the first PREVIEW_ROW_LIMIT rows, but the server
  // returns every row. Without this, a bad row at 1,500 of 2,000 is counted in
  // "5 rows need attention" and is then nowhere on screen — the operator is told
  // a problem exists and given no way to find it.
  const hiddenInvalidRows = report.rows
    .slice(PREVIEW_ROW_LIMIT)
    .filter((row) => row.status === 'error')

  return (
    <div className="space-y-4">
      {report.errors.length > 0 && (
        <div className="rounded-lg border border-red-200 bg-red-50 p-3">
          <p className="flex items-center gap-2 text-sm font-semibold text-red-800">
            <AlertTriangle className="h-4 w-4" />
            This file cannot be imported yet
          </p>
          <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-red-700">
            {report.errors.map((message) => (
              <li key={message}>{message}</li>
            ))}
          </ul>
        </div>
      )}

      {hiddenInvalidRows.length > 0 && (
        <div className="rounded-lg border border-red-200 bg-red-50 p-3">
          <p className="flex items-center gap-2 text-sm font-semibold text-red-800">
            <AlertTriangle className="h-4 w-4" />
            {hiddenInvalidRows.length} more row(s) further down the file need attention
          </p>
          <p className="mt-1 text-xs text-red-700">
            These are past the {PREVIEW_ROW_LIMIT}-row preview, so they are listed here instead of in
            the table below.
          </p>
          <ul className="mt-2 space-y-1 text-sm text-red-700">
            {hiddenInvalidRows.slice(0, 50).map((row) => (
              <li key={row.row}>
                <span className="font-medium">Row {row.row}</span>
                {row.product ? ` (${row.product})` : ''}: {row.errors.join(' ')}
              </li>
            ))}
          </ul>
          {hiddenInvalidRows.length > 50 && (
            <p className="mt-2 text-xs text-red-700">
              …and {hiddenInvalidRows.length - 50} more. Fix these first and upload again to see the
              rest.
            </p>
          )}
        </div>
      )}

      {report.warnings.length > 0 && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-3">
          <p className="text-sm font-semibold text-amber-800">Check before importing</p>
          <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-800">
            {report.warnings.map((message) => (
              <li key={message}>{message}</li>
            ))}
          </ul>
        </div>
      )}

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <SummaryTile label="Rows ready" value={`${summary.valid_rows} of ${summary.rows}`} tone="neutral" />
        <SummaryTile label="Units" value={summary.units.toLocaleString()} tone="blue" />
        <SummaryTile label="Total cost" value={money(summary.total_cost)} tone="neutral" />
        <SummaryTile label="Expected revenue" value={money(summary.expected_revenue)} tone="emerald" />
      </div>

      {report.branches.length > 0 && (
        <div className="flex flex-wrap gap-2 text-xs text-slate-600">
          {report.branches.map((branch) => (
            <span key={branch.branch_id} className="rounded-full bg-slate-100 px-2.5 py-1">
              {branch.branch}: {branch.units} unit(s) in {branch.rows} row(s)
            </span>
          ))}
        </div>
      )}

      <div className="overflow-x-auto rounded-lg border border-slate-200">
        <table className="w-full text-sm">
          <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
            <tr>
              <th className="px-3 py-2">Row</th>
              <th className="px-3 py-2">Branch</th>
              <th className="px-3 py-2">Product</th>
              <th className="px-3 py-2 text-right">Qty</th>
              <th className="px-3 py-2 text-right">Cost</th>
              <th className="px-3 py-2 text-right">Selling</th>
              <th className="px-3 py-2">Status</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-200">
            {previewRows.map((row) => (
              <tr key={row.row} className={row.status === 'error' ? 'bg-red-50' : undefined}>
                <td className="px-3 py-2 font-medium text-slate-700">{row.row}</td>
                <td className="px-3 py-2 text-slate-700">{row.branch ?? '—'}</td>
                <td className="px-3 py-2 text-slate-700">
                  {row.product || '—'}
                  {row.color ? <span className="text-slate-400"> · {row.color}</span> : null}
                </td>
                <td className="px-3 py-2 text-right text-slate-700">{row.quantity ?? '—'}</td>
                <td className="px-3 py-2 text-right text-slate-700">{money(row.buying_price)}</td>
                <td className="px-3 py-2 text-right text-slate-700">
                  {money(row.target_selling_price)}
                  {row.price_source === 'inherited' && (
                    <span className="ml-1 rounded bg-sky-100 px-1 py-0.5 text-[10px] font-medium text-sky-700">
                      inherited
                    </span>
                  )}
                </td>
                <td className="px-3 py-2">
                  {row.status === 'error' ? (
                    <ul className="list-disc space-y-0.5 pl-4 text-xs text-red-700">
                      {row.errors.map((message) => (
                        <li key={message}>{message}</li>
                      ))}
                    </ul>
                  ) : (
                    <span className="inline-flex items-center gap-1 text-xs font-medium text-emerald-700">
                      <CheckCircle2 className="h-3.5 w-3.5" />
                      Ready
                    </span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {hiddenRows > 0 && (
        <p className="text-xs text-slate-500">
          Showing the first {PREVIEW_ROW_LIMIT} of {report.rows.length} rows.
          {hiddenInvalidRows.length > 0
            ? ' The rows below that need fixing are listed above.'
            : ' All of them will be imported if the file is accepted.'}
        </p>
      )}
    </div>
  )
}

function SummaryTile({
  label,
  value,
  tone,
}: {
  label: string
  value: string
  tone: 'neutral' | 'blue' | 'emerald'
}) {
  const toneClass =
    tone === 'blue'
      ? 'bg-blue-50 text-blue-700'
      : tone === 'emerald'
        ? 'bg-emerald-50 text-emerald-700'
        : 'bg-slate-50 text-slate-700'

  return (
    <div className={`rounded-lg p-3 ${toneClass}`}>
      <p className="text-xs opacity-80">{label}</p>
      <p className="mt-1 text-sm font-semibold">{value}</p>
    </div>
  )
}

function ResultPanel({ result }: { result: StockImportResult }) {
  return (
    <div className="space-y-4">
      <div className="flex items-start gap-3 rounded-lg border border-emerald-200 bg-emerald-50 p-4">
        <CheckCircle2 className="mt-0.5 h-5 w-5 flex-shrink-0 text-emerald-600" />
        <div>
          <p className="font-semibold text-emerald-800">
            {result.units.toLocaleString()} unit(s) received
          </p>
          <p className="mt-1 text-sm text-emerald-700">
            {result.batches} product line(s) across {result.branches.length} branch(es). Each unit has an opening
            stock movement, so it is traceable from now on.
          </p>
        </div>
      </div>

      <div className="overflow-hidden rounded-lg border border-slate-200">
        <table className="w-full text-sm">
          <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
            <tr>
              <th className="px-3 py-2">Branch</th>
              <th className="px-3 py-2">Receipt</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-200">
            {result.branches.map((branch) => (
              <tr key={branch.receipt_number}>
                <td className="px-3 py-2 text-slate-700">{branch.branch ?? `Branch ${branch.branch_id}`}</td>
                <td className="px-3 py-2 font-medium text-slate-900">{branch.receipt_number}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <p className="text-xs text-slate-500">
        Find these under Inventory → Batches, or open the receipts list for the full delivery document.
      </p>
    </div>
  )
}
