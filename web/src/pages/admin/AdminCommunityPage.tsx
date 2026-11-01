import { formatMalawiDate, formatMalawiDateTime } from '@/lib/datetime'
import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  Search,
  AlertTriangle,
  MessageSquare,
  DollarSign,
  Trophy,
  Loader2,
  Eye,
  Check,
  X,
  User,
  Smartphone,
  MapPin,
  Clock,
  Star,
  CreditCard,
} from 'lucide-react'
import { Authorize } from '@/components/auth/Authorize'
import { PERMISSIONS } from '@/hooks/usePermission'
import apiClient from '@/api/client'
import type { CommunityAlert, CommunityTip, BountyClaim, SuccessStory } from '@/types'

type TabType = 'alerts' | 'tips' | 'claims' | 'stories'

export default function AdminCommunityPage() {
  const [activeTab, setActiveTab] = useState<TabType>('alerts')

  const tabs = [
    { id: 'alerts' as const, label: 'Alerts', icon: AlertTriangle, permission: PERMISSIONS.VIEW_COMMUNITY_ALERTS },
    { id: 'tips' as const, label: 'Tips', icon: MessageSquare, permission: PERMISSIONS.VERIFY_TIPS },
    { id: 'claims' as const, label: 'Claims', icon: DollarSign, permission: PERMISSIONS.MANAGE_BOUNTY_CLAIMS },
    { id: 'stories' as const, label: 'Stories', icon: Trophy, permission: PERMISSIONS.MANAGE_SUCCESS_STORIES },
  ]

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            Community Management
          </h1>
          <p className="text-slate-500 text-sm mt-1">Manage community alerts, tips, claims, and stories</p>
        </div>
      </div>

      {/* Tabs */}
      <div className="bg-white rounded-xl border border-slate-200/60 p-1 inline-flex gap-1">
        {tabs.map((tab) => (
          <Authorize key={tab.id} permission={tab.permission}>
            <button
              onClick={() => setActiveTab(tab.id)}
              className={`inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                activeTab === tab.id
                  ? 'bg-slate-900 text-white'
                  : 'text-slate-600 hover:bg-slate-100'
              }`}
            >
              <tab.icon className="h-4 w-4" />
              {tab.label}
            </button>
          </Authorize>
        ))}
      </div>

      {/* Tab Content */}
      {activeTab === 'alerts' && <AlertsTab />}
      {activeTab === 'tips' && <TipsTab />}
      {activeTab === 'claims' && <ClaimsTab />}
      {activeTab === 'stories' && <StoriesTab />}
    </div>
  )
}

// ============================================================================
// Alerts Tab
// ============================================================================
function AlertsTab() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)

  const { data, isLoading } = useQuery({
    queryKey: ['admin-community-alerts', search, status, page],
    queryFn: async () => {
      const params = new URLSearchParams()
      params.append('page', page.toString())
      params.append('per_page', '15')
      if (search) params.append('search', search)
      if (status) params.append('status', status)

      const response = await apiClient.get<{
        data: CommunityAlert[]
        meta: { current_page: number; last_page: number; total: number }
      }>(`/admin/community/alerts?${params.toString()}`)
      return response.data
    },
  })

  const alerts = data?.data || []
  const meta = data?.meta

  const getStatusBadge = (statusObj: { value: string; label: string }) => {
    const colors: Record<string, string> = {
      active: 'bg-red-50 text-red-700',
      paused: 'bg-amber-50 text-amber-700',
      resolved: 'bg-emerald-50 text-emerald-700',
      expired: 'bg-slate-100 text-slate-600',
    }
    return (
      <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium ${colors[statusObj.value] || colors.active}`}>
        {statusObj.label}
      </span>
    )
  }

  return (
    <div className="bg-white rounded-xl border border-slate-200/60 shadow-sm">
      {/* Filters */}
      <div className="p-4 border-b border-slate-200/60">
        <div className="flex flex-wrap items-center gap-4">
          <div className="relative flex-1 min-w-[200px] max-w-md">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
            <input
              type="text"
              placeholder="Search alerts..."
              className="w-full pl-10 pr-4 py-2.5 rounded-lg border border-slate-200 bg-slate-50/50 text-sm"
              value={search}
              onChange={(e) => { setSearch(e.target.value); setPage(1) }}
            />
          </div>
          <select
            className="px-4 py-2.5 rounded-lg border border-slate-200 bg-slate-50/50 text-sm text-slate-600"
            value={status}
            onChange={(e) => { setStatus(e.target.value); setPage(1) }}
          >
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="paused">Paused</option>
            <option value="resolved">Resolved</option>
            <option value="expired">Expired</option>
          </select>
          <div className="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-600 text-sm font-medium">
            {meta?.total || 0} total
          </div>
        </div>
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-16">
          <Loader2 className="h-8 w-8 animate-spin text-blue-500" />
        </div>
      ) : alerts.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-16">
          <AlertTriangle className="h-12 w-12 text-slate-300 mb-3" />
          <p className="text-slate-500">No community alerts found</p>
        </div>
      ) : (
        <>
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b border-slate-200/60">
                  <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase">Device</th>
                  <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase">Status</th>
                  <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase">Bounty</th>
                  <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase">Stats</th>
                  <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase">Created</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {alerts.map((alert) => (
                  <tr key={alert.id} className="hover:bg-slate-50/50 transition-colors">
                    <td className="py-3 px-4">
                      <div className="flex items-center gap-3">
                        <div className="h-10 w-10 rounded-xl bg-red-100 flex items-center justify-center">
                          <Smartphone className="h-5 w-5 text-red-600" />
                        </div>
                        <div>
                          <p className="font-medium text-slate-800">
                            {alert.theft_report?.device?.brand} {alert.theft_report?.device?.model}
                          </p>
                          <p className="text-xs text-slate-500">
                            Report #{alert.theft_report?.report_number}
                          </p>
                        </div>
                      </div>
                    </td>
                    <td className="py-3 px-4">{getStatusBadge(alert.status)}</td>
                    <td className="py-3 px-4">
                      {alert.has_bounty ? (
                        <span className="inline-flex items-center gap-1 text-emerald-600 font-medium">
                          <DollarSign className="h-4 w-4" />
                          MWK {Number(alert.bounty_amount).toLocaleString()}
                        </span>
                      ) : (
                        <span className="text-slate-400 text-sm">No bounty</span>
                      )}
                    </td>
                    <td className="py-3 px-4 text-sm text-slate-600">
                      <div className="flex items-center gap-3">
                        <span className="flex items-center gap-1">
                          <MessageSquare className="h-3.5 w-3.5 text-slate-400" />
                          {alert.tips_count}
                        </span>
                        <span className="flex items-center gap-1">
                          <Eye className="h-3.5 w-3.5 text-slate-400" />
                          {alert.view_count}
                        </span>
                      </div>
                    </td>
                    <td className="py-3 px-4 text-sm text-slate-500">
                      {formatMalawiDate(alert.created_at)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between px-4 py-3 border-t border-slate-200/60">
              <p className="text-sm text-slate-500">Page {meta.current_page} of {meta.last_page}</p>
              <div className="flex gap-2">
                <button
                  onClick={() => setPage(page - 1)}
                  disabled={page === 1}
                  className="px-3 py-1.5 text-sm font-medium text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 disabled:opacity-50"
                >
                  Previous
                </button>
                <button
                  onClick={() => setPage(page + 1)}
                  disabled={page === meta.last_page}
                  className="px-3 py-1.5 text-sm font-medium text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 disabled:opacity-50"
                >
                  Next
                </button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}

// ============================================================================
// Tips Tab
// ============================================================================
function TipsTab() {
  const queryClient = useQueryClient()
  const [status, setStatus] = useState('pending')
  const [page, setPage] = useState(1)

  const { data, isLoading } = useQuery({
    queryKey: ['admin-community-tips', status, page],
    queryFn: async () => {
      const params = new URLSearchParams()
      params.append('page', page.toString())
      params.append('per_page', '15')
      if (status) params.append('status', status)

      const response = await apiClient.get<{
        data: CommunityTip[]
        meta: { current_page: number; last_page: number; total: number }
      }>(`/admin/community/tips?${params.toString()}`)
      return response.data
    },
  })

  const verifyTip = useMutation({
    mutationFn: async (tipId: number) => {
      await apiClient.post(`/community/tips/${tipId}/verify`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-community-tips'] })
    },
  })

  const rejectTip = useMutation({
    mutationFn: async ({ tipId, notes }: { tipId: number; notes?: string }) => {
      await apiClient.post(`/community/tips/${tipId}/reject`, { notes })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-community-tips'] })
    },
  })

  const tips = data?.data || []
  const meta = data?.meta

  const handleVerify = (tipId: number) => {
    if (confirm('Verify this tip as helpful?')) {
      verifyTip.mutate(tipId)
    }
  }

  const handleReject = (tipId: number) => {
    const notes = prompt('Rejection reason (optional):')
    rejectTip.mutate({ tipId, notes: notes || undefined })
  }

  const getStatusBadge = (statusObj: { value: string; label: string }) => {
    const colors: Record<string, string> = {
      pending: 'bg-amber-50 text-amber-700',
      reviewing: 'bg-blue-50 text-blue-700',
      verified: 'bg-emerald-50 text-emerald-700',
      false_lead: 'bg-slate-100 text-slate-600',
    }
    return (
      <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium ${colors[statusObj.value] || colors.pending}`}>
        {statusObj.label}
      </span>
    )
  }

  return (
    <div className="bg-white rounded-xl border border-slate-200/60 shadow-sm">
      {/* Filters */}
      <div className="p-4 border-b border-slate-200/60">
        <div className="flex items-center gap-4">
          <select
            className="px-4 py-2.5 rounded-lg border border-slate-200 bg-slate-50/50 text-sm text-slate-600"
            value={status}
            onChange={(e) => { setStatus(e.target.value); setPage(1) }}
          >
            <option value="">All Status</option>
            <option value="pending">Pending Review</option>
            <option value="reviewing">Reviewing</option>
            <option value="verified">Verified</option>
            <option value="false_lead">False Lead</option>
          </select>
          <div className="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-600 text-sm font-medium">
            {meta?.total || 0} total
          </div>
        </div>
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-16">
          <Loader2 className="h-8 w-8 animate-spin text-blue-500" />
        </div>
      ) : tips.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-16">
          <MessageSquare className="h-12 w-12 text-slate-300 mb-3" />
          <p className="text-slate-500">No tips found</p>
        </div>
      ) : (
        <div className="divide-y divide-slate-100">
          {tips.map((tip) => (
            <div key={tip.id} className="p-4 hover:bg-slate-50/50 transition-colors">
              <div className="flex items-start justify-between gap-4">
                <div className="flex items-start gap-3">
                  <div className="p-2 bg-slate-100 rounded-full">
                    <User className="h-4 w-4 text-slate-500" />
                  </div>
                  <div>
                    <div className="flex items-center gap-2 mb-1">
                      <span className="font-medium text-slate-800">{tip.tipster_display_name}</span>
                      {getStatusBadge(tip.status)}
                      <span className="text-xs text-slate-400 px-2 py-0.5 bg-slate-100 rounded">
                        {tip.tip_type}
                      </span>
                    </div>
                    <p className="text-sm text-slate-600 mb-2">{tip.description}</p>
                    {tip.spotted_address && (
                      <p className="flex items-center gap-1 text-xs text-slate-400 mb-1">
                        <MapPin className="h-3 w-3" />
                        {tip.spotted_address}
                      </p>
                    )}
                    <p className="text-xs text-slate-400">
                      <Clock className="inline h-3 w-3 mr-1" />
                      {formatMalawiDateTime(tip.created_at)}
                    </p>
                  </div>
                </div>

                {tip.status.value === 'pending' && (
                  <div className="flex items-center gap-2">
                    <Authorize permission={PERMISSIONS.VERIFY_TIPS}>
                      <button
                        onClick={() => handleVerify(tip.id)}
                        disabled={verifyTip.isPending}
                        className="p-2 text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors"
                        title="Verify tip"
                      >
                        <Check className="h-4 w-4" />
                      </button>
                      <button
                        onClick={() => handleReject(tip.id)}
                        disabled={rejectTip.isPending}
                        className="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                        title="Reject tip"
                      >
                        <X className="h-4 w-4" />
                      </button>
                    </Authorize>
                  </div>
                )}
              </div>
            </div>
          ))}
        </div>
      )}

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between px-4 py-3 border-t border-slate-200/60">
          <p className="text-sm text-slate-500">Page {meta.current_page} of {meta.last_page}</p>
          <div className="flex gap-2">
            <button
              onClick={() => setPage(page - 1)}
              disabled={page === 1}
              className="px-3 py-1.5 text-sm font-medium text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 disabled:opacity-50"
            >
              Previous
            </button>
            <button
              onClick={() => setPage(page + 1)}
              disabled={page === meta.last_page}
              className="px-3 py-1.5 text-sm font-medium text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 disabled:opacity-50"
            >
              Next
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

// ============================================================================
// Claims Tab
// ============================================================================
function ClaimsTab() {
  const queryClient = useQueryClient()
  const [status, setStatus] = useState('pending')
  const [page, setPage] = useState(1)
  const [paymentModal, setPaymentModal] = useState<BountyClaim | null>(null)
  const [paymentRef, setPaymentRef] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['admin-community-claims', status, page],
    queryFn: async () => {
      const params = new URLSearchParams()
      params.append('page', page.toString())
      params.append('per_page', '15')
      if (status) params.append('status', status)

      const response = await apiClient.get<{
        data: BountyClaim[]
        meta: { current_page: number; last_page: number; total: number }
      }>(`/admin/community/claims?${params.toString()}`)
      return response.data
    },
  })

  const approveClaim = useMutation({
    mutationFn: async ({ claimId, approved_amount }: { claimId: number; approved_amount: number }) => {
      await apiClient.post(`/community/claims/${claimId}/approve`, { approved_amount })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-community-claims'] })
    },
  })

  const rejectClaim = useMutation({
    mutationFn: async ({ claimId, reason }: { claimId: number; reason: string }) => {
      await apiClient.post(`/community/claims/${claimId}/reject`, { reason })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-community-claims'] })
    },
  })

  const markPaid = useMutation({
    mutationFn: async ({ claimId, payment_reference }: { claimId: number; payment_reference: string }) => {
      await apiClient.post(`/community/claims/${claimId}/paid`, { payment_reference })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-community-claims'] })
      setPaymentModal(null)
      setPaymentRef('')
    },
  })

  const claims = data?.data || []
  const meta = data?.meta

  const handleApprove = (claim: BountyClaim) => {
    const amount = prompt(`Approve amount (requested: MWK ${claim.claim_amount}):`, String(claim.claim_amount))
    if (amount && !isNaN(Number(amount))) {
      approveClaim.mutate({ claimId: claim.id, approved_amount: Number(amount) })
    }
  }

  const handleReject = (claimId: number) => {
    const reason = prompt('Rejection reason:')
    if (reason) {
      rejectClaim.mutate({ claimId, reason })
    }
  }

  const getStatusBadge = (statusObj: { value: string; label: string }) => {
    const colors: Record<string, string> = {
      pending: 'bg-amber-50 text-amber-700',
      approved: 'bg-blue-50 text-blue-700',
      rejected: 'bg-red-50 text-red-700',
      paid: 'bg-emerald-50 text-emerald-700',
    }
    return (
      <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium ${colors[statusObj.value] || colors.pending}`}>
        {statusObj.label}
      </span>
    )
  }

  return (
    <div className="bg-white rounded-xl border border-slate-200/60 shadow-sm">
      {/* Filters */}
      <div className="p-4 border-b border-slate-200/60">
        <div className="flex items-center gap-4">
          <select
            className="px-4 py-2.5 rounded-lg border border-slate-200 bg-slate-50/50 text-sm text-slate-600"
            value={status}
            onChange={(e) => { setStatus(e.target.value); setPage(1) }}
          >
            <option value="">All Status</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
            <option value="paid">Paid</option>
          </select>
          <div className="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-600 text-sm font-medium">
            {meta?.total || 0} total
          </div>
        </div>
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-16">
          <Loader2 className="h-8 w-8 animate-spin text-blue-500" />
        </div>
      ) : claims.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-16">
          <DollarSign className="h-12 w-12 text-slate-300 mb-3" />
          <p className="text-slate-500">No bounty claims found</p>
        </div>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="border-b border-slate-200/60">
                <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase">Claimant</th>
                <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase">Type</th>
                <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase">Amount</th>
                <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase">Status</th>
                <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase">Date</th>
                <th className="text-right py-3 px-4 text-xs font-semibold text-slate-500 uppercase">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {claims.map((claim) => (
                <tr key={claim.id} className="hover:bg-slate-50/50 transition-colors">
                  <td className="py-3 px-4">
                    <div className="flex items-center gap-2">
                      <User className="h-4 w-4 text-slate-400" />
                      <span className="text-sm text-slate-700">{claim.claimant?.name || 'Unknown'}</span>
                    </div>
                  </td>
                  <td className="py-3 px-4">
                    <span className="text-xs px-2 py-0.5 bg-slate-100 text-slate-600 rounded">
                      {claim.claim_type.replace('_', ' ')}
                    </span>
                  </td>
                  <td className="py-3 px-4">
                    <div className="text-sm">
                      <p className="font-medium text-slate-800">MWK {claim.claim_amount.toLocaleString()}</p>
                      {claim.approved_amount && (
                        <p className="text-xs text-emerald-600">
                          Approved: MWK {claim.approved_amount.toLocaleString()}
                        </p>
                      )}
                    </div>
                  </td>
                  <td className="py-3 px-4">{getStatusBadge(claim.status)}</td>
                  <td className="py-3 px-4 text-sm text-slate-500">
                    {formatMalawiDate(claim.created_at)}
                  </td>
                  <td className="py-3 px-4">
                    <div className="flex items-center justify-end gap-1">
                      <Authorize permission={PERMISSIONS.MANAGE_BOUNTY_CLAIMS}>
                        {claim.status.value === 'pending' && (
                          <>
                            <button
                              onClick={() => handleApprove(claim)}
                              disabled={approveClaim.isPending}
                              className="p-2 text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors"
                              title="Approve claim"
                            >
                              <Check className="h-4 w-4" />
                            </button>
                            <button
                              onClick={() => handleReject(claim.id)}
                              disabled={rejectClaim.isPending}
                              className="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                              title="Reject claim"
                            >
                              <X className="h-4 w-4" />
                            </button>
                          </>
                        )}
                        {claim.status.value === 'approved' && (
                          <button
                            onClick={() => setPaymentModal(claim)}
                            className="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                            title="Mark as paid"
                          >
                            <CreditCard className="h-4 w-4" />
                          </button>
                        )}
                      </Authorize>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between px-4 py-3 border-t border-slate-200/60">
          <p className="text-sm text-slate-500">Page {meta.current_page} of {meta.last_page}</p>
          <div className="flex gap-2">
            <button
              onClick={() => setPage(page - 1)}
              disabled={page === 1}
              className="px-3 py-1.5 text-sm font-medium text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 disabled:opacity-50"
            >
              Previous
            </button>
            <button
              onClick={() => setPage(page + 1)}
              disabled={page === meta.last_page}
              className="px-3 py-1.5 text-sm font-medium text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 disabled:opacity-50"
            >
              Next
            </button>
          </div>
        </div>
      )}

      {/* Payment Modal */}
      {paymentModal && (
        <>
          <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40" onClick={() => setPaymentModal(null)} />
          <div className="fixed inset-x-4 top-[10%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-md bg-white rounded-2xl shadow-xl z-50 overflow-hidden">
            <div className="p-5 border-b border-slate-200">
              <div className="flex items-center justify-between">
                <h3 className="text-lg font-semibold text-slate-800">Mark Payment Complete</h3>
                <button onClick={() => setPaymentModal(null)} className="p-1 text-slate-400 hover:text-slate-600 text-xl">×</button>
              </div>
            </div>
            <div className="p-5 space-y-4">
              <div className="p-3 bg-slate-50 rounded-lg">
                <p className="text-sm text-slate-600">
                  Paying <strong>{paymentModal.claimant?.name}</strong> via{' '}
                  <strong>{paymentModal.payment_method}</strong>
                </p>
                <p className="text-lg font-bold text-slate-800 mt-1">
                  MWK {(paymentModal.approved_amount || paymentModal.claim_amount).toLocaleString()}
                </p>
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1">Payment Reference</label>
                <input
                  type="text"
                  value={paymentRef}
                  onChange={(e) => setPaymentRef(e.target.value)}
                  placeholder="Transaction ID or reference number"
                  className="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
                />
              </div>

              <div className="flex gap-2">
                <button
                  onClick={() => setPaymentModal(null)}
                  className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200"
                >
                  Cancel
                </button>
                <button
                  onClick={() => markPaid.mutate({ claimId: paymentModal.id, payment_reference: paymentRef })}
                  disabled={!paymentRef || markPaid.isPending}
                  className="flex-1 px-4 py-2.5 bg-emerald-600 text-white text-sm font-medium rounded-lg hover:bg-emerald-700 disabled:opacity-50"
                >
                  {markPaid.isPending ? <Loader2 className="h-4 w-4 animate-spin mx-auto" /> : 'Confirm Payment'}
                </button>
              </div>
            </div>
          </div>
        </>
      )}
    </div>
  )
}

// ============================================================================
// Stories Tab
// ============================================================================
function StoriesTab() {
  const queryClient = useQueryClient()
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)

  const { data, isLoading } = useQuery({
    queryKey: ['admin-community-stories', status, page],
    queryFn: async () => {
      const params = new URLSearchParams()
      params.append('page', page.toString())
      params.append('per_page', '15')
      if (status === 'pending') params.append('published', 'false')
      if (status === 'featured') params.append('featured', 'true')

      const response = await apiClient.get<{
        data: SuccessStory[]
        meta: { current_page: number; last_page: number; total: number }
      }>(`/admin/community/stories?${params.toString()}`)
      return response.data
    },
  })

  const toggleFeature = useMutation({
    mutationFn: async (storyId: number) => {
      await apiClient.post(`/admin/community/stories/${storyId}/feature`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-community-stories'] })
    },
  })

  const approveStory = useMutation({
    mutationFn: async (storyId: number) => {
      await apiClient.post(`/admin/community/stories/${storyId}/approve`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-community-stories'] })
    },
  })

  const stories = data?.data || []
  const meta = data?.meta

  return (
    <div className="bg-white rounded-xl border border-slate-200/60 shadow-sm">
      {/* Filters */}
      <div className="p-4 border-b border-slate-200/60">
        <div className="flex items-center gap-4">
          <select
            className="px-4 py-2.5 rounded-lg border border-slate-200 bg-slate-50/50 text-sm text-slate-600"
            value={status}
            onChange={(e) => { setStatus(e.target.value); setPage(1) }}
          >
            <option value="">All Stories</option>
            <option value="pending">Pending Approval</option>
            <option value="featured">Featured</option>
          </select>
          <div className="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-600 text-sm font-medium">
            {meta?.total || 0} total
          </div>
        </div>
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-16">
          <Loader2 className="h-8 w-8 animate-spin text-blue-500" />
        </div>
      ) : stories.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-16">
          <Trophy className="h-12 w-12 text-slate-300 mb-3" />
          <p className="text-slate-500">No success stories found</p>
        </div>
      ) : (
        <div className="divide-y divide-slate-100">
          {stories.map((story) => (
            <div key={story.id} className="p-4 hover:bg-slate-50/50 transition-colors">
              <div className="flex items-start justify-between gap-4">
                <div className="flex items-start gap-4">
                  {story.photos && story.photos.length > 0 ? (
                    <img
                      src={story.photos[0]}
                      alt={story.title}
                      className="w-16 h-16 rounded-lg object-cover"
                    />
                  ) : (
                    <div className="w-16 h-16 rounded-lg bg-amber-100 flex items-center justify-center">
                      <Trophy className="h-8 w-8 text-amber-500" />
                    </div>
                  )}
                  <div>
                    <div className="flex items-center gap-2 mb-1">
                      <h3 className="font-medium text-slate-800">{story.title}</h3>
                      {story.is_featured && (
                        <Star className="h-4 w-4 text-amber-500 fill-amber-500" />
                      )}
                      {story.is_pending && (
                        <span className="px-2 py-0.5 bg-amber-50 text-amber-700 text-xs rounded-full">
                          Pending
                        </span>
                      )}
                    </div>
                    <p className="text-sm text-slate-600 line-clamp-2 mb-2">{story.story}</p>
                    <div className="flex items-center gap-4 text-xs text-slate-500">
                      <span className="flex items-center gap-1">
                        <User className="h-3 w-3" />
                        {story.author?.name || 'Anonymous'}
                      </span>
                      <span className="flex items-center gap-1">
                        <Eye className="h-3 w-3" />
                        {story.view_count} views
                      </span>
                      <span className="flex items-center gap-1">
                        ❤️ {story.likes_count}
                      </span>
                    </div>
                  </div>
                </div>

                <div className="flex items-center gap-2">
                  <Authorize permission={PERMISSIONS.MANAGE_SUCCESS_STORIES}>
                    {story.is_pending && (
                      <button
                        onClick={() => approveStory.mutate(story.id)}
                        disabled={approveStory.isPending}
                        className="px-3 py-1.5 bg-emerald-500 text-white text-sm font-medium rounded-lg hover:bg-emerald-600"
                      >
                        Approve
                      </button>
                    )}
                    <button
                      onClick={() => toggleFeature.mutate(story.id)}
                      disabled={toggleFeature.isPending}
                      className={`p-2 rounded-lg transition-colors ${
                        story.is_featured
                          ? 'bg-amber-100 text-amber-600'
                          : 'bg-slate-100 text-slate-500 hover:bg-amber-50 hover:text-amber-600'
                      }`}
                      title={story.is_featured ? 'Unfeature' : 'Feature'}
                    >
                      <Star className={`h-4 w-4 ${story.is_featured ? 'fill-amber-500' : ''}`} />
                    </button>
                  </Authorize>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between px-4 py-3 border-t border-slate-200/60">
          <p className="text-sm text-slate-500">Page {meta.current_page} of {meta.last_page}</p>
          <div className="flex gap-2">
            <button
              onClick={() => setPage(page - 1)}
              disabled={page === 1}
              className="px-3 py-1.5 text-sm font-medium text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 disabled:opacity-50"
            >
              Previous
            </button>
            <button
              onClick={() => setPage(page + 1)}
              disabled={page === meta.last_page}
              className="px-3 py-1.5 text-sm font-medium text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 disabled:opacity-50"
            >
              Next
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
