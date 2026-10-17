import { useEffect, useRef, useState } from 'react'
import { Printer, X } from 'lucide-react'
import { createPortal, flushSync } from 'react-dom'
import type { Sale } from '@/types'

type ReceiptCopy = 'customer' | 'company'

interface ReceiptModalProps {
  sale: Sale
  onClose: () => void
}

export default function ReceiptModal({ sale, onClose }: ReceiptModalProps) {
  const [step, setStep] = useState<ReceiptCopy | 'complete'>('customer')
  const [receiptCopy, setReceiptCopy] = useState<ReceiptCopy>('customer')
  const [isPrinting, setIsPrinting] = useState(false)
  const [printError, setPrintError] = useState<string | null>(null)
  const pendingCopy = useRef<ReceiptCopy | null>(null)

  useEffect(() => {
    // Browsers fire afterprint on both print and cancel. Staff can reprint
    // either copy and explicitly finish after checking the paper receipts.
    const handleAfterPrint = () => {
      const copy = pendingCopy.current
      if (!copy) return

      pendingCopy.current = null
      setIsPrinting(false)
      setReceiptCopy('company')
      setStep(current => {
        if (copy === 'company') return 'complete'
        return current === 'customer' ? 'company' : current
      })
    }

    window.addEventListener('afterprint', handleAfterPrint)
    return () => window.removeEventListener('afterprint', handleAfterPrint)
  }, [])

  const formatDateTime = (dateString: string) => {
    return new Date(dateString).toLocaleString()
  }

  const handlePrint = (copy: ReceiptCopy) => {
    if (pendingCopy.current) return
    pendingCopy.current = copy

    // Commit the copy label before the browser captures the print document.
    flushSync(() => {
      setReceiptCopy(copy)
      setIsPrinting(true)
      setPrintError(null)
    })

    try {
      window.print()
    } catch {
      pendingCopy.current = null
      setIsPrinting(false)
      setPrintError('Unable to open printing. Please try again.')
    }
  }

  // The receipt must be outside the dashboard so it can be the only print
  // layout. Hidden dashboard content still creates pages in the print job.
  return createPortal(
    <>
      {/* Print Styles */}
      <style>{`
        #receipt-content {
          overflow-wrap: anywhere;
          word-wrap: break-word;
        }

        #receipt-content .flex {
          gap: 2mm;
        }

        #receipt-content .flex > :first-child {
          flex-shrink: 0;
        }

        #receipt-content .flex > :last-child {
          min-width: 0;
          text-align: right;
        }

        #receipt-content table { table-layout: fixed; }
        #receipt-content td:first-child { padding-right: 2mm; }
        #receipt-content td:last-child { width: 38%; }

        @page {
          size: auto;
          margin: 3mm;
        }

        @media print {
          html,
          body {
            width: auto !important;
            max-width: 80mm !important;
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
            height: auto !important;
            min-height: 0 !important;
            overflow: visible !important;
          }

          body > :not(#receipt-modal) {
            display: none !important;
          }

          /* Fixed elements repeat on every printed page. Use normal flow so
             long receipts continue onto the next page without duplicating. */
          #receipt-modal,
          #receipt-dialog {
            position: static !important;
            display: block !important;
            width: 100% !important;
            max-width: 80mm !important;
            height: auto !important;
            max-height: none !important;
            overflow: visible !important;
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
            box-shadow: none !important;
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
            position: static;
            width: 74mm;
            max-width: 100%;
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

          #receipt-content .flex > :last-child {
            white-space: normal;
            overflow: visible;
            margin-left: 0;
          }
        }

        @media print and (max-width: 60mm) {
          #receipt-content .flex { display: block; }
          #receipt-content .flex > :last-child { display: block; }
          #receipt-content table, #receipt-content tbody,
          #receipt-content tr, #receipt-content td { display: block; width: 100%; }
          #receipt-content td:last-child { width: 100%; }
        }
      `}</style>

      <div id="receipt-modal" className="fixed inset-0 bg-black/50 flex items-center justify-center z-[70] p-4">
        <div id="receipt-dialog" role="dialog" aria-modal="true" aria-labelledby="receipt-title" className="bg-white rounded-xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto">
          {/* Header */}
          <div className="sticky top-0 bg-white border-b border-slate-200 px-4 py-3 flex items-center justify-between print:hidden">
            <div>
              <h2 id="receipt-title" className="text-lg font-bold text-slate-900">Sales receipts</h2>
              <p className="text-xs text-slate-500">{sale.sale_number}</p>
            </div>
            <button
              onClick={onClose}
              aria-label="Close receipts"
              className="p-1.5 hover:bg-slate-100 rounded-lg transition-colors"
            >
              <X className="h-4 w-4" />
            </button>
          </div>

          <div className="border-b border-slate-200 bg-slate-50 px-4 py-3 print:hidden">
            <p role="status" className="text-sm font-semibold text-slate-900">
              {step === 'customer' ? '1 of 2: Customer receipt' : step === 'company' ? '2 of 2: Company receipt' : 'Finish receipt printing'}
            </p>
            <p className="mt-1 text-xs text-slate-600">
              {step === 'complete'
                ? 'Check that both receipts printed correctly. Reprint either copy below if needed.'
                : step === 'company'
                  ? 'Next, print one company receipt. Set Copies to 1. Reprint the customer receipt below if needed.'
                  : 'Print one customer receipt, then continue to the company receipt. Set Copies to 1.'}
            </p>
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
              <p data-receipt-copy className="text-xs font-bold text-slate-900 mt-1">
                {receiptCopy === 'customer' ? 'Customer copy' : 'Company copy'}
              </p>
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
          <div className="sticky bottom-0 bg-slate-50 border-t border-slate-200 px-4 py-3 print:hidden">
            {printError && <p role="alert" className="mb-3 text-sm text-red-700">{printError}</p>}
            {step !== 'customer' && (
              <div className="mb-3 flex flex-wrap gap-x-4 gap-y-2">
                <button
                  onClick={() => handlePrint('customer')}
                  disabled={isPrinting}
                  className="min-h-10 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-50"
                >
                  Reprint customer receipt
                </button>
                {step === 'complete' && (
                  <button
                    onClick={() => handlePrint('company')}
                    disabled={isPrinting}
                    className="min-h-10 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-50"
                  >
                    Reprint company receipt
                  </button>
                )}
              </div>
            )}
            <div className="flex gap-2">
              {step !== 'complete' && (
                <button
                  onClick={onClose}
                  className="px-3 py-2 text-sm border border-slate-200 rounded-lg hover:bg-white transition-colors font-medium text-slate-700"
                >
                  Close
                </button>
              )}
              <button
                onClick={() => step === 'complete' ? onClose() : handlePrint(step)}
                disabled={isPrinting}
                className="min-h-12 flex-1 px-4 py-3 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition-colors font-semibold text-sm flex items-center justify-center gap-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {step !== 'complete' && <Printer className="h-4 w-4 shrink-0" />}
                {isPrinting ? 'Print dialog open…' : step === 'customer' ? 'Print customer receipt' : step === 'company' ? 'Print company receipt' : 'Finish'}
              </button>
            </div>
          </div>
        </div>
      </div>
    </>,
    document.body,
  )
}
