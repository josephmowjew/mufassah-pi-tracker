import { formatMalawiDate } from '@/lib/datetime'
import React, { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Search, AlertTriangle, MapPin, User, Smartphone, Loader2, Eye, UserCheck, CheckCircle, Clock, XCircle } from 'lucide-react'
import { Authorize } from '@/components/auth/Authorize'
import { PERMISSIONS } from '@/hooks/usePermission'
import apiClient from '@/api/client'
import type { TheftReport } from '@/types'

interface TheftReportsResponse {
  data: TheftReport[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

const STATUSES = [
  { value: 'reported', label: 'Reported' },
  { value: 'investigating', label: 'Investigating' },
  { value: 'recovered', label: 'Recovered' },
  { value: 'closed', label: 'Closed' },
  { value: 'false_alarm', label: 'False Alarm' },
]

export default function AdminTheftReportsPage() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [selectedReport, setSelectedReport] = useState<TheftReport | null>(null)
  const [statusModalReport, setStatusModalReport] = useState<TheftReport | null>(null)
  const [newStatus, setNewStatus] = useState('')
  const [statusNote, setStatusNote] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['admin-theft-reports', search, status, page],
    queryFn: async () => {
      const params = new URLSearchParams()
      params.append('page', page.toString())
      params.append('per_page', '15')
      if (search) params.append('search', search)
      if (status) params.append('status', status)

      const response = await apiClient.get<{ success: boolean; data: TheftReport[]; meta: TheftReportsResponse['meta'] }>(
        `/theft-reports?${params.toString()}`
      )
      return response.data
    },
  })

  const updateStatusMutation = useMutation({
    mutationFn: async ({ reportId, status, notes }: { reportId: number; status: string; notes?: string }) => {
      await apiClient.post(`/theft-reports/${reportId}/status`, { status, notes })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-theft-reports'] })
      setStatusModalReport(null)
      setNewStatus('')
      setStatusNote('')
    },
  })

  const reports = data?.data || []
  const meta = data?.meta

  const getStatusBadge = (reportStatus: string | { value: string; label?: string } | null | undefined) => {
    // Extract status value - handle both string and object formats
    const statusValue = reportStatus
      ? (typeof reportStatus === 'string' ? reportStatus : reportStatus.value)
      : 'reported'

    const statusConfig: Record<string, { bg: string; text: string; icon: React.ReactNode }> = {
      reported: { bg: 'bg-red-50', text: 'text-red-700', icon: <AlertTriangle className="h-3.5 w-3.5" /> },
      investigating: { bg: 'bg-amber-50', text: 'text-amber-700', icon: <Clock className="h-3.5 w-3.5" /> },
      recovered: { bg: 'bg-emerald-50', text: 'text-emerald-700', icon: <CheckCircle className="h-3.5 w-3.5" /> },
      closed: { bg: 'bg-slate-100', text: 'text-slate-600', icon: <XCircle className="h-3.5 w-3.5" /> },
      false_alarm: { bg: 'bg-blue-50', text: 'text-blue-700', icon: <CheckCircle className="h-3.5 w-3.5" /> },
    }
    const config = statusConfig[statusValue] || statusConfig.reported
    return (
      <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${config.bg} ${config.text}`}>
        {config.icon}
        {statusValue.replace('_', ' ')}
      </span>
    )
  }

  const handleUpdateStatus = () => {
    if (!statusModalReport || !newStatus) return
    updateStatusMutation.mutate({
      reportId: statusModalReport.id,
      status: newStatus,
      notes: statusNote || undefined,
    })
  }

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            Theft Reports
          </h1>
          <p className="text-slate-500 text-sm mt-1">Monitor and manage theft investigations</p>
        </div>
        <div className="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-600 text-sm font-medium">
          {meta?.total || 0} total
        </div>
      </div>

      {/* Main Card */}
      <div className="bg-white rounded-xl border border-slate-200/60 shadow-sm">
        {/* Filters */}
        <div className="p-4 border-b border-slate-200/60">
          <div className="flex flex-wrap items-center gap-4">
            <div className="relative flex-1 min-w-[200px] max-w-md">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
              <input
                type="text"
                placeholder="Search by location or police report..."
                className="w-full pl-10 pr-4 py-2.5 rounded-lg border border-slate-200 bg-slate-50/50 text-sm placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors"
                value={search}
                onChange={(e) => {
                  setSearch(e.target.value)
                  setPage(1)
                }}
              />
            </div>
            <select
              className="px-4 py-2.5 rounded-lg border border-slate-200 bg-slate-50/50 text-sm text-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors"
              value={status}
              onChange={(e) => {
                setStatus(e.target.value)
                setPage(1)
              }}
            >
              <option value="">All Status</option>
              <option value="reported">Reported</option>
              <option value="investigating">Investigating</option>
              <option value="recovered">Recovered</option>
              <option value="closed">Closed</option>
              <option value="false_alarm">False Alarm</option>
            </select>
          </div>
        </div>

        {/* Content */}
        {isLoading ? (
          <div className="flex items-center justify-center py-16">
            <Loader2 className="h-8 w-8 animate-spin text-blue-500" />
          </div>
        ) : reports.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-16">
            <div className="h-12 w-12 rounded-xl bg-slate-100 flex items-center justify-center mb-3">
              <AlertTriangle className="h-6 w-6 text-slate-400" />
            </div>
            <p className="text-slate-500 text-sm">No theft reports found</p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-slate-200/60">
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Device</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Reporter</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Location</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                    <th className="text-right py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {reports.map((report) => (
                    <tr key={report.id} className="hover:bg-slate-50/50 transition-colors">
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-3">
                          <div className="h-10 w-10 rounded-xl bg-gradient-to-br from-red-100 to-red-200 flex items-center justify-center flex-shrink-0">
                            <Smartphone className="h-5 w-5 text-red-600" />
                          </div>
                          <div className="min-w-0">
                            <p className="font-medium text-slate-800">
                              {report.device?.brand} {report.device?.model}
                            </p>
                            <p className="text-xs text-slate-500 font-mono">
                              {report.device?.serial_number || report.report_number}
                            </p>
                          </div>
                        </div>
                      </td>
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-2 text-slate-600">
                          <User className="h-4 w-4 text-slate-400" />
                          <span className="text-sm">{report.reporter?.name || 'Unknown'}</span>
                        </div>
                      </td>
                      <td className="py-3 px-4">
                        {getStatusBadge(report.status)}
                      </td>
                      <td className="py-3 px-4">
                        {report.theft_location ? (
                          <div className="flex items-center gap-2 text-sm text-slate-500">
                            <MapPin className="h-4 w-4 text-slate-400 flex-shrink-0" />
                            <span className="max-w-[180px] truncate">{report.theft_location}</span>
                          </div>
                        ) : (
                          <span className="text-sm text-slate-400">Not specified</span>
                        )}
                      </td>
                      <td className="py-3 px-4 text-sm text-slate-500">
                        {formatMalawiDate(report.theft_date)}
                      </td>
                      <td className="py-3 px-4">
                        <div className="flex items-center justify-end gap-1">
                          <Authorize permission={PERMISSIONS.VIEW_THEFT_REPORTS}>
                            <button
                              onClick={() => setSelectedReport(report)}
                              className="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                              title="View details"
                            >
                              <Eye className="h-4 w-4" />
                            </button>
                          </Authorize>
                          <Authorize permission={PERMISSIONS.MANAGE_THEFT_INVESTIGATIONS}>
                            <button
                              onClick={() => {
                                setStatusModalReport(report)
                                setNewStatus(report.status.value)
                              }}
                              className="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                              title="Update status"
                            >
                              <UserCheck className="h-4 w-4" />
                            </button>
                          </Authorize>
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

      {/* Report Details Modal */}
      {selectedReport && (
        <>
          <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40" onClick={() => setSelectedReport(null)} />
          <div className="fixed inset-x-4 top-[5%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-lg bg-white rounded-2xl shadow-xl z-50 overflow-hidden max-h-[90vh] overflow-y-auto">
            <div className="p-5 border-b border-slate-200 sticky top-0 bg-white">
              <div className="flex items-center justify-between">
                <h3 className="text-lg font-semibold text-slate-800">Theft Report Details</h3>
                <button onClick={() => setSelectedReport(null)} className="p-1 text-slate-400 hover:text-slate-600 rounded-lg text-xl">×</button>
              </div>
            </div>
            <div className="p-5 space-y-4">
              <div className="flex items-center gap-4">
                <div className="h-14 w-14 rounded-xl bg-gradient-to-br from-red-100 to-red-200 flex items-center justify-center">
                  <Smartphone className="h-7 w-7 text-red-600" />
                </div>
                <div>
                  <p className="text-lg font-semibold text-slate-800">
                    {selectedReport.device?.brand} {selectedReport.device?.model}
                  </p>
                  <p className="text-sm text-slate-500 font-mono">{selectedReport.device?.serial_number}</p>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4 pt-2">
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-1">Status</p>
                  {getStatusBadge(selectedReport.status)}
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-1">Theft Date</p>
                  <p className="text-sm font-medium text-slate-700">
                    {formatMalawiDate(selectedReport.theft_date)}
                  </p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg col-span-2">
                  <p className="text-xs text-slate-500 mb-1">Location</p>
                  <p className="text-sm font-medium text-slate-700">{selectedReport.theft_location || 'Not specified'}</p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-1">Police Report #</p>
                  <p className="text-sm font-medium text-slate-700 font-mono">{selectedReport.police_report_number || 'Not filed'}</p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-1">Reporter</p>
                  <p className="text-sm font-medium text-slate-700">{selectedReport.reporter?.name || 'Unknown'}</p>
                </div>
              </div>

              {selectedReport.description && (
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-1">Description</p>
                  <p className="text-sm text-slate-700">{selectedReport.description}</p>
                </div>
              )}

              <div className="flex gap-2 pt-2">
                <Authorize permission={PERMISSIONS.MANAGE_THEFT_INVESTIGATIONS}>
                  <button
                    onClick={() => {
                      setSelectedReport(null)
                      setStatusModalReport(selectedReport)
                      setNewStatus(selectedReport.status.value)
                    }}
                    className="flex-1 px-4 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors"
                  >
                    Update Status
                  </button>
                </Authorize>
                <button
                  onClick={() => setSelectedReport(null)}
                  className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors"
                >
                  Close
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* Update Status Modal */}
      {statusModalReport && (
        <>
          <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40" onClick={() => setStatusModalReport(null)} />
          <div className="fixed inset-x-4 top-[10%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-md bg-white rounded-2xl shadow-xl z-50 overflow-hidden">
            <div className="p-5 border-b border-slate-200">
              <div className="flex items-center justify-between">
                <h3 className="text-lg font-semibold text-slate-800">Update Status</h3>
                <button onClick={() => setStatusModalReport(null)} className="p-1 text-slate-400 hover:text-slate-600 rounded-lg text-xl">×</button>
              </div>
            </div>
            <div className="p-5 space-y-4">
              <p className="text-sm text-slate-600">
                Update status for <strong>{statusModalReport.device?.brand} {statusModalReport.device?.model}</strong>
              </p>
              <p className="text-xs text-slate-500">
                Current status: {getStatusBadge(statusModalReport.status)}
              </p>

              <div className="space-y-2">
                {STATUSES.map((s) => (
                  <button
                    key={s.value}
                    onClick={() => setNewStatus(s.value)}
                    className={`w-full p-3 text-left rounded-lg border transition-colors ${
                      newStatus === s.value
                        ? 'border-blue-500 bg-blue-50 text-blue-700'
                        : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50'
                    }`}
                  >
                    <p className="text-sm font-medium">{s.label}</p>
                  </button>
                ))}
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1">Notes (optional)</label>
                <textarea
                  value={statusNote}
                  onChange={(e) => setStatusNote(e.target.value)}
                  placeholder="Add any notes about this status change..."
                  className="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors resize-none"
                  rows={3}
                />
              </div>

              <div className="flex gap-2">
                <button
                  onClick={() => setStatusModalReport(null)}
                  disabled={updateStatusMutation.isPending}
                  className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors disabled:opacity-50"
                >
                  Cancel
                </button>
                <button
                  onClick={handleUpdateStatus}
                  disabled={updateStatusMutation.isPending || !newStatus}
                  className="flex-1 px-4 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors disabled:opacity-50"
                >
                  {updateStatusMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin mx-auto" /> : 'Update Status'}
                </button>
              </div>
            </div>
          </div>
        </>
      )}
    </div>
  )
}
