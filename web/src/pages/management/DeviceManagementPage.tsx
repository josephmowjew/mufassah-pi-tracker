import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Search, Smartphone, MapPin, User, Loader2, Wifi, WifiOff, Eye, Calendar, Tag, Hash, Palette, DollarSign, Clock, Activity, Battery, Signal, SquarePen, Trash2, AlertTriangle, Link2, Unlink, Plus } from 'lucide-react'
import { Authorize } from '@/components/auth/Authorize'
import { PERMISSIONS } from '@/stores/auth-store'
import apiClient from '@/api/client'
import { toast } from 'sonner'
import type { Device, IotModule } from '@/types'
import ActionChallengePinModal, { type ActionChallengeInfo } from '@/components/admin/ActionChallengePinModal'
import { getApiErrorMessage, requiresApiConfirmation } from '@/lib/utils'

interface PaginatedResponse {
  data: Device[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export default function DeviceManagementPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [selectedDevice, setSelectedDevice] = useState<Device | null>(null)
  const [statusDevice, setStatusDevice] = useState<Device | null>(null)
  const [editingDevice, setEditingDevice] = useState<Device | null>(null)
  const [deleteDevice, setDeleteDevice] = useState<Device | null>(null)
  const [deleteReason, setDeleteReason] = useState('')
  const [deleteNotes, setDeleteNotes] = useState('')
  const [deleteChallenge, setDeleteChallenge] = useState<ActionChallengeInfo | null>(null)
  const [deleteChallengeError, setDeleteChallengeError] = useState<string | null>(null)
  const [deleteWarning, setDeleteWarning] = useState<string | null>(null)
  const [newStatus, setNewStatus] = useState('')
  const [linkingDevice, setLinkingDevice] = useState<Device | null>(null)
  const [moduleSearch, setModuleSearch] = useState('')
  const [selectedModuleId, setSelectedModuleId] = useState<number | null>(null)
  const [editForm, setEditForm] = useState({
    brand: '',
    model: '',
    serial_number: '',
    color: '',
    description: '',
    notes: '',
    purchase_date: '',
    purchase_price: '',
    purchase_location: '',
  })

  const { data, isLoading, error } = useQuery({
    queryKey: ['admin-devices', search, page],
    queryFn: async () => {
      const params = new URLSearchParams()
      params.append('page', page.toString())
      params.append('per_page', '15')
      if (search) params.append('search', search)

      const response = await apiClient.get<{ success: boolean; data: Device[]; meta: PaginatedResponse['meta'] }>(
        `/admin/devices?${params.toString()}`
      )
      return response.data
    },
  })

  // Mutations
  const updateDeviceMutation = useMutation({
    mutationFn: async ({ deviceId, data }: { deviceId: number; data: typeof editForm }) => {
      await apiClient.put(`/admin/devices/${deviceId}`, data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-devices'] })
      toast.success('Device updated successfully')
      setEditingDevice(null)
    },
    onError: () => {
      toast.error('Failed to update device')
    },
  })

  const updateStatusMutation = useMutation({
    mutationFn: async ({ deviceId, status }: { deviceId: number; status: string }) => {
      await apiClient.put(`/admin/devices/${deviceId}/status`, { status })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-devices'] })
      toast.success('Device status updated successfully')
      setStatusDevice(null)
      setNewStatus('')
    },
    onError: () => {
      toast.error('Failed to update device status')
    },
  })

  const requestDeleteMutation = useMutation({
    mutationFn: async ({ deviceId, reason, notes, confirmHistoryLoss }: { deviceId: number; reason: string; notes?: string; confirmHistoryLoss?: boolean }) => {
      const response = await apiClient.post<{ success: boolean; data: ActionChallengeInfo }>(`/admin/devices/${deviceId}/delete/request`, {
        reason,
        notes: notes || undefined,
        confirm_history_loss: confirmHistoryLoss || undefined,
      })
      return response.data.data
    },
    onSuccess: (challenge) => {
      setDeleteChallenge(challenge)
      setDeleteChallengeError(null)
      setDeleteWarning(null)
      toast.success('Verification PIN sent to the admin approval mailbox')
    },
    onError: (error) => {
      if (requiresApiConfirmation(error)) {
        setDeleteWarning(getApiErrorMessage(error, 'This device has inventory history. Confirm if you still want to delete it.'))
        return
      }
      setDeleteWarning(null)
      toast.error(getApiErrorMessage(error, 'Unable to request device deletion'))
    },
  })

  const verifyDeleteMutation = useMutation({
    mutationFn: async ({ challengeId, pin }: { challengeId: number; pin: string }) => {
      const response = await apiClient.post(`/admin/action-challenges/${challengeId}/verify`, { pin })
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-devices'] })
      toast.success('Device deleted successfully')
      setDeleteChallenge(null)
      setDeleteChallengeError(null)
      setDeleteDevice(null)
      setDeleteReason('')
      setDeleteNotes('')
    },
    onError: (error) => {
      setDeleteChallengeError(getApiErrorMessage(error, 'Invalid or expired PIN'))
    },
  })

  const resendDeleteMutation = useMutation({
    mutationFn: async (challengeId: number) => {
      const response = await apiClient.post<{ success: boolean; data: ActionChallengeInfo }>(`/admin/action-challenges/${challengeId}/resend`)
      return response.data.data
    },
    onSuccess: (challenge) => {
      setDeleteChallenge(challenge)
      setDeleteChallengeError(null)
      toast.success('A new PIN was sent')
    },
    onError: (error) => {
      setDeleteChallengeError(getApiErrorMessage(error, 'Unable to resend PIN'))
    },
  })

  // Search IoT modules for linking
  const { data: modulesData, isLoading: modulesLoading } = useQuery({
    queryKey: ['iot-modules-for-linking', moduleSearch],
    queryFn: async () => {
      if (!moduleSearch || moduleSearch.length < 2) return { data: [] }

      const params = new URLSearchParams()
      params.append('search', moduleSearch)
      params.append('per_page', '10')

      const response = await apiClient.get<{ success: boolean; data: IotModule[] }>(
        `/admin/iot-modules?${params.toString()}`
      )
      return response.data
    },
    enabled: !!linkingDevice && moduleSearch.length >= 2,
  })

  // Link module to device mutation
  const linkModuleMutation = useMutation({
    mutationFn: async ({ deviceId, moduleId }: { deviceId: number; moduleId: number }) => {
      await apiClient.post(`/devices/${deviceId}/link-module`, { iot_module_id: moduleId })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-devices'] })
      queryClient.invalidateQueries({ queryKey: ['iot-modules'] })
      toast.success('Module linked to device successfully')
      setLinkingDevice(null)
      setModuleSearch('')
      setSelectedModuleId(null)
    },
    onError: () => {
      toast.error('Failed to link module to device')
    },
  })

  // Unlink module from device mutation
  const unlinkModuleMutation = useMutation({
    mutationFn: async ({ deviceId }: { deviceId: number }) => {
      await apiClient.delete(`/devices/${deviceId}/unlink-module`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-devices'] })
      queryClient.invalidateQueries({ queryKey: ['iot-modules'] })
      toast.success('Module unlinked successfully')
    },
    onError: () => {
      toast.error('Failed to unlink module')
    },
  })

  const modules = modulesData?.data || []

  // Show error state
  if (error) {
    return (
      <div className="flex flex-col items-center justify-center py-16">
        <div className="h-12 w-12 rounded-xl bg-red-100 flex items-center justify-center mb-3">
          <Smartphone className="h-6 w-6 text-red-500" />
        </div>
        <p className="text-red-500 text-sm">Error loading devices: {error.message}</p>
      </div>
    )
  }

  const devices = data?.data || []
  const meta = data?.meta

  const getStatusBadge = (status: string) => {
    const statusConfig: Record<string, { bg: string; text: string; dot: string }> = {
      active: { bg: 'bg-emerald-50', text: 'text-emerald-700', dot: 'bg-emerald-500' },
      inactive: { bg: 'bg-slate-100', text: 'text-slate-600', dot: 'bg-slate-400' },
      stolen: { bg: 'bg-red-50', text: 'text-red-700', dot: 'bg-red-500' },
      recovered: { bg: 'bg-blue-50', text: 'text-blue-700', dot: 'bg-blue-500' },
    }
    const config = statusConfig[status] || statusConfig.inactive
    return (
      <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${config.bg} ${config.text}`}>
        <span className={`h-1.5 w-1.5 rounded-full ${config.dot}`} />
        {status}
      </span>
    )
  }

  const handleViewOwner = (userId: number) => {
    navigate(`/admin/users?highlight=${userId}`)
  }

  const handleEditDevice = (device: Device) => {
    setEditForm({
      brand: device.brand || '',
      model: device.model || '',
      serial_number: device.serial_number || '',
      color: device.color || '',
      description: device.description || '',
      notes: device.notes || '',
      purchase_date: device.purchase_date || '',
      purchase_price: device.purchase_price?.toString() || '',
      purchase_location: device.purchase_location || '',
    })
    setEditingDevice(device)
  }

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            All Devices
          </h1>
          <p className="text-slate-500 text-sm mt-1">View and manage all registered devices</p>
        </div>
        <div className="flex items-center gap-3">
          <div className="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-600 text-sm font-medium">
            {meta?.total || 0} total
          </div>
          <Authorize permission={PERMISSIONS.CREATE_DEVICES}>
            <button
              onClick={() => navigate('/admin/inventory')}
              className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors"
            >
              <Plus className="h-4 w-4" />
              Add Device
            </button>
          </Authorize>
        </div>
      </div>

      {/* Main Card */}
      <div className="bg-white rounded-xl border border-slate-200/60 shadow-sm">
        {/* Search Bar */}
        <div className="p-4 border-b border-slate-200/60">
          <div className="relative max-w-md">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
            <input
              type="text"
              placeholder="Search by serial, brand, model, or owner..."
              className="w-full pl-10 pr-4 py-2.5 rounded-lg border border-slate-200 bg-slate-50/50 text-sm placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(1)
              }}
            />
          </div>
        </div>

        {/* Content */}
        {isLoading ? (
          <div className="flex items-center justify-center py-16">
            <Loader2 className="h-8 w-8 animate-spin text-blue-500" />
          </div>
        ) : devices.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-16">
            <div className="h-12 w-12 rounded-xl bg-slate-100 flex items-center justify-center mb-3">
              <Smartphone className="h-6 w-6 text-slate-400" />
            </div>
            <p className="text-slate-500 text-sm">No devices found</p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-slate-200/60">
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Device</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Owner</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">IoT Module</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Last Seen</th>
                    <th className="text-right py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {devices.map((device) => {
                    const status = device.status
                      ? (typeof device.status === 'string' ? device.status : device.status.value)
                      : 'inactive'
                    return (
                      <tr key={device.id} className="hover:bg-slate-50/50 transition-colors">
                        <td className="py-3 px-4">
                          <div className="flex items-center gap-3">
                            <div className="h-10 w-10 rounded-xl bg-gradient-to-br from-slate-100 to-slate-200 flex items-center justify-center flex-shrink-0">
                              <Smartphone className="h-5 w-5 text-slate-500" />
                            </div>
                            <div className="min-w-0">
                              <p className="font-medium text-slate-800">{device.brand} {device.model}</p>
                              <p className="text-xs text-slate-500 font-mono">{device.serial_number}</p>
                            </div>
                          </div>
                        </td>
                        <td className="py-3 px-4">
                          <button
                            onClick={() => device.owner?.id && handleViewOwner(device.owner.id)}
                            className="flex items-center gap-2 text-slate-600 hover:text-blue-600 transition-colors group"
                          >
                            <User className="h-4 w-4 text-slate-400 group-hover:text-blue-500" />
                            <span className="text-sm">{device.owner?.name || 'Unknown'}</span>
                          </button>
                        </td>
                        <td className="py-3 px-4">
                          {getStatusBadge(status)}
                        </td>
                        <td className="py-3 px-4">
                          {device.iot_module ? (
                            <div className="flex items-center gap-1.5 text-emerald-600">
                              <Wifi className="h-4 w-4" />
                              <span className="text-xs font-medium">Connected</span>
                            </div>
                          ) : (
                            <div className="flex items-center gap-1.5 text-slate-400">
                              <WifiOff className="h-4 w-4" />
                              <span className="text-xs font-medium">Not linked</span>
                            </div>
                          )}
                        </td>
                        <td className="py-3 px-4">
                          {device.last_seen_at ? (
                            <div className="flex items-center gap-2 text-sm text-slate-500">
                              <MapPin className="h-4 w-4 text-slate-400" />
                              {new Date(device.last_seen_at).toLocaleDateString()}
                            </div>
                          ) : (
                            <span className="text-sm text-slate-400">Never</span>
                          )}
                        </td>
                        <td className="py-3 px-4">
                          <div className="flex items-center justify-end gap-1">
                            <Authorize permission={PERMISSIONS.VIEW_ALL_DEVICES}>
                              <button
                                onClick={() => setSelectedDevice(device)}
                                className="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                                title="View device details"
                              >
                                <Eye className="h-4 w-4" />
                              </button>
                            </Authorize>
                            <Authorize permission={PERMISSIONS.UPDATE_DEVICES}>
                              <button
                                onClick={() => handleEditDevice(device)}
                                className="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                                title="Edit device"
                              >
                                <SquarePen className="h-4 w-4" />
                              </button>
                            </Authorize>
                            <Authorize permission={PERMISSIONS.MANAGE_DEVICE_STATUS}>
                              <button
                                onClick={() => {
                                  setStatusDevice(device)
                                  setNewStatus(status)
                                }}
                                className="p-2 text-slate-400 hover:text-amber-600 hover:bg-amber-50 rounded-lg transition-colors"
                                title="Change status"
                              >
                                <Activity className="h-4 w-4" />
                              </button>
                            </Authorize>
                            <Authorize permission={PERMISSIONS.DELETE_DEVICES}>
                              <button
                                onClick={() => { setDeleteDevice(device); setDeleteWarning(null) }}
                                className="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                title="Delete device"
                              >
                                <Trash2 className="h-4 w-4" />
                              </button>
                            </Authorize>
                          </div>
                        </td>
                      </tr>
                    )
                  })}
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

      {/* Device Details Modal */}
      {selectedDevice && (
        <>
          <div
            className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40"
            onClick={() => setSelectedDevice(null)}
          />
          <div className="fixed inset-x-4 top-[5%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-2xl bg-white rounded-2xl shadow-xl z-50 overflow-hidden max-h-[90vh] overflow-y-auto">
            <div className="p-5 border-b border-slate-200 sticky top-0 bg-white">
              <div className="flex items-center justify-between">
                <h3 className="text-lg font-semibold text-slate-800">Device Details</h3>
                <button
                  onClick={() => setSelectedDevice(null)}
                  className="p-1 text-slate-400 hover:text-slate-600 rounded-lg text-xl"
                >
                  ×
                </button>
              </div>
            </div>
            <div className="p-5 space-y-6">
              {/* Header */}
              <div className="flex items-center gap-4">
                <div className="h-16 w-16 rounded-xl bg-gradient-to-br from-slate-100 to-slate-200 flex items-center justify-center">
                  <Smartphone className="h-8 w-8 text-slate-500" />
                </div>
                <div>
                  <p className="text-xl font-semibold text-slate-800">{selectedDevice.brand} {selectedDevice.model}</p>
                  <p className="text-sm text-slate-500 font-mono">{selectedDevice.serial_number}</p>
                  <div className="mt-2">
                    {getStatusBadge(selectedDevice.status ? (typeof selectedDevice.status === 'string' ? selectedDevice.status : selectedDevice.status.value) : 'inactive')}
                  </div>
                </div>
              </div>

              {/* Device Info Grid */}
              <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                <div className="p-3 bg-slate-50 rounded-lg">
                  <div className="flex items-center gap-2 mb-1">
                    <User className="h-3.5 w-3.5 text-slate-400" />
                    <span className="text-xs text-slate-500">Owner</span>
                  </div>
                  <p className="text-sm font-medium text-slate-700">{selectedDevice.owner?.name || 'Unknown'}</p>
                  <p className="text-xs text-slate-500">{selectedDevice.owner?.email}</p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <div className="flex items-center gap-2 mb-1">
                    <Tag className="h-3.5 w-3.5 text-slate-400" />
                    <span className="text-xs text-slate-500">Category</span>
                  </div>
                  <p className="text-sm font-medium text-slate-700">{selectedDevice.category?.name || 'N/A'}</p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <div className="flex items-center gap-2 mb-1">
                    <Hash className="h-3.5 w-3.5 text-slate-400" />
                    <span className="text-xs text-slate-500">Device Code</span>
                  </div>
                  <p className="text-sm font-medium text-slate-700 font-mono">{selectedDevice.unique_device_code || selectedDevice.device_code || 'N/A'}</p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <div className="flex items-center gap-2 mb-1">
                    <Palette className="h-3.5 w-3.5 text-slate-400" />
                    <span className="text-xs text-slate-500">Color</span>
                  </div>
                  <p className="text-sm font-medium text-slate-700">{selectedDevice.color || 'N/A'}</p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <div className="flex items-center gap-2 mb-1">
                    <Calendar className="h-3.5 w-3.5 text-slate-400" />
                    <span className="text-xs text-slate-500">Purchase Date</span>
                  </div>
                  <p className="text-sm font-medium text-slate-700">
                    {selectedDevice.purchase_date ? new Date(selectedDevice.purchase_date).toLocaleDateString() : 'N/A'}
                  </p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <div className="flex items-center gap-2 mb-1">
                    <DollarSign className="h-3.5 w-3.5 text-slate-400" />
                    <span className="text-xs text-slate-500">Purchase Price</span>
                  </div>
                  <p className="text-sm font-medium text-slate-700">
                    {selectedDevice.purchase_price ? `MWK ${Number(selectedDevice.purchase_price).toLocaleString()}` : 'N/A'}
                  </p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <div className="flex items-center gap-2 mb-1">
                    <MapPin className="h-3.5 w-3.5 text-slate-400" />
                    <span className="text-xs text-slate-500">Purchase Location</span>
                  </div>
                  <p className="text-sm font-medium text-slate-700">{selectedDevice.purchase_location || 'N/A'}</p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <div className="flex items-center gap-2 mb-1">
                    <Clock className="h-3.5 w-3.5 text-slate-400" />
                    <span className="text-xs text-slate-500">Last Seen</span>
                  </div>
                  <p className="text-sm font-medium text-slate-700">
                    {selectedDevice.last_seen_at ? new Date(selectedDevice.last_seen_at).toLocaleString() : 'Never'}
                  </p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <div className="flex items-center gap-2 mb-1">
                    <Calendar className="h-3.5 w-3.5 text-slate-400" />
                    <span className="text-xs text-slate-500">Registered</span>
                  </div>
                  <p className="text-sm font-medium text-slate-700">
                    {new Date(selectedDevice.created_at).toLocaleDateString()}
                  </p>
                </div>
              </div>

              {/* IoT Module Section */}
              <div className="border-t border-slate-200 pt-4">
                <div className="flex items-center justify-between mb-3">
                  <h4 className="text-sm font-semibold text-slate-800 flex items-center gap-2">
                    <Wifi className="h-4 w-4" />
                    IoT Module
                  </h4>
                  <div className="flex items-center gap-2">
                    {selectedDevice.iot_module ? (
                      <>
                        <Authorize permission={PERMISSIONS.VIEW_ALL_IOT_MODULES}>
                          <button
                            onClick={() => navigate(`/admin/iot-modules?highlight=${selectedDevice.iot_module!.id}`)}
                            className="px-3 py-1 bg-blue-100 text-blue-700 text-xs font-medium rounded-lg hover:bg-blue-200 transition-colors"
                          >
                            View Module
                          </button>
                        </Authorize>
                        <Authorize permission={PERMISSIONS.ASSIGN_IOT_MODULES}>
                          <button
                            onClick={() => unlinkModuleMutation.mutate({ deviceId: selectedDevice.id })}
                            className="flex items-center gap-1.5 px-3 py-1 bg-red-100 text-red-700 text-xs font-medium rounded-lg hover:bg-red-200 transition-colors"
                          >
                            <Unlink className="h-3 w-3" />
                            Unlink
                          </button>
                        </Authorize>
                      </>
                    ) : (
                      <Authorize permission={PERMISSIONS.ASSIGN_IOT_MODULES}>
                        <button
                          onClick={() => {
                            setSelectedDevice(null)
                            setLinkingDevice(selectedDevice)
                          }}
                          className="flex items-center gap-1.5 px-3 py-1 bg-emerald-100 text-emerald-700 text-xs font-medium rounded-lg hover:bg-emerald-200 transition-colors"
                        >
                          <Link2 className="h-3 w-3" />
                          Link Module
                        </button>
                      </Authorize>
                    )}
                  </div>
                </div>
                {selectedDevice.iot_module ? (
                  <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div className="p-3 bg-emerald-50 rounded-lg">
                      <p className="text-xs text-emerald-600 mb-1">Module ID</p>
                      <p className="text-sm font-medium text-emerald-700 font-mono">{selectedDevice.iot_module.module_id}</p>
                    </div>
                    <div className="p-3 bg-emerald-50 rounded-lg">
                      <div className="flex items-center gap-1.5 text-emerald-600 mb-1">
                        <Activity className="h-3.5 w-3.5" />
                        <p className="text-xs">Status</p>
                      </div>
                      <p className="text-sm font-medium text-emerald-700 capitalize">
                        {typeof selectedDevice.iot_module.status === 'string'
                          ? selectedDevice.iot_module.status
                          : (selectedDevice.iot_module.status as { value?: string })?.value || 'unknown'}
                      </p>
                    </div>
                    {selectedDevice.iot_module.battery_level !== null && (
                      <div className="p-3 bg-emerald-50 rounded-lg">
                        <div className="flex items-center gap-1.5 text-emerald-600 mb-1">
                          <Battery className="h-3.5 w-3.5" />
                          <p className="text-xs">Battery</p>
                        </div>
                        <p className="text-sm font-medium text-emerald-700">{selectedDevice.iot_module.battery_level}%</p>
                      </div>
                    )}
                    {selectedDevice.iot_module.signal_strength !== null && (
                      <div className="p-3 bg-emerald-50 rounded-lg">
                        <div className="flex items-center gap-1.5 text-emerald-600 mb-1">
                          <Signal className="h-3.5 w-3.5" />
                          <p className="text-xs">Signal</p>
                        </div>
                        <p className="text-sm font-medium text-emerald-700">{selectedDevice.iot_module.signal_strength}%</p>
                      </div>
                    )}
                  </div>
                ) : (
                  <div className="p-4 bg-slate-50 rounded-lg text-center">
                    <WifiOff className="h-6 w-6 text-slate-400 mx-auto mb-2" />
                    <p className="text-sm text-slate-500">No IoT module linked to this device</p>
                  </div>
                )}
              </div>

              {/* Description & Notes */}
              {(selectedDevice.description || selectedDevice.notes) && (
                <div className="border-t border-slate-200 pt-4 space-y-3">
                  {selectedDevice.description && (
                    <div>
                      <h4 className="text-sm font-semibold text-slate-800 mb-1">Description</h4>
                      <p className="text-sm text-slate-600">{selectedDevice.description}</p>
                    </div>
                  )}
                  {selectedDevice.notes && (
                    <div>
                      <h4 className="text-sm font-semibold text-slate-800 mb-1">Notes</h4>
                      <p className="text-sm text-slate-600">{selectedDevice.notes}</p>
                    </div>
                  )}
                </div>
              )}

              {/* Footer Actions */}
              <div className="border-t border-slate-200 pt-4 flex gap-2">
                <button
                  onClick={() => selectedDevice.owner?.id && handleViewOwner(selectedDevice.owner.id)}
                  className="flex-1 px-4 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors"
                >
                  View Owner Profile
                </button>
                <button
                  onClick={() => setSelectedDevice(null)}
                  className="px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors"
                >
                  Close
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* Change Status Modal */}
      {statusDevice && (
        <>
          <div
            className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40"
            onClick={() => {
              setStatusDevice(null)
              setNewStatus('')
            }}
          />
          <div className="fixed inset-x-4 top-[20%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-md bg-white rounded-2xl shadow-xl z-50 overflow-hidden">
            <div className="p-5 border-b border-slate-200">
              <h3 className="text-lg font-semibold text-slate-800">Change Device Status</h3>
              <p className="text-sm text-slate-500 mt-1">
                {statusDevice.brand} {statusDevice.model}
              </p>
            </div>
            <div className="p-5 space-y-4">
              <div className="grid grid-cols-2 gap-3">
                {['active', 'inactive', 'stolen', 'recovered'].map((status) => (
                  <button
                    key={status}
                    onClick={() => setNewStatus(status)}
                    className={`p-3 rounded-lg border-2 text-sm font-medium capitalize transition-colors ${
                      newStatus === status
                        ? status === 'active'
                          ? 'border-emerald-500 bg-emerald-50 text-emerald-700'
                          : status === 'inactive'
                            ? 'border-slate-400 bg-slate-100 text-slate-700'
                            : status === 'stolen'
                              ? 'border-red-500 bg-red-50 text-red-700'
                              : 'border-blue-500 bg-blue-50 text-blue-700'
                        : 'border-slate-200 hover:border-slate-300 text-slate-600'
                    }`}
                  >
                    {status}
                  </button>
                ))}
              </div>
              <div className="flex gap-2 pt-2">
                <button
                  onClick={() => {
                    setStatusDevice(null)
                    setNewStatus('')
                  }}
                  className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors"
                >
                  Cancel
                </button>
                <button
                  onClick={() => {
                    if (newStatus) {
                      updateStatusMutation.mutate({ deviceId: statusDevice.id, status: newStatus })
                    }
                  }}
                  disabled={!newStatus || updateStatusMutation.isPending}
                  className="flex-1 px-4 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                  {updateStatusMutation.isPending ? 'Updating...' : 'Update Status'}
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* Delete Confirmation Modal */}
      {deleteDevice && (
        <>
          <div
            className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40"
            onClick={() => { setDeleteDevice(null); setDeleteWarning(null) }}
          />
          <div className="fixed inset-x-4 top-[20%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-md bg-white rounded-2xl shadow-xl z-50 overflow-hidden">
            <div className="p-5 border-b border-slate-200">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-full bg-red-100 flex items-center justify-center">
                  <AlertTriangle className="h-5 w-5 text-red-600" />
                </div>
                <div>
                  <h3 className="text-lg font-semibold text-slate-800">Delete Device</h3>
                  <p className="text-sm text-slate-500">This action cannot be undone</p>
                </div>
              </div>
            </div>
            <div className="p-5 space-y-4">
              <p className="text-sm text-slate-600">
                Are you sure you want to delete <span className="font-semibold">{deleteDevice.brand} {deleteDevice.model}</span>?
                This will permanently remove the device and all associated data including locations and geofences.
              </p>
              <div>
                <label className="mb-1 block text-sm font-medium text-slate-700">Reason <span className="text-red-500">*</span></label>
                <input value={deleteReason} onChange={(event) => setDeleteReason(event.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none" placeholder="Why should this device be deleted?" />
              </div>
              <div>
                <label className="mb-1 block text-sm font-medium text-slate-700">Notes</label>
                <textarea value={deleteNotes} onChange={(event) => setDeleteNotes(event.target.value)} rows={2} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none" placeholder="Optional context" />
              </div>
              {deleteWarning && (
                <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                  <div className="flex items-start gap-2">
                    <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0 text-amber-600" />
                    <div>
                      <p className="font-semibold">Confirm deletion of tracked inventory</p>
                      <p className="mt-1">{deleteWarning}</p>
                      <p className="mt-2">Proceeding will permanently delete this device after PIN verification. This cannot be undone.</p>
                    </div>
                  </div>
                  <button type="button" onClick={() => setDeleteWarning(null)} className="mt-3 text-xs font-semibold text-amber-800 underline">Go back and review</button>
                </div>
              )}
              <div className="flex gap-2">
                <button
                  onClick={() => { setDeleteDevice(null); setDeleteWarning(null); setDeleteReason(''); setDeleteNotes('') }}
                  className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors"
                >
                  Cancel
                </button>
                <button
                  onClick={() => requestDeleteMutation.mutate({ deviceId: deleteDevice.id, reason: deleteReason, notes: deleteNotes, confirmHistoryLoss: Boolean(deleteWarning) })}
                  disabled={!deleteReason.trim() || requestDeleteMutation.isPending}
                  className="flex-1 px-4 py-2.5 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                  {requestDeleteMutation.isPending ? 'Sending PIN...' : deleteWarning ? 'Proceed & send PIN' : 'Send PIN'}
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {deleteChallenge && (
        <ActionChallengePinModal
          challenge={deleteChallenge}
          title="Confirm device deletion"
          description="Device deletion is irreversible. Enter the PIN sent to the administrator approval mailbox to continue."
          error={deleteChallengeError}
          isVerifying={verifyDeleteMutation.isPending}
          isResending={resendDeleteMutation.isPending}
          onVerify={(pin) => verifyDeleteMutation.mutate({ challengeId: deleteChallenge.challenge_id, pin })}
          onResend={() => resendDeleteMutation.mutate(deleteChallenge.challenge_id)}
          onClose={() => { setDeleteChallenge(null); setDeleteChallengeError(null); setDeleteWarning(null) }}
        />
      )}

      {/* Edit Device Modal */}
      {editingDevice && (
        <>
          <div
            className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40"
            onClick={() => setEditingDevice(null)}
          />
          <div className="fixed inset-x-4 top-[5%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-2xl bg-white rounded-2xl shadow-xl z-50 overflow-hidden max-h-[90vh] overflow-y-auto">
            <div className="p-5 border-b border-slate-200 sticky top-0 bg-white">
              <div className="flex items-center justify-between">
                <h3 className="text-lg font-semibold text-slate-800">Edit Device</h3>
                <button
                  onClick={() => setEditingDevice(null)}
                  className="p-1 text-slate-400 hover:text-slate-600 rounded-lg text-xl"
                >
                  ×
                </button>
              </div>
              <p className="text-sm text-slate-500 mt-1">
                {editingDevice.brand} {editingDevice.model} • {editingDevice.serial_number}
              </p>
            </div>
            <div className="p-5 space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Brand</label>
                  <input
                    type="text"
                    value={editForm.brand}
                    onChange={(e) => setEditForm({ ...editForm, brand: e.target.value })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Model</label>
                  <input
                    type="text"
                    value={editForm.model}
                    onChange={(e) => setEditForm({ ...editForm, model: e.target.value })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                  />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Serial Number</label>
                  <input
                    type="text"
                    value={editForm.serial_number}
                    onChange={(e) => setEditForm({ ...editForm, serial_number: e.target.value })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Color</label>
                  <input
                    type="text"
                    value={editForm.color}
                    onChange={(e) => setEditForm({ ...editForm, color: e.target.value })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                  />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Purchase Date</label>
                  <input
                    type="date"
                    value={editForm.purchase_date}
                    onChange={(e) => setEditForm({ ...editForm, purchase_date: e.target.value })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Purchase Price (MWK)</label>
                  <input
                    type="number"
                    value={editForm.purchase_price}
                    onChange={(e) => setEditForm({ ...editForm, purchase_price: e.target.value })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                  />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1">Purchase Location</label>
                <input
                  type="text"
                  value={editForm.purchase_location}
                  onChange={(e) => setEditForm({ ...editForm, purchase_location: e.target.value })}
                  className="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1">Description</label>
                <textarea
                  value={editForm.description}
                  onChange={(e) => setEditForm({ ...editForm, description: e.target.value })}
                  rows={2}
                  className="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1">Notes</label>
                <textarea
                  value={editForm.notes}
                  onChange={(e) => setEditForm({ ...editForm, notes: e.target.value })}
                  rows={2}
                  className="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                />
              </div>
              <div className="flex gap-2 pt-2">
                <button
                  onClick={() => setEditingDevice(null)}
                  className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors"
                >
                  Cancel
                </button>
                <button
                  onClick={() => updateDeviceMutation.mutate({ deviceId: editingDevice.id, data: editForm })}
                  disabled={updateDeviceMutation.isPending}
                  className="flex-1 px-4 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                  {updateDeviceMutation.isPending ? 'Saving...' : 'Save Changes'}
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* Link IoT Module Modal */}
      {linkingDevice && (
        <>
          <div
            className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40"
            onClick={() => {
              setLinkingDevice(null)
              setModuleSearch('')
              setSelectedModuleId(null)
            }}
          />
          <div className="fixed inset-x-4 top-[15%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-lg bg-white rounded-2xl shadow-xl z-50 overflow-hidden">
            <div className="p-5 border-b border-slate-200">
              <h3 className="text-lg font-semibold text-slate-800">Link IoT Module</h3>
              <p className="text-sm text-slate-500 mt-1">
                {linkingDevice.brand} {linkingDevice.model}
              </p>
            </div>
            <div className="p-5 space-y-4">
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-2">Search for Module</label>
                <div className="relative">
                  <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
                  <input
                    type="text"
                    value={moduleSearch}
                    onChange={(e) => setModuleSearch(e.target.value)}
                    placeholder="Search by module ID, IMEI, or SIM..."
                    className="w-full pl-10 pr-4 py-2.5 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                  />
                </div>
              </div>

              {/* Module Results */}
              {moduleSearch.length >= 2 && (
                <div className="max-h-64 overflow-y-auto border border-slate-200 rounded-lg">
                  {modulesLoading ? (
                    <div className="flex items-center justify-center py-8">
                      <Loader2 className="h-5 w-5 animate-spin text-blue-500" />
                    </div>
                  ) : modules.length === 0 ? (
                    <div className="p-4 text-center text-sm text-slate-500">
                      No IoT modules found
                    </div>
                  ) : (
                    <div className="divide-y divide-slate-100">
                      {modules.map((module) => (
                        <button
                          key={module.id}
                          onClick={() => setSelectedModuleId(module.id)}
                          className={`w-full p-3 text-left hover:bg-slate-50 transition-colors ${
                            selectedModuleId === module.id ? 'bg-blue-50' : ''
                          }`}
                        >
                          <div className="flex items-center justify-between">
                            <div>
                              <p className="font-medium text-slate-800">
                                {module.module_id}
                              </p>
                              <p className="text-xs text-slate-500">
                                {typeof module.modem_type === 'string' ? module.modem_type.toUpperCase() : 'GSM'}
                                {module.sim_number && ` • ${module.sim_number}`}
                              </p>
                              <p className="text-xs mt-1">
                                <span className={`inline-flex items-center gap-1 ${
                                  (typeof module.status === 'string' ? module.status : (module.status as { value?: string })?.value) === 'active'
                                    ? 'text-emerald-600'
                                    : 'text-slate-500'
                                }`}>
                                  Status: {typeof module.status === 'string' ? module.status : (module.status as { value?: string })?.value || 'inactive'}
                                </span>
                              </p>
                              {module.device && (
                                <p className="text-xs text-amber-600 mt-1">
                                  Already linked to: {module.device.brand} {module.device.model}
                                </p>
                              )}
                            </div>
                            {selectedModuleId === module.id && (
                              <Eye className="h-5 w-5 text-blue-600" />
                            )}
                          </div>
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              )}

              {moduleSearch.length < 2 && (
                <div className="p-4 bg-slate-50 rounded-lg text-center">
                  <Search className="h-6 w-6 text-slate-400 mx-auto mb-2" />
                  <p className="text-sm text-slate-500">
                    Type at least 2 characters to search for IoT modules
                  </p>
                </div>
              )}

              <div className="flex gap-2 pt-2">
                <button
                  onClick={() => {
                    setLinkingDevice(null)
                    setModuleSearch('')
                    setSelectedModuleId(null)
                  }}
                  className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors"
                >
                  Cancel
                </button>
                <button
                  onClick={() => {
                    if (selectedModuleId) {
                      linkModuleMutation.mutate({ deviceId: linkingDevice.id, moduleId: selectedModuleId })
                    }
                  }}
                  disabled={!selectedModuleId || linkModuleMutation.isPending}
                  className="flex-1 px-4 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                  {linkModuleMutation.isPending ? 'Linking...' : 'Link Module'}
                </button>
              </div>
            </div>
          </div>
        </>
      )}
    </div>
  )
}
