import { useState } from 'react'
import { Link } from 'react-router-dom'
import {
  AlertTriangle,
  Search,
  FileText,
  MoreVertical,
  Eye,
  XCircle,
  Loader2,
  Calendar,
  MapPin,
  Smartphone,
  Filter,
  Shield,
  CheckCircle,
  Clock,
} from 'lucide-react'
import { useTheftReports, useCancelTheftReport } from '@/hooks/useTheftReports'
import type { TheftReport, TheftReportStatusValue } from '@/types'
import CreateTheftReportModal from './CreateTheftReportModal'

const statusConfig: Record<TheftReportStatusValue, { bg: string; text: string; icon: typeof AlertTriangle; label: string }> = {
  reported: { bg: 'bg-red-50', text: 'text-red-700', icon: AlertTriangle, label: 'Reported' },
  investigating: { bg: 'bg-amber-50', text: 'text-amber-700', icon: Search, label: 'Investigating' },
  recovered: { bg: 'bg-emerald-50', text: 'text-emerald-700', icon: CheckCircle, label: 'Recovered' },
  closed: { bg: 'bg-slate-100', text: 'text-slate-600', icon: XCircle, label: 'Closed' },
  false_alarm: { bg: 'bg-blue-50', text: 'text-blue-700', icon: Shield, label: 'False Alarm' },
}

export default function TheftReportsPage() {
  const [showCreateModal, setShowCreateModal] = useState(false)
  const [statusFilter, setStatusFilter] = useState<TheftReportStatusValue | ''>('')
  const [page, setPage] = useState(1)
  const [menuOpen, setMenuOpen] = useState<number | null>(null)

  const { data: reportsData, isLoading, error } = useTheftReports({
    page,
    per_page: 10,
    status: statusFilter || undefined,
  })
  const cancelReport = useCancelTheftReport()

  const reports = reportsData?.data || []
  const meta = reportsData?.meta

  const handleCancel = (id: number) => {
    if (confirm('Are you sure you want to cancel this theft report?')) {
      cancelReport.mutate(id)
    }
    setMenuOpen(null)
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            Theft Reports
          </h1>
          <p className="text-sm text-slate-500 mt-1">
            Report and track stolen devices
          </p>
        </div>
        <button
          onClick={() => setShowCreateModal(true)}
          className="inline-flex items-center gap-2 px-4 py-2.5 bg-red-500 text-white rounded-xl text-sm font-medium hover:bg-red-600 transition-colors shadow-sm"
        >
          <AlertTriangle className="h-4 w-4" />
          Report Theft
        </button>
      </div>

      {/* Filters */}
      <div className="bg-white rounded-xl border border-slate-200/60 p-4">
        <div className="flex items-center gap-3">
          <Filter className="h-4 w-4 text-slate-400" />
          <span className="text-sm text-slate-600">Filter by status:</span>
          <div className="flex flex-wrap gap-2">
            <button
              onClick={() => setStatusFilter('')}
              className={`px-3 py-1.5 rounded-lg text-sm font-medium transition-colors ${
                statusFilter === ''
                  ? 'bg-slate-900 text-white'
                  : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
              }`}
            >
              All
            </button>
            {(Object.entries(statusConfig) as [TheftReportStatusValue, typeof statusConfig['reported']][]).map(
              ([status, config]) => (
                <button
                  key={status}
                  onClick={() => setStatusFilter(status)}
                  className={`px-3 py-1.5 rounded-lg text-sm font-medium transition-colors ${
                    statusFilter === status
                      ? 'bg-slate-900 text-white'
                      : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                  }`}
                >
                  {config.label}
                </button>
              )
            )}
          </div>
        </div>
      </div>

      {/* Reports List */}
      <div className="bg-white rounded-xl border border-slate-200/60 overflow-hidden">
        {isLoading ? (
          <div className="flex items-center justify-center py-20">
            <Loader2 className="h-8 w-8 text-teal-500 animate-spin" />
          </div>
        ) : error ? (
          <div className="flex flex-col items-center justify-center py-20 text-center">
            <AlertTriangle className="h-10 w-10 text-red-400 mb-3" />
            <p className="text-slate-600">Failed to load theft reports</p>
            <p className="text-sm text-slate-400">Please try again later</p>
          </div>
        ) : reports.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-20 text-center">
            <div className="p-4 bg-emerald-50 rounded-full mb-4">
              <Shield className="h-10 w-10 text-emerald-400" />
            </div>
            <h3 className="text-lg font-medium text-slate-800 mb-1">No theft reports</h3>
            <p className="text-sm text-slate-500 mb-6 max-w-sm">
              {statusFilter
                ? 'No reports match your filter.'
                : 'Great news! You have no theft reports. We hope it stays that way.'}
            </p>
          </div>
        ) : (
          <>
            <div className="divide-y divide-slate-100">
              {reports.map((report) => (
                <TheftReportItem
                  key={report.id}
                  report={report}
                  menuOpen={menuOpen === report.id}
                  onMenuToggle={() => setMenuOpen(menuOpen === report.id ? null : report.id)}
                  onMenuClose={() => setMenuOpen(null)}
                  onCancel={() => handleCancel(report.id)}
                />
              ))}
            </div>

            {/* Pagination */}
            {meta && meta.last_page > 1 && (
              <div className="flex items-center justify-between px-4 py-3 border-t border-slate-100">
                <p className="text-sm text-slate-500">
                  Showing {meta.from} to {meta.to} of {meta.total} reports
                </p>
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                    disabled={page === 1}
                    className="px-3 py-1.5 rounded-lg border border-slate-200 text-sm hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    Previous
                  </button>
                  <span className="text-sm text-slate-600">
                    Page {page} of {meta.last_page}
                  </span>
                  <button
                    onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                    disabled={page === meta.last_page}
                    className="px-3 py-1.5 rounded-lg border border-slate-200 text-sm hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    Next
                  </button>
                </div>
              </div>
            )}
          </>
        )}
      </div>

      {/* Create Modal */}
      <CreateTheftReportModal
        isOpen={showCreateModal}
        onClose={() => setShowCreateModal(false)}
      />
    </div>
  )
}

function TheftReportItem({
  report,
  menuOpen,
  onMenuToggle,
  onMenuClose,
  onCancel,
}: {
  report: TheftReport
  menuOpen: boolean
  onMenuToggle: () => void
  onMenuClose: () => void
  onCancel: () => void
}) {
  const config = statusConfig[report.status.value]
  const StatusIcon = config.icon

  return (
    <div className="flex items-start gap-4 p-4 hover:bg-slate-50/50 transition-colors">
      {/* Status Icon */}
      <div className={`p-3 rounded-xl ${config.bg}`}>
        <StatusIcon className={`h-5 w-5 ${config.text}`} />
      </div>

      {/* Content */}
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-3 mb-1">
          <span className="font-mono text-sm text-slate-600">
            #{report.report_number}
          </span>
          <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${config.bg} ${config.text}`}>
            {config.label}
          </span>
        </div>

        {report.device && (
          <Link
            to={`/devices/${report.device_id}`}
            className="flex items-center gap-2 text-sm text-slate-800 font-medium hover:text-teal-600 mb-2"
          >
            <Smartphone className="h-4 w-4 text-slate-400" />
            {report.device.name}
            <span className="text-slate-400 font-normal">
              ({report.device.brand} {report.device.model})
            </span>
          </Link>
        )}

        <div className="flex flex-wrap items-center gap-4 text-sm text-slate-500">
          <span className="flex items-center gap-1">
            <Calendar className="h-3.5 w-3.5" />
            Theft date: {new Date(report.theft_date).toLocaleDateString()}
          </span>
          {report.theft_location && (
            <span className="flex items-center gap-1">
              <MapPin className="h-3.5 w-3.5" />
              {report.theft_location}
            </span>
          )}
          <span className="flex items-center gap-1">
            <Clock className="h-3.5 w-3.5" />
            Reported: {new Date(report.created_at).toLocaleDateString()}
          </span>
        </div>

        {report.description && (
          <p className="text-sm text-slate-600 mt-2 line-clamp-2">
            {report.description}
          </p>
        )}

        {report.police_report_number && (
          <div className="flex items-center gap-2 mt-2 text-xs text-slate-500">
            <FileText className="h-3.5 w-3.5" />
            Police Report: {report.police_report_number}
            {report.police_station && ` (${report.police_station})`}
          </div>
        )}
      </div>

      {/* Actions */}
      <div className="relative">
        <button
          onClick={onMenuToggle}
          className="p-2 hover:bg-slate-100 rounded-lg transition-colors"
        >
          <MoreVertical className="h-4 w-4 text-slate-500" />
        </button>
        {menuOpen && (
          <>
            <div className="fixed inset-0 z-10" onClick={onMenuClose} />
            <div className="absolute right-0 mt-1 w-40 bg-white border border-slate-200 rounded-lg shadow-lg z-20 py-1">
              <Link
                to={`/theft-reports/${report.id}`}
                className="flex items-center gap-2 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
                onClick={onMenuClose}
              >
                <Eye className="h-4 w-4" />
                View Details
              </Link>
              {(report.status.value === 'reported' || report.status.value === 'investigating') && (
                <button
                  onClick={onCancel}
                  className="flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 w-full"
                >
                  <XCircle className="h-4 w-4" />
                  Cancel Report
                </button>
              )}
            </div>
          </>
        )}
      </div>
    </div>
  )
}
