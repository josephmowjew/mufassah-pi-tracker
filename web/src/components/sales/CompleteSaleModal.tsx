import { useState } from 'react'
import { Check } from 'lucide-react'
import type { Sale } from '@/types'

interface CompleteSaleModalProps {
  sale?: Sale
  onClose: () => void
  onConfirm: (data: { payment_reference?: string; payment_amount?: number }) => void
  isLoading: boolean
}

function CompleteSaleModal({ sale, onClose, onConfirm, isLoading }: CompleteSaleModalProps) {
  const [paymentReference, setPaymentReference] = useState('')
  const [paymentAmount, setPaymentAmount] = useState('')
  const paymentMethod = typeof sale?.payment_method === 'object' ? sale.payment_method.value : sale?.payment_method
  const needsCashAmount = paymentMethod === 'cash' && !sale?.payment_amount
  const totalDue = Number(sale?.total_amount || 0)

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (needsCashAmount && Number(paymentAmount || 0) < totalDue) {
      return
    }

    onConfirm({
      payment_reference: paymentReference || undefined,
      payment_amount: needsCashAmount ? Number(paymentAmount) : undefined,
    })
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-[80] p-4">
      <div className="bg-white rounded-xl max-w-md w-full p-6">
        <div className="mb-4">
          <div className="flex items-center gap-3 mb-2">
            <div className="p-2 bg-emerald-100 rounded-lg">
              <Check className="h-5 w-5 text-emerald-600" />
            </div>
            <h2 className="text-xl font-bold text-slate-900">Complete Sale</h2>
          </div>
          <p className="text-sm text-slate-600 mt-3">
            Are you sure you want to complete this sale? This will finalize the transaction and transfer device ownership to the customer.
          </p>
          <p className="text-xs text-emerald-600 mt-2">
            Note: This action cannot be undone.
          </p>
        </div>
        <form onSubmit={handleSubmit} className="space-y-4">
          {needsCashAmount && (
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">
                Amount Paid
              </label>
              <input
                type="number"
                min={totalDue}
                step="0.01"
                value={paymentAmount}
                onChange={(e) => {
                  const value = e.target.value
                  if (value === '' || (Number.isFinite(Number(value)) && Number(value) >= 0)) {
                    setPaymentAmount(value)
                  }
                }}
                placeholder={`Minimum MWK ${totalDue.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`}
                className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500"
                required
              />
              {Number(paymentAmount || 0) >= totalDue && (
                <p className="mt-1 text-xs text-emerald-600">
                  Change due: MWK {(Number(paymentAmount || 0) - totalDue).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                </p>
              )}
            </div>
          )}
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">
              Payment Reference (optional)
            </label>
            <input
              type="text"
              value={paymentReference}
              onChange={(e) => setPaymentReference(e.target.value)}
              placeholder="Transaction ID, receipt number, etc."
              className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500"
            />
          </div>
          <div className="flex gap-3">
            <button
              type="button"
              onClick={onClose}
              disabled={isLoading}
              className="flex-1 px-4 py-2 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors disabled:opacity-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={isLoading || (needsCashAmount && Number(paymentAmount || 0) < totalDue)}
              className="flex-1 px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 disabled:opacity-50 transition-colors"
            >
              {isLoading ? 'Completing...' : 'Complete Sale'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

export default CompleteSaleModal
