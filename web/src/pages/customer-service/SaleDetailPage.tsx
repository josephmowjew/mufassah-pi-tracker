import { useParams, Link } from 'react-router-dom'
import { ArrowLeft, Printer } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import apiClient from '@/api/client'
import type { Sale } from '@/types'
import ReceiptModal from '@/components/sales/ReceiptModal'
import { useState } from 'react'

export default function SaleDetailPage() {
  const { id } = useParams<{ id: string }>()
  const [showReceiptModal, setShowReceiptModal] = useState(false)

  const { data: sale, isLoading, error } = useQuery({
    queryKey: ['sale', id],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: Sale }>(
        `/customer-service/sales/${id}`
      )
      return response.data.data
    },
    enabled: !!id,
  })

  const formatDateTime = (dateString: string) => {
    return new Date(dateString).toLocaleString()
  }

  const getStatusBadge = (sale: Sale) => {
    const statusValue = typeof sale.status === 'string' ? sale.status : sale.status?.value
    const statusConfig: Record<string, { bg: string; text: string; label: string }> = {
      draft: { bg: 'bg-slate-100', text: 'text-slate-700', label: 'Draft' },
      completed: { bg: 'bg-emerald-50', text: 'text-emerald-700', label: 'Completed' },
      cancelled: { bg: 'bg-red-50', text: 'text-red-700', label: 'Cancelled' },
      refunded: { bg: 'bg-purple-50', text: 'text-purple-700', label: 'Refunded' },
    }
    const config = statusConfig[statusValue || ''] || statusConfig.draft
    return (
      <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium ${config.bg} ${config.text}`}>
        {config.label}
      </span>
    )
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-16">
        <div className="animate-spin h-8 w-8 border-3 border-teal-600 border-t-transparent rounded-full" />
      </div>
    )
  }

  if (error || !sale) {
    return (
      <div className="flex flex-col items-center justify-center py-16">
        <p className="text-red-600 mb-4">Failed to load sale details</p>
        <Link to="/customer-service/sales" className="text-teal-600 hover:underline">
          Back to Sales
        </Link>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Link
          to="/customer-service/sales"
          className="p-2 hover:bg-slate-100 rounded-lg transition-colors"
        >
          <ArrowLeft className="h-5 w-5" />
        </Link>
        <div className="flex-1">
          <div className="flex items-start justify-between">
            <div>
              <h1 className="text-2xl font-bold text-slate-900">Sale Details</h1>
              <p className="text-sm text-slate-500 mt-1">{sale.sale_number}</p>
            </div>
            <button
              onClick={() => setShowReceiptModal(true)}
              className="px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition-colors flex items-center gap-2"
            >
              <Printer className="h-4 w-4" />
              Print Receipt
            </button>
          </div>
        </div>
      </div>

      {/* Sale Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="bg-white rounded-xl border border-slate-200 p-6">
          <p className="text-sm text-slate-600 mb-1">Total Amount</p>
          <p className="text-2xl font-bold text-slate-900">MWK {Number(sale.total_amount).toLocaleString()}</p>
        </div>
        <div className="bg-white rounded-xl border border-slate-200 p-6">
          <p className="text-sm text-slate-600 mb-1">Items</p>
          <p className="text-2xl font-bold text-slate-900">{sale.items_count || sale.items?.length || 0}</p>
        </div>
        <div className="bg-white rounded-xl border border-slate-200 p-6">
          <p className="text-sm text-slate-600 mb-1">Status</p>
          <div className="mt-1">{getStatusBadge(sale)}</div>
        </div>
        <div className="bg-white rounded-xl border border-slate-200 p-6">
          <p className="text-sm text-slate-600 mb-1">Payment Status</p>
          <p className="text-lg font-medium text-slate-900 capitalize mt-1">
            {typeof sale.payment_status === 'string' ? sale.payment_status : sale.payment_status?.label || 'N/A'}
          </p>
        </div>
      </div>

      {/* Sale Information */}
      <div className="bg-white rounded-xl border border-slate-200 p-6">
        <h2 className="text-lg font-semibold text-slate-900 mb-4">Sale Information</h2>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <p className="text-sm text-slate-500 mb-1">Sale Number</p>
            <p className="font-medium text-slate-900">{sale.sale_number}</p>
          </div>
          <div>
            <p className="text-sm text-slate-500 mb-1">Date & Time</p>
            <p className="font-medium text-slate-900">{formatDateTime(sale.sale_date)}</p>
          </div>
          <div>
            <p className="text-sm text-slate-500 mb-1">Payment Method</p>
            <p className="font-medium text-slate-900 capitalize">
              {typeof sale.payment_method === 'string' ? sale.payment_method : sale.payment_method?.label || 'N/A'}
            </p>
          </div>
          {sale.payment_reference && (
            <div>
              <p className="text-sm text-slate-500 mb-1">Payment Reference</p>
              <p className="font-medium text-slate-900">{sale.payment_reference}</p>
            </div>
          )}
          <div>
            <p className="text-sm text-slate-500 mb-1">VAT Applicable</p>
            <p className="font-medium text-slate-900">{sale.vat_applicable ? 'Yes (17.5%)' : 'No'}</p>
          </div>
          {sale.notes && (
            <div className="md:col-span-2">
              <p className="text-sm text-slate-500 mb-1">Notes</p>
              <p className="font-medium text-slate-900">{sale.notes}</p>
            </div>
          )}
        </div>
      </div>

      {/* Customer Information */}
      {sale.customer && (
        <div className="bg-white rounded-xl border border-slate-200 p-6">
          <h2 className="text-lg font-semibold text-slate-900 mb-4">Customer Information</h2>
          <div className="flex items-center gap-4">
            <div className="h-12 w-12 rounded-full bg-gradient-to-br from-teal-400 to-teal-600 flex items-center justify-center text-white text-lg font-semibold">
              {sale.customer.name.charAt(0).toUpperCase()}
            </div>
            <div>
              <p className="font-medium text-slate-900">{sale.customer.name}</p>
              <p className="text-sm text-slate-500">{sale.customer.email}</p>
              {sale.customer.phone_number && (
                <p className="text-sm text-slate-500">{sale.customer.phone_number}</p>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Sale Items */}
      {sale.items && sale.items.length > 0 && (
        <div className="bg-white rounded-xl border border-slate-200 p-6">
          <h2 className="text-lg font-semibold text-slate-900 mb-4">Sale Items</h2>
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="bg-slate-50 border-b border-slate-200">
                  <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                    Description
                  </th>
                  <th className="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">
                    Unit Price
                  </th>
                  <th className="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">
                    Discount
                  </th>
                  <th className="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">
                    Tax
                  </th>
                  <th className="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">
                    Total
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {sale.items.map((item, index) => (
                  <tr key={index}>
                    <td className="px-4 py-3 text-sm text-slate-900">{item.item_description}</td>
                    <td className="px-4 py-3 text-sm text-slate-900 text-right">
                      MWK {Number(item.unit_price).toLocaleString()}
                    </td>
                    <td className="px-4 py-3 text-sm text-red-600 text-right">
                      {Number(item.discount || 0) > 0 ? `-MWK ${Number(item.discount).toLocaleString()}` : '—'}
                    </td>
                    <td className="px-4 py-3 text-sm text-teal-600 text-right">
                      {Number(item.tax || 0) > 0 ? `MWK ${Number(item.tax).toLocaleString()}` : '—'}
                    </td>
                    <td className="px-4 py-3 text-sm font-medium text-slate-900 text-right">
                      MWK {Number(item.total_price).toLocaleString()}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Totals */}
      <div className="bg-white rounded-xl border border-slate-200 p-6">
        <h2 className="text-lg font-semibold text-slate-900 mb-4">Totals</h2>
        <div className="space-y-3 max-w-md">
          <div className="flex justify-between text-sm">
            <span className="text-slate-600">Subtotal</span>
            <span className="text-slate-900">MWK {Number(sale.subtotal).toLocaleString()}</span>
          </div>
          {Number(sale.tax_amount) > 0 && (
            <div className="flex justify-between text-sm">
              <span className="text-slate-600">VAT (17.5%)</span>
              <span className="text-slate-900">MWK {Number(sale.tax_amount).toLocaleString()}</span>
            </div>
          )}
          {Number(sale.discount_amount) > 0 && (
            <div className="flex justify-between text-sm">
              <span className="text-red-600">Discount</span>
              <span className="text-red-600">-MWK {Number(sale.discount_amount).toLocaleString()}</span>
            </div>
          )}
          <div className="flex justify-between pt-3 border-t border-slate-200 font-semibold text-base">
            <span className="text-slate-900">Total</span>
            <span className="text-teal-600">MWK {Number(sale.total_amount).toLocaleString()}</span>
          </div>
        </div>
      </div>

      {/* Receipt Modal */}
      {showReceiptModal && (
        <ReceiptModal
          sale={sale}
          onClose={() => setShowReceiptModal(false)}
        />
      )}
    </div>
  )
}
