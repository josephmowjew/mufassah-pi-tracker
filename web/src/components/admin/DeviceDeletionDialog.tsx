import { AlertTriangle, Trash2 } from 'lucide-react'
import type { Device } from '@/types'

interface DeviceDeletionDialogProps {
  devices: Device[]
  reason: string
  notes: string
  warning: string | null
  isSubmitting: boolean
  onReasonChange: (value: string) => void
  onNotesChange: (value: string) => void
  onSubmit: () => void
  onCancel: () => void
  onDismissWarning: () => void
}

export default function DeviceDeletionDialog({
  devices,
  reason,
  notes,
  warning,
  isSubmitting,
  onReasonChange,
  onNotesChange,
  onSubmit,
  onCancel,
  onDismissWarning,
}: DeviceDeletionDialogProps) {
  const isBulk = devices.length > 1
  const visibleDevices = devices.slice(0, 6)

  return (
    <>
      <div className="fixed inset-0 z-40 bg-slate-900/50 backdrop-blur-sm" onClick={onCancel} />
      <div className="fixed inset-x-4 top-[10%] z-50 max-h-[85vh] overflow-y-auto rounded-2xl bg-white shadow-xl md:inset-x-auto md:left-1/2 md:w-full md:max-w-lg md:-translate-x-1/2">
        <div className="border-b border-slate-200 p-5">
          <div className="flex items-start gap-3">
            <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-red-100">
              <Trash2 className="h-5 w-5 text-red-600" />
            </div>
            <div>
              <h3 className="text-lg font-semibold text-slate-800">
                Delete {isBulk ? devices.length + ' devices' : 'device'}
              </h3>
              <p className="mt-1 text-sm text-slate-500">
                This action cannot be undone and requires a PIN.
              </p>
            </div>
          </div>
        </div>

        <div className="space-y-4 p-5">
          <div className="rounded-lg border border-red-100 bg-red-50 p-3 text-sm text-red-900">
            <p>
              {isBulk
                ? 'All ' + devices.length + ' selected devices will be permanently removed, including their locations and geofences.'
                : 'This device and its locations and geofences will be permanently removed.'}
            </p>
            {isBulk && (
              <ul className="mt-2 max-h-32 space-y-1 overflow-y-auto text-xs text-red-800">
                {visibleDevices.map((device) => (
                  <li key={device.id} className="truncate">
                    {device.brand} {device.model} <span className="font-mono">({device.serial_number})</span>
                  </li>
                ))}
                {devices.length > visibleDevices.length && (
                  <li>…and {devices.length - visibleDevices.length} more</li>
                )}
              </ul>
            )}
          </div>

          <div>
            <label htmlFor="device-delete-reason" className="mb-1 block text-sm font-medium text-slate-700">
              Reason <span className="text-red-500">*</span>
            </label>
            <input
              id="device-delete-reason"
              value={reason}
              onChange={(event) => onReasonChange(event.target.value)}
              className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm placeholder:text-slate-400 focus:border-blue-500 focus:outline-none"
              placeholder={isBulk ? 'Why should these devices be deleted?' : 'Why should this device be deleted?'}
              maxLength={255}
            />
          </div>

          <div>
            <label htmlFor="device-delete-notes" className="mb-1 block text-sm font-medium text-slate-700">
              Notes
            </label>
            <textarea
              id="device-delete-notes"
              value={notes}
              onChange={(event) => onNotesChange(event.target.value)}
              rows={3}
              maxLength={1000}
              className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm placeholder:text-slate-400 focus:border-blue-500 focus:outline-none"
              placeholder="Optional context for the deletion audit trail"
            />
          </div>

          {warning && (
            <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
              <div className="flex items-start gap-2">
                <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0 text-amber-600" />
                <div>
                  <p className="font-semibold">Confirm deletion of tracked inventory</p>
                  <p className="mt-1">{warning}</p>
                  <p className="mt-2">
                    Proceeding will permanently delete {isBulk ? 'all selected devices' : 'this device'} after PIN verification.
                  </p>
                </div>
              </div>
              <button type="button" onClick={onDismissWarning} className="mt-3 text-xs font-semibold text-amber-800 underline">
                Go back and review
              </button>
            </div>
          )}

          <div className="flex gap-2 pt-1">
            <button type="button" onClick={onCancel} className="flex-1 rounded-lg bg-slate-100 px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-200">
              Cancel
            </button>
            <button
              type="button"
              onClick={onSubmit}
              disabled={!reason.trim() || isSubmitting}
              className="flex-1 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {isSubmitting ? 'Sending PIN...' : warning ? 'Proceed & send PIN' : 'Send PIN'}
            </button>
          </div>
        </div>
      </div>
    </>
  )
}
