import { formatMalawiDateTime } from '@/lib/datetime'

interface ShiftTimesProps {
  startedAt: string
  endedAt?: string | null
  status: string
}

const formatShiftTime = (value: string) => formatMalawiDateTime(value, 'en-GB', {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
  hour: 'numeric',
  minute: '2-digit',
  hour12: true,
})

/** Keep each endpoint explicit, including its date for overnight shifts. */
export function ShiftTimes({ startedAt, endedAt, status }: ShiftTimesProps) {
  return (
    <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 whitespace-nowrap text-sm">
      <dt className="text-slate-500">Started</dt>
      <dd className="font-medium text-slate-900">
        <time dateTime={startedAt}>{formatShiftTime(startedAt)}</time>
      </dd>
      <dt className="text-slate-500">Ended</dt>
      <dd className="text-slate-700">
        {endedAt
          ? <time dateTime={endedAt}>{formatShiftTime(endedAt)}</time>
          : status === 'active' ? 'Still working' : 'Not recorded'}
      </dd>
    </dl>
  )
}
