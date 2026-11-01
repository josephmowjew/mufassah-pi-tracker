import { formatMalawiDate } from '@/lib/datetime'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import {
  AlertTriangle,
  Loader2,
  Eye,
  MessageSquare,
  DollarSign,
  Clock,
  Smartphone,
  Share2,
  Plus,
  Pause,
  Play,
  CheckCircle,
  MoreVertical,
  ChevronLeft,
} from 'lucide-react'
import {
  useMyAlerts,
  usePauseAlert,
  useResumeAlert,
  useResolveAlert,
  useSetBounty,
} from '@/hooks/useCommunity'
import type { CommunityAlert, CommunityAlertStatusValue } from '@/types'

const statusConfig: Record<CommunityAlertStatusValue, { bg: string; text: string; label: string }> = {
  active: { bg: 'bg-red-50', text: 'text-red-700', label: 'Active' },
  paused: { bg: 'bg-amber-50', text: 'text-amber-700', label: 'Paused' },
  resolved: { bg: 'bg-emerald-50', text: 'text-emerald-700', label: 'Resolved' },
  expired: { bg: 'bg-slate-100', text: 'text-slate-600', label: 'Expired' },
}

export default function MyAlertsPage() {
  const [page, setPage] = useState(1)

  const { data: alertsData, isLoading, error } = useMyAlerts({ page, per_page: 10 })
  const pauseAlert = usePauseAlert()
  const resumeAlert = useResumeAlert()
  const resolveAlert = useResolveAlert()
  const setBounty = useSetBounty()

  const alerts = alertsData?.data || []
  const meta = alertsData?.meta

  const handlePause = (id: number) => pauseAlert.mutate(id)
  const handleResume = (id: number) => resumeAlert.mutate(id)
  const handleResolve = (id: number) => {
    if (confirm('Mark this device as recovered?')) {
      resolveAlert.mutate(id)
    }
  }

  const handleSetBounty = (id: number) => {
    const amount = prompt('Enter bounty amount (MWK):')
    if (amount && !isNaN(Number(amount))) {
      setBounty.mutate({ id, amount: Number(amount) })
    }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Link
          to="/community"
          className="p-2 hover:bg-slate-100 rounded-lg transition-colors"
        >
          <ChevronLeft className="h-5 w-5 text-slate-600" />
        </Link>
        <div className="flex-1">
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            My Alerts
          </h1>
          <p className="text-sm text-slate-500">
            Manage your stolen device alerts
          </p>
        </div>
        <Link
          to="/theft-reports"
          className="inline-flex items-center gap-2 px-4 py-2.5 bg-teal-500 text-white rounded-xl text-sm font-medium hover:bg-teal-600 transition-colors shadow-sm"
        >
          <Plus className="h-4 w-4" />
          Report Theft
        </Link>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white rounded-xl border border-slate-200/60 p-4">
          <p className="text-2xl font-bold text-slate-800">
            {alerts.filter(a => a.status.value === 'active').length}
          </p>
          <p className="text-xs text-slate-500">Active Alerts</p>
        </div>
        <div className="bg-white rounded-xl border border-slate-200/60 p-4">
          <p className="text-2xl font-bold text-slate-800">
            {alerts.reduce((sum, a) => sum + a.tips_count, 0)}
          </p>
          <p className="text-xs text-slate-500">Total Tips</p>
        </div>
        <div className="bg-white rounded-xl border border-slate-200/60 p-4">
          <p className="text-2xl font-bold text-slate-800">
            {alerts.filter(a => a.has_bounty).length}
          </p>
          <p className="text-xs text-slate-500">With Bounty</p>
        </div>
        <div className="bg-white rounded-xl border border-slate-200/60 p-4">
          <p className="text-2xl font-bold text-emerald-600">
            {alerts.filter(a => a.status.value === 'resolved').length}
          </p>
          <p className="text-xs text-slate-500">Recovered</p>
        </div>
      </div>

      {/* Alerts List */}
      {isLoading ? (
        <div className="flex items-center justify-center py-20">
          <Loader2 className="h-8 w-8 text-teal-500 animate-spin" />
        </div>
      ) : error ? (
        <div className="bg-white rounded-xl border border-slate-200/60 flex flex-col items-center justify-center py-20 text-center">
          <AlertTriangle className="h-10 w-10 text-red-400 mb-3" />
          <p className="text-slate-600">Failed to load alerts</p>
        </div>
      ) : alerts.length === 0 ? (
        <div className="bg-white rounded-xl border border-slate-200/60 flex flex-col items-center justify-center py-20 text-center">
          <div className="p-4 bg-slate-100 rounded-full mb-4">
            <AlertTriangle className="h-10 w-10 text-slate-400" />
          </div>
          <h3 className="text-lg font-medium text-slate-800 mb-1">No alerts yet</h3>
          <p className="text-sm text-slate-500 mb-6 max-w-sm">
            When you report a stolen device, you can create a community alert to help recover it.
          </p>
          <Link
            to="/theft-reports"
            className="inline-flex items-center gap-2 px-4 py-2.5 bg-teal-500 text-white rounded-xl text-sm font-medium hover:bg-teal-600 transition-colors"
          >
            <Plus className="h-4 w-4" />
            Report Stolen Device
          </Link>
        </div>
      ) : (
        <div className="space-y-4">
          {alerts.map((alert) => (
            <MyAlertCard
              key={alert.id}
              alert={alert}
              onPause={handlePause}
              onResume={handleResume}
              onResolve={handleResolve}
              onSetBounty={handleSetBounty}
            />
          ))}

          {/* Pagination */}
          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between px-4 py-3 bg-white rounded-xl border border-slate-200/60">
              <p className="text-sm text-slate-500">
                Showing {meta.from} to {meta.to} of {meta.total}
              </p>
              <div className="flex items-center gap-2">
                <button
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={page === 1}
                  className="px-3 py-1.5 rounded-lg border border-slate-200 text-sm hover:bg-slate-50 disabled:opacity-50"
                >
                  Previous
                </button>
                <span className="text-sm text-slate-600">
                  Page {page} of {meta.last_page}
                </span>
                <button
                  onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                  disabled={page === meta.last_page}
                  className="px-3 py-1.5 rounded-lg border border-slate-200 text-sm hover:bg-slate-50 disabled:opacity-50"
                >
                  Next
                </button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

interface MyAlertCardProps {
  alert: CommunityAlert
  onPause: (id: number) => void
  onResume: (id: number) => void
  onResolve: (id: number) => void
  onSetBounty: (id: number) => void
}

function MyAlertCard({ alert, onPause, onResume, onResolve, onSetBounty }: MyAlertCardProps) {
  const [showMenu, setShowMenu] = useState(false)
  const status = statusConfig[alert.status.value]
  const device = alert.theft_report?.device

  return (
    <div className="bg-white rounded-xl border border-slate-200/60 overflow-hidden">
      {/* Status Bar */}
      <div className={`px-4 py-2 ${status.bg} flex items-center justify-between`}>
        <span className={`text-xs font-medium ${status.text}`}>{status.label}</span>
        {alert.has_bounty && (
          <span className="inline-flex items-center gap-1 px-2 py-0.5 bg-emerald-500 text-white rounded-full text-xs font-medium">
            <DollarSign className="h-3 w-3" />
            MWK {Number(alert.bounty_amount).toLocaleString()}
          </span>
        )}
      </div>

      <div className="p-4">
        <div className="flex items-start justify-between gap-4">
          {/* Device Info */}
          <div className="flex items-start gap-3">
            <div className="p-2 bg-slate-100 rounded-lg">
              <Smartphone className="h-5 w-5 text-slate-500" />
            </div>
            <div>
              {device && (
                <h3 className="font-medium text-slate-800">
                  {device.brand} {device.model}
                </h3>
              )}
              <p className="text-sm text-slate-500">
                Report #{alert.theft_report?.report_number}
              </p>
            </div>
          </div>

          {/* Actions Menu */}
          <div className="relative">
            <button
              onClick={() => setShowMenu(!showMenu)}
              className="p-2 hover:bg-slate-100 rounded-lg transition-colors"
            >
              <MoreVertical className="h-4 w-4 text-slate-500" />
            </button>
            {showMenu && (
              <div className="absolute right-0 top-full mt-1 w-48 bg-white rounded-lg shadow-lg border border-slate-200 py-1 z-10">
                <Link
                  to={`/community/alerts/${alert.id}`}
                  className="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50"
                >
                  <Eye className="h-4 w-4" />
                  View Details
                </Link>
                {alert.status.value === 'active' && (
                  <>
                    <button
                      onClick={() => { setShowMenu(false); onPause(alert.id) }}
                      className="flex items-center gap-2 w-full px-4 py-2 text-sm text-slate-700 hover:bg-slate-50"
                    >
                      <Pause className="h-4 w-4" />
                      Pause Alert
                    </button>
                    <button
                      onClick={() => { setShowMenu(false); onSetBounty(alert.id) }}
                      className="flex items-center gap-2 w-full px-4 py-2 text-sm text-slate-700 hover:bg-slate-50"
                    >
                      <DollarSign className="h-4 w-4" />
                      {alert.has_bounty ? 'Update Bounty' : 'Add Bounty'}
                    </button>
                    <button
                      onClick={() => { setShowMenu(false); onResolve(alert.id) }}
                      className="flex items-center gap-2 w-full px-4 py-2 text-sm text-emerald-600 hover:bg-emerald-50"
                    >
                      <CheckCircle className="h-4 w-4" />
                      Mark Recovered
                    </button>
                  </>
                )}
                {alert.status.value === 'paused' && (
                  <button
                    onClick={() => { setShowMenu(false); onResume(alert.id) }}
                    className="flex items-center gap-2 w-full px-4 py-2 text-sm text-slate-700 hover:bg-slate-50"
                  >
                    <Play className="h-4 w-4" />
                    Resume Alert
                  </button>
                )}
              </div>
            )}
          </div>
        </div>

        {/* Stats */}
        <div className="flex flex-wrap items-center gap-4 mt-4 pt-4 border-t border-slate-100 text-xs text-slate-500">
          <span className="flex items-center gap-1">
            <MessageSquare className="h-3.5 w-3.5" />
            {alert.tips_count} tips
          </span>
          <span className="flex items-center gap-1">
            <Eye className="h-3.5 w-3.5" />
            {alert.view_count} views
          </span>
          <span className="flex items-center gap-1">
            <Share2 className="h-3.5 w-3.5" />
            {alert.share_count} shares
          </span>
          <span className="flex items-center gap-1 ml-auto text-slate-400">
            <Clock className="h-3.5 w-3.5" />
            {formatMalawiDate(alert.created_at)}
          </span>
        </div>

        {/* Tips Preview */}
        {alert.tips_count > 0 && (
          <Link
            to={`/community/alerts/${alert.id}`}
            className="block mt-3 px-3 py-2 bg-teal-50 rounded-lg text-sm text-teal-700 hover:bg-teal-100 transition-colors"
          >
            View {alert.tips_count} tip{alert.tips_count !== 1 ? 's' : ''} from the community
          </Link>
        )}
      </div>
    </div>
  )
}
