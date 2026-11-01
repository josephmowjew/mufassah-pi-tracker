import { formatMalawiDate } from '@/lib/datetime'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import {
  MapPin,
  Smartphone,
  Loader2,
  AlertTriangle,
  RefreshCw,
  Clock,
  Navigation,
  ChevronRight,
  Eye,
  EyeOff,
  Target,
  Radio,
} from 'lucide-react'
import { useDevices, useDeviceLatestLocations } from '@/hooks/useDevices'
import type { Device, DeviceLocation, DeviceStatus, DeviceStatusValue } from '@/types'

const statusColors: Record<DeviceStatusValue, { bg: string; dot: string }> = {
  active: { bg: 'bg-emerald-50', dot: 'bg-emerald-500' },
  inactive: { bg: 'bg-slate-100', dot: 'bg-slate-400' },
  stolen: { bg: 'bg-red-50', dot: 'bg-red-500' },
  recovered: { bg: 'bg-blue-50', dot: 'bg-blue-500' },
}

// Helper to extract status value from either string or object format
function getStatusValue(status: DeviceStatus): DeviceStatusValue {
  if (typeof status === 'string') return status
  return status.value
}

export default function TrackingPage() {
  const [searchParams] = useSearchParams()
  const selectedDeviceId = searchParams.get('device')
  const [showList, setShowList] = useState(true)

  const { data: devicesData, isLoading, error, refetch, isRefetching } = useDevices({ per_page: 100 })
  const devices = devicesData?.data || []

  // One batch request for every device on the page. Previously each
  // DeviceLocationCard ran its own 30-second polling query, so a customer with
  // 100 devices held 100 parallel requests on 100 staggered timers.
  const { data: latestLocations, isLoading: isLoadingLocations } = useDeviceLatestLocations(
    devices.map((d) => d.id),
  )

  // Get devices with location data
  const activeDevices = devices.filter((d) => {
    const status = getStatusValue(d.status)
    return status === 'active' || status === 'stolen'
  })

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            Live Tracking
          </h1>
          <p className="text-sm text-slate-500 mt-1">
            Real-time location tracking for all your devices
          </p>
        </div>
        <div className="flex items-center gap-2">
          <button
            onClick={() => refetch()}
            disabled={isRefetching}
            className="inline-flex items-center gap-2 px-4 py-2.5 border border-slate-200 text-slate-700 rounded-xl text-sm font-medium hover:bg-slate-50 transition-colors disabled:opacity-50"
          >
            <RefreshCw className={`h-4 w-4 ${isRefetching ? 'animate-spin' : ''}`} />
            Refresh
          </button>
          <button
            onClick={() => setShowList(!showList)}
            className="inline-flex items-center gap-2 px-4 py-2.5 bg-teal-500 text-white rounded-xl text-sm font-medium hover:bg-teal-600 transition-colors"
          >
            {showList ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
            {showList ? 'Hide List' : 'Show List'}
          </button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Map Area */}
        <div className={`${showList ? 'lg:col-span-2' : 'lg:col-span-3'}`}>
          <div className="bg-white rounded-xl border border-slate-200/60 overflow-hidden">
            {/* Map placeholder - will be replaced with actual map */}
            <div className="relative h-[600px] bg-gradient-to-br from-slate-100 via-slate-50 to-slate-100">
              {/* Grid pattern overlay */}
              <div
                className="absolute inset-0 opacity-10"
                style={{
                  backgroundImage: `url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23000000' fill-opacity='0.4'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E")`,
                }}
              />

              {isLoading ? (
                <div className="absolute inset-0 flex items-center justify-center">
                  <div className="text-center">
                    <Loader2 className="h-10 w-10 text-teal-500 animate-spin mx-auto mb-3" />
                    <p className="text-slate-600">Loading device locations...</p>
                  </div>
                </div>
              ) : error ? (
                <div className="absolute inset-0 flex items-center justify-center">
                  <div className="text-center">
                    <AlertTriangle className="h-10 w-10 text-red-400 mx-auto mb-3" />
                    <p className="text-slate-600">Failed to load devices</p>
                  </div>
                </div>
              ) : activeDevices.length === 0 ? (
                <div className="absolute inset-0 flex items-center justify-center">
                  <div className="text-center">
                    <div className="p-4 bg-white rounded-full shadow-lg mx-auto mb-4 w-fit">
                      <MapPin className="h-10 w-10 text-slate-400" />
                    </div>
                    <h3 className="text-lg font-medium text-slate-800 mb-1">No devices to track</h3>
                    <p className="text-sm text-slate-500 mb-4">
                      Add devices and enable tracking to see them on the map
                    </p>
                    <Link
                      to="/devices"
                      className="inline-flex items-center gap-2 px-4 py-2 bg-teal-500 text-white rounded-lg text-sm font-medium hover:bg-teal-600 transition-colors"
                    >
                      Go to Devices
                      <ChevronRight className="h-4 w-4" />
                    </Link>
                  </div>
                </div>
              ) : (
                <>
                  {/* Map placeholder with device markers simulation */}
                  <div className="absolute inset-0 flex items-center justify-center">
                    <div className="text-center">
                      <div className="relative">
                        <div className="absolute -inset-20 bg-teal-500/10 rounded-full animate-ping" />
                        <div className="relative p-4 bg-white rounded-full shadow-lg mx-auto mb-4 w-fit">
                          <Target className="h-10 w-10 text-teal-500" />
                        </div>
                      </div>
                      <h3 className="text-lg font-medium text-slate-800 mb-1">
                        {activeDevices.length} device{activeDevices.length !== 1 ? 's' : ''} available
                      </h3>
                      <p className="text-sm text-slate-500 mb-4">
                        Map integration requires Leaflet or Google Maps API
                      </p>
                      <div className="flex items-center justify-center gap-2 text-xs text-slate-400">
                        <Radio className="h-4 w-4 animate-pulse text-teal-500" />
                        Live tracking active
                      </div>
                    </div>
                  </div>

                  {/* Floating device count */}
                  <div className="absolute top-4 left-4 bg-white rounded-lg shadow-lg px-4 py-2 flex items-center gap-2">
                    <Smartphone className="h-4 w-4 text-teal-500" />
                    <span className="text-sm font-medium text-slate-700">
                      {activeDevices.length} Devices
                    </span>
                  </div>

                  {/* Legend */}
                  <div className="absolute bottom-4 left-4 bg-white rounded-lg shadow-lg p-3">
                    <p className="text-xs font-medium text-slate-600 mb-2">Status Legend</p>
                    <div className="space-y-1">
                      <div className="flex items-center gap-2">
                        <span className="h-2 w-2 rounded-full bg-emerald-500" />
                        <span className="text-xs text-slate-500">Active</span>
                      </div>
                      <div className="flex items-center gap-2">
                        <span className="h-2 w-2 rounded-full bg-red-500" />
                        <span className="text-xs text-slate-500">Stolen</span>
                      </div>
                      <div className="flex items-center gap-2">
                        <span className="h-2 w-2 rounded-full bg-slate-400" />
                        <span className="text-xs text-slate-500">Inactive</span>
                      </div>
                    </div>
                  </div>
                </>
              )}
            </div>
          </div>
        </div>

        {/* Device List */}
        {showList && (
          <div className="space-y-4">
            <div className="bg-white rounded-xl border border-slate-200/60 p-4">
              <h2 className="text-sm font-semibold text-slate-800 mb-3" style={{ fontFamily: 'var(--font-heading)' }}>
                Tracked Devices
              </h2>
              {isLoading ? (
                <div className="py-8 text-center">
                  <Loader2 className="h-6 w-6 text-teal-500 animate-spin mx-auto" />
                </div>
              ) : devices.length === 0 ? (
                <div className="py-8 text-center">
                  <p className="text-sm text-slate-500">No devices found</p>
                </div>
              ) : (
                <div className="space-y-2 max-h-[500px] overflow-y-auto">
                  {devices.map((device) => (
                    <DeviceLocationCard
                      key={device.id}
                      device={device}
                      isSelected={selectedDeviceId === device.id.toString()}
                      location={latestLocations?.[String(device.id)] ?? null}
                      isLoading={isLoadingLocations}
                    />
                  ))}
                </div>
              )}
            </div>

            {/* Quick stats */}
            <div className="bg-white rounded-xl border border-slate-200/60 p-4">
              <h2 className="text-sm font-semibold text-slate-800 mb-3" style={{ fontFamily: 'var(--font-heading)' }}>
                Quick Stats
              </h2>
              <div className="grid grid-cols-2 gap-3">
                <div className="p-3 rounded-lg bg-emerald-50">
                  <p className="text-2xl font-bold text-emerald-600">
                    {devices.filter((d) => getStatusValue(d.status) === 'active').length}
                  </p>
                  <p className="text-xs text-emerald-700">Active</p>
                </div>
                <div className="p-3 rounded-lg bg-red-50">
                  <p className="text-2xl font-bold text-red-600">
                    {devices.filter((d) => getStatusValue(d.status) === 'stolen').length}
                  </p>
                  <p className="text-xs text-red-700">Stolen</p>
                </div>
                <div className="p-3 rounded-lg bg-slate-100">
                  <p className="text-2xl font-bold text-slate-600">
                    {devices.filter((d) => getStatusValue(d.status) === 'inactive').length}
                  </p>
                  <p className="text-xs text-slate-700">Inactive</p>
                </div>
                <div className="p-3 rounded-lg bg-blue-50">
                  <p className="text-2xl font-bold text-blue-600">
                    {devices.filter((d) => d.last_seen_at).length}
                  </p>
                  <p className="text-xs text-blue-700">With Location</p>
                </div>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}

function DeviceLocationCard({
  device,
  isSelected,
  location,
  isLoading,
}: {
  device: Device
  isSelected: boolean
  location: DeviceLocation | null
  isLoading: boolean
}) {
  return (
    <Link
      to={`/tracking?device=${device.id}`}
      className={`block p-3 rounded-lg transition-all ${
        isSelected
          ? 'bg-teal-50 border border-teal-200'
          : 'bg-slate-50 hover:bg-slate-100 border border-transparent'
      }`}
    >
      <div className="flex items-start gap-3">
        <div className={`p-2 rounded-lg ${statusColors[getStatusValue(device.status)]?.bg || 'bg-slate-100'}`}>
          <Smartphone className="h-4 w-4 text-slate-600" />
        </div>
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2">
            <p className="text-sm font-medium text-slate-800 truncate">{device.name}</p>
            <span
              className={`h-2 w-2 rounded-full ${statusColors[getStatusValue(device.status)]?.dot || 'bg-slate-400'}`}
            />
          </div>
          {isLoading ? (
            <p className="text-xs text-slate-400 mt-0.5">Loading location...</p>
          ) : location ? (
            <div className="mt-1">
              <p className="text-xs text-slate-500 truncate flex items-center gap-1">
                <MapPin className="h-3 w-3" />
                {location.address || `${location.latitude.toFixed(4)}, ${location.longitude.toFixed(4)}`}
              </p>
              <p className="text-xs text-slate-400 flex items-center gap-1 mt-0.5">
                <Clock className="h-3 w-3" />
                {formatTimeAgo(new Date(location.recorded_at))}
              </p>
            </div>
          ) : (
            <p className="text-xs text-slate-400 mt-0.5 flex items-center gap-1">
              <Navigation className="h-3 w-3" />
              No location data
            </p>
          )}
        </div>
        <ChevronRight className="h-4 w-4 text-slate-400" />
      </div>
    </Link>
  )
}

function formatTimeAgo(date: Date): string {
  const now = new Date()
  const diffInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000)

  if (diffInSeconds < 60) return 'Just now'
  if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)}m ago`
  if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)}h ago`
  if (diffInSeconds < 604800) return `${Math.floor(diffInSeconds / 86400)}d ago`

  return formatMalawiDate(date)
}
