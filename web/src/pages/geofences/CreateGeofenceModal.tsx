import { useState, useEffect } from 'react'
import { X, Loader2, Shield, MapPin, Map, Keyboard } from 'lucide-react'
import { toast } from 'sonner'
import { useCreateGeofence } from '@/hooks/useGeofences'
import { useDevices } from '@/hooks/useDevices'
import GeofenceMap from '@/components/maps/GeofenceMap'

interface CreateGeofenceModalProps {
  isOpen: boolean
  onClose: () => void
  defaultDeviceId?: number
}

type InputMode = 'map' | 'manual'

export default function CreateGeofenceModal({ isOpen, onClose, defaultDeviceId }: CreateGeofenceModalProps) {
  const [inputMode, setInputMode] = useState<InputMode>('map')
  const [formData, setFormData] = useState({
    device_id: defaultDeviceId?.toString() || '',
    name: '',
    type: 'circle' as const,
    center_latitude: '',
    center_longitude: '',
    radius_meters: 100,
    notify_on_entry: true,
    notify_on_exit: true,
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [useCurrentLocation, setUseCurrentLocation] = useState(false)

  const { data: devicesData, isLoading: devicesLoading } = useDevices({ per_page: 100 })
  const createGeofence = useCreateGeofence()

  useEffect(() => {
    if (defaultDeviceId) {
      setFormData((prev) => ({ ...prev, device_id: defaultDeviceId.toString() }))
    }
  }, [defaultDeviceId])

  // Get map center from form data
  const mapCenter =
    formData.center_latitude && formData.center_longitude
      ? { lat: parseFloat(formData.center_latitude), lng: parseFloat(formData.center_longitude) }
      : null

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    const { name, value, type } = e.target
    if (type === 'checkbox') {
      const checked = (e.target as HTMLInputElement).checked
      setFormData((prev) => ({ ...prev, [name]: checked }))
    } else {
      setFormData((prev) => ({ ...prev, [name]: value }))
    }
    if (errors[name]) {
      setErrors((prev) => ({ ...prev, [name]: '' }))
    }
  }

  const handleMapCenterChange = (center: { lat: number; lng: number }) => {
    setFormData((prev) => ({
      ...prev,
      center_latitude: center.lat.toFixed(6),
      center_longitude: center.lng.toFixed(6),
    }))
    setErrors((prev) => ({ ...prev, center_latitude: '', center_longitude: '' }))
  }

  const handleRadiusChange = (radius: number) => {
    setFormData((prev) => ({ ...prev, radius_meters: radius }))
  }

  const getCurrentLocation = () => {
    if (!navigator.geolocation) {
      setErrors((prev) => ({ ...prev, location: 'Geolocation is not supported' }))
      return
    }

    setUseCurrentLocation(true)
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setFormData((prev) => ({
          ...prev,
          center_latitude: position.coords.latitude.toFixed(6),
          center_longitude: position.coords.longitude.toFixed(6),
        }))
        setUseCurrentLocation(false)
      },
      () => {
        setErrors((prev) => ({ ...prev, location: 'Could not get location' }))
        setUseCurrentLocation(false)
      }
    )
  }

  const validate = () => {
    const newErrors: Record<string, string> = {}
    if (!formData.device_id) newErrors.device_id = 'Device is required'
    if (!formData.name.trim()) newErrors.name = 'Name is required'
    if (!formData.center_latitude) newErrors.center_latitude = 'Latitude is required'
    if (!formData.center_longitude) newErrors.center_longitude = 'Longitude is required'
    if (formData.radius_meters <= 0) {
      newErrors.radius_meters = 'Radius must be greater than 0'
    }

    const lat = parseFloat(formData.center_latitude)
    const lng = parseFloat(formData.center_longitude)
    if (isNaN(lat) || lat < -90 || lat > 90) {
      newErrors.center_latitude = 'Invalid latitude (-90 to 90)'
    }
    if (isNaN(lng) || lng < -180 || lng > 180) {
      newErrors.center_longitude = 'Invalid longitude (-180 to 180)'
    }

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!validate()) return

    createGeofence.mutate(
      {
        device_id: parseInt(formData.device_id),
        name: formData.name,
        type: formData.type,
        center_latitude: parseFloat(formData.center_latitude),
        center_longitude: parseFloat(formData.center_longitude),
        radius_meters: formData.radius_meters,
        is_active: true,
        notify_on_entry: formData.notify_on_entry,
        notify_on_exit: formData.notify_on_exit,
      },
      {
        onSuccess: () => {
          toast.success('Geofence created successfully')
          onClose()
          setFormData({
            device_id: '',
            name: '',
            type: 'circle',
            center_latitude: '',
            center_longitude: '',
            radius_meters: 100,
            notify_on_entry: true,
            notify_on_exit: true,
          })
        },
        onError: (error) => {
          const err = error as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }
          if (err.response?.data?.errors) {
            const apiErrors: Record<string, string> = {}
            Object.entries(err.response.data.errors).forEach(([key, messages]) => {
              apiErrors[key] = messages[0]
            })
            setErrors(apiErrors)
          } else {
            toast.error(err.response?.data?.message || 'Failed to create geofence')
          }
        },
      }
    )
  }

  if (!isOpen) return null

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto">
      {/* Backdrop */}
      <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" onClick={onClose} />

      {/* Modal */}
      <div className="relative min-h-screen flex items-center justify-center p-4">
        <div className="relative bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-hidden">
          {/* Header */}
          <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <div className="flex items-center gap-3">
              <div className="p-2 bg-violet-50 rounded-lg">
                <Shield className="h-5 w-5 text-violet-600" />
              </div>
              <h2 className="text-lg font-semibold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
                Create Geofence
              </h2>
            </div>
            <button
              onClick={onClose}
              className="p-2 hover:bg-slate-100 rounded-lg transition-colors"
            >
              <X className="h-5 w-5 text-slate-500" />
            </button>
          </div>

          {/* Form */}
          <form onSubmit={handleSubmit} className="overflow-y-auto max-h-[calc(90vh-140px)]">
            <div className="p-6 space-y-5">
              {/* Device Selection */}
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">
                  Device <span className="text-red-500">*</span>
                </label>
                <select
                  name="device_id"
                  value={formData.device_id}
                  onChange={handleChange}
                  disabled={devicesLoading}
                  className={`w-full px-4 py-2.5 bg-slate-50 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 ${
                    errors.device_id ? 'border-red-300' : 'border-slate-200'
                  }`}
                >
                  <option value="">Select device</option>
                  {devicesData?.data?.map((device) => (
                    <option key={device.id} value={device.id}>
                      {device.name} ({device.brand} {device.model})
                    </option>
                  ))}
                </select>
                {errors.device_id && (
                  <p className="mt-1 text-xs text-red-500">{errors.device_id}</p>
                )}
              </div>

              {/* Name */}
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">
                  Geofence Name <span className="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  name="name"
                  value={formData.name}
                  onChange={handleChange}
                  placeholder="e.g., Home, Office, School"
                  className={`w-full px-4 py-2.5 bg-slate-50 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 ${
                    errors.name ? 'border-red-300' : 'border-slate-200'
                  }`}
                />
                {errors.name && <p className="mt-1 text-xs text-red-500">{errors.name}</p>}
              </div>

              {/* Input Mode Toggle */}
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-2">
                  Location Input Method
                </label>
                <div className="flex gap-2">
                  <button
                    type="button"
                    onClick={() => setInputMode('map')}
                    className={`flex-1 flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium transition-colors ${
                      inputMode === 'map'
                        ? 'bg-violet-100 text-violet-700 border-2 border-violet-500'
                        : 'bg-slate-50 text-slate-600 border-2 border-transparent hover:bg-slate-100'
                    }`}
                  >
                    <Map className="h-4 w-4" />
                    Use Map
                  </button>
                  <button
                    type="button"
                    onClick={() => setInputMode('manual')}
                    className={`flex-1 flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium transition-colors ${
                      inputMode === 'manual'
                        ? 'bg-violet-100 text-violet-700 border-2 border-violet-500'
                        : 'bg-slate-50 text-slate-600 border-2 border-transparent hover:bg-slate-100'
                    }`}
                  >
                    <Keyboard className="h-4 w-4" />
                    Enter Manually
                  </button>
                </div>
              </div>

              {/* Map Input */}
              {inputMode === 'map' && (
                <div>
                  <div className="flex items-center justify-between mb-2">
                    <label className="block text-sm font-medium text-slate-700">
                      Draw on Map <span className="text-red-500">*</span>
                    </label>
                    <button
                      type="button"
                      onClick={getCurrentLocation}
                      disabled={useCurrentLocation}
                      className="text-xs text-violet-600 hover:text-violet-700 font-medium inline-flex items-center gap-1"
                    >
                      {useCurrentLocation ? (
                        <Loader2 className="h-3 w-3 animate-spin" />
                      ) : (
                        <MapPin className="h-3 w-3" />
                      )}
                      Use my location
                    </button>
                  </div>
                  <GeofenceMap
                    center={mapCenter}
                    radius={formData.radius_meters}
                    onCenterChange={handleMapCenterChange}
                    onRadiusChange={handleRadiusChange}
                    height="350px"
                  />
                  {(errors.center_latitude || errors.center_longitude) && (
                    <p className="mt-2 text-xs text-red-500">Please click on the map to set the geofence center</p>
                  )}
                  {mapCenter && (
                    <p className="mt-2 text-xs text-slate-500">
                      Center: {mapCenter.lat.toFixed(6)}, {mapCenter.lng.toFixed(6)} | Radius: {formData.radius_meters}m
                    </p>
                  )}
                </div>
              )}

              {/* Manual Location Input */}
              {inputMode === 'manual' && (
                <>
                  <div>
                    <div className="flex items-center justify-between mb-1.5">
                      <label className="block text-sm font-medium text-slate-700">
                        Center Location <span className="text-red-500">*</span>
                      </label>
                      <button
                        type="button"
                        onClick={getCurrentLocation}
                        disabled={useCurrentLocation}
                        className="text-xs text-violet-600 hover:text-violet-700 font-medium inline-flex items-center gap-1"
                      >
                        {useCurrentLocation ? (
                          <Loader2 className="h-3 w-3 animate-spin" />
                        ) : (
                          <MapPin className="h-3 w-3" />
                        )}
                        Use current location
                      </button>
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                      <div>
                        <input
                          type="text"
                          name="center_latitude"
                          value={formData.center_latitude}
                          onChange={handleChange}
                          placeholder="Latitude"
                          className={`w-full px-4 py-2.5 bg-slate-50 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 ${
                            errors.center_latitude ? 'border-red-300' : 'border-slate-200'
                          }`}
                        />
                        {errors.center_latitude && (
                          <p className="mt-1 text-xs text-red-500">{errors.center_latitude}</p>
                        )}
                      </div>
                      <div>
                        <input
                          type="text"
                          name="center_longitude"
                          value={formData.center_longitude}
                          onChange={handleChange}
                          placeholder="Longitude"
                          className={`w-full px-4 py-2.5 bg-slate-50 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 ${
                            errors.center_longitude ? 'border-red-300' : 'border-slate-200'
                          }`}
                        />
                        {errors.center_longitude && (
                          <p className="mt-1 text-xs text-red-500">{errors.center_longitude}</p>
                        )}
                      </div>
                    </div>
                    {errors.location && (
                      <p className="mt-1 text-xs text-red-500">{errors.location}</p>
                    )}
                  </div>

                  {/* Radius */}
                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1.5">
                      Radius (meters) <span className="text-red-500">*</span>
                    </label>
                    <input
                      type="number"
                      name="radius_meters"
                      value={formData.radius_meters}
                      onChange={(e) => setFormData((prev) => ({ ...prev, radius_meters: parseInt(e.target.value) || 0 }))}
                      min="10"
                      max="50000"
                      className={`w-full px-4 py-2.5 bg-slate-50 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 ${
                        errors.radius_meters ? 'border-red-300' : 'border-slate-200'
                      }`}
                    />
                    {errors.radius_meters && (
                      <p className="mt-1 text-xs text-red-500">{errors.radius_meters}</p>
                    )}
                    <p className="mt-1 text-xs text-slate-500">
                      Common sizes: 50m (home), 100m (office), 500m (neighborhood)
                    </p>
                  </div>
                </>
              )}

              {/* Notification Settings */}
              <div className="space-y-3">
                <label className="block text-sm font-medium text-slate-700">
                  Notifications
                </label>
                <div className="flex items-center gap-3 p-3 bg-slate-50 rounded-lg">
                  <input
                    type="checkbox"
                    id="notify_on_entry"
                    name="notify_on_entry"
                    checked={formData.notify_on_entry}
                    onChange={handleChange}
                    className="h-4 w-4 text-violet-600 rounded border-slate-300 focus:ring-violet-500"
                  />
                  <label htmlFor="notify_on_entry" className="text-sm text-slate-700">
                    Notify when device <strong>enters</strong> this zone
                  </label>
                </div>
                <div className="flex items-center gap-3 p-3 bg-slate-50 rounded-lg">
                  <input
                    type="checkbox"
                    id="notify_on_exit"
                    name="notify_on_exit"
                    checked={formData.notify_on_exit}
                    onChange={handleChange}
                    className="h-4 w-4 text-violet-600 rounded border-slate-300 focus:ring-violet-500"
                  />
                  <label htmlFor="notify_on_exit" className="text-sm text-slate-700">
                    Notify when device <strong>exits</strong> this zone
                  </label>
                </div>
              </div>
            </div>

            {/* Footer */}
            <div className="flex items-center justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50/50">
              <button
                type="button"
                onClick={onClose}
                className="px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-slate-800 hover:bg-slate-100 rounded-lg transition-colors"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={createGeofence.isPending}
                className="inline-flex items-center gap-2 px-5 py-2.5 bg-violet-500 text-white rounded-lg text-sm font-medium hover:bg-violet-600 transition-colors disabled:opacity-50"
              >
                {createGeofence.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
                {createGeofence.isPending ? 'Creating...' : 'Create Geofence'}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  )
}
