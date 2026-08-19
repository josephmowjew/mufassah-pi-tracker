import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Search, Clock, CheckCircle, XCircle, Loader2, MapPin, ShoppingCart, DollarSign, Eye } from 'lucide-react'
import { Authorize } from '@/components/auth/Authorize'
import { PERMISSIONS } from '@/stores/auth-store'
import apiClient from '@/api/client'
import type { Shift, Branch, User as UserType } from '@/types'
import { useAuthStore } from '@/stores/auth-store'

interface PaginatedResponse {
  data: Shift[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

// Helper functions
const formatDuration = (startedAt: string, endedAt: string | null | undefined) => {
  if (!endedAt) return '-'

  const start = new Date(startedAt)
  const end = new Date(endedAt)
  const diffMs = end.getTime() - start.getTime()

  // If negative, shift has ended before started (data error)
  if (diffMs < 0) {
    return 'Invalid'
  }

  const totalMinutes = Math.floor(diffMs / 60000)
  const days = Math.floor(totalMinutes / (24 * 60))
  const hours = Math.floor((totalMinutes % (24 * 60)) / 60)
  const minutes = totalMinutes % 60

  const parts = []
  if (days > 0) {
    parts.push(`${days}d`)
  }
  if (hours > 0) {
    parts.push(`${hours}h`)
  }
  if (minutes > 0 || parts.length === 0) {
    parts.push(`${minutes}m`)
  }

  return parts.join(' ')
}

const formatCurrency = (amount: number | string) => {
  return `MWK ${Number(amount).toLocaleString()}`
}

export default function AdminShiftsPage() {
  const isSuperAdmin = useAuthStore((state) => state.isSuperAdmin())
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [statusFilter, setStatusFilter] = useState('')
  const [branchFilter, setBranchFilter] = useState('')
  const [userFilter, setUserFilter] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [selectedShift, setSelectedShift] = useState<Shift | null>(null)
  const [showDetailModal, setShowDetailModal] = useState(false)

  // Fetch shifts
  const { data: shiftsData, isLoading } = useQuery({
    queryKey: ['admin-shifts', search, page, statusFilter, branchFilter, userFilter, dateFrom, dateTo],
    queryFn: async () => {
      const params = new URLSearchParams()
      params.append('page', page.toString())
      params.append('per_page', '20')
      if (search) params.append('search', search)
      if (statusFilter) params.append('status', statusFilter)
      if (branchFilter) params.append('branch_id', branchFilter)
      if (userFilter) params.append('user_id', userFilter)
      if (dateFrom) params.append('date_from', dateFrom)
      if (dateTo) params.append('date_to', dateTo)

      const response = await apiClient.get<{ success: boolean; data: Shift[]; meta: PaginatedResponse['meta'] }>(
        `/admin/shifts?${params.toString()}`
      )
      return response.data
    },
  })

  // Fetch branches for filter
  const { data: branches } = useQuery({
    queryKey: ['branches'],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: Branch[] }>('/admin/branches')
      return response.data.data
    },
  })

  // Fetch customer service staff for filter
  const { data: staff } = useQuery({
    queryKey: ['customer-service-staff'],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: UserType[] }>('/admin/users?role=customer_service&per_page=100')
      return response.data.data
    },
  })

  // Fetch shift statistics
  const { data: stats } = useQuery({
    queryKey: ['admin-shifts-stats', dateFrom, dateTo, branchFilter],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (dateFrom) params.append('date_from', dateFrom)
      if (dateTo) params.append('date_to', dateTo)
      if (branchFilter) params.append('branch_id', branchFilter)

      const response = await apiClient.get<{ success: boolean; data: any }>(
        `/admin/shifts/stats?${params.toString()}`
      )
      return response.data.data
    },
  })

  const shifts = shiftsData?.data || []
  const meta = shiftsData?.meta

  const handleViewShift = (shift: Shift) => {
    setSelectedShift(shift)
    setShowDetailModal(true)
  }

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'active':
        return <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700">
          <Clock className="h-3 w-3" />
          Active
        </span>
      case 'completed':
        return <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
          <CheckCircle className="h-3 w-3" />
          Completed
        </span>
      case 'cancelled':
        return <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-red-100 text-red-700">
          <XCircle className="h-3 w-3" />
          Cancelled
        </span>
      default:
        return <span className="px-2.5 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-700">
          {status}
        </span>
    }
  }

  const formatDate = (dateString: string) => {
    const date = new Date(dateString)
    return date.toLocaleDateString('en-MW', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
  }

  return (
    <Authorize permission={PERMISSIONS.ACCESS_ADMIN_PANEL} fallback={<div className="p-6">Access denied</div>}>
      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold text-slate-900">Shift Management</h1>
            <p className="text-slate-500 text-sm mt-1">View and manage staff shifts across all branches</p>
          </div>
        </div>

        {/* Statistics Cards */}
        {stats && (
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div className="bg-white rounded-xl border border-slate-200 p-4">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-lg bg-slate-100 flex items-center justify-center">
                  <Clock className="h-5 w-5 text-slate-600" />
                </div>
                <div>
                  <p className="text-2xl font-bold text-slate-900">{stats.total_shifts}</p>
                  <p className="text-xs text-slate-500">Total Shifts</p>
                </div>
              </div>
            </div>
            <div className="bg-white rounded-xl border border-slate-200 p-4">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-lg bg-emerald-100 flex items-center justify-center">
                  <CheckCircle className="h-5 w-5 text-emerald-600" />
                </div>
                <div>
                  <p className="text-2xl font-bold text-emerald-600">{stats.completed_shifts}</p>
                  <p className="text-xs text-slate-500">Completed</p>
                </div>
              </div>
            </div>
            <div className="bg-white rounded-xl border border-slate-200 p-4">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-lg bg-blue-100 flex items-center justify-center">
                  <DollarSign className="h-5 w-5 text-blue-600" />
                </div>
                <div>
                  <p className="text-2xl font-bold text-blue-600">MWK {(stats.total_revenue || 0).toLocaleString()}</p>
                  <p className="text-xs text-slate-500">Total Revenue</p>
                </div>
              </div>
            </div>
            <div className="bg-white rounded-xl border border-slate-200 p-4">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-lg bg-purple-100 flex items-center justify-center">
                  <ShoppingCart className="h-5 w-5 text-purple-600" />
                </div>
                <div>
                  <p className="text-2xl font-bold text-purple-600">{stats.total_sales || 0}</p>
                  <p className="text-xs text-slate-500">Total Sales</p>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Filters */}
        <div className="bg-white rounded-xl border border-slate-200 p-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-4">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
              <input
                type="text"
                placeholder="Search staff..."
                value={search}
                onChange={(e) => { setSearch(e.target.value); setPage(1) }}
                className="w-full pl-10 pr-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <select
              value={statusFilter}
              onChange={(e) => { setStatusFilter(e.target.value); setPage(1) }}
              className="px-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">All Status</option>
              <option value="active">Active</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
            </select>
            {isSuperAdmin && <select
              value={branchFilter}
              onChange={(e) => { setBranchFilter(e.target.value); setPage(1) }}
              className="px-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">All Branches</option>
              {branches?.map((branch) => (
                <option key={branch.id} value={branch.id.toString()}>
                  {branch.name}
                </option>
              ))}
            </select>}
            <select
              value={userFilter}
              onChange={(e) => { setUserFilter(e.target.value); setPage(1) }}
              className="px-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">All Staff</option>
              {staff?.map((user) => (
                <option key={user.id} value={user.id.toString()}>
                  {user.name}
                </option>
              ))}
            </select>
            <input
              type="date"
              value={dateFrom}
              onChange={(e) => setDateFrom(e.target.value)}
              className="px-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              placeholder="From"
            />
            <input
              type="date"
              value={dateTo}
              onChange={(e) => setDateTo(e.target.value)}
              className="px-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              placeholder="To"
            />
          </div>
        </div>

        {/* Shifts Table */}
        <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
          {isLoading ? (
            <div className="flex items-center justify-center py-16">
              <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
            </div>
          ) : shifts.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-16">
              <Clock className="h-12 w-12 text-slate-300 mb-4" />
              <p className="text-slate-500">No shifts found</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead className="bg-slate-50 border-b border-slate-200">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Staff</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Branch</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Date & Time</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Duration</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Status</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Revenue</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Sales</th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200">
                  {shifts.map((shift) => (
                    <tr key={shift.id} className="hover:bg-slate-50 transition-colors">
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-3">
                          <div className="h-8 w-8 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white text-sm font-medium">
                            {shift.user?.name?.charAt(0).toUpperCase()}
                          </div>
                          <div>
                            <p className="font-medium text-slate-900">{shift.user?.name}</p>
                            <p className="text-xs text-slate-500">{shift.user?.email}</p>
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-2">
                          <MapPin className="h-4 w-4 text-slate-400" />
                          <span className="text-slate-700">{shift.branch?.name}</span>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="text-sm">
                          <p className="text-slate-900 font-medium">{formatDate(shift.started_at)}</p>
                          {shift.ended_at && (
                            <p className="text-slate-500">to {formatDate(shift.ended_at)}</p>
                          )}
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-slate-700">{formatDuration(shift.started_at, shift.ended_at)}</span>
                      </td>
                      <td className="px-6 py-4">
                        {getStatusBadge(shift.status)}
                      </td>
                      <td className="px-6 py-4">
                        <span className="font-medium text-blue-600">MWK {shift.revenue.toLocaleString()}</span>
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-slate-700">{shift.sales_count || 0}</span>
                      </td>
                      <td className="px-6 py-4 text-right">
                        <button
                          onClick={() => handleViewShift(shift)}
                          className="inline-flex items-center gap-1 text-blue-600 hover:text-blue-800 text-sm font-medium"
                        >
                          <Eye className="h-4 w-4" />
                          View
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {/* Pagination */}
          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between px-6 py-4 border-t border-slate-200">
              <p className="text-sm text-slate-500">
                Showing {((meta.current_page - 1) * meta.per_page + 1)} to{' '}
                {Math.min(meta.current_page * meta.per_page, meta.total)} of {meta.total} shifts
              </p>
              <div className="flex gap-2">
                <button
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={page === 1}
                  className="px-3 py-1.5 text-sm border border-slate-300 rounded-lg hover:bg-white disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                  Previous
                </button>
                <span className="px-3 py-1.5 text-sm text-slate-600">
                  Page {meta.current_page} of {meta.last_page}
                </span>
                <button
                  onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                  disabled={page === meta.last_page}
                  className="px-3 py-1.5 text-sm border border-slate-300 rounded-lg hover:bg-white disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                  Next
                </button>
              </div>
            </div>
          )}
        </div>

        {/* Shift Detail Modal */}
        {showDetailModal && selectedShift && (
          <ShiftDetailModal
            shift={selectedShift}
            onClose={() => {
              setShowDetailModal(false)
              setSelectedShift(null)
            }}
          />
        )}
      </div>
    </Authorize>
  )
}

interface ShiftDetailModalProps {
  shift: Shift
  onClose: () => void
}

function ShiftDetailModal({ shift, onClose }: ShiftDetailModalProps) {
  const { data: shiftDetail, isLoading } = useQuery({
    queryKey: ['admin-shift-detail', shift.id],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: Shift }>(`/admin/shifts/${shift.id}`)
      return response.data.data
    },
    enabled: !!shift,
  })

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-xl max-w-4xl w-full max-h-[90vh] overflow-hidden flex flex-col">
        {/* Header */}
        <div className="p-6 border-b border-slate-200">
          <div className="flex items-start justify-between">
            <div>
              <h2 className="text-lg font-semibold text-slate-900">Shift Details</h2>
              <p className="text-sm text-slate-500 mt-1">
                {shiftDetail?.user?.name} - {shiftDetail?.branch?.name}
              </p>
            </div>
            <button
              onClick={onClose}
              className="text-slate-400 hover:text-slate-600"
            >
              <XCircle className="h-5 w-5" />
            </button>
          </div>
        </div>

        {/* Content */}
        <div className="p-6 overflow-y-auto flex-1">
          {isLoading ? (
            <div className="flex items-center justify-center py-16">
              <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
            </div>
          ) : shiftDetail ? (
            <div className="space-y-6">
              {/* Shift Info */}
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div className="bg-slate-50 rounded-lg p-4">
                  <p className="text-xs text-slate-500 mb-1">Started</p>
                  <p className="font-medium text-slate-900">{new Date(shiftDetail.started_at).toLocaleString()}</p>
                </div>
                <div className="bg-slate-50 rounded-lg p-4">
                  <p className="text-xs text-slate-500 mb-1">Ended</p>
                  <p className="font-medium text-slate-900">
                    {shiftDetail.ended_at ? new Date(shiftDetail.ended_at).toLocaleString() : 'Active'}
                  </p>
                </div>
                <div className="bg-slate-50 rounded-lg p-4">
                  <p className="text-xs text-slate-500 mb-1">Duration</p>
                  <p className="font-medium text-slate-900">
                    {formatDuration(shiftDetail.started_at, shiftDetail.ended_at)}
                  </p>
                </div>
                <div className="bg-slate-50 rounded-lg p-4">
                  <p className="text-xs text-slate-500 mb-1">Status</p>
                  <div>{getStatusBadge(shiftDetail.status)}</div>
                </div>
              </div>

              {/* Financial Summary */}
              <div className="grid grid-cols-3 gap-4">
                <div className="bg-blue-50 rounded-lg p-4">
                  <p className="text-xs text-blue-600 mb-1 font-medium">Total Revenue</p>
                  <p className="text-xl font-bold text-blue-700">{formatCurrency(shiftDetail.revenue)}</p>
                </div>
                <div className="bg-emerald-50 rounded-lg p-4">
                  <p className="text-xs text-emerald-600 mb-1 font-medium">Cash Sales</p>
                  <p className="text-xl font-bold text-emerald-700">{formatCurrency(shiftDetail.cash_sales)}</p>
                </div>
                <div className="bg-purple-50 rounded-lg p-4">
                  <p className="text-xs text-purple-600 mb-1 font-medium">Total Sales</p>
                  <p className="text-xl font-bold text-purple-700">{shiftDetail.sales_count}</p>
                </div>
              </div>

              {/* Notes */}
              {shiftDetail.notes && (
                <div>
                  <h3 className="text-sm font-medium text-slate-700 mb-2">Notes</h3>
                  <p className="text-sm text-slate-600 bg-slate-50 rounded-lg p-3">{shiftDetail.notes}</p>
                </div>
              )}

              {/* Sales List */}
              {shiftDetail.sales && shiftDetail.sales.length > 0 && (
                <div>
                  <h3 className="text-sm font-medium text-slate-700 mb-3">Sales During This Shift</h3>
                  <div className="border border-slate-200 rounded-lg overflow-hidden">
                    <table className="w-full">
                      <thead className="bg-slate-50">
                        <tr>
                          <th className="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Sale #</th>
                          <th className="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Customer</th>
                          <th className="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Time</th>
                          <th className="px-4 py-2 text-right text-xs font-medium text-slate-500 uppercase">Amount</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-200">
                        {shiftDetail.sales.map((sale) => (
                          <tr key={sale.id} className="hover:bg-slate-50">
                            <td className="px-4 py-3 text-sm font-medium text-blue-600">{sale.sale_number}</td>
                            <td className="px-4 py-3 text-sm text-slate-700">{sale.customer?.name || 'Walk-in'}</td>
                            <td className="px-4 py-3 text-sm text-slate-500">
                              {new Date(sale.created_at).toLocaleTimeString()}
                            </td>
                            <td className="px-4 py-3 text-sm text-slate-900 text-right font-medium">
                              {formatCurrency(Number(sale.total_amount))}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}
            </div>
          ) : null}
        </div>

        {/* Footer */}
        <div className="p-6 border-t border-slate-200 bg-slate-50">
          <button
            onClick={onClose}
            className="w-full px-4 py-2 bg-slate-200 text-slate-700 rounded-lg hover:bg-slate-300 transition-colors"
          >
            Close
          </button>
        </div>
      </div>
    </div>
  )
}

function getStatusBadge(status: string) {
  switch (status) {
    case 'active':
      return <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700">
        <Clock className="h-3 w-3" />
        Active
      </span>
    case 'completed':
      return <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
        <CheckCircle className="h-3 w-3" />
        Completed
      </span>
    case 'cancelled':
      return <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-red-100 text-red-700">
        <XCircle className="h-3 w-3" />
        Cancelled
      </span>
    default:
      return <span className="px-2.5 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-700">
        {status}
      </span>
  }
}
