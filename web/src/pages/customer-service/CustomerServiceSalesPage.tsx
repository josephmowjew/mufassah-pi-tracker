import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Search, ShoppingCart, Eye, XCircle, RotateCcw, Printer, Loader2, CheckCircle, Clock, Plus, Check } from 'lucide-react'
import apiClient from '@/api/client'
import type { Sale } from '@/types'
import ReceiptModal from '@/components/sales/ReceiptModal'
import CompleteSaleModal from '@/components/sales/CompleteSaleModal'
import { toast } from 'sonner'

interface ApiResponse {
  success: boolean
  message: string
  data: Sale[]
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

export default function CustomerServiceSalesPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [statusFilter, setStatusFilter] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [selectedSaleForReceipt, setSelectedSaleForReceipt] = useState<Sale | null>(null)
  const [showReceiptModal, setShowReceiptModal] = useState(false)
  const [showCompleteModal, setShowCompleteModal] = useState(false)
  const [saleToComplete, setSaleToComplete] = useState<Sale | null>(null)

  // Complete sale mutation
  const completeSaleMutation = useMutation({
    mutationFn: async ({ saleId, payment_reference, payment_amount }: { saleId: number; payment_reference?: string; payment_amount?: number }) => {
      const response = await apiClient.post<{ success: boolean; data: Sale }>(
        `/customer-service/sales/${saleId}/complete`,
        {
          payment_reference: payment_reference || undefined,
          payment_amount,
        }
      )
      return response.data.data
    },
    onSuccess: () => {
      toast.success('Sale completed successfully')
      queryClient.invalidateQueries({ queryKey: ['customer-service-sales'] })
      setShowCompleteModal(false)
      setSaleToComplete(null)
    },
    onError: () => {
      toast.error('Failed to complete sale')
    },
  })

  const { data, isLoading, error } = useQuery({
    queryKey: ['customer-service-sales', search, page, statusFilter, dateFrom, dateTo],
    queryFn: async () => {
      const params = new URLSearchParams()
      params.append('page', page.toString())
      params.append('per_page', '15')
      if (search) params.append('search', search)
      if (statusFilter) params.append('status', statusFilter)
      if (dateFrom) params.append('date_from', dateFrom)
      if (dateTo) params.append('date_to', dateTo)

      const response = await apiClient.get<ApiResponse>(
        `/customer-service/sales?${params.toString()}`
      )
      return {
        data: response.data.data,
        meta: response.data.meta,
        links: response.data.links,
      }
    },
  })

  const meta = data?.meta

  const getStatusBadge = (sale: Sale) => {
    const statusValue = sale.status.value || sale.status
    const statusConfig: Record<string, { bg: string; text: string; icon: typeof CheckCircle }> = {
      draft: { bg: 'bg-slate-100', text: 'text-slate-700', icon: Clock },
      completed: { bg: 'bg-emerald-50', text: 'text-emerald-700', icon: CheckCircle },
      cancelled: { bg: 'bg-red-50', text: 'text-red-700', icon: XCircle },
      refunded: { bg: 'bg-purple-50', text: 'text-purple-700', icon: RotateCcw },
    }
    const config = statusConfig[statusValue] || statusConfig.draft
    const Icon = config.icon

    return (
      <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${config.bg} ${config.text}`}>
        <Icon className="h-3 w-3" />
        {sale.status.label || statusValue}
      </span>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">My Sales</h1>
          <p className="text-sm text-slate-500">
            View and manage sales you've created
          </p>
        </div>
        <Link
          to="/customer-service/sales/new"
          className="inline-flex items-center gap-2 px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition-colors self-start"
        >
          <Plus className="h-4 w-4" />
          New Sale
        </Link>
      </div>

      {/* Filters */}
      <div className="bg-white rounded-xl border border-slate-200 p-4">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          {/* Search */}
          <div className="lg:col-span-2">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
              <input
                type="text"
                placeholder="Search by receipt number, customer name..."
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
          <div>
            <select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value)
                setPage(1)
              }}
              className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500"
            >
              <option value="">All Statuses</option>
              <option value="draft">Draft</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
              <option value="refunded">Refunded</option>
            </select>
          </div>

          {/* Date From */}
          <div>
            <input
              type="date"
              value={dateFrom}
              onChange={(e) => {
                setDateFrom(e.target.value)
                setPage(1)
              }}
              className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500"
            />
          </div>
        </div>

        {/* Date To Row */}
        {(dateFrom || dateTo) && (
          <div className="mt-4">
            <div className="flex items-center gap-4">
              <div className="flex-1">
                <input
                  type="date"
                  value={dateTo}
                  onChange={(e) => {
                    setDateTo(e.target.value)
                    setPage(1)
                  }}
                  min={dateFrom || undefined}
                  className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500"
                  placeholder="To date"
                />
              </div>
              {(dateFrom || dateTo) && (
                <button
                  onClick={() => {
                    setDateFrom('')
                    setDateTo('')
                    setPage(1)
                  }}
                  className="px-4 py-2 text-sm text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded-lg transition-colors"
                >
                  Clear Dates
                </button>
              )}
            </div>
          </div>
        )}
      </div>

      {/* Sales Table */}
      <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
        {isLoading ? (
          <div className="flex items-center justify-center py-16">
            <Loader2 className="h-8 w-8 animate-spin text-teal-600" />
          </div>
        ) : error ? (
          <div className="flex flex-col items-center justify-center py-16">
            <p className="text-red-600 mb-4">Failed to load sales</p>
          <p className="text-sm text-slate-500">Please try again later</p>
          </div>
        ) : !data?.data || data.data.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-16">
            <ShoppingCart className="h-12 w-12 text-slate-300 mb-4" />
            <p className="text-slate-500">No sales found</p>
            <p className="text-sm text-slate-400 mt-1">
              {search || statusFilter ? 'Try adjusting your filters' : 'Start by creating a new sale'}
            </p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="bg-slate-50 border-b border-slate-200">
                    <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                      Receipt #
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                      Customer
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                      Date
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                      Items
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                      Total
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                      Status
                    </th>
                    <th className="px-6 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200">
                  {data.data.map((sale) => (
                    <tr key={sale.id} className="hover:bg-slate-50">
                      <td className="px-6 py-4">
                        <span className="font-medium text-slate-900">{sale.sale_number}</span>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-3">
                          <div className="h-8 w-8 rounded-full bg-gradient-to-br from-teal-400 to-teal-600 flex items-center justify-center text-white text-xs font-semibold">
                            {sale.customer?.name?.charAt(0).toUpperCase()}
                          </div>
                          <span className="text-sm text-slate-700">{sale.customer?.name}</span>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="text-sm text-slate-900">
                          {new Date(sale.sale_date).toLocaleDateString()}
                        </div>
                        <div className="text-xs text-slate-500">
                          {new Date(sale.sale_date).toLocaleTimeString()}
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-700">
                          {sale.items_count || sale.items?.length || 0}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        <span className="font-semibold text-slate-900">
                          MWK {Number(sale.total_amount).toLocaleString()}
                        </span>
                      </td>
                      <td className="px-6 py-4">{getStatusBadge(sale)}</td>
                      <td className="px-6 py-4 text-right">
                        <div className="flex items-center justify-end gap-2">
                          {/* Complete button for draft sales */}
                          {(typeof sale.status === 'object' && sale.status.value === 'draft') && (
                            <button
                              onClick={() => {
                                setSaleToComplete(sale)
                                setShowCompleteModal(true)
                              }}
                              disabled={completeSaleMutation.isPending}
                              className="text-emerald-600 hover:text-emerald-800 disabled:opacity-50"
                              title="Complete Sale"
                            >
                              <Check className="h-4 w-4" />
                            </button>
                          )}
                          <button
                            onClick={async () => {
                              try {
                                const response = await apiClient.get<{ success: boolean; data: Sale }>(
                                  `/customer-service/sales/${sale.id}`
                                )
                                setSelectedSaleForReceipt(response.data.data)
                                setShowReceiptModal(true)
                              } catch (error) {
                                toast.error('Failed to load receipt')
                              }
                            }}
                            className="text-teal-600 hover:text-teal-800"
                            title="Print Receipt"
                          >
                            <Printer className="h-4 w-4" />
                          </button>
                          <button
                            onClick={() => navigate(`/customer-service/sales/${sale.id}`)}
                            className="text-blue-600 hover:text-blue-800"
                            title="View Details"
                          >
                            <Eye className="h-4 w-4" />
                          </button>
                        </div>
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
                  {Math.min(meta.current_page * meta.per_page, meta.total)} of {meta.total} sales
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

      {/* Receipt Modal */}
      {showReceiptModal && selectedSaleForReceipt && (
        <ReceiptModal
          sale={selectedSaleForReceipt}
          onClose={() => {
            setShowReceiptModal(false)
            setSelectedSaleForReceipt(null)
          }}
        />
      )}

      {/* Complete Sale Modal */}
      {showCompleteModal && saleToComplete && (
        <CompleteSaleModal
          sale={saleToComplete}
          onClose={() => {
            setShowCompleteModal(false)
            setSaleToComplete(null)
          }}
          onConfirm={(data) => {
            completeSaleMutation.mutate({
              saleId: saleToComplete.id,
              payment_reference: data.payment_reference,
              payment_amount: data.payment_amount,
            })
          }}
          isLoading={completeSaleMutation.isPending}
        />
      )}
    </div>
  )
}
