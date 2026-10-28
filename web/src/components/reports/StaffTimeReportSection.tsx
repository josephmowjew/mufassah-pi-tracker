import { useQuery } from '@tanstack/react-query'
import { Coffee, Clock, Users } from 'lucide-react'
import apiClient from '@/api/client'

interface StaffTimeSummary {
  total_shifts: number
  staff_count: number
  total_break_count: number
  total_scheduled_minutes: number
  total_break_minutes: number
  total_worked_minutes: number
  break_percentage: number
  average_shift_minutes: number
  average_break_minutes: number
  longest_break_minutes: number
}

interface StaffTimeRow {
  user_id: number
  staff_name: string
  branch_name: string
  shifts: number
  break_count: number
  scheduled_minutes: number
  break_minutes: number
  worked_minutes: number
  average_shift_minutes: number
  average_break_minutes: number
  break_percentage: number
}

interface StaffTimeData {
  summary: StaffTimeSummary
  by_staff: StaffTimeRow[]
  by_day: Array<{
    date: string
    shifts: number
    staff: number
    scheduled_minutes: number
    break_minutes: number
    worked_minutes: number
  }>
  breaks: Array<{
    shift_id: number
    date: string
    staff_name: string
    type: string
    minutes: number
  }>
}

/** `7h 15m` — minutes are the API's unit, not a display format. */
function formatMinutes(minutes: number | null | undefined): string {
  if (minutes === null || minutes === undefined) return '—'

  const total = Math.round(minutes)
  const hours = Math.floor(total / 60)
  const mins = total % 60

  return hours > 0 ? `${hours}h ${mins}m` : `${mins}m`
}

/**
 * Staff time section.
 *
 * Fetches on the same filter string the other sections use, so switching the
 * date range or branch keeps every panel in step. Completed shifts only — the
 * server excludes running and cancelled shifts.
 */
export function StaffTimeReportSection({ query }: { query: string }) {
  const { data, isLoading } = useQuery({
    queryKey: ['admin-reports-staff-time', query],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: StaffTimeData }>(
        `/admin/reports/staff-time?${query}`
      )
      return response.data.data
    },
  })

  const summary = data?.summary

  return (
    <div className="bg-white rounded-xl border border-slate-200/60 shadow-sm">
      <div className="p-5 border-b border-slate-200/60">
        <h2
          className="text-base font-semibold text-slate-800"
          style={{ fontFamily: 'var(--font-heading)' }}
        >
          Staff Time
        </h2>
        <p className="text-sm text-slate-500 mt-1">
          Scheduled hours, break time and actual working time per employee. Completed shifts only.
        </p>
      </div>

      <div className="p-5 space-y-5">
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          <div className="p-4 bg-slate-50 rounded-xl">
            <div className="flex items-center gap-2 mb-1">
              <Clock className="h-4 w-4 text-slate-500" />
              <p className="text-xs text-slate-500 font-medium">Shifts</p>
            </div>
            <p className="text-2xl font-bold text-slate-800">{summary?.total_shifts ?? 0}</p>
          </div>
          <div className="p-4 bg-blue-50 rounded-xl">
            <div className="flex items-center gap-2 mb-1">
              <Users className="h-4 w-4 text-blue-500" />
              <p className="text-xs text-blue-600 font-medium">Staff</p>
            </div>
            <p className="text-2xl font-bold text-blue-700">{summary?.staff_count ?? 0}</p>
          </div>
          <div className="p-4 bg-amber-50 rounded-xl">
            <div className="flex items-center gap-2 mb-1">
              <Coffee className="h-4 w-4 text-amber-500" />
              <p className="text-xs text-amber-600 font-medium">Break time</p>
            </div>
            <p className="text-lg font-bold text-amber-700">
              {formatMinutes(summary?.total_break_minutes)}
            </p>
            <p className="text-xs text-amber-600 mt-0.5">
              {summary?.total_break_count ?? 0} breaks · {summary?.break_percentage ?? 0}% of time
            </p>
          </div>
          <div className="p-4 bg-teal-50 rounded-xl">
            <div className="flex items-center gap-2 mb-1">
              <Clock className="h-4 w-4 text-teal-500" />
              <p className="text-xs text-teal-600 font-medium">Worked time</p>
            </div>
            <p className="text-lg font-bold text-teal-700">
              {formatMinutes(summary?.total_worked_minutes)}
            </p>
            <p className="text-xs text-teal-600 mt-0.5">
              avg shift {formatMinutes(summary?.average_shift_minutes)}
            </p>
          </div>
        </div>

        <div>
          <h3 className="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">
            By employee
          </h3>

          {isLoading ? (
            <p className="text-sm text-slate-400">Loading…</p>
          ) : data && data.by_staff.length > 0 ? (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-slate-200">
                    <th className="px-3 py-2 text-left text-xs font-medium text-slate-500 uppercase">Staff</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-slate-500 uppercase">Branch</th>
                    <th className="px-3 py-2 text-right text-xs font-medium text-slate-500 uppercase">Shifts</th>
                    <th className="px-3 py-2 text-right text-xs font-medium text-slate-500 uppercase">Breaks</th>
                    <th className="px-3 py-2 text-right text-xs font-medium text-slate-500 uppercase">Scheduled</th>
                    <th className="px-3 py-2 text-right text-xs font-medium text-slate-500 uppercase">Break</th>
                    <th className="px-3 py-2 text-right text-xs font-medium text-slate-500 uppercase">Worked</th>
                    <th className="px-3 py-2 text-right text-xs font-medium text-slate-500 uppercase">Break %</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {data.by_staff.map((row) => (
                    <tr key={row.user_id} className="hover:bg-slate-50">
                      <td className="px-3 py-2 text-sm font-medium text-slate-800">{row.staff_name}</td>
                      <td className="px-3 py-2 text-sm text-slate-600">{row.branch_name}</td>
                      <td className="px-3 py-2 text-sm text-slate-700 text-right">{row.shifts}</td>
                      <td className="px-3 py-2 text-sm text-slate-700 text-right">{row.break_count}</td>
                      <td className="px-3 py-2 text-sm text-slate-700 text-right">
                        {formatMinutes(row.scheduled_minutes)}
                      </td>
                      <td className="px-3 py-2 text-sm text-amber-700 text-right">
                        {formatMinutes(row.break_minutes)}
                      </td>
                      <td className="px-3 py-2 text-sm font-medium text-slate-900 text-right">
                        {formatMinutes(row.worked_minutes)}
                      </td>
                      <td className="px-3 py-2 text-sm text-slate-600 text-right">
                        {row.break_percentage}%
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="text-sm text-slate-400">
              No completed shifts in this period.
            </p>
          )}
        </div>
      </div>
    </div>
  )
}
