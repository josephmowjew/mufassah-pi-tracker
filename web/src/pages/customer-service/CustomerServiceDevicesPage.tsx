import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Search, Smartphone, Loader2, Eye } from 'lucide-react'
import apiClient from '@/api/client'
import type { Device } from '@/types'

interface ApiResponse {
  success: boolean
  message: string
  data: Device[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
  links: {
    first: string
    last: string
    prev: string | null
    next: string | null
  }
}

export default function CustomerServiceDevicesPage() {
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [statusFilter, setStatusFilter] = useState('')

  const { data, isLoading, error } = useQuery({
    queryKey: ['customer-service-devices', search, page, statusFilter],
    queryFn: async () => {
      const params = new URLSearchParams()
      params.append('page', page.toString())
      params.append('per_page', '15')
      if (search) params.append('search', search)
      if (statusFilter) params.append('inventory_status', statusFilter)

      const response = await apiClient.get<ApiResponse>(
        `/customer-service/devices?${params.toString()}`
      )
      return response.data
    },
  })

  const meta = data?.meta

  const getStatusBadge = (device: Device) => {
    const statusConfig: Record<string, { bg: string; text: string }> = {
      in_stock: { bg: 'bg-emerald-50', text: 'text-emerald-700' },
      sold: { bg: 'bg-blue-50', text: 'text-blue-700' },
      reserved: { bg: 'bg-amber-50', text: 'text-amber-700' },
      damaged: { bg: 'bg-red-50', text: 'text-red-700' },
      lost_stolen: { bg: 'bg-purple-50', text: 'text-purple-700' },
    }

    // Handle both string and object formats for inventory_status
    let statusValue = 'unknown'
    if (typeof device.inventory_status === 'string') {
      statusValue = device.inventory_status
    } else if (device.inventory_status && typeof device.inventory_status === 'object') {
      statusValue = (device.inventory_status as any).value || 'unknown'
    }

    const config = statusConfig[statusValue] || { bg: 'bg-slate-50', text: 'text-slate-700' }

    return (
      <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium ${config.bg} ${config.text}`}>
        {statusValue.replace('_', ' ').replace(/\b\w/g, (l: string) => l.toUpperCase())}
      </span>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Devices</h1>
          <p className="text-sm text-slate-500">View all devices in the system</p>
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
                placeholder="Search by serial number, brand, model..."
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
              <option value="in_stock">In Stock</option>
              <option value="sold">Sold</option>
              <option value="reserved">Reserved</option>
              <option value="damaged">Damaged</option>
              <option value="lost_stolen">Lost/Stolen</option>
            </select>
          </div>
        </div>
      </div>

      {/* Devices Table */}
      <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
        {isLoading ? (
          <div className="flex items-center justify-center py-16">
            <Loader2 className="h-8 w-8 animate-spin text-teal-600" />
          </div>
        ) : error ? (
          <div className="flex flex-col items-center justify-center py-16">
            <p className="text-red-600 mb-4">Failed to load devices</p>
            <p className="text-sm text-slate-500">Please try again later</p>
          </div>
        ) : !data?.data || data.data.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-16">
            <Smartphone className="h-12 w-12 text-slate-300 mb-4" />
            <p className="text-slate-500">No devices found</p>
            <p className="text-sm text-slate-400 mt-1">
              {search || statusFilter ? 'Try adjusting your filters' : 'No devices in the system yet'}
            </p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="bg-slate-50 border-b border-slate-200">
                    <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                      Device
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                      Serial Number
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                      Category
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                      Status
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                      Owner
                    </th>
                    <th className="px-6 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200">
                  {data.data.map((device) => (
                    <tr key={device.id} className="hover:bg-slate-50">
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-3">
                          <div className="h-10 w-10 rounded-lg bg-gradient-to-br from-teal-400 to-teal-600 flex items-center justify-center text-white">
                            <Smartphone className="h-5 w-5" />
                          </div>
                          <div>
                            <p className="font-medium text-slate-900">{device.brand} {device.model}</p>
                            {device.unique_device_code && (
                              <p className="text-xs text-slate-500">Code: {device.unique_device_code}</p>
                            )}
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-sm text-slate-700 font-mono">{device.serial_number}</span>
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-sm text-slate-700">{device.category?.name}</span>
                      </td>
                      <td className="px-6 py-4">{getStatusBadge(device)}</td>
                      <td className="px-6 py-4">
                        {device.owner ? (
                          <div className="flex items-center gap-2">
                            <div className="h-6 w-6 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white text-xs font-semibold">
                              {device.owner.name?.charAt(0).toUpperCase()}
                            </div>
                            <span className="text-sm text-slate-700">{device.owner.name}</span>
                          </div>
                        ) : (
                          <span className="text-sm text-slate-400">—</span>
                        )}
                      </td>
                      <td className="px-6 py-4 text-right">
                        <button className="text-teal-600 hover:text-teal-800" title="View Details">
                          <Eye className="h-4 w-4" />
                        </button>
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
                  {Math.min(meta.current_page * meta.per_page, meta.total)} of {meta.total} devices
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
