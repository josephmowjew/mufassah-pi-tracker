import { formatMalawiDate } from '@/lib/datetime'
import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Search, FileText, Loader2, AlertCircle, Clock, CheckCircle, XCircle } from 'lucide-react'
import apiClient from '@/api/client'

interface WarrantyClaim {
  id: number
  claim_number: string
  device_serial: string
  customer_name: string
  issue: string
  status: 'pending' | 'approved' | 'rejected' | 'in_progress'
  created_at: string
}

interface PaginatedResponse {
  data: WarrantyClaim[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

interface ApiResponse {
  success: boolean
  data: PaginatedResponse
}

export default function CustomerServiceWarrantiesPage() {
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [statusFilter, setStatusFilter] = useState('')

  const { data, isLoading, error } = useQuery({
    queryKey: ['customer-service-warranties', search, page, statusFilter],
    queryFn: async () => {
      const params = new URLSearchParams()
      params.append('page', page.toString())
      params.append('per_page', '15')
      if (search) params.append('search', search)
      if (statusFilter) params.append('status', statusFilter)

      const response = await apiClient.get<ApiResponse>(
        `/customer-service/warranties?${params.toString()}`
      )
      return response.data.data
    },
  })

  const meta = data?.meta

  const getStatusBadge = (status: string) => {
    const statusConfig: Record<string, { bg: string; text: string; icon: any }> = {
      pending: { bg: 'bg-amber-50', text: 'text-amber-700', icon: Clock },
      in_progress: { bg: 'bg-blue-50', text: 'text-blue-700', icon: AlertCircle },
      approved: { bg: 'bg-emerald-50', text: 'text-emerald-700', icon: CheckCircle },
      rejected: { bg: 'bg-red-50', text: 'text-red-700', icon: XCircle },
    }
    const config = statusConfig[status] || statusConfig.pending
    const Icon = config.icon

    return (
      <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${config.bg} ${config.text}`}>
        <Icon className="h-3 w-3" />
        {status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}
      </span>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Warranty Claims</h1>
          <p className="text-sm text-slate-500">View and manage warranty claims</p>
        </div>
      </div>

      {/* Filters */}
      <div className="bg-white rounded-xl border border-slate-200 p-4">
        <div className="flex flex-col sm:flex-row gap-4">
          {/* Search */}
          <div className="flex-1">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
              <input
                type="text"
                placeholder="Search by claim number, customer name..."
                value={search}
                onChange={(e) => {
                  setSearch(e.target.value)
                  setPage(1)
                }}
                className="w-full pl-10 pr-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500"
              />
            </div>
          </div>

          {/* Status Filter */}
          <div className="sm:w-48">
            <select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value)
                setPage(1)
              }}
              className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500"
            >
              <option value="">All Statuses</option>
              <option value="pending">Pending</option>
              <option value="in_progress">In Progress</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
            </select>
          </div>
        </div>
      </div>

      {/* Claims Table */}
      <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
        {isLoading ? (
          <div className="flex items-center justify-center py-16">
            <Loader2 className="h-8 w-8 animate-spin text-teal-600" />
          </div>
        ) : error ? (
          <div className="flex flex-col items-center justify-center py-16">
            <p className="text-red-600 mb-4">Failed to load warranty claims</p>
            <p className="text-sm text-slate-500">Please try again later</p>
          </div>
        ) : !data?.data || data.data.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-16">
            <FileText className="h-12 w-12 text-slate-300 mb-4" />
            <p className="text-slate-500">No warranty claims found</p>
            <p className="text-sm text-slate-400 mt-1">
              {search || statusFilter ? 'Try adjusting your filters' : 'No warranty claims submitted yet'}
            </p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="bg-slate-50 border-b border-slate-200">
                    <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                      Claim #
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                      Device
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                      Customer
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                      Issue
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                      Status
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                      Date
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200">
                  {data.data.map((claim) => (
                    <tr key={claim.id} className="hover:bg-slate-50">
                      <td className="px-6 py-4">
                        <span className="font-medium text-slate-900">#{claim.claim_number}</span>
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-sm text-slate-700 font-mono">{claim.device_serial}</span>
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-sm text-slate-700">{claim.customer_name}</span>
                      </td>
                      <td className="px-6 py-4">
                        <p className="text-sm text-slate-700 max-w-xs truncate">{claim.issue}</p>
                      </td>
                      <td className="px-6 py-4">{getStatusBadge(claim.status)}</td>
                      <td className="px-6 py-4">
                        <p className="text-sm text-slate-700">
                          {formatMalawiDate(claim.created_at)}
                        </p>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Pagination */}
            {meta && meta.last_page > 1 && (
              <div className="flex items-center justify-between px-6 py-4 border-t border-slate-200 bg-slate-50">
                <p className="text-sm text-slate-500">
                  Showing {((meta.current_page - 1) * meta.per_page + 1)} to{' '}
                  {Math.min(meta.current_page * meta.per_page, meta.total)} of {meta.total} claims
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
          </>
        )}
      </div>
    </div>
  )
}
