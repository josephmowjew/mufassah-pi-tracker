import { useEffect, useState } from 'react'
import { useCurrentShift } from '@/hooks/useShifts'
import { formatMalawiTime } from '@/lib/datetime'
import { ShiftEndModal } from './ShiftEndModal'

const WARNING_LEAD_MS = 30 * 60 * 1000

function ClosingWarning({ endsAt, refreshShift }: { endsAt: string; refreshShift: () => void }) {
  const deadline = Date.parse(endsAt)
  const warningAt = deadline - WARNING_LEAD_MS
  const [clock, setClock] = useState(Date.now)
  const [dismissed, setDismissed] = useState(false)
  const [showEndModal, setShowEndModal] = useState(false)

  useEffect(() => {
    const warning = window.setTimeout(() => setClock(Date.now()), Math.max(0, warningAt - Date.now()))
    const closing = window.setTimeout(() => {
      setClock(Date.now())
      refreshShift()
    }, Math.max(0, deadline - Date.now()))

    return () => {
      window.clearTimeout(warning)
      window.clearTimeout(closing)
    }
  }, [deadline, refreshShift, warningAt])

  if (clock < warningAt || clock >= deadline || dismissed) return null

  return (
    <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-950">
      <p role="status" className="text-sm">
        Your shift will end automatically at <strong>{formatMalawiTime(endsAt, 'en-US', { hour: 'numeric', minute: '2-digit' })} Malawi time</strong>.
      </p>
      <div className="flex flex-wrap gap-2 text-sm font-medium">
        <button type="button" onClick={() => setDismissed(true)} className="rounded-lg border border-amber-300 px-3 py-2 hover:bg-amber-100">
          Continue working
        </button>
        <button type="button" onClick={() => setShowEndModal(true)} className="rounded-lg bg-amber-800 px-3 py-2 text-white hover:bg-amber-900">
          End shift
        </button>
      </div>
      {showEndModal && <ShiftEndModal onClose={() => setShowEndModal(false)} />}
    </div>
  )
}

export function ShiftClosingNotice() {
  const { data: shift, refetch } = useCurrentShift()
  if (!shift?.automatic_end_at) return null

  return <ClosingWarning key={shift.id} endsAt={shift.automatic_end_at} refreshShift={refetch} />
}
