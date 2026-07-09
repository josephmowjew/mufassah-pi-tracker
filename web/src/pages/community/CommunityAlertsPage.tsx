import { useState } from 'react'
import { Link } from 'react-router-dom'
import {
  AlertTriangle,
  MapPin,
  Loader2,
  Eye,
  MessageSquare,
  DollarSign,
  Users,
  Trophy,
  Filter,
  Clock,
  Smartphone,
  Share2,
} from 'lucide-react'
import { useCommunityAlerts, useCommunityStats } from '@/hooks/useCommunity'
import type { CommunityAlert, CommunityAlertStatusValue } from '@/types'

const statusConfig: Record<CommunityAlertStatusValue, { bg: string; text: string; label: string }> = {
  active: { bg: 'bg-red-50', text: 'text-red-700', label: 'Active' },
  paused: { bg: 'bg-amber-50', text: 'text-amber-700', label: 'Paused' },
  resolved: { bg: 'bg-emerald-50', text: 'text-emerald-700', label: 'Resolved' },
  expired: { bg: 'bg-slate-100', text: 'text-slate-600', label: 'Expired' },
}

export default function CommunityAlertsPage() {
  const [withBounty, setWithBounty] = useState(false)
  const [page, setPage] = useState(1)

  const { data: alertsData, isLoading, error } = useCommunityAlerts({
    page,
    per_page: 12,
    with_bounty: withBounty || undefined,
  })
  const { data: stats } = useCommunityStats()

  const alerts = alertsData?.data || []
  const meta = alertsData?.meta

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            Community Recovery Network
          </h1>
          <p className="text-sm text-slate-500 mt-1">
            Help recover stolen devices and earn bounties
          </p>
        </div>
        <div className="flex items-center gap-3">
          <Link
            to="/community/leaderboard"
            className="inline-flex items-center gap-2 px-4 py-2.5 border border-slate-200 text-slate-700 rounded-xl text-sm font-medium hover:bg-slate-50 transition-colors"
          >
            <Trophy className="h-4 w-4 text-amber-500" />
            Leaderboard
          </Link>
          <Link
            to="/community/my-alerts"
            className="inline-flex items-center gap-2 px-4 py-2.5 bg-teal-500 text-white rounded-xl text-sm font-medium hover:bg-teal-600 transition-colors shadow-sm"
          >
            <AlertTriangle className="h-4 w-4" />
            My Alerts
          </Link>
        </div>
      </div>

      {/* Quick Stats */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white rounded-xl border border-slate-200/60 p-4">
          <div className="flex items-center gap-3">
            <div className="p-2.5 bg-red-50 rounded-xl">
              <AlertTriangle className="h-5 w-5 text-red-500" />
            </div>
            <div>
              <p className="text-2xl font-bold text-slate-800">{stats?.active_alerts || 0}</p>
              <p className="text-xs text-slate-500">Active Alerts</p>
            </div>
          </div>
        </div>
        <div className="bg-white rounded-xl border border-slate-200/60 p-4">
          <div className="flex items-center gap-3">
            <div className="p-2.5 bg-emerald-50 rounded-xl">
              <DollarSign className="h-5 w-5 text-emerald-500" />
            </div>
            <div>
              <p className="text-2xl font-bold text-slate-800">
                {stats?.total_recoveries || 0}
              </p>
              <p className="text-xs text-slate-500">Recoveries</p>
            </div>
          </div>
        </div>
        <div className="bg-white rounded-xl border border-slate-200/60 p-4">
          <div className="flex items-center gap-3">
            <div className="p-2.5 bg-blue-50 rounded-xl">
              <Users className="h-5 w-5 text-blue-500" />
            </div>
            <div>
              <p className="text-2xl font-bold text-slate-800">
                {stats?.total_tips || 0}
              </p>
              <p className="text-xs text-slate-500">Tips Submitted</p>
            </div>
          </div>
        </div>
        <Link to="/community/stories" className="bg-white rounded-xl border border-slate-200/60 p-4 hover:bg-slate-50 transition-colors">
          <div className="flex items-center gap-3">
            <div className="p-2.5 bg-amber-50 rounded-xl">
              <Trophy className="h-5 w-5 text-amber-500" />
            </div>
            <div>
              <p className="text-sm font-semibold text-slate-800">Success Stories</p>
              <p className="text-xs text-slate-500">{stats?.total_stories || 0} stories →</p>
            </div>
          </div>
        </Link>
      </div>

      {/* Filters */}
      <div className="bg-white rounded-xl border border-slate-200/60 p-4">
        <div className="flex items-center gap-3">
          <Filter className="h-4 w-4 text-slate-400" />
          <span className="text-sm text-slate-600">Filter:</span>
          <button
            onClick={() => setWithBounty(false)}
            className={`px-3 py-1.5 rounded-lg text-sm font-medium transition-colors ${
              !withBounty
                ? 'bg-slate-900 text-white'
                : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
            }`}
          >
            All Alerts
          </button>
          <button
            onClick={() => setWithBounty(true)}
            className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors ${
              withBounty
                ? 'bg-emerald-500 text-white'
                : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
            }`}
          >
            <DollarSign className="h-3.5 w-3.5" />
            With Bounty
          </button>
        </div>
      </div>

      {/* Alerts Grid */}
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
          <div className="p-4 bg-emerald-50 rounded-full mb-4">
            <AlertTriangle className="h-10 w-10 text-emerald-400" />
          </div>
          <h3 className="text-lg font-medium text-slate-800 mb-1">No active alerts</h3>
          <p className="text-sm text-slate-500 mb-6 max-w-sm">
            Great news! There are no stolen devices nearby.
          </p>
        </div>
      ) : (
        <>
          <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {alerts.map((alert) => (
              <AlertCard key={alert.id} alert={alert} />
            ))}
          </div>

          {/* Pagination */}
          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between px-4 py-3 bg-white rounded-xl border border-slate-200/60">
              <p className="text-sm text-slate-500">
                Showing {meta.from} to {meta.to} of {meta.total} alerts
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
        </>
      )}
    </div>
  )
}

function AlertCard({ alert }: { alert: CommunityAlert }) {
  const status = statusConfig[alert.status.value]
  const device = alert.theft_report?.device

  return (
    <Link
      to={`/community/alerts/${alert.id}`}
      className="bg-white rounded-xl border border-slate-200/60 overflow-hidden hover:shadow-md transition-shadow"
    >
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
        {/* Device Info */}
        {device && (
          <div className="flex items-center gap-2 mb-3">
            <Smartphone className="h-4 w-4 text-slate-400" />
            <span className="font-medium text-slate-800">
              {device.brand} {device.model}
            </span>
          </div>
        )}

        {/* Description */}
        {alert.theft_report?.description && (
          <p className="text-sm text-slate-600 line-clamp-2 mb-3">
            {alert.theft_report.description}
          </p>
        )}

        {/* Meta Info */}
        <div className="flex flex-wrap items-center gap-3 text-xs text-slate-500">
          {alert.distance_km && (
            <span className="flex items-center gap-1">
              <MapPin className="h-3 w-3" />
              {alert.distance_km} km away
            </span>
          )}
          <span className="flex items-center gap-1">
            <MessageSquare className="h-3 w-3" />
            {alert.tips_count} tips
          </span>
          <span className="flex items-center gap-1">
            <Eye className="h-3 w-3" />
            {alert.view_count} views
          </span>
          <span className="flex items-center gap-1">
            <Share2 className="h-3 w-3" />
            {alert.share_count}
          </span>
        </div>

        {/* Time */}
        <div className="flex items-center gap-1 text-xs text-slate-400 mt-3 pt-3 border-t border-slate-100">
          <Clock className="h-3 w-3" />
          Posted {new Date(alert.created_at).toLocaleDateString()}
        </div>
      </div>
    </Link>
  )
}
