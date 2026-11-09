import { LaybyShiftTotals } from '@/components/shifts/LaybyShiftTotals'
import { useParams, Link } from 'react-router-dom'
import { ArrowLeft, Clock, TrendingUp, ShoppingCart, User, Building2, DollarSign } from 'lucide-react'
import { useShift } from '@/hooks/useShifts'
import { formatMalawiDate, formatMalawiTime } from '@/lib/datetime'

export default function ShiftDetailPage() {
  const { id } = useParams<{ id: string }>()
  const { data: shift, isLoading, error } = useShift(parseInt(id || '0'))

  const formatDateTime = (dateString: string) => {
    return `${formatMalawiDate(dateString, 'en-US', { month: 'short', day: '2-digit', year: 'numeric' })} • ${formatMalawiTime(dateString, 'en-GB')}`
  }

  const formatTime = (dateString: string) => {
    return formatMalawiTime(dateString, 'en-GB')
  }

  const formatDuration = (minutes: number | null) => {
    if (!minutes) return '—'
    const hours = Math.floor(minutes / 60)
    const mins = Math.floor(minutes % 60)
    const secs = Math.floor((minutes % 1) * 60)
    if (hours > 0) {
      return `${hours}h ${mins}m ${secs}s`
    }
    return `${mins}m ${secs}s`
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-16">
        <div className="animate-spin h-8 w-8 border-3 border-teal-600 border-t-transparent rounded-full" />
      </div>
    )
  }

  if (error || !shift) {
    return (
      <div className="flex flex-col items-center justify-center py-16">
        <p className="text-red-600 mb-4">Failed to load shift details</p>
        <Link to="/customer-service/shifts" className="text-teal-600 hover:underline">
          Back to Shift History
        </Link>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Link
          to="/customer-service/shifts"
          className="p-2 hover:bg-slate-100 rounded-lg transition-colors"
        >
          <ArrowLeft className="h-5 w-5" />
        </Link>
        <div className="flex-1">
          <h1 className="text-2xl font-bold text-slate-900">Shift Details</h1>
          <p className="text-sm text-slate-500">View shift information and sales</p>
        </div>
      </div>

      {/* Shift Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white rounded-xl border border-slate-200 p-6">
          <div className="flex items-center justify-between mb-4">
            <ShoppingCart className="h-8 w-8 text-blue-600" />
          </div>
          <p className="text-sm text-slate-600 mb-1">Total Sales</p>
          <p className="text-2xl font-bold text-slate-900">{shift.sales_count || 0}</p>
        </div>
        <div className="bg-white rounded-xl border border-slate-200 p-6">
          <div className="flex items-center justify-between mb-4">
            <TrendingUp className="h-8 w-8 text-emerald-600" />
          </div>
          <p className="text-sm text-slate-600 mb-1">Total Revenue</p>
          <p className="text-2xl font-bold text-slate-900">MWK {shift.revenue.toLocaleString()}</p>
        </div>
        <div className="bg-white rounded-xl border border-slate-200 p-6">
          <div className="flex items-center justify-between mb-4">
            <DollarSign className="h-8 w-8 text-amber-600" />
          </div>
          <p className="text-sm text-slate-600 mb-1">Cash Sales</p>
          <p className="text-2xl font-bold text-slate-900">MWK {shift.cash_sales.toLocaleString()}</p>
        </div>
        <div className="bg-white rounded-xl border border-slate-200 p-6">
          <div className="flex items-center justify-between mb-4">
            <Clock className="h-8 w-8 text-purple-600" />
          </div>
          <p className="text-sm text-slate-600 mb-1">Duration</p>
          <p className="text-2xl font-bold text-slate-900">{formatDuration(shift.duration_minutes)}</p>
        </div>
      </div>

      <LaybyShiftTotals totals={shift.layby_tender_totals_minor} />

      {/* Shift Details */}
      <div className="bg-white rounded-xl border border-slate-200 p-6">
        <h2 className="text-lg font-semibold text-slate-900 mb-4">Shift Information</h2>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <p className="text-sm text-slate-500 mb-1">Status</p>
            <p className="font-medium text-slate-900 capitalize">{shift.status}</p>
          </div>
          <div>
            <p className="text-sm text-slate-500 mb-1">Started At</p>
            <p className="font-medium text-slate-900">{formatDateTime(shift.started_at)}</p>
          </div>
          {shift.ended_at && (
            <div>
              <p className="text-sm text-slate-500 mb-1">Ended At</p>
              <p className="font-medium text-slate-900">{formatDateTime(shift.ended_at)}</p>
            </div>
          )}
          {shift.branch && (
            <div>
              <p className="text-sm text-slate-500 mb-1">Branch</p>
              <div className="flex items-center gap-2">
                <Building2 className="h-4 w-4 text-slate-400" />
                <p className="font-medium text-slate-900">{shift.branch.name}</p>
              </div>
            </div>
          )}
          {shift.opening_cash > 0 && (
            <div>
              <p className="text-sm text-slate-500 mb-1">Opening Cash</p>
              <p className="font-medium text-slate-900">MWK {shift.opening_cash.toLocaleString()}</p>
            </div>
          )}
          {shift.closing_cash && (
            <div>
              <p className="text-sm text-slate-500 mb-1">Closing Cash</p>
              <p className="font-medium text-slate-900">MWK {shift.closing_cash.toLocaleString()}</p>
            </div>
          )}
          {shift.notes && (
            <div className="md:col-span-2">
              <p className="text-sm text-slate-500 mb-1">Notes</p>
              <p className="font-medium text-slate-900">{shift.notes}</p>
            </div>
          )}
        </div>
      </div>

      {/* Time on the clock */}
      <div className="bg-white rounded-xl border border-slate-200 p-6">
        <h2 className="text-lg font-semibold text-slate-900 mb-4">Time on the clock</h2>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <p className="text-sm text-slate-500 mb-1">Scheduled</p>
            <p className="text-xl font-bold text-slate-900">{formatDuration(shift.duration_minutes)}</p>
          </div>
          <div>
            <p className="text-sm text-slate-500 mb-1">
              Breaks ({shift.break_count ?? 0})
            </p>
            <p className="text-xl font-bold text-amber-600">
              {shift.break_minutes > 0 ? formatDuration(shift.break_minutes) : '0m'}
            </p>
          </div>
          <div>
            <p className="text-sm text-slate-500 mb-1">Worked</p>
            <p className="text-xl font-bold text-teal-700">{formatDuration(shift.worked_minutes)}</p>
          </div>
        </div>

        {shift.breaks && shift.breaks.length > 0 && (
          <div className="mt-6 overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="bg-slate-50 border-b border-slate-200">
                  <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                    Break
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                    Started
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                    Ended
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                    Duration
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                    Notes
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {shift.breaks.map((entry) => (
                  <tr key={entry.id} className="hover:bg-slate-50">
                    <td className="px-4 py-3 text-sm font-medium text-slate-900">
                      {entry.type_label}
                    </td>
                    <td className="px-4 py-3 text-sm text-slate-700">
                      {formatTime(entry.started_at)}
                    </td>
                    <td className="px-4 py-3 text-sm text-slate-700">
                      {entry.ended_at ? formatTime(entry.ended_at) : (
                        <span className="text-amber-600">In progress</span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-sm text-slate-700">
                      {entry.duration_minutes === null
                        ? '—'
                        : formatDuration(entry.duration_minutes)}
                    </td>
                    <td className="px-4 py-3 text-sm text-slate-500">{entry.notes || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Sales */}
      {shift.sales && shift.sales.length > 0 && (
        <div className="bg-white rounded-xl border border-slate-200 p-6">
          <h2 className="text-lg font-semibold text-slate-900 mb-4">Sales During This Shift</h2>
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="bg-slate-50 border-b border-slate-200">
                  <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                    Sale Number
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                    Customer
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                    Time
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                    Amount
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                    Status
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {shift.sales.map((sale) => (
                  <tr key={sale.id} className="hover:bg-slate-50">
                    <td className="px-4 py-3 text-sm font-medium text-slate-900">
                      {sale.sale_number}
                    </td>
                    <td className="px-4 py-3">
                      {sale.customer ? (
                        <div className="flex items-center gap-2">
                          <User className="h-4 w-4 text-slate-400" />
                          <span className="text-sm text-slate-700">{sale.customer.name}</span>
                        </div>
                      ) : (
                        <span className="text-sm text-slate-400">—</span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-sm text-slate-700">
                      {formatTime(sale.sale_date)}
                    </td>
                    <td className="px-4 py-3 text-sm font-medium text-slate-900">
                      MWK {Number(sale.total_amount).toLocaleString()}
                    </td>
                    <td className="px-4 py-3">
                      <span className={`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${
                        sale.status.value === 'completed' ? 'bg-emerald-50 text-emerald-700' :
                        sale.status.value === 'draft' ? 'bg-amber-50 text-amber-700' :
                        'bg-slate-50 text-slate-700'
                      }`}>
                        {sale.status.value}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}
