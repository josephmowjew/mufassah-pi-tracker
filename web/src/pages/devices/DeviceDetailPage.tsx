import React, { useState } from 'react'
import { useParams, Link, useNavigate, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import {
  ArrowLeft,
  Smartphone,
  MapPin,
  Calendar,
  Tag,
  Hash,
  Palette,
  DollarSign,
  Clock,
  Edit,
  Trash2,
  AlertTriangle,
  CheckCircle,
  XCircle,
  Shield,
  Camera,
  Plus,
  Loader2,
  ChevronRight,
  Activity,
  Wifi,
  Battery,
  Signal,
} from 'lucide-react'
import {
  useDevice,
  useDeleteDevice,
  useUpdateDeviceStatus,
  useUploadDevicePhotos,
  useDeleteDevicePhoto,
  useDeviceLocations,
  useDeviceLatestLocation,
} from '@/hooks/useDevices'
import { getStorageUrl } from '@/api/client'
import type { DeviceStatus, DeviceStatusValue } from '@/types'
import EditDeviceModal from './EditDeviceModal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'

const statusConfig: Record<DeviceStatusValue, { bg: string; text: string; icon: typeof CheckCircle; label: string }> = {
  active: { bg: 'bg-emerald-50', text: 'text-emerald-700', icon: CheckCircle, label: 'Active' },
  inactive: { bg: 'bg-slate-100', text: 'text-slate-600', icon: XCircle, label: 'Inactive' },
  stolen: { bg: 'bg-red-50', text: 'text-red-700', icon: AlertTriangle, label: 'Stolen' },
  recovered: { bg: 'bg-blue-50', text: 'text-blue-700', icon: Shield, label: 'Recovered' },
}

// Helper to extract status value from either string or object format
function getStatusValue(status: DeviceStatus): DeviceStatusValue {
  if (typeof status === 'string') return status
  return status.value
}

export default function DeviceDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const [showEditModal, setShowEditModal] = useState(searchParams.get('edit') === 'true')
  const [showStatusMenu, setShowStatusMenu] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [photoToDelete, setPhotoToDelete] = useState<number | null>(null)
  const [showDeleteDeviceDialog, setShowDeleteDeviceDialog] = useState(false)

  const deviceId = parseInt(id || '0')
  const { data: device, isLoading, error } = useDevice(deviceId)
  const { data: locations } = useDeviceLocations(deviceId)
  const { data: latestLocation } = useDeviceLatestLocation(deviceId)
  const deleteDevice = useDeleteDevice()
  const updateStatus = useUpdateDeviceStatus()
  const uploadPhotos = useUploadDevicePhotos()
  const deletePhoto = useDeleteDevicePhoto()

  const handleDeleteDeviceConfirm = () => {
    deleteDevice.mutate(deviceId, {
      onSuccess: () => {
        toast.success('Device deleted successfully')
        navigate('/devices')
      },
      onError: () => {
        toast.error('Failed to delete device. Please try again.')
      },
      onSettled: () => setShowDeleteDeviceDialog(false),
    })
  }

  const handleStatusChange = (status: DeviceStatusValue) => {
    updateStatus.mutate(
      { id: deviceId, status },
      {
        onSuccess: () => {
          toast.success(`Device status updated to ${statusConfig[status].label}`)
        },
        onError: () => {
          toast.error('Failed to update status. Please try again.')
        },
      }
    )
    setShowStatusMenu(false)
  }

  const handlePhotoUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = e.target.files
    if (!files || files.length === 0) return

    const fileArray = Array.from(files)
    const maxSize = 2 * 1024 * 1024 // 2MB
    const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg']
    const currentPhotos = device?.photos?.length || 0
    const maxPhotos = 5

    // Client-side validation
    if (currentPhotos + fileArray.length > maxPhotos) {
      toast.error(`You can only have ${maxPhotos} photos per device. Currently have ${currentPhotos}.`)
      e.target.value = ''
      return
    }

    const invalidFiles = fileArray.filter(file => !allowedTypes.includes(file.type))
    if (invalidFiles.length > 0) {
      toast.error('Only JPEG and PNG images are allowed.')
      e.target.value = ''
      return
    }

    const oversizedFiles = fileArray.filter(file => file.size > maxSize)
    if (oversizedFiles.length > 0) {
      toast.error('Each photo must be less than 2MB.')
      e.target.value = ''
      return
    }

    setUploading(true)
    uploadPhotos.mutate(
      { id: deviceId, photos: fileArray },
      {
        onSuccess: () => {
          toast.success(fileArray.length > 1 ? `${fileArray.length} photos uploaded successfully` : 'Photo uploaded successfully')
        },
        onError: (error) => {
          const err = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
          if (err.response?.data?.errors) {
            const errorMessages = Object.values(err.response.data.errors).flat()
            if (errorMessages.some(msg => msg.includes('failed to upload'))) {
              toast.error('Upload failed. Please ensure photos are JPEG/PNG and under 2MB each.')
            } else {
              toast.error(errorMessages[0] || 'Failed to upload photos.')
            }
          } else {
            toast.error('Failed to upload photos. Please try again.')
          }
        },
        onSettled: () => setUploading(false),
      }
    )
    e.target.value = ''
  }

  const handlePhotoDeleteConfirm = () => {
    if (photoToDelete === null) return
    deletePhoto.mutate(
      { id: deviceId, photoIndex: photoToDelete },
      {
        onSuccess: () => {
          toast.success('Photo deleted successfully')
        },
        onError: () => {
          toast.error('Failed to delete photo. Please try again.')
        },
        onSettled: () => setPhotoToDelete(null),
      }
    )
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <Loader2 className="h-8 w-8 text-teal-500 animate-spin" />
      </div>
    )
  }

  if (error || !device) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[400px] text-center">
        <AlertTriangle className="h-12 w-12 text-red-400 mb-4" />
        <h2 className="text-lg font-semibold text-slate-800 mb-2">Device Not Found</h2>
        <p className="text-slate-500 mb-6">The device you're looking for doesn't exist or has been removed.</p>
        <Link
          to="/devices"
          className="inline-flex items-center gap-2 px-4 py-2 bg-teal-500 text-white rounded-lg text-sm font-medium hover:bg-teal-600 transition-colors"
        >
          <ArrowLeft className="h-4 w-4" />
          Back to Devices
        </Link>
      </div>
    )
  }

  const deviceStatusValue = getStatusValue(device.status)
  const currentStatusConfig = statusConfig[deviceStatusValue] || statusConfig.inactive
  const StatusIcon = currentStatusConfig.icon

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div className="flex items-center gap-4">
          <Link
            to="/devices"
            className="p-2 hover:bg-slate-100 rounded-lg transition-colors"
          >
            <ArrowLeft className="h-5 w-5 text-slate-500" />
          </Link>
          <div>
            <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
              {device.name}
            </h1>
            <p className="text-sm text-slate-500 mt-0.5">
              {device.brand} {device.model}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <button
            onClick={() => setShowEditModal(true)}
            className="inline-flex items-center gap-2 px-4 py-2.5 border border-slate-200 text-slate-700 rounded-xl text-sm font-medium hover:bg-slate-50 transition-colors"
          >
            <Edit className="h-4 w-4" />
            Edit
          </button>
          <button
            onClick={() => setShowDeleteDeviceDialog(true)}
            className="inline-flex items-center gap-2 px-4 py-2.5 border border-red-200 text-red-600 rounded-xl text-sm font-medium hover:bg-red-50 transition-colors"
          >
            <Trash2 className="h-4 w-4" />
            Delete
          </button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Main Info */}
        <div className="lg:col-span-2 space-y-6">
          {/* Status & Quick Info */}
          <div className="bg-white rounded-xl border border-slate-200/60 p-6">
            <div className="flex items-start justify-between mb-6">
              <div className="flex items-center gap-4">
                <div className="h-16 w-16 rounded-xl bg-gradient-to-br from-slate-100 to-slate-50 border border-slate-200 flex items-center justify-center">
                  <Smartphone className="h-8 w-8 text-slate-500" />
                </div>
                <div>
                  <div className="flex items-center gap-3 mb-1">
                    <span
                      className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium ${currentStatusConfig.bg} ${currentStatusConfig.text}`}
                    >
                      <StatusIcon className="h-4 w-4" />
                      {currentStatusConfig.label}
                    </span>
                  </div>
                  <p className="text-sm text-slate-500">
                    Device Code: <span className="font-mono text-slate-700">{device.unique_device_code || device.device_code}</span>
                  </p>
                </div>
              </div>
              <div className="relative">
                <button
                  onClick={() => setShowStatusMenu(!showStatusMenu)}
                  disabled={updateStatus.isPending}
                  className="text-sm text-teal-600 hover:text-teal-700 font-medium disabled:opacity-50"
                >
                  {updateStatus.isPending ? 'Updating...' : 'Change Status'}
                </button>
                {showStatusMenu && (
                  <>
                    <div className="fixed inset-0 z-10" onClick={() => setShowStatusMenu(false)} />
                    <div className="absolute right-0 mt-2 w-48 bg-white border border-slate-200 rounded-lg shadow-lg z-20 py-1">
                      {(Object.keys(statusConfig) as DeviceStatusValue[]).map((status) => (
                        <button
                          key={status}
                          onClick={() => handleStatusChange(status)}
                          disabled={deviceStatusValue === status}
                          className={`flex items-center gap-2 w-full px-3 py-2 text-sm text-left hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed ${statusConfig[status].text}`}
                        >
                          {React.createElement(statusConfig[status].icon, { className: 'h-4 w-4' })}
                          {statusConfig[status].label}
                        </button>
                      ))}
                    </div>
                  </>
                )}
              </div>
            </div>

            {/* Device Details Grid */}
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-4">
              <DetailItem icon={Tag} label="Category" value={device.category?.name || 'N/A'} />
              <DetailItem icon={Hash} label="Serial Number" value={device.serial_number || 'N/A'} mono />
              <DetailItem icon={Palette} label="Color" value={device.color || 'N/A'} />
              <DetailItem icon={Calendar} label="Purchase Date" value={device.purchase_date ? new Date(device.purchase_date).toLocaleDateString() : 'N/A'} />
              <DetailItem icon={DollarSign} label="Purchase Price" value={device.purchase_price ? `MWK ${Number(device.purchase_price).toLocaleString()}` : 'N/A'} />
              <DetailItem icon={MapPin} label="Purchase Location" value={device.purchase_location || 'N/A'} />
            </div>

            {device.description && (
              <div className="mt-6 pt-6 border-t border-slate-100">
                <h3 className="text-sm font-medium text-slate-700 mb-2">Description</h3>
                <p className="text-sm text-slate-600">{device.description}</p>
              </div>
            )}
          </div>

          {/* Photos */}
          <div className="bg-white rounded-xl border border-slate-200/60 p-6">
            <div className="flex items-center justify-between mb-4">
              <div>
                <h2 className="text-base font-semibold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
                  Photos
                </h2>
                <p className="text-xs text-slate-400 mt-0.5">
                  {device.photos?.length || 0}/5 photos (JPEG/PNG, max 2MB each)
                </p>
              </div>
              <label
                className="inline-flex items-center gap-2 px-3 py-1.5 border border-slate-200 text-slate-600 rounded-lg text-sm font-medium hover:bg-slate-50 transition-colors cursor-pointer"
                title="JPEG/PNG only, max 2MB each, up to 5 photos"
              >
                {uploading ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />}
                Add Photo
                <input
                  type="file"
                  accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                  multiple
                  onChange={handlePhotoUpload}
                  className="hidden"
                  disabled={uploading || (device.photos?.length || 0) >= 5}
                />
              </label>
            </div>
            {device.photos && device.photos.length > 0 ? (
              <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                {device.photos.map((photo, index) => (
                  <div key={index} className="aspect-square rounded-lg overflow-hidden bg-slate-100 group relative">
                    <img
                      src={getStorageUrl(photo)}
                      alt={`${device.name} photo ${index + 1}`}
                      className="w-full h-full object-cover"
                      onError={(e) => {
                        // Fallback for broken images
                        const target = e.target as HTMLImageElement
                        target.src = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="%23cbd5e1" stroke-width="1"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>'
                      }}
                    />
                    {/* Delete button overlay */}
                    <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                      <button
                        onClick={() => setPhotoToDelete(index)}
                        className="p-2 bg-red-500 text-white rounded-full hover:bg-red-600 transition-colors"
                        title="Delete photo"
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <div className="flex flex-col items-center justify-center py-10 text-center border-2 border-dashed border-slate-200 rounded-lg">
                <Camera className="h-8 w-8 text-slate-300 mb-2" />
                <p className="text-sm text-slate-500">No photos yet</p>
                <p className="text-xs text-slate-400">Upload photos of your device</p>
              </div>
            )}
          </div>

          {/* Location History */}
          <div className="bg-white rounded-xl border border-slate-200/60 p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-base font-semibold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
                Location History
              </h2>
              <Link
                to={`/tracking?device=${deviceId}`}
                className="text-sm text-teal-600 hover:text-teal-700 font-medium inline-flex items-center gap-1"
              >
                View on Map
                <ChevronRight className="h-4 w-4" />
              </Link>
            </div>
            {locations && locations.length > 0 ? (
              <div className="space-y-3 max-h-[300px] overflow-y-auto">
                {locations.slice(0, 10).map((location) => (
                  <div key={location.id} className="flex items-start gap-3 p-3 rounded-lg bg-slate-50 hover:bg-slate-100 transition-colors">
                    <div className="p-2 bg-white rounded-lg border border-slate-200">
                      <MapPin className="h-4 w-4 text-teal-500" />
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm text-slate-700 truncate">
                        {location.address || `${location.latitude.toFixed(6)}, ${location.longitude.toFixed(6)}`}
                      </p>
                      <p className="text-xs text-slate-500 mt-0.5">
                        {new Date(location.recorded_at).toLocaleString()}
                      </p>
                    </div>
                    {location.accuracy && (
                      <span className="text-xs text-slate-400">±{location.accuracy}m</span>
                    )}
                  </div>
                ))}
              </div>
            ) : (
              <div className="flex flex-col items-center justify-center py-10 text-center border-2 border-dashed border-slate-200 rounded-lg">
                <MapPin className="h-8 w-8 text-slate-300 mb-2" />
                <p className="text-sm text-slate-500">No location data yet</p>
                <p className="text-xs text-slate-400">Locations will appear once tracking starts</p>
              </div>
            )}
          </div>
        </div>

        {/* Sidebar */}
        <div className="space-y-6">
          {/* Latest Location Card */}
          <div className="bg-white rounded-xl border border-slate-200/60 p-6">
            <h2 className="text-base font-semibold text-slate-800 mb-4" style={{ fontFamily: 'var(--font-heading)' }}>
              Current Location
            </h2>
            {latestLocation ? (
              <div className="space-y-4">
                <div className="aspect-video rounded-lg bg-gradient-to-br from-slate-100 to-slate-50 flex items-center justify-center border border-slate-200">
                  <div className="text-center">
                    <MapPin className="h-6 w-6 text-teal-500 mx-auto mb-1" />
                    <p className="text-xs text-slate-500">Map Preview</p>
                  </div>
                </div>
                <div>
                  <p className="text-sm text-slate-700">
                    {latestLocation.address || `${latestLocation.latitude.toFixed(6)}, ${latestLocation.longitude.toFixed(6)}`}
                  </p>
                  <p className="text-xs text-slate-500 mt-1 flex items-center gap-1">
                    <Clock className="h-3 w-3" />
                    {new Date(latestLocation.recorded_at).toLocaleString()}
                  </p>
                </div>
                <Link
                  to={`/tracking?device=${deviceId}`}
                  className="block w-full text-center px-4 py-2.5 bg-teal-500 text-white rounded-lg text-sm font-medium hover:bg-teal-600 transition-colors"
                >
                  Track Live
                </Link>
              </div>
            ) : (
              <div className="text-center py-6">
                <div className="p-3 bg-slate-100 rounded-full w-fit mx-auto mb-3">
                  <MapPin className="h-6 w-6 text-slate-400" />
                </div>
                <p className="text-sm text-slate-500">No location recorded</p>
              </div>
            )}
          </div>

          {/* IoT Module Card */}
          <div className="bg-white rounded-xl border border-slate-200/60 p-6">
            <h2 className="text-base font-semibold text-slate-800 mb-4" style={{ fontFamily: 'var(--font-heading)' }}>
              IoT Module
            </h2>
            {device.iot_module ? (
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <span className="text-sm text-slate-500">Module ID</span>
                  <span className="text-sm font-mono text-slate-700">{device.iot_module.module_id}</span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-sm text-slate-500">Status</span>
                  <span className={`inline-flex items-center gap-1 text-sm font-medium ${
                    device.iot_module.status === 'active' ? 'text-emerald-600' : 'text-slate-500'
                  }`}>
                    <Activity className="h-3.5 w-3.5" />
                    {device.iot_module.status}
                  </span>
                </div>
                {device.iot_module.battery_level !== null && (
                  <div className="flex items-center justify-between">
                    <span className="text-sm text-slate-500">Battery</span>
                    <span className="text-sm text-slate-700 flex items-center gap-1">
                      <Battery className="h-3.5 w-3.5" />
                      {device.iot_module.battery_level}%
                    </span>
                  </div>
                )}
                {device.iot_module.signal_strength !== null && (
                  <div className="flex items-center justify-between">
                    <span className="text-sm text-slate-500">Signal</span>
                    <span className="text-sm text-slate-700 flex items-center gap-1">
                      <Signal className="h-3.5 w-3.5" />
                      {device.iot_module.signal_strength}%
                    </span>
                  </div>
                )}
                {device.iot_module.last_ping && (
                  <div className="pt-3 border-t border-slate-100">
                    <p className="text-xs text-slate-500">
                      Last ping: {new Date(device.iot_module.last_ping).toLocaleString()}
                    </p>
                  </div>
                )}
              </div>
            ) : (
              <div className="text-center py-6">
                <div className="p-3 bg-slate-100 rounded-full w-fit mx-auto mb-3">
                  <Wifi className="h-6 w-6 text-slate-400" />
                </div>
                <p className="text-sm text-slate-500 mb-3">No IoT module linked</p>
                <button className="text-sm text-teal-600 hover:text-teal-700 font-medium">
                  Link Module
                </button>
              </div>
            )}
          </div>

          {/* Quick Actions */}
          <div className="bg-white rounded-xl border border-slate-200/60 p-6">
            <h2 className="text-base font-semibold text-slate-800 mb-4" style={{ fontFamily: 'var(--font-heading)' }}>
              Quick Actions
            </h2>
            <div className="space-y-2">
              <Link
                to={`/geofences?device=${deviceId}`}
                className="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-50 transition-colors text-slate-700"
              >
                <div className="p-2 bg-violet-50 rounded-lg">
                  <Shield className="h-4 w-4 text-violet-500" />
                </div>
                <span className="text-sm font-medium">Manage Geofences</span>
              </Link>
              {deviceStatusValue !== 'stolen' && (
                <button
                  onClick={() => handleStatusChange('stolen')}
                  className="flex items-center gap-3 p-3 rounded-lg hover:bg-red-50 transition-colors text-red-600 w-full"
                >
                  <div className="p-2 bg-red-50 rounded-lg">
                    <AlertTriangle className="h-4 w-4 text-red-500" />
                  </div>
                  <span className="text-sm font-medium">Report as Stolen</span>
                </button>
              )}
            </div>
          </div>

          {/* Timestamps */}
          <div className="bg-slate-50 rounded-xl p-4">
            <div className="space-y-2 text-xs text-slate-500">
              <p>Created: {new Date(device.created_at).toLocaleString()}</p>
              <p>Updated: {new Date(device.updated_at).toLocaleString()}</p>
              {device.last_seen_at && (
                <p>Last Seen: {new Date(device.last_seen_at).toLocaleString()}</p>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* Edit Modal */}
      {showEditModal && (
        <EditDeviceModal
          device={device}
          isOpen={showEditModal}
          onClose={() => setShowEditModal(false)}
        />
      )}

      {/* Delete Photo Confirmation Dialog */}
      <ConfirmDialog
        isOpen={photoToDelete !== null}
        onClose={() => setPhotoToDelete(null)}
        onConfirm={handlePhotoDeleteConfirm}
        title="Delete Photo"
        message="Are you sure you want to delete this photo? This action cannot be undone."
        confirmText="Delete"
        cancelText="Cancel"
        variant="danger"
        isLoading={deletePhoto.isPending}
      />

      {/* Delete Device Confirmation Dialog */}
      <ConfirmDialog
        isOpen={showDeleteDeviceDialog}
        onClose={() => setShowDeleteDeviceDialog(false)}
        onConfirm={handleDeleteDeviceConfirm}
        title="Delete Device"
        message={`Are you sure you want to delete "${device.name}"? This will permanently remove the device and all associated data including photos, location history, and geofences. This action cannot be undone.`}
        confirmText="Delete Device"
        cancelText="Cancel"
        variant="danger"
        isLoading={deleteDevice.isPending}
      />
    </div>
  )
}

function DetailItem({
  icon: Icon,
  label,
  value,
  mono = false,
}: {
  icon: typeof Tag
  label: string
  value: string
  mono?: boolean
}) {
  return (
    <div className="p-3 rounded-lg bg-slate-50">
      <div className="flex items-center gap-2 mb-1">
        <Icon className="h-3.5 w-3.5 text-slate-400" />
        <span className="text-xs text-slate-500">{label}</span>
      </div>
      <p className={`text-sm text-slate-700 ${mono ? 'font-mono' : ''}`}>{value}</p>
    </div>
  )
}
