import {
  AlertTriangle,
  Boxes,
  CheckCircle2,
  Loader2,
  MapPin,
  Package,
  Radio,
  ShieldAlert,
  Warehouse,
  X,
} from 'lucide-react'
import type { DeviceDeletionImpact } from '@/types'

interface DeviceDeletionImpactModalProps {
  impact: DeviceDeletionImpact | null
  isLoading: boolean
  error: string | null
  elevated?: boolean
  onConfirm: () => void
  onCancel: () => void
  onRetry: () => void
}

const formatMoney = (value: number | string | null | undefined) => {
  const amount = Number(value || 0)
  return `MWK ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

const statusClasses: Record<string, string> = {
  in_stock: 'bg-emerald-100 text-emerald-800',
  reserved: 'bg-amber-100 text-amber-800',
  sold: 'bg-blue-100 text-blue-800',
  returned: 'bg-purple-100 text-purple-800',
  faulty: 'bg-red-100 text-red-800',
}

function ImpactTile({ label, value, tone }: { label: string; value: string | number; tone: 'red' | 'amber' | 'slate' }) {
  const tones = {
    red: 'bg-red-50 text-red-800',
    amber: 'bg-amber-50 text-amber-800',
    slate: 'bg-slate-50 text-slate-700',
  }

  return (
    <div className={`rounded-lg p-3 ${tones[tone]}`}>
      <p className="text-xs opacity-80">{label}</p>
      <p className="mt-0.5 text-lg font-semibold">{value}</p>
    </div>
  )
}

export default function DeviceDeletionImpactModal({
  impact,
  isLoading,
  error,
  elevated = false,
  onConfirm,
  onCancel,
  onRetry,
}: DeviceDeletionImpactModalProps) {
  const zIndex = elevated ? 'z-[80]' : 'z-[70]'
  const hasBlockedDevices = (impact?.devices.blocked ?? 0) > 0

  return (
    <div className={`fixed inset-0 ${zIndex} flex items-center justify-center bg-slate-900/60 p-4`}>
      <div className="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div className="flex items-start gap-3 border-b border-slate-200 p-5">
          <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-700">
            <AlertTriangle className="h-5 w-5" />
          </div>
          <div className="min-w-0 flex-1">
            <h2 className="text-lg font-semibold text-slate-900">Review device deletion impact</h2>
            <p className="mt-1 text-sm text-slate-500">
              Nothing is deleted yet. Review the affected devices, stock, and preserved history before continuing to the reason and PIN steps.
            </p>
          </div>
          <button
            type="button"
            onClick={onCancel}
            className="rounded-lg p-1 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700"
            aria-label="Close deletion impact preview"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="flex-1 space-y-4 overflow-y-auto p-5">
          {isLoading && (
            <div className="flex flex-col items-center justify-center py-16 text-center">
              <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
              <p className="mt-4 text-sm font-medium text-slate-700">Calculating the deletion impact…</p>
              <p className="mt-1 text-xs text-slate-500">Checking device history, stock cost, IoT links, and geofences.</p>
            </div>
          )}

          {!isLoading && error && (
            <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
              <div className="flex items-start gap-2">
                <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" />
                <div>
                  <p className="font-semibold">Unable to load the deletion impact</p>
                  <p className="mt-1">{error}</p>
                </div>
              </div>
              <button type="button" onClick={onRetry} className="mt-3 rounded-lg bg-white px-3 py-2 text-xs font-semibold text-red-700 shadow-sm ring-1 ring-red-200 hover:bg-red-100">
                Try again
              </button>
            </div>
          )}

          {!isLoading && !error && impact && (
            <>
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <ImpactTile label="Devices selected" value={impact.devices.total} tone="slate" />
                <ImpactTile label="Will be removed" value={impact.devices.will_be_deleted} tone="red" />
                <ImpactTile label="Units written off" value={impact.stock_impact.units_leaving_stock} tone="amber" />
                <ImpactTile label="Stock value at cost" value={formatMoney(impact.stock_impact.value_at_cost)} tone="slate" />
              </div>

              {impact.stock_impact.value_at_cost_is_partial && (
                <p className="rounded-lg bg-amber-50 p-3 text-xs text-amber-800">
                  Some selected devices have no available purchase or batch cost, so the stock value shown is partial.
                </p>
              )}

              {hasBlockedDevices && (
                <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                  <div className="flex items-start gap-2">
                    <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" />
                    <div>
                      <p className="font-semibold">{impact.devices.blocked} selected device(s) cannot be deleted</p>
                      <p className="mt-1">Remove the protected units from this selection and run the preview again. Protected units must be handled through their appropriate inventory disposition.</p>
                    </div>
                  </div>
                </div>
              )}

              <div className="grid gap-3 md:grid-cols-2">
                <div className="rounded-lg border border-slate-200 p-3">
                  <p className="flex items-center gap-2 text-sm font-semibold text-slate-700"><Warehouse className="h-4 w-4 text-slate-400" /> Branches and statuses</p>
                  <div className="mt-2 flex flex-wrap gap-1.5">
                    {impact.subject.branches.map((branch) => (
                      <span key={branch.id} className="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-700">
                        {branch.name || `Branch ${branch.id}`}: {branch.count}
                      </span>
                    ))}
                  </div>
                  <div className="mt-3 grid grid-cols-2 gap-1.5 sm:grid-cols-3">
                    {Object.entries(impact.devices.by_status).filter(([, count]) => count > 0).map(([status, count]) => (
                      <span key={status} className={`rounded px-2 py-1 text-xs font-medium ${statusClasses[status] || 'bg-slate-100 text-slate-700'}`}>
                        {status.replace('_', ' ')}: {count}
                      </span>
                    ))}
                  </div>
                </div>

                <div className="rounded-lg border border-slate-200 p-3">
                  <p className="flex items-center gap-2 text-sm font-semibold text-slate-700"><Boxes className="h-4 w-4 text-slate-400" /> Related records</p>
                  <ul className="mt-2 space-y-1 text-xs text-slate-600">
                    <li>{impact.related.iot_modules_unlinked} linked IoT module(s) will be unlinked</li>
                    <li>{impact.related.geofences_removed} geofence(s) will be removed</li>
                    <li>{impact.related.locations_preserved} location record(s) will be preserved</li>
                    <li>{impact.related.inventory_movements_preserved} inventory movement(s) will be preserved</li>
                    <li>{impact.related.ownership_records_preserved} ownership record(s), {impact.related.warranties_preserved} warranty record(s), and {impact.related.theft_reports_preserved} theft report(s) will be preserved</li>
                    {impact.related.open_theft_reports > 0 && <li>{impact.related.open_theft_reports} open theft report(s) are blocking deletion</li>}
                    <li>{impact.related.photos_preserved} device photo(s) will be preserved</li>
                  </ul>
                </div>
              </div>

              <div className="rounded-lg border border-blue-100 bg-blue-50 p-3 text-xs text-blue-800">
                <p className="font-semibold">What will change</p>
                <ul className="mt-1 space-y-1">
                  <li>Devices are soft-deleted from active inventory; brand, model, and category records remain.</li>
                  <li>On-hand units receive a stock write-off movement before removal.</li>
                  <li>Location history, device photos, ownership/sale records, warranties, theft reports, and inventory movements remain for audit.</li>
                </ul>
              </div>

              {impact.warnings.length > 0 && (
                <div className="space-y-2 rounded-lg bg-amber-50 p-3">
                  <p className="flex items-center gap-2 text-sm font-semibold text-amber-900"><AlertTriangle className="h-4 w-4" /> Read before continuing</p>
                  <ul className="list-disc space-y-1 pl-5 text-xs text-amber-800">
                    {impact.warnings.map((warning) => <li key={warning}>{warning}</li>)}
                  </ul>
                </div>
              )}

              <div>
                <p className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-700"><Package className="h-4 w-4 text-slate-400" /> Selected device details</p>
                <div className="max-h-64 overflow-y-auto rounded-lg border border-slate-200">
                  <table className="w-full text-left text-xs">
                    <thead className="sticky top-0 bg-slate-50 text-slate-500">
                      <tr>
                        <th className="px-3 py-2 font-medium">Device</th>
                        <th className="px-3 py-2 font-medium">Status / branch</th>
                        <th className="px-3 py-2 font-medium">Impact</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {impact.detail.map((device) => (
                        <tr key={device.id}>
                          <td className="px-3 py-2">
                            <p className="font-medium text-slate-800">{device.brand || 'Unknown'} {device.model || ''}</p>
                            <p className="text-slate-500">{device.unique_device_code || device.serial_number || `Device #${device.id}`}</p>
                          </td>
                          <td className="px-3 py-2 text-slate-600">
                            <span className={`rounded px-1.5 py-0.5 text-[11px] font-medium ${statusClasses[device.inventory_status || ''] || 'bg-slate-100 text-slate-700'}`}>
                              {device.inventory_status_label || device.inventory_status || 'Unknown'}
                            </span>
                            <p className="mt-1 flex items-center gap-1"><MapPin className="h-3 w-3" />{device.branch || 'No branch'}</p>
                            {device.tracking_status === 'stolen' && <p className="mt-1 text-[11px] font-medium text-red-700">Tracking status: stolen</p>}
                            {device.open_theft_report_count > 0 && <p className="mt-1 text-[11px] text-red-700">{device.open_theft_report_count} open theft report(s)</p>}
                          </td>
                          <td className="px-3 py-2">
                            <div className="flex flex-wrap gap-1">
                              {device.will_be_written_off && <span className="rounded bg-amber-100 px-1.5 py-0.5 text-amber-800">write-off {device.unit_cost ? formatMoney(device.unit_cost) : ''}</span>}
                              {device.iot_module_id && <span className="inline-flex items-center gap-1 rounded bg-violet-100 px-1.5 py-0.5 text-violet-800"><Radio className="h-3 w-3" /> IoT</span>}
                              {device.geofence_count > 0 && <span className="rounded bg-slate-100 px-1.5 py-0.5 text-slate-700">{device.geofence_count} geofence(s)</span>}
                              {device.protected && <span className="rounded bg-red-100 px-1.5 py-0.5 text-red-700">protected</span>}
                            </div>
                            {device.protected && device.protection_reasons.length > 0 && <p className="mt-1 text-[11px] text-red-700">{device.protection_reasons.join(', ')}</p>}
                            {device.requires_confirmation && !device.protected && <p className="mt-1 text-[11px] text-amber-700">Inventory history confirmation required</p>}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </>
          )}
        </div>

        <div className="flex justify-end gap-2 border-t border-slate-200 p-5">
          <button
            type="button"
            onClick={onCancel}
            className="rounded-lg px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-100"
          >
            Cancel
          </button>
          {!isLoading && error && (
            <button type="button" onClick={onRetry} className="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
              Reload preview
            </button>
          )}
          {!isLoading && !error && impact && (
            <button
              type="button"
              onClick={onConfirm}
              disabled={hasBlockedDevices}
              className="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
              <CheckCircle2 className="h-4 w-4" />
              Continue to reason
            </button>
          )}
          {isLoading && <Loader2 className="h-4 w-4 animate-spin text-blue-600" />}
        </div>
      </div>
    </div>
  )
}
