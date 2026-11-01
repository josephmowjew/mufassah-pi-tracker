import { formatMalawiDateTime, malawiHour } from '@/lib/datetime'
import { Link } from 'react-router-dom'
import {
  Smartphone,
  MapPin,
  AlertTriangle,
  Shield,
  Plus,
  ArrowRight,
  Clock,
  TrendingUp,
  Bell,
  Map,
  Activity,
  Zap,
  Loader2,
} from 'lucide-react'
import { useAuthStore } from '@/stores/auth-store'
import { useDashboardStats } from '@/hooks/useDashboard'
import type { DeviceStatus, DeviceStatusValue } from '@/types'

// Helper to extract status value from either string or object format
function getStatusValue(status: DeviceStatus): DeviceStatusValue {
  if (typeof status === 'string') return status
  return status.value
}

export default function DashboardPage() {
  const { user } = useAuthStore()
  const { data: stats, isLoading } = useDashboardStats()

  // Get time-based greeting
  const getGreeting = () => {
    const hour = malawiHour()
    if (hour < 12) return 'Good morning'
    if (hour < 17) return 'Good afternoon'
    return 'Good evening'
  }

  const statCards = [
    {
      label: 'Total Devices',
      value: isLoading ? '...' : stats?.totalDevices?.toString() || '0',
      change: null,
      icon: Smartphone,
      iconBg: 'bg-gradient-to-br from-teal-400 to-teal-600',
      cardBg: 'bg-gradient-to-br from-slate-50 to-slate-100/50',
    },
    {
      label: 'Active Tracking',
      value: isLoading ? '...' : stats?.activeDevices?.toString() || '0',
      change: null,
      icon: Activity,
      iconBg: 'bg-gradient-to-br from-blue-400 to-blue-600',
      cardBg: 'bg-gradient-to-br from-slate-50 to-slate-100/50',
    },
    {
      label: 'Geofences',
      value: isLoading ? '...' : stats?.geofencesCount?.toString() || '0',
      change: null,
      icon: Shield,
      iconBg: 'bg-gradient-to-br from-violet-400 to-violet-600',
      cardBg: 'bg-gradient-to-br from-slate-50 to-slate-100/50',
    },
    {
      label: 'Alerts Today',
      value: isLoading ? '...' : stats?.alertsToday?.toString() || '0',
      change: null,
      icon: Bell,
      iconBg: 'bg-gradient-to-br from-amber-400 to-orange-500',
      cardBg: 'bg-gradient-to-br from-slate-50 to-slate-100/50',
    },
  ]

  const quickActions = [
    {
      label: 'Add Device',
      description: 'Register a new device',
      icon: Plus,
      href: '/devices',
      iconBg: 'bg-gradient-to-br from-teal-400 to-teal-600',
    },
    {
      label: 'Live Tracking',
      description: 'View device locations',
      icon: MapPin,
      href: '/tracking',
      iconBg: 'bg-gradient-to-br from-blue-400 to-blue-600',
    },
    {
      label: 'Create Geofence',
      description: 'Set up safe zones',
      icon: Shield,
      href: '/geofences',
      iconBg: 'bg-gradient-to-br from-violet-400 to-violet-600',
    },
    {
      label: 'Report Theft',
      description: 'File a theft report',
      icon: AlertTriangle,
      href: '/theft-reports',
      iconBg: 'bg-gradient-to-br from-red-400 to-red-600',
    },
  ]

  return (
    <div className="space-y-6">
      {/* Welcome Section */}
      <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-slate-800 via-slate-900 to-slate-800 p-6 lg:p-8 text-white">
        {/* Background decorations */}
        <div className="absolute top-0 right-0 -mt-20 -mr-20 h-80 w-80 rounded-full bg-teal-500/20 blur-3xl" />
        <div className="absolute bottom-0 left-0 -mb-20 -ml-20 h-60 w-60 rounded-full bg-emerald-500/10 blur-2xl" />
        <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 h-40 w-40 rounded-full bg-teal-400/5 blur-xl" />

        <div className="relative z-10">
          <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
            <div>
              <p className="text-slate-400 text-sm font-medium mb-1">
                {getGreeting()},
              </p>
              <h1 className="text-2xl lg:text-3xl font-bold mb-2" style={{ fontFamily: 'var(--font-heading)' }}>
                {user?.name || 'Welcome back'}!
              </h1>
              <p className="text-slate-400 max-w-md text-sm lg:text-base">
                Track your devices, monitor locations, and keep your assets secure.
              </p>
            </div>
            <div className="flex flex-wrap gap-3">
              <Link
                to="/devices"
                className="inline-flex items-center gap-2 px-4 py-2.5 bg-teal-500 text-white rounded-xl text-sm font-medium hover:bg-teal-400 transition-colors shadow-lg shadow-teal-500/25"
              >
                <Plus className="h-4 w-4" />
                Add Device
              </Link>
              <Link
                to="/tracking"
                className="inline-flex items-center gap-2 px-4 py-2.5 bg-slate-700/50 text-white rounded-xl text-sm font-medium hover:bg-slate-700 transition-colors border border-slate-600/50"
              >
                <Map className="h-4 w-4" />
                Live Map
              </Link>
            </div>
          </div>
        </div>
      </div>

      {/* Stats Grid */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {statCards.map((stat) => (
          <div
            key={stat.label}
            className={`group relative overflow-hidden rounded-xl ${stat.cardBg} border border-slate-200/60 p-5 hover:shadow-md hover:border-slate-300/60 transition-all duration-300`}
          >
            <div className="flex items-start justify-between mb-3">
              <div className={`p-2.5 rounded-xl ${stat.iconBg} shadow-lg`}>
                <stat.icon className="h-4 w-4 text-white" />
              </div>
              {stat.change && (
                <span className="inline-flex items-center gap-1 text-xs font-medium text-emerald-600 bg-emerald-50 px-2 py-1 rounded-full">
                  <TrendingUp className="h-3 w-3" />
                  {stat.change}
                </span>
              )}
            </div>
            <p className="text-2xl lg:text-3xl font-bold text-slate-800 mb-0.5" style={{ fontFamily: 'var(--font-heading)' }}>
              {stat.value}
            </p>
            <p className="text-xs lg:text-sm text-slate-500">{stat.label}</p>
          </div>
        ))}
      </div>

      {/* Main Content Grid */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
        {/* Quick Actions */}
        <div className="lg:col-span-2 bg-white rounded-xl border border-slate-200/60 p-5">
          <div className="flex items-center justify-between mb-5">
            <h2 className="text-base font-semibold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
              Quick Actions
            </h2>
            <div className="p-1.5 rounded-lg bg-amber-50">
              <Zap className="h-4 w-4 text-amber-500" />
            </div>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            {quickActions.map((action) => (
              <Link
                key={action.label}
                to={action.href}
                className="group flex items-center gap-3 p-3.5 rounded-xl bg-slate-50/80 border border-slate-100 hover:bg-slate-100/80 hover:border-slate-200 transition-all duration-200"
              >
                <div className={`p-2.5 rounded-xl ${action.iconBg} text-white transition-transform group-hover:scale-105 shadow-md`}>
                  <action.icon className="h-4 w-4" />
                </div>
                <div className="flex-1 min-w-0">
                  <p className="font-medium text-sm text-slate-800">{action.label}</p>
                  <p className="text-xs text-slate-500">{action.description}</p>
                </div>
                <ArrowRight className="h-4 w-4 text-slate-300 group-hover:text-slate-500 group-hover:translate-x-0.5 transition-all" />
              </Link>
            ))}
          </div>
        </div>

        {/* Map Preview */}
        <div className="bg-white rounded-xl border border-slate-200/60 p-5">
          <div className="flex items-center justify-between mb-5">
            <h2 className="text-base font-semibold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
              Device Map
            </h2>
            <Link to="/tracking" className="text-xs text-teal-600 hover:text-teal-700 font-medium">
              View all
            </Link>
          </div>
          {stats?.totalDevices && stats.totalDevices > 0 ? (
            <div className="aspect-[4/3] rounded-xl bg-gradient-to-br from-slate-100 to-slate-50 flex flex-col items-center justify-center text-center p-5 border border-slate-200">
              <div className="p-3 bg-white rounded-full shadow-sm mb-3 border border-slate-100">
                <Map className="h-6 w-6 text-teal-500" />
              </div>
              <p className="text-slate-600 text-sm mb-2 font-medium">
                {stats.totalDevices} device{stats.totalDevices !== 1 ? 's' : ''} registered
              </p>
              <Link
                to="/tracking"
                className="text-sm text-teal-600 hover:text-teal-700 font-medium inline-flex items-center gap-1"
              >
                View on map
                <ArrowRight className="h-3 w-3" />
              </Link>
            </div>
          ) : (
            <div className="aspect-[4/3] rounded-xl bg-gradient-to-br from-slate-100 to-slate-50 flex flex-col items-center justify-center text-center p-5 border border-dashed border-slate-200">
              <div className="p-3 bg-white rounded-full shadow-sm mb-3 border border-slate-100">
                <Map className="h-6 w-6 text-slate-400" />
              </div>
              <p className="text-slate-500 text-xs mb-2">
                No devices to display yet
              </p>
              <Link
                to="/devices"
                className="text-xs text-teal-600 hover:text-teal-700 font-medium inline-flex items-center gap-1"
              >
                Add your first device
                <ArrowRight className="h-3 w-3" />
              </Link>
            </div>
          )}
        </div>
      </div>

      {/* Recent Activity */}
      <div className="bg-white rounded-xl border border-slate-200/60 p-5">
        <div className="flex items-center justify-between mb-5">
          <h2 className="text-base font-semibold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            Recent Activity
          </h2>
          <Link to="/notifications" className="text-xs text-teal-600 hover:text-teal-700 font-medium">
            View all
          </Link>
        </div>

        {isLoading ? (
          <div className="flex items-center justify-center py-10">
            <Loader2 className="h-6 w-6 text-teal-500 animate-spin" />
          </div>
        ) : stats?.recentNotifications && stats.recentNotifications.length > 0 ? (
          <div className="space-y-3">
            {stats.recentNotifications.map((notification) => (
              <div
                key={notification.id}
                className={`flex items-start gap-3 p-3 rounded-lg ${
                  notification.read_at ? 'bg-slate-50' : 'bg-teal-50/50'
                }`}
              >
                <div className={`p-2 rounded-lg ${notification.read_at ? 'bg-slate-100' : 'bg-teal-100'}`}>
                  <Bell className={`h-4 w-4 ${notification.read_at ? 'text-slate-500' : 'text-teal-600'}`} />
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-sm text-slate-700">
                    {(notification.data as { message?: string })?.message || notification.type}
                  </p>
                  <p className="text-xs text-slate-500 mt-0.5">
                    {formatMalawiDateTime(notification.created_at)}
                  </p>
                </div>
              </div>
            ))}
          </div>
        ) : (
          <div className="flex flex-col items-center justify-center py-10 text-center">
            <div className="relative mb-5">
              <div className="absolute inset-0 bg-teal-100 rounded-full blur-xl opacity-40" />
              <div className="relative p-4 bg-gradient-to-br from-slate-50 to-slate-100 rounded-full border border-slate-200/60">
                <Clock className="h-8 w-8 text-slate-400" />
              </div>
            </div>
            <h3 className="text-sm font-medium text-slate-800 mb-1">No activity yet</h3>
            <p className="text-slate-500 text-xs max-w-xs mb-5">
              Once you add devices and start tracking, you'll see location updates, alerts, and more here.
            </p>
            <Link
              to="/devices"
              className="inline-flex items-center gap-2 px-4 py-2 bg-slate-900 text-white rounded-lg text-sm font-medium hover:bg-slate-800 transition-colors"
            >
              Get Started
              <ArrowRight className="h-3.5 w-3.5" />
            </Link>
          </div>
        )}
      </div>

      {/* Recent Devices - Only show if there are devices */}
      {stats?.recentDevices && stats.recentDevices.length > 0 && (
        <div className="bg-white rounded-xl border border-slate-200/60 p-5">
          <div className="flex items-center justify-between mb-5">
            <h2 className="text-base font-semibold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
              Recent Devices
            </h2>
            <Link to="/devices" className="text-xs text-teal-600 hover:text-teal-700 font-medium">
              View all
            </Link>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-3">
            {stats.recentDevices.map((device) => (
              <Link
                key={device.id}
                to={`/devices/${device.id}`}
                className="flex items-center gap-3 p-3 rounded-lg bg-slate-50 hover:bg-slate-100 transition-colors"
              >
                <div className="h-10 w-10 rounded-lg bg-gradient-to-br from-slate-100 to-slate-50 border border-slate-200 flex items-center justify-center">
                  <Smartphone className="h-5 w-5 text-slate-500" />
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium text-slate-700 truncate">{device.name}</p>
                  <p className="text-xs text-slate-500">{device.brand}</p>
                </div>
                <span
                  className={`h-2 w-2 rounded-full ${
                    getStatusValue(device.status) === 'active'
                      ? 'bg-emerald-500'
                      : getStatusValue(device.status) === 'stolen'
                      ? 'bg-red-500'
                      : 'bg-slate-400'
                  }`}
                />
              </Link>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
