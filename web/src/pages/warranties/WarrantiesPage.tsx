import { formatMalawiDate } from '@/lib/datetime'
import { useState } from 'react'
import { Shield, Calendar, Smartphone, CheckCircle, XCircle, Clock, AlertTriangle, FileText, Plus, Loader2, ChevronRight } from 'lucide-react'
import { toast } from 'sonner'
import { useWarranties, useWarrantyClaims, useSubmitWarrantyClaim } from '@/hooks/useWarranties'
import type { Warranty, WarrantyClaim } from '@/types'

const statusConfig = {
  active: { bg: 'bg-emerald-50', text: 'text-emerald-700', icon: CheckCircle, label: 'Active' },
  expired: { bg: 'bg-slate-100', text: 'text-slate-600', icon: XCircle, label: 'Expired' },
  voided: { bg: 'bg-red-50', text: 'text-red-700', icon: AlertTriangle, label: 'Voided' },
}

const claimStatusConfig = {
  pending: { bg: 'bg-amber-50', text: 'text-amber-700', label: 'Pending' },
  approved: { bg: 'bg-emerald-50', text: 'text-emerald-700', label: 'Approved' },
  rejected: { bg: 'bg-red-50', text: 'text-red-700', label: 'Rejected' },
  completed: { bg: 'bg-blue-50', text: 'text-blue-700', label: 'Completed' },
}

export default function WarrantiesPage() {
  const [selectedWarranty, setSelectedWarranty] = useState<Warranty | null>(null)
  const [showClaimModal, setShowClaimModal] = useState(false)
  const [claimDescription, setClaimDescription] = useState('')

  const { data: warrantiesData, isLoading } = useWarranties()
  const { data: claims, isLoading: claimsLoading } = useWarrantyClaims(selectedWarranty?.id || 0)
  const submitClaim = useSubmitWarrantyClaim()

  const warranties = warrantiesData?.data || []

  const handleSubmitClaim = () => {
    if (!selectedWarranty || !claimDescription.trim()) {
      toast.error('Please describe the issue')
      return
    }

    submitClaim.mutate(
      { warrantyId: selectedWarranty.id, data: { issue_description: claimDescription } },
      {
        onSuccess: () => {
          toast.success('Warranty claim submitted successfully')
          setShowClaimModal(false)
          setClaimDescription('')
        },
        onError: () => {
          toast.error('Failed to submit claim. Please try again.')
        },
      }
    )
  }

  const getDaysRemaining = (endDate: string) => {
    const end = new Date(endDate)
    const now = new Date()
    const diff = Math.ceil((end.getTime() - now.getTime()) / (1000 * 60 * 60 * 24))
    return diff
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <Loader2 className="h-8 w-8 text-teal-500 animate-spin" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
          Warranties
        </h1>
        <p className="text-slate-500 text-sm mt-1">
          Manage your device warranties and submit claims
        </p>
      </div>

      {warranties.length === 0 ? (
        <div className="bg-white rounded-xl border border-slate-200/60 p-12 text-center">
          <div className="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <Shield className="h-8 w-8 text-slate-400" />
          </div>
          <h3 className="text-lg font-semibold text-slate-800 mb-2">No Warranties Found</h3>
          <p className="text-slate-500 max-w-md mx-auto">
            Warranties are automatically created when you register a device. Register a device to get started.
          </p>
        </div>
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          {/* Warranties List */}
          <div className="lg:col-span-2 space-y-4">
            {warranties.map((warranty) => {
              const status = statusConfig[warranty.status as keyof typeof statusConfig] || statusConfig.expired
              const StatusIcon = status.icon
              const daysRemaining = getDaysRemaining(warranty.ends_at)

              return (
                <div
                  key={warranty.id}
                  onClick={() => setSelectedWarranty(warranty)}
                  className={`bg-white rounded-xl border p-5 cursor-pointer transition-all hover:shadow-md ${
                    selectedWarranty?.id === warranty.id ? 'border-teal-500 ring-1 ring-teal-500' : 'border-slate-200/60'
                  }`}
                >
                  <div className="flex items-start justify-between">
                    <div className="flex items-start gap-4">
                      <div className="p-3 bg-gradient-to-br from-slate-100 to-slate-50 rounded-xl border border-slate-200">
                        <Smartphone className="h-6 w-6 text-slate-500" />
                      </div>
                      <div>
                        <h3 className="font-semibold text-slate-800">{warranty.device?.name || 'Device'}</h3>
                        <p className="text-sm text-slate-500 mt-0.5">
                          Warranty #{warranty.warranty_number}
                        </p>
                        <div className="flex items-center gap-3 mt-2">
                          <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${status.bg} ${status.text}`}>
                            <StatusIcon className="h-3 w-3" />
                            {status.label}
                          </span>
                          {warranty.status === 'active' && (
                            <span className="text-xs text-slate-500">
                              {daysRemaining > 0 ? `${daysRemaining} days remaining` : 'Expires today'}
                            </span>
                          )}
                        </div>
                      </div>
                    </div>
                    <ChevronRight className={`h-5 w-5 text-slate-400 transition-transform ${selectedWarranty?.id === warranty.id ? 'rotate-90' : ''}`} />
                  </div>

                  <div className="mt-4 pt-4 border-t border-slate-100 grid grid-cols-2 gap-4 text-sm">
                    <div className="flex items-center gap-2 text-slate-500">
                      <Calendar className="h-4 w-4" />
                      <span>Starts: {formatMalawiDate(warranty.starts_at)}</span>
                    </div>
                    <div className="flex items-center gap-2 text-slate-500">
                      <Calendar className="h-4 w-4" />
                      <span>Ends: {formatMalawiDate(warranty.ends_at)}</span>
                    </div>
                  </div>
                </div>
              )
            })}
          </div>

          {/* Warranty Details Sidebar */}
          <div className="space-y-4">
            {selectedWarranty ? (
              <>
                <div className="bg-white rounded-xl border border-slate-200/60 p-5">
                  <h2 className="text-base font-semibold text-slate-800 mb-4" style={{ fontFamily: 'var(--font-heading)' }}>
                    Warranty Details
                  </h2>
                  <div className="space-y-3 text-sm">
                    <div className="flex justify-between">
                      <span className="text-slate-500">Device</span>
                      <span className="text-slate-800 font-medium">{selectedWarranty.device?.name}</span>
                    </div>
                    <div className="flex justify-between">
                      <span className="text-slate-500">Warranty #</span>
                      <span className="text-slate-800 font-mono text-xs">{selectedWarranty.warranty_number}</span>
                    </div>
                    <div className="flex justify-between">
                      <span className="text-slate-500">Status</span>
                      <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${statusConfig[selectedWarranty.status as keyof typeof statusConfig]?.bg} ${statusConfig[selectedWarranty.status as keyof typeof statusConfig]?.text}`}>
                        {statusConfig[selectedWarranty.status as keyof typeof statusConfig]?.label}
                      </span>
                    </div>
                    <div className="flex justify-between">
                      <span className="text-slate-500">Coverage Period</span>
                      <span className="text-slate-800">
                        {Math.ceil((new Date(selectedWarranty.ends_at).getTime() - new Date(selectedWarranty.starts_at).getTime()) / (1000 * 60 * 60 * 24 * 30))} months
                      </span>
                    </div>
                  </div>

                  {selectedWarranty.terms && (
                    <div className="mt-4 pt-4 border-t border-slate-100">
                      <h3 className="text-sm font-medium text-slate-700 mb-2">Terms & Conditions</h3>
                      <p className="text-xs text-slate-500">{selectedWarranty.terms}</p>
                    </div>
                  )}

                  {selectedWarranty.status === 'active' && (
                    <button
                      onClick={() => setShowClaimModal(true)}
                      className="w-full mt-4 inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-teal-500 text-white rounded-lg text-sm font-medium hover:bg-teal-600 transition-colors"
                    >
                      <Plus className="h-4 w-4" />
                      Submit Claim
                    </button>
                  )}
                </div>

                {/* Claims History */}
                <div className="bg-white rounded-xl border border-slate-200/60 p-5">
                  <h2 className="text-base font-semibold text-slate-800 mb-4" style={{ fontFamily: 'var(--font-heading)' }}>
                    Claim History
                  </h2>
                  {claimsLoading ? (
                    <div className="flex justify-center py-4">
                      <Loader2 className="h-5 w-5 text-teal-500 animate-spin" />
                    </div>
                  ) : claims && claims.length > 0 ? (
                    <div className="space-y-3">
                      {claims.map((claim: WarrantyClaim) => (
                        <div key={claim.id} className="p-3 bg-slate-50 rounded-lg">
                          <div className="flex items-start justify-between mb-2">
                            <span className="text-xs font-mono text-slate-500">#{claim.claim_number}</span>
                            <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${claimStatusConfig[claim.status as keyof typeof claimStatusConfig]?.bg} ${claimStatusConfig[claim.status as keyof typeof claimStatusConfig]?.text}`}>
                              {claimStatusConfig[claim.status as keyof typeof claimStatusConfig]?.label}
                            </span>
                          </div>
                          <p className="text-sm text-slate-700 line-clamp-2">{claim.issue_description}</p>
                          <p className="text-xs text-slate-400 mt-2">
                            {formatMalawiDate(claim.created_at)}
                          </p>
                        </div>
                      ))}
                    </div>
                  ) : (
                    <div className="text-center py-6">
                      <FileText className="h-8 w-8 text-slate-300 mx-auto mb-2" />
                      <p className="text-sm text-slate-500">No claims submitted</p>
                    </div>
                  )}
                </div>
              </>
            ) : (
              <div className="bg-slate-50 rounded-xl p-6 text-center">
                <Clock className="h-8 w-8 text-slate-300 mx-auto mb-2" />
                <p className="text-sm text-slate-500">Select a warranty to view details</p>
              </div>
            )}
          </div>
        </div>
      )}

      {/* Submit Claim Modal */}
      {showClaimModal && selectedWarranty && (
        <div className="fixed inset-0 z-50 overflow-y-auto">
          <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" onClick={() => setShowClaimModal(false)} />
          <div className="relative min-h-full flex items-center justify-center p-4">
            <div className="relative bg-white rounded-xl shadow-xl max-w-md w-full">
              <div className="p-6">
                <h2 className="text-lg font-semibold text-slate-800 mb-4" style={{ fontFamily: 'var(--font-heading)' }}>
                  Submit Warranty Claim
                </h2>
                <p className="text-sm text-slate-500 mb-4">
                  Describe the issue you're experiencing with your {selectedWarranty.device?.name}.
                </p>
                <textarea
                  value={claimDescription}
                  onChange={(e) => setClaimDescription(e.target.value)}
                  placeholder="Describe the issue in detail..."
                  rows={4}
                  className="w-full px-4 py-3 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent resize-none"
                />
              </div>
              <div className="flex items-center justify-end gap-3 px-6 py-4 bg-slate-50 rounded-b-xl border-t border-slate-100">
                <button
                  onClick={() => setShowClaimModal(false)}
                  disabled={submitClaim.isPending}
                  className="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors disabled:opacity-50"
                >
                  Cancel
                </button>
                <button
                  onClick={handleSubmitClaim}
                  disabled={submitClaim.isPending || !claimDescription.trim()}
                  className="px-4 py-2 text-sm font-medium text-white bg-teal-500 rounded-lg hover:bg-teal-600 transition-colors disabled:opacity-50 inline-flex items-center gap-2"
                >
                  {submitClaim.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
                  Submit Claim
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
