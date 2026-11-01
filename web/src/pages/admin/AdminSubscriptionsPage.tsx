import { formatMalawiDate } from '@/lib/datetime'
import React, { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { CreditCard, Calendar, Loader2, Eye, Ban, RefreshCw, CheckCircle, XCircle, Clock, Package } from 'lucide-react'
import { Authorize } from '@/components/auth/Authorize'
import { PERMISSIONS } from '@/hooks/usePermission'
import apiClient from '@/api/client'
import type { Subscription } from '@/types'

interface SubscriptionsResponse {
  data: Subscription[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export default function AdminSubscriptionsPage() {
  const queryClient = useQueryClient()
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [selectedSubscription, setSelectedSubscription] = useState<Subscription | null>(null)
  const [confirmAction, setConfirmAction] = useState<{ type: 'suspend' | 'reactivate'; subscription: Subscription } | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['admin-subscriptions', status, page],
    queryFn: async () => {
      const params = new URLSearchParams()
      params.append('page', page.toString())
      params.append('per_page', '15')
      if (status) params.append('status', status)

      const response = await apiClient.get<{ success: boolean; data: Subscription[]; meta: SubscriptionsResponse['meta'] }>(
        `/admin/subscriptions?${params.toString()}`
      )
      return response.data
    },
  })

  const suspendMutation = useMutation({
    mutationFn: async (subscriptionId: number) => {
      await apiClient.post(`/admin/subscriptions/${subscriptionId}/suspend`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-subscriptions'] })
      setConfirmAction(null)
    },
  })

  const reactivateMutation = useMutation({
    mutationFn: async (subscriptionId: number) => {
      await apiClient.post(`/admin/subscriptions/${subscriptionId}/reactivate`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-subscriptions'] })
      setConfirmAction(null)
    },
  })

  const subscriptions = data?.data || []
  const meta = data?.meta

  // Helper to extract status value from string or object
  const getStatusValue = (status: string | { value: string } | null | undefined): string => {
    if (!status) return 'pending'
    return typeof status === 'string' ? status : status.value
  }

  const getStatusBadge = (subStatus: string | { value: string } | null | undefined) => {
    const statusValue = getStatusValue(subStatus)
    const statusConfig: Record<string, { bg: string; text: string; icon: React.ReactNode }> = {
      active: { bg: 'bg-emerald-50', text: 'text-emerald-700', icon: <CheckCircle className="h-3.5 w-3.5" /> },
      cancelled: { bg: 'bg-red-50', text: 'text-red-700', icon: <XCircle className="h-3.5 w-3.5" /> },
      expired: { bg: 'bg-slate-100', text: 'text-slate-600', icon: <Clock className="h-3.5 w-3.5" /> },
      suspended: { bg: 'bg-amber-50', text: 'text-amber-700', icon: <Ban className="h-3.5 w-3.5" /> },
      pending: { bg: 'bg-blue-50', text: 'text-blue-700', icon: <Clock className="h-3.5 w-3.5" /> },
    }
    const config = statusConfig[statusValue] || statusConfig.pending
    return (
      <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${config.bg} ${config.text}`}>
        {config.icon}
        {statusValue}
      </span>
    )
  }

  const getBillingCycleBadge = (cycle: string) => {
    return (
      <span className={`px-2.5 py-1 rounded-full text-xs font-medium ${cycle === 'yearly' ? 'bg-indigo-50 text-indigo-700' : 'bg-blue-50 text-blue-700'}`}>
        {cycle}
      </span>
    )
  }

  const handleConfirmAction = () => {
    if (!confirmAction) return

    if (confirmAction.type === 'suspend') {
      suspendMutation.mutate(confirmAction.subscription.id)
    } else {
      reactivateMutation.mutate(confirmAction.subscription.id)
    }
  }

  const isActionLoading = suspendMutation.isPending || reactivateMutation.isPending

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            Subscriptions
          </h1>
          <p className="text-slate-500 text-sm mt-1">Manage customer subscriptions and billing</p>
        </div>
        <div className="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-600 text-sm font-medium">
          {meta?.total || 0} total
        </div>
      </div>

      {/* Main Card */}
      <div className="bg-white rounded-xl border border-slate-200/60 shadow-sm">
        {/* Filters */}
        <div className="p-4 border-b border-slate-200/60">
          <div className="flex items-center gap-4">
            <select
              className="px-4 py-2.5 rounded-lg border border-slate-200 bg-slate-50/50 text-sm text-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors"
              value={status}
              onChange={(e) => {
                setStatus(e.target.value)
                setPage(1)
              }}
            >
              <option value="">All Status</option>
              <option value="active">Active</option>
              <option value="cancelled">Cancelled</option>
              <option value="expired">Expired</option>
              <option value="suspended">Suspended</option>
              <option value="pending">Pending</option>
            </select>
          </div>
        </div>

        {/* Content */}
        {isLoading ? (
          <div className="flex items-center justify-center py-16">
            <Loader2 className="h-8 w-8 animate-spin text-blue-500" />
          </div>
        ) : subscriptions.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-16">
            <div className="h-12 w-12 rounded-xl bg-slate-100 flex items-center justify-center mb-3">
              <Package className="h-6 w-6 text-slate-400" />
            </div>
            <p className="text-slate-500 text-sm">No subscriptions found</p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-slate-200/60">
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">User</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Plan</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Billing</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Expires</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Auto-Renew</th>
                    <th className="text-right py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {subscriptions.map((subscription) => (
                    <tr key={subscription.id} className="hover:bg-slate-50/50 transition-colors">
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-3">
                          <div className="h-10 w-10 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center flex-shrink-0">
                            <span className="text-white font-medium text-sm">
                              {subscription.user?.name?.charAt(0).toUpperCase() || 'U'}
                            </span>
                          </div>
                          <div className="min-w-0">
                            <p className="font-medium text-slate-800">{subscription.user?.name || 'Unknown'}</p>
                            <p className="text-xs text-slate-500 truncate">
                              {subscription.user?.email}
                            </p>
                          </div>
                        </div>
                      </td>
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-2 text-slate-600">
                          <CreditCard className="h-4 w-4 text-slate-400" />
                          <span className="text-sm font-medium">{subscription.plan?.name || 'N/A'}</span>
                        </div>
                      </td>
                      <td className="py-3 px-4">
                        {getBillingCycleBadge(subscription.billing_cycle)}
                      </td>
                      <td className="py-3 px-4">
                        {getStatusBadge(subscription.status)}
                      </td>
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                          <Calendar className="h-4 w-4 text-slate-400" />
                          {formatMalawiDate(subscription.ends_at)}
                        </div>
                      </td>
                      <td className="py-3 px-4">
                        {subscription.auto_renew ? (
                          <div className="flex items-center gap-1.5 text-emerald-600">
                            <CheckCircle className="h-4 w-4" />
                            <span className="text-xs font-medium">Yes</span>
                          </div>
                        ) : (
                          <div className="flex items-center gap-1.5 text-slate-400">
                            <XCircle className="h-4 w-4" />
                            <span className="text-xs font-medium">No</span>
                          </div>
                        )}
                      </td>
                      <td className="py-3 px-4">
                        <div className="flex items-center justify-end gap-1">
                          <Authorize permission={PERMISSIONS.VIEW_SUBSCRIPTIONS}>
                            <button
                              onClick={() => setSelectedSubscription(subscription)}
                              className="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                              title="View details"
                            >
                              <Eye className="h-4 w-4" />
                            </button>
                          </Authorize>
                          {getStatusValue(subscription.status) === 'active' && (
                            <Authorize permission={PERMISSIONS.MANAGE_SUBSCRIPTIONS}>
                              <button
                                onClick={() => setConfirmAction({ type: 'suspend', subscription })}
                                className="p-2 text-slate-400 hover:text-amber-600 hover:bg-amber-50 rounded-lg transition-colors"
                                title="Suspend subscription"
                              >
                                <Ban className="h-4 w-4" />
                              </button>
                            </Authorize>
                          )}
                          {getStatusValue(subscription.status) === 'suspended' && (
                            <Authorize permission={PERMISSIONS.MANAGE_SUBSCRIPTIONS}>
                              <button
                                onClick={() => setConfirmAction({ type: 'reactivate', subscription })}
                                className="p-2 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors"
                                title="Reactivate subscription"
                              >
                                <RefreshCw className="h-4 w-4" />
                              </button>
                            </Authorize>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Pagination */}
            {meta && meta.last_page > 1 && (
              <div className="flex items-center justify-between px-4 py-3 border-t border-slate-200/60">
                <p className="text-sm text-slate-500">
                  Page {meta.current_page} of {meta.last_page}
                </p>
                <div className="flex gap-2">
                  <button
                    className="px-3 py-1.5 text-sm font-medium text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    disabled={meta.current_page === 1}
                    onClick={() => setPage(page - 1)}
                  >
                    Previous
                  </button>
                  <button
                    className="px-3 py-1.5 text-sm font-medium text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    disabled={meta.current_page === meta.last_page}
                    onClick={() => setPage(page + 1)}
                  >
                    Next
                  </button>
                </div>
              </div>
            )}
          </>
        )}
      </div>

      {/* Subscription Details Modal */}
      {selectedSubscription && (
        <>
          <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40" onClick={() => setSelectedSubscription(null)} />
          <div className="fixed inset-x-4 top-[10%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-lg bg-white rounded-2xl shadow-xl z-50 overflow-hidden">
            <div className="p-5 border-b border-slate-200">
              <div className="flex items-center justify-between">
                <h3 className="text-lg font-semibold text-slate-800">Subscription Details</h3>
                <button onClick={() => setSelectedSubscription(null)} className="p-1 text-slate-400 hover:text-slate-600 rounded-lg">×</button>
              </div>
            </div>
            <div className="p-5 space-y-4">
              <div className="flex items-center gap-4">
                <div className="h-14 w-14 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center">
                  <span className="text-white font-semibold text-xl">
                    {selectedSubscription.user?.name?.charAt(0).toUpperCase() || 'U'}
                  </span>
                </div>
                <div>
                  <p className="text-lg font-semibold text-slate-800">{selectedSubscription.user?.name}</p>
                  <p className="text-sm text-slate-500">{selectedSubscription.user?.email}</p>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4 pt-2">
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-1">Plan</p>
                  <p className="text-sm font-medium text-slate-700">{selectedSubscription.plan?.name || 'N/A'}</p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-1">Status</p>
                  {getStatusBadge(selectedSubscription.status)}
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-1">Billing Cycle</p>
                  {getBillingCycleBadge(selectedSubscription.billing_cycle)}
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-1">Expires</p>
                  <p className="text-sm font-medium text-slate-700">
                    {formatMalawiDate(selectedSubscription.ends_at)}
                  </p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-1">Auto-Renew</p>
                  <p className="text-sm font-medium text-slate-700">
                    {selectedSubscription.auto_renew ? 'Yes' : 'No'}
                  </p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-1">Started</p>
                  <p className="text-sm font-medium text-slate-700">
                    {formatMalawiDate(selectedSubscription.starts_at)}
                  </p>
                </div>
              </div>
              <div className="flex gap-2 pt-2">
                {getStatusValue(selectedSubscription.status) === 'active' && (
                  <Authorize permission={PERMISSIONS.MANAGE_SUBSCRIPTIONS}>
                    <button
                      onClick={() => {
                        setSelectedSubscription(null)
                        setConfirmAction({ type: 'suspend', subscription: selectedSubscription })
                      }}
                      className="flex-1 px-4 py-2.5 bg-amber-600 text-white text-sm font-medium rounded-lg hover:bg-amber-700 transition-colors"
                    >
                      Suspend
                    </button>
                  </Authorize>
                )}
                {getStatusValue(selectedSubscription.status) === 'suspended' && (
                  <Authorize permission={PERMISSIONS.MANAGE_SUBSCRIPTIONS}>
                    <button
                      onClick={() => {
                        setSelectedSubscription(null)
                        setConfirmAction({ type: 'reactivate', subscription: selectedSubscription })
                      }}
                      className="flex-1 px-4 py-2.5 bg-emerald-600 text-white text-sm font-medium rounded-lg hover:bg-emerald-700 transition-colors"
                    >
                      Reactivate
                    </button>
                  </Authorize>
                )}
                <button
                  onClick={() => setSelectedSubscription(null)}
                  className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors"
                >
                  Close
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* Confirm Action Modal */}
      {confirmAction && (
        <>
          <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40" onClick={() => setConfirmAction(null)} />
          <div className="fixed inset-x-4 top-[20%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-sm bg-white rounded-2xl shadow-xl z-50 overflow-hidden">
            <div className="p-5 space-y-4">
              <div className={`h-12 w-12 rounded-full mx-auto flex items-center justify-center ${
                confirmAction.type === 'suspend' ? 'bg-amber-100' : 'bg-emerald-100'
              }`}>
                {confirmAction.type === 'suspend'
                  ? <Ban className="h-6 w-6 text-amber-600" />
                  : <RefreshCw className="h-6 w-6 text-emerald-600" />}
              </div>
              <div className="text-center">
                <h3 className="text-lg font-semibold text-slate-800">
                  {confirmAction.type === 'suspend' ? 'Suspend Subscription' : 'Reactivate Subscription'}
                </h3>
                <p className="text-sm text-slate-500 mt-1">
                  {confirmAction.type === 'suspend'
                    ? `Are you sure you want to suspend ${confirmAction.subscription.user?.name}'s subscription?`
                    : `Are you sure you want to reactivate ${confirmAction.subscription.user?.name}'s subscription?`}
                </p>
              </div>
              <div className="flex gap-2">
                <button
                  onClick={() => setConfirmAction(null)}
                  disabled={isActionLoading}
                  className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors disabled:opacity-50"
                >
                  Cancel
                </button>
                <button
                  onClick={handleConfirmAction}
                  disabled={isActionLoading}
                  className={`flex-1 px-4 py-2.5 text-white text-sm font-medium rounded-lg transition-colors disabled:opacity-50 ${
                    confirmAction.type === 'suspend' ? 'bg-amber-600 hover:bg-amber-700' : 'bg-emerald-600 hover:bg-emerald-700'
                  }`}
                >
                  {isActionLoading ? <Loader2 className="h-4 w-4 animate-spin mx-auto" /> : 'Confirm'}
                </button>
              </div>
            </div>
          </div>
        </>
      )}
    </div>
  )
}
