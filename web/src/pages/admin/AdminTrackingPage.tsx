import { useState, useMemo, useEffect } from 'react'
import { useSearchParams } from 'react-router-dom'
import { MapContainer, TileLayer, Marker, Popup, useMap } from 'react-leaflet'
import type { LatLngExpression } from 'leaflet'
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
  Search,
} from 'lucide-react'
import apiClient from '@/api/client'
import type { Device, DeviceLocation, DeviceStatus, DeviceStatusValue } from '@/types'

const statusColors: Record<DeviceStatusValue, { bg: string; dot: string; marker: string }> = {
  active: { bg: 'bg-emerald-50', dot: 'bg-emerald-500', marker: '#22c55e' },
  inactive: { bg: 'bg-slate-100', dot: 'bg-slate-400', marker: '#94a3b8' },
  stolen: { bg: 'bg-red-50', dot: 'bg-red-500', marker: '#ef4444' },
  recovered: { bg: 'bg-blue-50', dot: 'bg-blue-500', marker: '#3b82f6' },
}

function getStatusValue(status: DeviceStatus): DeviceStatusValue {
  if (typeof status === 'string') return status
  return status.value
}

interface DeviceWithLocation extends Device {
  location?: DeviceLocation | null
}

async function fetchAllDevicesWithLocations(
  page = 1,
  perPage = 100
): Promise<DeviceWithLocation[]> {
  const response = await apiClient.get<{
    success: boolean
    data: Device[]
    meta: { current_page: number; last_page: number; total: number }
  }>(`/admin/devices?page=${page}&per_page=${perPage}`)

  const devices = response.data.data

  const devicesWithLocations = await Promise.all(
    devices.map(async (device) => {
      try {
        const locationResponse = await apiClient.get<{
          success: boolean
          data: DeviceLocation | null
        }>(`/devices/${device.id}/locations/latest`)

        return {
          ...device,
          location: locationResponse.data.data,
        }
      } catch {
        return {
          ...device,
          location: null,
        }
      }
    })
  )

  return devicesWithLocations
}

function MapController({ center }: { center: LatLngExpression | null }) {
  const map = useMap()

  useMemo(() => {
    if (center) {
      map.flyTo(center, map.getZoom(), {
        animate: true,
        duration: 0.5,
      })
    }
  }, [center, map])

  return null
}

export default function AdminTrackingPage() {
  const [searchParams] = useSearchParams()
  const selectedDeviceId = searchParams.get('device')
  const [showList, setShowList] = useState(true)
  const [searchQuery, setSearchQuery] = useState('')

  const [devices, setDevices] = useState<DeviceWithLocation[]>([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState<Error | null>(null)
  const [isRefetching, setIsRefetching] = useState(false)

  const fetchDevices = async () => {
    try {
      setIsRefetching(true)
      const data = await fetchAllDevicesWithLocations()
      setDevices(data)
      setError(null)
    } catch (err) {
      setError(err as Error)
    } finally {
      setIsLoading(false)
      setIsRefetching(false)
    }
  }

  useEffect(() => {
    fetchDevices()
  }, [])

  useEffect(() => {
    const interval = setInterval(() => {
      fetchDevices()
    }, 30000)
    return () => clearInterval(interval)
  }, [])

  const filteredDevices = useMemo(() => {
    if (!searchQuery) return devices
    const query = searchQuery.toLowerCase()
    return devices.filter(
      (d) =>
        d.name?.toLowerCase().includes(query) ||
        d.serial_number?.toLowerCase().includes(query) ||
        d.model?.toLowerCase().includes(query)
    )
  }, [devices, searchQuery])

  const devicesWithLocation = useMemo(() => {
    return filteredDevices.filter((d) => d.location?.latitude && d.location?.longitude)
  }, [filteredDevices])

  const activeDevices = useMemo(() => {
    return filteredDevices.filter((d) => {
      const status = getStatusValue(d.status)
      return status === 'active' || status === 'stolen'
    })
  }, [filteredDevices])

  const mapCenter: LatLngExpression | null = useMemo(() => {
    if (devicesWithLocation.length === 0) return null
    const firstWithLocation = devicesWithLocation[0]
    if (firstWithLocation.location) {
      return [firstWithLocation.location.latitude, firstWithLocation.location.longitude]
    }
    return null
  }, [devicesWithLocation])

  const handleRefresh = () => {
    fetchDevices()
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            Live Tracking
          </h1>
          <p className="text-sm text-slate-500 mt-1">
            Real-time location tracking for all devices in the system
          </p>
        </div>
        <div className="flex items-center gap-2">
          <button
            onClick={handleRefresh}
            disabled={isRefetching}
            className="inline-flex items-center gap-2 px-4 py-2.5 border border-slate-200 text-slate-700 rounded-xl text-sm font-medium hover:bg-slate-50 transition-colors disabled:opacity-50"
          >
            <RefreshCw className={`h-4 w-4 ${isRefetching ? 'animate-spin' : ''}`} />
            Refresh
          </button>
          <button
            onClick={() => setShowList(!showList)}
            className="inline-flex items-center gap-2 px-4 py-2.5 bg-blue-500 text-white rounded-xl text-sm font-medium hover:bg-blue-600 transition-colors"
          >
            {showList ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
            {showList ? 'Hide List' : 'Show List'}
          </button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className={`${showList ? 'lg:col-span-2' : 'lg:col-span-3'}`}>
          <div className="bg-white rounded-xl border border-slate-200/60 overflow-hidden">
            <div className="relative h-[600px] bg-gradient-to-br from-slate-100 via-slate-50 to-slate-100">
              {isLoading ? (
                <div className="absolute inset-0 flex items-center justify-center">
                  <div className="text-center">
                    <Loader2 className="h-10 w-10 text-blue-500 animate-spin mx-auto mb-3" />
                    <p className="text-slate-600">Loading device locations...</p>
                  </div>
                </div>
              ) : error ? (
                <div className="absolute inset-0 flex items-center justify-center">
                  <div className="text-center">
                    <AlertTriangle className="h-10 w-10 text-red-400 mx-auto mb-3" />
                    <p className="text-slate-600">Failed to load devices</p>
                    <button
                      onClick={handleRefresh}
                      className="mt-2 text-sm text-blue-500 hover:text-blue-600"
                    >
                      Try again
                    </button>
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
                  </div>
                </div>
              ) : mapCenter ? (
                <>
                  <MapContainer
                    center={mapCenter}
                    zoom={13}
                    style={{ height: '100%', width: '100%' }}
                  >
                    <TileLayer
                      attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                      url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                    />
                    <MapController center={mapCenter} />
                    {devicesWithLocation.map((device) => {
                      if (!device.location?.latitude || !device.location?.longitude)
                        return null

                      return (
                        <Marker
                          key={device.id}
                          position={[
                            device.location.latitude,
                            device.location.longitude,
                          ]}
                        >
                          <Popup>
                            <div className="text-sm">
                              <p className="font-medium">{device.name}</p>
                              <p className="text-xs text-slate-500">{device.serial_number}</p>
                              {device.location?.address && (
                                <p className="text-xs text-slate-600 mt-1">
                                  {device.location.address}
                                </p>
                              )}
                            </div>
                          </Popup>
                        </Marker>
                      )
                    })}
                  </MapContainer>

                  <div className="absolute top-4 left-4 bg-white rounded-lg shadow-lg px-4 py-2 flex items-center gap-2 z-[1000]">
                    <Smartphone className="h-4 w-4 text-blue-500" />
                    <span className="text-sm font-medium text-slate-700">
                      {activeDevices.length} Devices
                    </span>
                  </div>

                  <div className="absolute bottom-4 left-4 bg-white rounded-lg shadow-lg p-3 z-[1000]">
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

                  <div className="absolute top-4 right-4 bg-white rounded-lg shadow-lg px-3 py-2 flex items-center gap-2 z-[1000]">
                    <Radio className="h-4 w-4 animate-pulse text-blue-500" />
                    <span className="text-sm font-medium text-slate-700">Live</span>
                  </div>
                </>
              ) : (
                <div className="absolute inset-0 flex items-center justify-center">
                  <div className="text-center">
                    <Target className="h-10 w-10 text-slate-400 mx-auto mb-3" />
                    <p className="text-sm text-slate-500">
                      No devices with location data available
                    </p>
                  </div>
                </div>
              )}
            </div>
          </div>
        </div>

        {showList && (
          <div className="space-y-4">
            <div className="bg-white rounded-xl border border-slate-200/60 p-4">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
                <input
                  type="text"
                  placeholder="Search devices..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="w-full pl-10 pr-4 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                />
              </div>
            </div>

            <div className="bg-white rounded-xl border border-slate-200/60 p-4">
              <h2 className="text-sm font-semibold text-slate-800 mb-3" style={{ fontFamily: 'var(--font-heading)' }}>
                Tracked Devices
              </h2>
              {isLoading ? (
                <div className="py-8 text-center">
                  <Loader2 className="h-6 w-6 text-blue-500 animate-spin mx-auto" />
                </div>
              ) : filteredDevices.length === 0 ? (
                <div className="py-8 text-center">
                  <p className="text-sm text-slate-500">No devices found</p>
                </div>
              ) : (
                <div className="space-y-2 max-h-[450px] overflow-y-auto">
                  {filteredDevices.map((device) => (
                    <DeviceLocationCard
                      key={device.id}
                      device={device}
                      isSelected={selectedDeviceId === device.id.toString()}
                    />
                  ))}
                </div>
              )}
            </div>

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
                    {devicesWithLocation.length}
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
}: {
  device: DeviceWithLocation
  isSelected: boolean
}) {
  const location = device.location

  return (
    <div
      className={`block p-3 rounded-lg transition-all ${
        isSelected
          ? 'bg-blue-50 border border-blue-200'
          : 'bg-slate-50 hover:bg-slate-100 border border-transparent'
      }`}
    >
      <div className="flex items-start gap-3">
        <div
          className={`p-2 rounded-lg ${
            statusColors[getStatusValue(device.status)]?.bg || 'bg-slate-100'
          }`}
        >
          <Smartphone className="h-4 w-4 text-slate-600" />
        </div>
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2">
            <p className="text-sm font-medium text-slate-800 truncate">{device.name}</p>
            <span
              className={`h-2 w-2 rounded-full ${
                statusColors[getStatusValue(device.status)]?.dot || 'bg-slate-400'
              }`}
            />
          </div>
          {location ? (
            <div className="mt-1">
              <p className="text-xs text-slate-500 truncate flex items-center gap-1">
                <MapPin className="h-3 w-3" />
                {location.address ||
                  `${location.latitude.toFixed(4)}, ${location.longitude.toFixed(4)}`}
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
    </div>
  )
}

function formatTimeAgo(date: Date): string {
  const now = new Date()
  const diffInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000)

  if (diffInSeconds < 60) return 'Just now'
  if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)}m ago`
  if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)}h ago`
  if (diffInSeconds < 604800) return `${Math.floor(diffInSeconds / 86400)}d ago`

  return date.toLocaleDateString()
}