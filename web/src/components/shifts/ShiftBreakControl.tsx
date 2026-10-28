import { useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { Coffee, Loader2, Play, Square } from 'lucide-react'
import { differenceInSeconds } from 'date-fns'
import { useEndBreak, useStartBreak } from '@/hooks/useShifts'
import type { Shift, ShiftBreakType } from '@/types'

const BREAK_OPTIONS: Array<{ value: ShiftBreakType; label: string }> = [
  { value: 'lunch', label: 'Lunch' },
  { value: 'tea', label: 'Tea break' },
  { value: 'comfort', label: 'Comfort' },
  { value: 'prayer', label: 'Prayer' },
  { value: 'other', label: 'Other' },
]

function pad(value: number): string {
  return value.toString().padStart(2, '0')
}

/** `mm:ss` under an hour, `h:mm:ss` above it. */
function formatClock(seconds: number): string {
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  const secs = seconds % 60

  return hours > 0
    ? `${hours}:${pad(minutes)}:${pad(secs)}`
    : `${pad(minutes)}:${pad(secs)}`
}

/** Compact `7h 15m` for totals, where seconds are noise. */
function formatMinutes(minutes: number | null | undefined): string {
  if (minutes === null || minutes === undefined) return '—'

  // Truncate so the live figure agrees with the shift's duration, which is
  // formatted the same way.
  const total = Math.floor(minutes)
  const hours = Math.floor(total / 60)
  const mins = total % 60

  return hours > 0 ? `${hours}h ${mins}m` : `${mins}m`
}

interface ShiftBreakControlProps {
  shift: Shift
}

/**
 * Break controls for the running shift.
 *
 * Taking a break never ends the shift — the parent `status` stays `active` and
 * the break is recorded as a separate row. The timer here is driven by the open
 * break's `started_at`, so it stays correct across a page reload or a slow
 * refetch.
 */
export function ShiftBreakControl({ shift }: ShiftBreakControlProps) {
  const startBreak = useStartBreak()
  const endBreak = useEndBreak()

  const [showModal, setShowModal] = useState(false)
  const [type, setType] = useState<ShiftBreakType>('lunch')
  const [notes, setNotes] = useState('')
  const [clock, setClock] = useState(() => new Date())

  const openBreak = shift.breaks?.find((entry) => entry.is_open) ?? null

  // Ticks only while a break is running. The elapsed value is derived during
  // render instead of being written into state from the effect body, which
  // would cause a cascading render on every mount.
  useEffect(() => {
    if (!openBreak) return

    const interval = setInterval(() => setClock(new Date()), 1000)

    return () => clearInterval(interval)
  }, [openBreak])

  const elapsedSeconds = openBreak
    ? Math.max(0, differenceInSeconds(clock, new Date(openBreak.started_at)))
    : 0

  const handleStart = (event: React.FormEvent) => {
    event.preventDefault()
    startBreak.mutate(
      { type, notes: notes || undefined },
      {
        onSuccess: () => {
          setShowModal(false)
          setNotes('')
        },
      }
    )
  }

  return (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <p className="text-xs text-teal-100">
        Worked {formatMinutes(shift.worked_minutes)} · {shift.break_count}{' '}
        {shift.break_count === 1 ? 'break' : 'breaks'} ({formatMinutes(shift.break_minutes)})
      </p>

      {openBreak ? (
        <div className="flex items-center gap-3 rounded-lg bg-amber-400/20 px-3 py-1.5">
          <Coffee className="h-4 w-4 text-amber-100" />
          <span className="text-sm font-medium text-amber-50">
            On break · {openBreak.type_label}
          </span>
          <span className="font-mono text-lg font-bold text-white">
            {formatClock(elapsedSeconds)}
          </span>
          <button
            onClick={() => endBreak.mutate({})}
            disabled={endBreak.isPending}
            className="flex items-center gap-1.5 rounded-lg bg-white px-3 py-1 text-sm font-medium text-amber-700 transition-colors hover:bg-amber-50 disabled:opacity-50"
          >
            {endBreak.isPending ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <Square className="h-3.5 w-3.5" />
            )}
            End break
          </button>
        </div>
      ) : (
        <button
          onClick={() => setShowModal(true)}
          className="flex items-center gap-2 rounded-lg bg-white/20 px-3 py-1.5 text-sm font-medium text-white transition-colors hover:bg-white/30"
        >
          <Coffee className="h-4 w-4" />
          Take a break
        </button>
      )}

      {showModal &&
        createPortal(
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="w-full max-w-md rounded-xl bg-white p-6">
            <div className="mb-4 flex items-center gap-3">
              <div className="rounded-lg bg-amber-100 p-2">
                <Coffee className="h-5 w-5 text-amber-600" />
              </div>
              <div>
                <h2 className="text-xl font-bold text-slate-900">Take a break</h2>
                <p className="text-sm text-slate-500">
                  Your shift stays running. Time away is recorded separately.
                </p>
              </div>
            </div>

            <form onSubmit={handleStart} className="space-y-4">
              <div>
                <label className="mb-1 block text-sm font-medium text-slate-700">Type</label>
                <select
                  value={type}
                  onChange={(event) => setType(event.target.value as ShiftBreakType)}
                  className="w-full rounded-lg border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-teal-500"
                >
                  {BREAK_OPTIONS.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="mb-1 block text-sm font-medium text-slate-700">
                  Notes (optional)
                </label>
                <textarea
                  value={notes}
                  onChange={(event) => setNotes(event.target.value)}
                  placeholder="Who is covering the counter?"
                  rows={2}
                  className="w-full rounded-lg border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-teal-500"
                />
              </div>

              {startBreak.isError && (
                <p className="text-sm text-red-600">
                  {(startBreak.error as { response?: { data?: { message?: string } } })?.response
                    ?.data?.message ?? 'Could not start the break.'}
                </p>
              )}

              <div className="flex gap-3">
                <button
                  type="button"
                  onClick={() => setShowModal(false)}
                  className="flex-1 rounded-lg border border-slate-200 px-4 py-2 transition-colors hover:bg-slate-50"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={startBreak.isPending}
                  className="flex flex-1 items-center justify-center gap-2 rounded-lg bg-amber-500 px-4 py-2 text-white transition-colors hover:bg-amber-600 disabled:opacity-50"
                >
                  <Play className="h-4 w-4" />
                  {startBreak.isPending ? 'Starting…' : 'Start break'}
                </button>
              </div>
            </form>
          </div>
        </div>,
          document.body
        )}
    </div>
  )
}
