import { useState } from 'react'
import { X, Loader2, AlertTriangle, MapPin } from 'lucide-react'
import { useCreateTheftReport } from '@/hooks/useTheftReports'
import { useDevices } from '@/hooks/useDevices'
import type { DeviceStatus, DeviceStatusValue } from '@/types'

// Helper to extract status value from either string or object format
function getStatusValue(status: DeviceStatus): DeviceStatusValue {
  if (typeof status === 'string') return status
  return status.value
}

interface CreateTheftReportModalProps {
  isOpen: boolean
  onClose: () => void
  defaultDeviceId?: number
}

export default function CreateTheftReportModal({ isOpen, onClose, defaultDeviceId }: CreateTheftReportModalProps) {
  const [formData, setFormData] = useState({
    device_id: defaultDeviceId?.toString() || '',
    theft_date: new Date().toISOString().split('T')[0],
    theft_location: '',
    description: '',
    police_report_number: '',
    police_station: '',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [gettingLocation, setGettingLocation] = useState(false)

  const { data: devicesData, isLoading: devicesLoading } = useDevices({ per_page: 100 })
  const createReport = useCreateTheftReport()

  // Filter to only show devices that can be reported stolen
  const availableDevices = devicesData?.data?.filter(
    (d) => {
      const status = getStatusValue(d.status)
      return status === 'active' || status === 'inactive'
    }
  )

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
    const { name, value } = e.target
    setFormData((prev) => ({ ...prev, [name]: value }))
    if (errors[name]) {
      setErrors((prev) => ({ ...prev, [name]: '' }))
    }
  }

  const getCurrentLocation = () => {
    if (!navigator.geolocation) {
      setErrors((prev) => ({ ...prev, theft_location: 'Geolocation is not supported' }))
      return
    }

    setGettingLocation(true)
    navigator.geolocation.getCurrentPosition(
      async (position) => {
        // Try to get address from coordinates using reverse geocoding
        // For now, just use coordinates
        const coords = `${position.coords.latitude.toFixed(6)}, ${position.coords.longitude.toFixed(6)}`
        setFormData((prev) => ({ ...prev, theft_location: coords }))
        setGettingLocation(false)
      },
      () => {
        setErrors((prev) => ({ ...prev, theft_location: 'Could not get location' }))
        setGettingLocation(false)
      }
    )
  }

  const validate = () => {
    const newErrors: Record<string, string> = {}
    if (!formData.device_id) newErrors.device_id = 'Please select a device'
    if (!formData.theft_date) newErrors.theft_date = 'Theft date is required'
    if (!formData.description.trim()) newErrors.description = 'Please describe the theft'
    if (formData.description.trim().length < 20) {
      newErrors.description = 'Description must be at least 20 characters'
    }
    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!validate()) return

    createReport.mutate(
      {
        device_id: parseInt(formData.device_id),
        theft_date: formData.theft_date,
        theft_location: formData.theft_location || undefined,
        description: formData.description,
        police_report_number: formData.police_report_number || undefined,
        police_station: formData.police_station || undefined,
      },
      {
        onSuccess: () => {
          onClose()
          setFormData({
            device_id: '',
            theft_date: new Date().toISOString().split('T')[0],
            theft_location: '',
            description: '',
            police_report_number: '',
            police_station: '',
          })
        },
        onError: (error) => {
          const err = error as { response?: { data?: { errors?: Record<string, string[]> } } }
          if (err.response?.data?.errors) {
            const apiErrors: Record<string, string> = {}
            Object.entries(err.response.data.errors).forEach(([key, messages]) => {
              apiErrors[key] = messages[0]
            })
            setErrors(apiErrors)
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
        <div className="relative bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-hidden">
          {/* Header */}
          <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-red-50">
            <div className="flex items-center gap-3">
              <div className="p-2 bg-red-100 rounded-lg">
                <AlertTriangle className="h-5 w-5 text-red-600" />
              </div>
              <div>
                <h2 className="text-lg font-semibold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
                  Report Theft
                </h2>
                <p className="text-xs text-slate-500">File a theft report for your device</p>
              </div>
            </div>
            <button
              onClick={onClose}
              className="p-2 hover:bg-red-100 rounded-lg transition-colors"
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
                  Stolen Device <span className="text-red-500">*</span>
                </label>
                <select
                  name="device_id"
                  value={formData.device_id}
                  onChange={handleChange}
                  disabled={devicesLoading}
                  className={`w-full px-4 py-2.5 bg-slate-50 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-red-500/20 focus:border-red-500 ${
                    errors.device_id ? 'border-red-300' : 'border-slate-200'
                  }`}
                >
                  <option value="">Select the stolen device</option>
                  {availableDevices?.map((device) => (
                    <option key={device.id} value={device.id}>
                      {device.name} ({device.brand} {device.model})
                    </option>
                  ))}
                </select>
                {errors.device_id && (
                  <p className="mt-1 text-xs text-red-500">{errors.device_id}</p>
                )}
                {!availableDevices?.length && !devicesLoading && (
                  <p className="mt-1 text-xs text-amber-600">
                    No devices available for theft report. All devices may already be reported stolen.
                  </p>
                )}
              </div>

              {/* Theft Date */}
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">
                  When was it stolen? <span className="text-red-500">*</span>
                </label>
                <input
                  type="date"
                  name="theft_date"
                  value={formData.theft_date}
                  onChange={handleChange}
                  max={new Date().toISOString().split('T')[0]}
                  className={`w-full px-4 py-2.5 bg-slate-50 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-red-500/20 focus:border-red-500 ${
                    errors.theft_date ? 'border-red-300' : 'border-slate-200'
                  }`}
                />
                {errors.theft_date && (
                  <p className="mt-1 text-xs text-red-500">{errors.theft_date}</p>
                )}
              </div>

              {/* Theft Location */}
              <div>
                <div className="flex items-center justify-between mb-1.5">
                  <label className="block text-sm font-medium text-slate-700">
                    Where was it stolen?
                  </label>
                  <button
                    type="button"
                    onClick={getCurrentLocation}
                    disabled={gettingLocation}
                    className="text-xs text-red-600 hover:text-red-700 font-medium inline-flex items-center gap-1"
                  >
                    {gettingLocation ? (
                      <Loader2 className="h-3 w-3 animate-spin" />
                    ) : (
                      <MapPin className="h-3 w-3" />
                    )}
                    Use current location
                  </button>
                </div>
                <input
                  type="text"
                  name="theft_location"
                  value={formData.theft_location}
                  onChange={handleChange}
                  placeholder="e.g., City Centre Mall, Lilongwe"
                  className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-red-500/20 focus:border-red-500"
                />
              </div>

              {/* Description */}
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">
                  Describe what happened <span className="text-red-500">*</span>
                </label>
                <textarea
                  name="description"
                  value={formData.description}
                  onChange={handleChange}
                  rows={4}
                  placeholder="Provide details about the theft: what happened, any witnesses, circumstances..."
                  className={`w-full px-4 py-2.5 bg-slate-50 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-red-500/20 focus:border-red-500 resize-none ${
                    errors.description ? 'border-red-300' : 'border-slate-200'
                  }`}
                />
                {errors.description && (
                  <p className="mt-1 text-xs text-red-500">{errors.description}</p>
                )}
              </div>

              {/* Police Report Details */}
              <div className="pt-4 border-t border-slate-100">
                <p className="text-sm font-medium text-slate-700 mb-3">
                  Police Report (Optional)
                </p>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-xs text-slate-500 mb-1">
                      Report Number
                    </label>
                    <input
                      type="text"
                      name="police_report_number"
                      value={formData.police_report_number}
                      onChange={handleChange}
                      placeholder="e.g., PR/2024/001"
                      className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-red-500/20 focus:border-red-500"
                    />
                  </div>
                  <div>
                    <label className="block text-xs text-slate-500 mb-1">
                      Police Station
                    </label>
                    <input
                      type="text"
                      name="police_station"
                      value={formData.police_station}
                      onChange={handleChange}
                      placeholder="e.g., Area 3 Police"
                      className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-red-500/20 focus:border-red-500"
                    />
                  </div>
                </div>
                <p className="text-xs text-slate-400 mt-2">
                  If you've reported to the police, adding the report number can help with recovery.
                </p>
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
                disabled={createReport.isPending}
                className="inline-flex items-center gap-2 px-5 py-2.5 bg-red-500 text-white rounded-lg text-sm font-medium hover:bg-red-600 transition-colors disabled:opacity-50"
              >
                {createReport.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
                {createReport.isPending ? 'Submitting...' : 'Submit Report'}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  )
}
