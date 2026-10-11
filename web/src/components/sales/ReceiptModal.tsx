import { Printer, X } from 'lucide-react'
import type { Sale } from '@/types'

interface ReceiptModalProps {
  sale: Sale
  onClose: () => void
  onPrint?: () => void
}

export default function ReceiptModal({ sale, onClose, onPrint }: ReceiptModalProps) {
  const formatDateTime = (dateString: string) => {
    return new Date(dateString).toLocaleString()
  }

  const handlePrint = () => {
    window.print()
    onPrint?.()
  }

  return (
    <>
      {/* Print Styles */}
      <style>{`
        @page {
          size: 80mm auto;
          margin: 0;
        }

        @media print {
          html,
          body {
            width: 80mm !important;
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
          }

          body * {
            visibility: hidden;
          }

          #receipt-content, #receipt-content * {
            visibility: visible;
            color: #000 !important;
            border-color: #000 !important;
            text-shadow: none !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
          }

          #receipt-content {
            position: absolute;
            left: 0;
            right: 0;
            top: 0;
            width: 74mm;
            margin: 0 auto !important;
            padding: 2mm !important;
            background: white !important;
            font-size: 12pt !important;
            font-weight: 700 !important;
            line-height: 1.35 !important;
          }

          #receipt-content .text-xs {
            font-size: 10pt !important;
          }

          #receipt-content .text-lg {
            font-size: 14pt !important;
          }

          #receipt-content [class~="text-[10px]"] {
            font-size: 9pt !important;
          }

          #receipt-content .text-base {
            font-size: 13pt !important;
          }
        }
      `}</style>

      <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-[70] p-4">
        <div className="bg-white rounded-xl shadow-2xl w-full max-w-md">
          {/* Header */}
          <div className="sticky top-0 bg-white border-b border-slate-200 px-4 py-3 flex items-center justify-between print:hidden">
            <div>
              <h2 className="text-lg font-bold text-slate-900">Receipt</h2>
              <p className="text-xs text-slate-500">{sale.sale_number}</p>
            </div>
            <button
              onClick={onClose}
              className="p-1.5 hover:bg-slate-100 rounded-lg transition-colors"
            >
              <X className="h-4 w-4" />
            </button>
          </div>

          {/* Receipt Content - Thermal Printer Style */}
          <div id="receipt-content" className="p-6 font-mono text-sm font-semibold bg-white">
            {/* Company Header */}
            <div className="text-center mb-4 pb-4 border-b-2 border-dashed border-slate-300">
              <h3 className="text-lg font-bold text-slate-900 uppercase tracking-wide">
                Mufasah Electronics
              </h3>
              <p className="text-xs text-slate-500 mt-1">T-PIN: 70474293</p>
              <p className="text-xs text-slate-500 mt-1">Device Sales Receipt</p>
            </div>

            {/* Sale Info */}
            <div className="mb-4 pb-4 border-b-2 border-dashed border-slate-300 space-y-1">
              <div className="flex justify-between">
                <span className="text-slate-600">Date:</span>
                <span className="text-slate-900">{formatDateTime(sale.sale_date)}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-600">Receipt #:</span>
                <span className="text-slate-900">{sale.sale_number}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-600">Customer:</span>
                <span className="text-slate-900 text-right flex-1 ml-4 truncate">
                  {sale.customer?.name || 'Walk-in'}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-600">Payment:</span>
                <span className="text-slate-900 capitalize">
                  {typeof sale.payment_method === 'string' ? sale.payment_method : sale.payment_method?.label || 'N/A'}
                </span>
              </div>
            </div>

            {/* Items */}
            <div className="mb-4 pb-4 border-b-2 border-dashed border-slate-300">
              {sale.items && sale.items.length > 0 ? (
                <table className="w-full">
                  <tbody>
                    {sale.items.map((item, index) => {
                      const price = parseFloat(String(item.unit_price))
                      const discount = parseFloat(String(item.discount || '0'))
                      const tax = parseFloat(String(item.tax || '0'))
                      const total = item.total_price !== undefined
                        ? parseFloat(String(item.total_price))
                        : price - discount + tax

                      return (
                        <tr key={index}>
                          <td className="align-top py-1">
                            <div className="text-slate-900">{item.item_description}</div>
                          </td>
                          <td className="text-right align-top py-1">
                            <div>{Number(total).toLocaleString()}</div>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              ) : (
                <div className="text-center text-slate-400 text-xs py-2">
                  No items data available
                </div>
              )}
            </div>

            {/* Totals */}
            <div className="space-y-1 mb-4 pb-4 border-b-2 border-dashed border-slate-300">
              <div className="flex justify-between">
                <span className="text-slate-600">Subtotal:</span>
                <span className="text-slate-900">{Number(parseFloat(String(sale.subtotal))).toLocaleString()}</span>
              </div>
              {parseFloat(String(sale.tax_amount)) > 0 && (
                <div className="flex justify-between">
                  <span className="text-slate-600">VAT (17.5%):</span>
                  <span className="text-slate-900">{Number(parseFloat(String(sale.tax_amount))).toLocaleString()}</span>
                </div>
              )}
              {parseFloat(String(sale.discount_amount)) > 0 && (
                <div className="flex justify-between">
                  <span className="text-red-600">Discount:</span>
                  <span className="text-red-600">-{Number(parseFloat(String(sale.discount_amount))).toLocaleString()}</span>
                </div>
              )}
              <div className="flex justify-between pt-2 border-t-2 border-dashed border-slate-400 font-bold text-base">
                <span className="text-slate-900">TOTAL:</span>
                <span className="text-slate-900">MWK {Number(parseFloat(String(sale.total_amount))).toLocaleString()}</span>
              </div>
            </div>

            {/* Cash Payment Details */}
            {sale.payment_method.value === 'cash' && sale.payment_amount && (
              <div className="pt-2 border-t-2 border-dashed border-slate-400 mb-4 pb-4 border-b-2 border-dashed border-slate-300">
                <div className="flex justify-between">
                  <span className="text-slate-600">Amount Paid:</span>
                  <span className="text-slate-900">MWK {Number(parseFloat(String(sale.payment_amount))).toLocaleString()}</span>
                </div>
                {parseFloat(String(sale.change_due || 0)) > 0 && (
                  <div className="flex justify-between font-bold text-base text-emerald-600 pt-2 mt-2 border-t-2 border-dashed border-slate-400">
                    <span>CHANGE DUE:</span>
                    <span>MWK {Number(parseFloat(String(sale.change_due))).toLocaleString()}</span>
                  </div>
                )}
              </div>
            )}

            {/* Footer */}
            <div className="text-center space-y-1 text-xs text-slate-600">
              <div className="pt-2 border-t border-dashed border-slate-300 mt-2">
                <p className="font-semibold text-slate-900">Thank you for your business!</p>
                <p className="text-[10px] text-slate-500 mt-1">Keep this receipt for warranty claims</p>
              </div>
              {sale.notes && (
                <div className="text-left mt-2 pt-2 border-t border-dashed border-slate-200">
                  <p className="font-semibold text-slate-700">Note:</p>
                  <p className="text-slate-600">{sale.notes}</p>
                </div>
              )}
            </div>
          </div>

          {/* Actions */}
          <div className="sticky bottom-0 bg-slate-50 border-t border-slate-200 px-4 py-3 flex gap-2 print:hidden">
            <button
              onClick={onClose}
              className="flex-1 px-3 py-2 text-sm border border-slate-200 rounded-lg hover:bg-white transition-colors font-medium text-slate-700"
            >
              Close
            </button>
            <button
              onClick={handlePrint}
              className="flex-1 px-3 py-2 bg-slate-800 text-white rounded-lg hover:bg-slate-900 transition-colors font-medium text-sm flex items-center justify-center gap-2"
            >
              <Printer className="h-4 w-4" />
              Print
            </button>
          </div>
        </div>
      </div>
    </>
  )
}
