import { formatMalawiDate, formatMalawiDateTime } from '@/lib/datetime'
import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import {
  Search,
  Wifi,
  WifiOff,
  Plus,
  Loader2,
  Eye,
  SquarePen,
  Trash2,
  AlertTriangle,
  Battery,
  Signal,
  Activity,
  Link2,
  Unlink,
  RotateCcw,
  Copy,
  Check,
  Smartphone,
  Radio,
  Clock,
  Hash,
  CreditCard,
  Shield,
} from 'lucide-react'
import { Authorize } from '@/components/auth/Authorize'
import { PERMISSIONS } from '@/stores/auth-store'
import apiClient from '@/api/client'
import { toast } from 'sonner'
import type { IotModule, Device } from '@/types'

interface PaginatedResponse {
  data: IotModule[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export default function IoTModulesPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [page, setPage] = useState(1)
  const [selectedModule, setSelectedModule] = useState<IotModule | null>(null)
  const [editingModule, setEditingModule] = useState<IotModule | null>(null)
  const [deleteModule, setDeleteModule] = useState<IotModule | null>(null)
  const [showCreateModal, setShowCreateModal] = useState(false)
  const [showTokenModal, setShowTokenModal] = useState(false)
  const [generatedToken, setGeneratedToken] = useState('')
  const [copiedToken, setCopiedToken] = useState(false)
  const [linkingModule, setLinkingModule] = useState<IotModule | null>(null)
  const [deviceSearch, setDeviceSearch] = useState('')
  const [selectedDeviceId, setSelectedDeviceId] = useState<number | null>(null)

  const [createForm, setCreateForm] = useState({
    module_id: '',
    modem_type: 'gsm' as 'gsm' | '4g_lte' | 'nb_iot' | 'lora',
    sim_number: '',
    imei: '',
    firmware_version: '',
    hardware_version: '',
  })

  const [editForm, setEditForm] = useState({
    sim_number: '',
    imei: '',
    firmware_version: '',
    hardware_version: '',
    notes: '',
  })

  // Fetch modules
  const { data, isLoading, error } = useQuery({
    queryKey: ['iot-modules', search, statusFilter, page],
    queryFn: async () => {
      const params = new URLSearchParams()
      params.append('page', page.toString())
      params.append('per_page', '15')
      if (search) params.append('search', search)
      if (statusFilter) params.append('status', statusFilter)

      const response = await apiClient.get<{ success: boolean; data: IotModule[]; meta: PaginatedResponse['meta'] }>(
        `/admin/iot-modules?${params.toString()}`
      )
      return response.data
    },
  })

  // Search devices for linking
  const { data: devicesData, isLoading: devicesLoading } = useQuery({
    queryKey: ['devices-for-linking', deviceSearch],
    queryFn: async () => {
      if (!deviceSearch || deviceSearch.length < 2) return { data: [] }

      const params = new URLSearchParams()
      params.append('search', deviceSearch)
      params.append('per_page', '10')

      const response = await apiClient.get<{ success: boolean; data: Device[] }>(
        `/admin/devices?${params.toString()}`
      )
      return response.data
    },
    enabled: !!linkingModule && deviceSearch.length >= 2,
  })

  // Create module mutation
  const createModuleMutation = useMutation({
    mutationFn: async (data: typeof createForm) => {
      const response = await apiClient.post<{ success: boolean; data: { module: IotModule; api_token: string } }>(
        '/admin/iot-modules',
        data
      )
      return response.data.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['iot-modules'] })
      setShowCreateModal(false)
      setGeneratedToken(data.api_token)
      setShowTokenModal(true)
      setCreateForm({
        module_id: '',
        modem_type: 'gsm',
        sim_number: '',
        imei: '',
        firmware_version: '',
        hardware_version: '',
      })
      toast.success('IoT module created successfully')
    },
    onError: () => {
      toast.error('Failed to create IoT module')
    },
  })

  // Update module mutation
  const updateModuleMutation = useMutation({
    mutationFn: async ({ moduleId, data }: { moduleId: number; data: typeof editForm }) => {
      await apiClient.put(`/admin/iot-modules/${moduleId}`, data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['iot-modules'] })
      toast.success('Module updated successfully')
      setEditingModule(null)
    },
    onError: () => {
      toast.error('Failed to update module')
    },
  })

  // Delete module mutation
  const deleteMutation = useMutation({
    mutationFn: async (moduleId: number) => {
      await apiClient.delete(`/admin/iot-modules/${moduleId}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['iot-modules'] })
      toast.success('Module deleted successfully')
      setDeleteModule(null)
    },
    onError: () => {
      toast.error('Failed to delete module')
    },
  })

  // Activate module mutation
  const activateMutation = useMutation({
    mutationFn: async (moduleId: number) => {
      await apiClient.post(`/admin/iot-modules/${moduleId}/activate`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['iot-modules'] })
      toast.success('Module activated successfully')
    },
    onError: () => {
      toast.error('Failed to activate module')
    },
  })

  // Deactivate module mutation
  const deactivateMutation = useMutation({
    mutationFn: async (moduleId: number) => {
      await apiClient.post(`/admin/iot-modules/${moduleId}/deactivate`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['iot-modules'] })
      toast.success('Module deactivated successfully')
    },
    onError: () => {
      toast.error('Failed to deactivate module')
    },
  })

  // Regenerate token mutation
  const regenerateTokenMutation = useMutation({
    mutationFn: async (moduleId: number) => {
      const response = await apiClient.post<{ success: boolean; data: { api_token: string } }>(
        `/admin/iot-modules/${moduleId}/regenerate-token`
      )
      return response.data.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['iot-modules'] })
      setGeneratedToken(data.api_token)
      setShowTokenModal(true)
      toast.success('API token regenerated successfully')
    },
    onError: () => {
      toast.error('Failed to regenerate token')
    },
  })

  // Link module to device mutation
  const linkModuleMutation = useMutation({
    mutationFn: async ({ deviceId, moduleId }: { deviceId: number; moduleId: number }) => {
      await apiClient.post(`/devices/${deviceId}/link-module`, { iot_module_id: moduleId })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['iot-modules'] })
      queryClient.invalidateQueries({ queryKey: ['admin-devices'] })
      toast.success('Module linked to device successfully')
      setLinkingModule(null)
      setDeviceSearch('')
      setSelectedDeviceId(null)
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
      queryClient.invalidateQueries({ queryKey: ['iot-modules'] })
      queryClient.invalidateQueries({ queryKey: ['admin-devices'] })
      toast.success('Module unlinked successfully')
    },
    onError: () => {
      toast.error('Failed to unlink module')
    },
  })

  const modules = data?.data || []
  const meta = data?.meta
  const devices = devicesData?.data || []

  const getStatusBadge = (status: string) => {
    const statusConfig: Record<string, { bg: string; text: string; dot: string }> = {
      active: { bg: 'bg-emerald-50', text: 'text-emerald-700', dot: 'bg-emerald-500' },
      inactive: { bg: 'bg-slate-100', text: 'text-slate-600', dot: 'bg-slate-400' },
      maintenance: { bg: 'bg-amber-50', text: 'text-amber-700', dot: 'bg-amber-500' },
    }
    const config = statusConfig[status] || statusConfig.inactive
    return (
      <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${config.bg} ${config.text}`}>
        <span className={`h-1.5 w-1.5 rounded-full ${config.dot}`} />
        {status}
      </span>
    )
  }

  const getModemTypeBadge = (type: string) => {
    const typeConfig: Record<string, { label: string; color: string }> = {
      gsm: { label: 'GSM', color: 'bg-blue-100 text-blue-700' },
      '4g_lte': { label: '4G LTE', color: 'bg-purple-100 text-purple-700' },
      nb_iot: { label: 'NB-IoT', color: 'bg-indigo-100 text-indigo-700' },
      lora: { label: 'LoRa', color: 'bg-cyan-100 text-cyan-700' },
    }
    const config = typeConfig[type] || { label: type.toUpperCase(), color: 'bg-slate-100 text-slate-700' }
    return (
      <span className={`px-2 py-0.5 rounded text-xs font-medium ${config.color}`}>
        {config.label}
      </span>
    )
  }

  const copyToken = () => {
    navigator.clipboard.writeText(generatedToken)
    setCopiedToken(true)
    setTimeout(() => setCopiedToken(false), 2000)
    toast.success('Token copied to clipboard')
  }

  const handleEditModule = (module: IotModule) => {
    setEditForm({
      sim_number: module.sim_number || '',
      imei: module.imei || '',
      firmware_version: module.firmware_version || '',
      hardware_version: '',
      notes: '',
    })
    setEditingModule(module)
  }

  if (error) {
    return (
      <div className="flex flex-col items-center justify-center py-16">
        <div className="h-12 w-12 rounded-xl bg-red-100 flex items-center justify-center mb-3">
          <Wifi className="h-6 w-6 text-red-500" />
        </div>
        <p className="text-red-500 text-sm">Error loading IoT modules: {(error as Error).message}</p>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            IoT Modules
          </h1>
          <p className="text-slate-500 text-sm mt-1">Manage GPS/GSM tracking modules</p>
        </div>
        <div className="flex items-center gap-3">
          <div className="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-600 text-sm font-medium">
            {meta?.total || 0} total
          </div>
          <Authorize permission={PERMISSIONS.CREATE_IOT_MODULES}>
            <button
              onClick={() => setShowCreateModal(true)}
              className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors"
            >
              <Plus className="h-4 w-4" />
              Add Module
            </button>
          </Authorize>
        </div>
      </div>

      {/* Main Card */}
      <div className="bg-white rounded-xl border border-slate-200/60 shadow-sm">
        {/* Search and Filter Bar */}
        <div className="p-4 border-b border-slate-200/60 flex items-center gap-4">
          <div className="relative flex-1 max-w-md">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
            <input
              type="text"
              placeholder="Search by module ID, IMEI, or SIM..."
              className="w-full pl-10 pr-4 py-2.5 rounded-lg border border-slate-200 bg-slate-50/50 text-sm placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(1)
              }}
            />
          </div>
          <select
            value={statusFilter}
            onChange={(e) => {
              setStatusFilter(e.target.value)
              setPage(1)
            }}
            className="px-4 py-2.5 rounded-lg border border-slate-200 bg-slate-50/50 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors"
          >
            <option value="">All Statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="maintenance">Maintenance</option>
          </select>
        </div>

        {/* Content */}
        {isLoading ? (
          <div className="flex items-center justify-center py-16">
            <Loader2 className="h-8 w-8 animate-spin text-blue-500" />
          </div>
        ) : modules.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-16">
            <div className="h-12 w-12 rounded-xl bg-slate-100 flex items-center justify-center mb-3">
              <Wifi className="h-6 w-6 text-slate-400" />
            </div>
            <p className="text-slate-500 text-sm">No IoT modules found</p>
            <Authorize permission={PERMISSIONS.CREATE_IOT_MODULES}>
              <button
                onClick={() => setShowCreateModal(true)}
                className="mt-4 text-sm text-blue-600 hover:text-blue-700 font-medium"
              >
                Create your first module
              </button>
            </Authorize>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-slate-200/60">
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Module</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Type</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Device</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Health</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Last Ping</th>
                    <th className="text-right py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {modules.map((module) => {
                    const status = typeof module.status === 'string' ? module.status : (module.status as { value?: string })?.value || 'inactive'
                    const modemType = typeof module.modem_type === 'string' ? module.modem_type : (module.modem_type as { value?: string })?.value || 'gsm'
                    return (
                      <tr key={module.id} className="hover:bg-slate-50/50 transition-colors">
                        <td className="py-3 px-4">
                          <div className="flex items-center gap-3">
                            <div className="h-10 w-10 rounded-xl bg-gradient-to-br from-blue-100 to-blue-200 flex items-center justify-center flex-shrink-0">
                              <Wifi className="h-5 w-5 text-blue-600" />
                            </div>
                            <div className="min-w-0">
                              <p className="font-medium text-slate-800">{module.module_id}</p>
                              {module.sim_number && (
                                <p className="text-xs text-slate-500 font-mono">{module.sim_number}</p>
                              )}
                            </div>
                          </div>
                        </td>
                        <td className="py-3 px-4">
                          {getModemTypeBadge(modemType)}
                        </td>
                        <td className="py-3 px-4">
                          {getStatusBadge(status)}
                        </td>
                        <td className="py-3 px-4">
                          {module.device ? (
                            <button
                              onClick={() => navigate(`/admin/devices?highlight=${module.device!.id}`)}
                              className="flex items-center gap-2 text-slate-600 hover:text-blue-600 transition-colors group"
                            >
                              <Smartphone className="h-4 w-4 text-slate-400 group-hover:text-blue-500" />
                              <span className="text-sm">{module.device.brand} {module.device.model}</span>
                            </button>
                          ) : (
                            <span className="text-sm text-slate-400">Not linked</span>
                          )}
                        </td>
                        <td className="py-3 px-4">
                          <div className="flex items-center gap-3">
                            {module.battery_level !== null && (
                              <div className="flex items-center gap-1 text-xs">
                                <Battery className={`h-3.5 w-3.5 ${module.battery_level > 20 ? 'text-emerald-600' : 'text-red-600'}`} />
                                <span className="text-slate-600">{module.battery_level}%</span>
                              </div>
                            )}
                            {module.signal_strength !== null && (
                              <div className="flex items-center gap-1 text-xs">
                                <Signal className={`h-3.5 w-3.5 ${module.signal_strength > 30 ? 'text-emerald-600' : 'text-amber-600'}`} />
                                <span className="text-slate-600">{module.signal_strength}%</span>
                              </div>
                            )}
                          </div>
                        </td>
                        <td className="py-3 px-4">
                          {module.last_ping ? (
                            <span className="text-sm text-slate-500">
                              {formatMalawiDateTime(module.last_ping)}
                            </span>
                          ) : (
                            <span className="text-sm text-slate-400">Never</span>
                          )}
                        </td>
                        <td className="py-3 px-4">
                          <div className="flex items-center justify-end gap-1">
                            <Authorize permission={PERMISSIONS.VIEW_ALL_IOT_MODULES}>
                              <button
                                onClick={() => setSelectedModule(module)}
                                className="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                                title="View details"
                              >
                                <Eye className="h-4 w-4" />
                              </button>
                            </Authorize>
                            <Authorize permission={PERMISSIONS.UPDATE_IOT_MODULES}>
                              <button
                                onClick={() => handleEditModule(module)}
                                className="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                                title="Edit module"
                              >
                                <SquarePen className="h-4 w-4" />
                              </button>
                            </Authorize>
                            <Authorize permission={PERMISSIONS.UPDATE_IOT_MODULES}>
                              {status === 'active' ? (
                                <button
                                  onClick={() => deactivateMutation.mutate(module.id)}
                                  className="p-2 text-slate-400 hover:text-amber-600 hover:bg-amber-50 rounded-lg transition-colors"
                                  title="Deactivate"
                                >
                                  <WifiOff className="h-4 w-4" />
                                </button>
                              ) : (
                                <button
                                  onClick={() => activateMutation.mutate(module.id)}
                                  className="p-2 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors"
                                  title="Activate"
                                >
                                  <Wifi className="h-4 w-4" />
                                </button>
                              )}
                            </Authorize>
                            <Authorize permission={PERMISSIONS.ASSIGN_IOT_MODULES}>
                              {module.device ? (
                                <button
                                  onClick={() => unlinkModuleMutation.mutate({ deviceId: module.device!.id })}
                                  className="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                  title="Unlink from device"
                                >
                                  <Unlink className="h-4 w-4" />
                                </button>
                              ) : (
                                <button
                                  onClick={() => setLinkingModule(module)}
                                  className="p-2 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors"
                                  title="Link to device"
                                >
                                  <Link2 className="h-4 w-4" />
                                </button>
                              )}
                            </Authorize>
                            <Authorize permission={PERMISSIONS.DELETE_IOT_MODULES}>
                              <button
                                onClick={() => setDeleteModule(module)}
                                className="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                title="Delete module"
                                disabled={!!module.device}
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

      {/* Create Module Modal */}
      {showCreateModal && (
        <>
          <div
            className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40"
            onClick={() => setShowCreateModal(false)}
          />
          <div className="fixed inset-x-4 top-[10%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-lg bg-white rounded-2xl shadow-xl z-50 overflow-hidden">
            <div className="p-5 border-b border-slate-200">
              <h3 className="text-lg font-semibold text-slate-800">Create IoT Module</h3>
              <p className="text-sm text-slate-500 mt-1">Add a new GPS/GSM tracking module</p>
            </div>
            <div className="p-5 space-y-4 max-h-[70vh] overflow-y-auto">
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1">
                  Module ID <span className="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  value={createForm.module_id}
                  onChange={(e) => setCreateForm({ ...createForm, module_id: e.target.value })}
                  placeholder="e.g., IOT-GPS-001"
                  className="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1">
                  Modem Type <span className="text-red-500">*</span>
                </label>
                <select
                  value={createForm.modem_type}
                  onChange={(e) => setCreateForm({ ...createForm, modem_type: e.target.value as any })}
                  className="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                >
                  <option value="gsm">GSM</option>
                  <option value="4g_lte">4G LTE</option>
                  <option value="nb_iot">NB-IoT</option>
                  <option value="lora">LoRa</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1">SIM Number</label>
                <input
                  type="text"
                  value={createForm.sim_number}
                  onChange={(e) => setCreateForm({ ...createForm, sim_number: e.target.value })}
                  placeholder="e.g., 265888123456"
                  className="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1">IMEI</label>
                <input
                  type="text"
                  value={createForm.imei}
                  onChange={(e) => setCreateForm({ ...createForm, imei: e.target.value })}
                  placeholder="15-digit IMEI number"
                  className="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1">Firmware Version</label>
                <input
                  type="text"
                  value={createForm.firmware_version}
                  onChange={(e) => setCreateForm({ ...createForm, firmware_version: e.target.value })}
                  placeholder="e.g., v2.1.0"
                  className="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                />
              </div>
              <div className="bg-amber-50 border border-amber-200 rounded-lg p-3">
                <p className="text-xs text-amber-800">
                  <strong>Important:</strong> An API token will be generated after creation. You must copy and save it immediately as it will only be shown once.
                </p>
              </div>
              <div className="flex gap-2 pt-2">
                <button
                  onClick={() => setShowCreateModal(false)}
                  className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors"
                >
                  Cancel
                </button>
                <button
                  onClick={() => createModuleMutation.mutate(createForm)}
                  disabled={!createForm.module_id || createModuleMutation.isPending}
                  className="flex-1 px-4 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                  {createModuleMutation.isPending ? 'Creating...' : 'Create Module'}
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* API Token Modal */}
      {showTokenModal && generatedToken && (
        <>
          <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40" />
          <div className="fixed inset-x-4 top-[20%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-lg bg-white rounded-2xl shadow-xl z-50 overflow-hidden">
            <div className="p-5 border-b border-slate-200">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-full bg-emerald-100 flex items-center justify-center">
                  <Shield className="h-5 w-5 text-emerald-600" />
                </div>
                <div>
                  <h3 className="text-lg font-semibold text-slate-800">API Token Generated</h3>
                  <p className="text-sm text-slate-500">Save this token securely</p>
                </div>
              </div>
            </div>
            <div className="p-5 space-y-4">
              <div className="bg-red-50 border border-red-200 rounded-lg p-4">
                <p className="text-sm text-red-800 font-medium mb-2">⚠️ Important Security Notice</p>
                <p className="text-xs text-red-700">
                  This API token will only be shown once. Copy it now and store it securely. You will need to configure it in the physical IoT device. If you lose it, you'll need to regenerate a new token.
                </p>
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-2">API Token</label>
                <div className="relative">
                  <input
                    type="text"
                    value={generatedToken}
                    readOnly
                    className="w-full px-3 py-2 pr-20 rounded-lg border border-slate-200 bg-slate-50 text-sm font-mono focus:outline-none"
                  />
                  <button
                    onClick={copyToken}
                    className="absolute right-2 top-1/2 -translate-y-1/2 px-3 py-1 bg-blue-600 text-white text-xs font-medium rounded hover:bg-blue-700 transition-colors flex items-center gap-1.5"
                  >
                    {copiedToken ? (
                      <>
                        <Check className="h-3 w-3" />
                        Copied
                      </>
                    ) : (
                      <>
                        <Copy className="h-3 w-3" />
                        Copy
                      </>
                    )}
                  </button>
                </div>
              </div>
              <div className="flex gap-2 pt-2">
                <button
                  onClick={() => {
                    setShowTokenModal(false)
                    setGeneratedToken('')
                    setCopiedToken(false)
                  }}
                  className="flex-1 px-4 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors"
                >
                  I've Saved the Token
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* View Module Details Modal */}
      {selectedModule && (
        <>
          <div
            className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40"
            onClick={() => setSelectedModule(null)}
          />
          <div className="fixed inset-x-4 top-[5%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-2xl bg-white rounded-2xl shadow-xl z-50 overflow-hidden max-h-[90vh] overflow-y-auto">
            <div className="p-5 border-b border-slate-200 sticky top-0 bg-white">
              <div className="flex items-center justify-between">
                <h3 className="text-lg font-semibold text-slate-800">Module Details</h3>
                <button
                  onClick={() => setSelectedModule(null)}
                  className="p-1 text-slate-400 hover:text-slate-600 rounded-lg text-xl"
                >
                  ×
                </button>
              </div>
            </div>
            <div className="p-5 space-y-6">
              {/* Header */}
              <div className="flex items-center gap-4">
                <div className="h-16 w-16 rounded-xl bg-gradient-to-br from-blue-100 to-blue-200 flex items-center justify-center">
                  <Wifi className="h-8 w-8 text-blue-600" />
                </div>
                <div>
                  <p className="text-xl font-semibold text-slate-800">{selectedModule.module_id}</p>
                  <div className="flex items-center gap-2 mt-2">
                    {getStatusBadge(typeof selectedModule.status === 'string' ? selectedModule.status : (selectedModule.status as { value?: string })?.value || 'inactive')}
                    {getModemTypeBadge(typeof selectedModule.modem_type === 'string' ? selectedModule.modem_type : (selectedModule.modem_type as { value?: string })?.value || 'gsm')}
                  </div>
                </div>
              </div>

              {/* Module Info Grid */}
              <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                {selectedModule.sim_number && (
                  <div className="p-3 bg-slate-50 rounded-lg">
                    <div className="flex items-center gap-2 mb-1">
                      <CreditCard className="h-3.5 w-3.5 text-slate-400" />
                      <span className="text-xs text-slate-500">SIM Number</span>
                    </div>
                    <p className="text-sm font-medium text-slate-700 font-mono">{selectedModule.sim_number}</p>
                  </div>
                )}
                {selectedModule.imei && (
                  <div className="p-3 bg-slate-50 rounded-lg">
                    <div className="flex items-center gap-2 mb-1">
                      <Hash className="h-3.5 w-3.5 text-slate-400" />
                      <span className="text-xs text-slate-500">IMEI</span>
                    </div>
                    <p className="text-sm font-medium text-slate-700 font-mono">{selectedModule.imei}</p>
                  </div>
                )}
                {selectedModule.firmware_version && (
                  <div className="p-3 bg-slate-50 rounded-lg">
                    <div className="flex items-center gap-2 mb-1">
                      <Radio className="h-3.5 w-3.5 text-slate-400" />
                      <span className="text-xs text-slate-500">Firmware</span>
                    </div>
                    <p className="text-sm font-medium text-slate-700">{selectedModule.firmware_version}</p>
                  </div>
                )}
                {selectedModule.battery_level !== null && (
                  <div className="p-3 bg-slate-50 rounded-lg">
                    <div className="flex items-center gap-2 mb-1">
                      <Battery className="h-3.5 w-3.5 text-slate-400" />
                      <span className="text-xs text-slate-500">Battery</span>
                    </div>
                    <p className="text-sm font-medium text-slate-700">{selectedModule.battery_level}%</p>
                  </div>
                )}
                {selectedModule.signal_strength !== null && (
                  <div className="p-3 bg-slate-50 rounded-lg">
                    <div className="flex items-center gap-2 mb-1">
                      <Signal className="h-3.5 w-3.5 text-slate-400" />
                      <span className="text-xs text-slate-500">Signal</span>
                    </div>
                    <p className="text-sm font-medium text-slate-700">{selectedModule.signal_strength}%</p>
                  </div>
                )}
                {selectedModule.last_ping && (
                  <div className="p-3 bg-slate-50 rounded-lg">
                    <div className="flex items-center gap-2 mb-1">
                      <Clock className="h-3.5 w-3.5 text-slate-400" />
                      <span className="text-xs text-slate-500">Last Ping</span>
                    </div>
                    <p className="text-sm font-medium text-slate-700">
                      {formatMalawiDateTime(selectedModule.last_ping)}
                    </p>
                  </div>
                )}
                {selectedModule.activated_at && (
                  <div className="p-3 bg-slate-50 rounded-lg">
                    <div className="flex items-center gap-2 mb-1">
                      <Activity className="h-3.5 w-3.5 text-slate-400" />
                      <span className="text-xs text-slate-500">Activated</span>
                    </div>
                    <p className="text-sm font-medium text-slate-700">
                      {formatMalawiDate(selectedModule.activated_at)}
                    </p>
                  </div>
                )}
              </div>

              {/* Linked Device Section */}
              <div className="border-t border-slate-200 pt-4">
                <h4 className="text-sm font-semibold text-slate-800 mb-3 flex items-center gap-2">
                  <Smartphone className="h-4 w-4" />
                  Linked Device
                </h4>
                {selectedModule.device ? (
                  <div className="p-4 bg-blue-50 rounded-lg">
                    <div className="flex items-center justify-between">
                      <div>
                        <p className="font-medium text-blue-900">
                          {selectedModule.device.brand} {selectedModule.device.model}
                        </p>
                        <p className="text-sm text-blue-700 font-mono">{selectedModule.device.serial_number}</p>
                      </div>
                      <button
                        onClick={() => navigate(`/admin/devices?highlight=${selectedModule.device!.id}`)}
                        className="px-3 py-1.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors"
                      >
                        View Device
                      </button>
                    </div>
                  </div>
                ) : (
                  <div className="p-4 bg-slate-50 rounded-lg text-center">
                    <Smartphone className="h-6 w-6 text-slate-400 mx-auto mb-2" />
                    <p className="text-sm text-slate-500">Not linked to any device</p>
                    <Authorize permission={PERMISSIONS.ASSIGN_IOT_MODULES}>
                      <button
                        onClick={() => {
                          setSelectedModule(null)
                          setLinkingModule(selectedModule)
                        }}
                        className="mt-2 text-sm text-blue-600 hover:text-blue-700 font-medium"
                      >
                        Link to Device
                      </button>
                    </Authorize>
                  </div>
                )}
              </div>

              {/* Footer Actions */}
              <div className="border-t border-slate-200 pt-4 flex gap-2">
                <Authorize permission={PERMISSIONS.UPDATE_IOT_MODULES}>
                  <button
                    onClick={() => {
                      setSelectedModule(null)
                      handleEditModule(selectedModule)
                    }}
                    className="flex-1 px-4 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors"
                  >
                    Edit Module
                  </button>
                </Authorize>
                <Authorize permission={PERMISSIONS.UPDATE_IOT_MODULES}>
                  <button
                    onClick={() => {
                      regenerateTokenMutation.mutate(selectedModule.id)
                      setSelectedModule(null)
                    }}
                    className="flex items-center gap-2 px-4 py-2.5 bg-amber-600 text-white text-sm font-medium rounded-lg hover:bg-amber-700 transition-colors"
                  >
                    <RotateCcw className="h-4 w-4" />
                    Regenerate Token
                  </button>
                </Authorize>
                <button
                  onClick={() => setSelectedModule(null)}
                  className="px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors"
                >
                  Close
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* Edit Module Modal */}
      {editingModule && (
        <>
          <div
            className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40"
            onClick={() => setEditingModule(null)}
          />
          <div className="fixed inset-x-4 top-[10%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-lg bg-white rounded-2xl shadow-xl z-50 overflow-hidden">
            <div className="p-5 border-b border-slate-200">
              <h3 className="text-lg font-semibold text-slate-800">Edit Module</h3>
              <p className="text-sm text-slate-500 mt-1">{editingModule.module_id}</p>
            </div>
            <div className="p-5 space-y-4 max-h-[70vh] overflow-y-auto">
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1">SIM Number</label>
                <input
                  type="text"
                  value={editForm.sim_number}
                  onChange={(e) => setEditForm({ ...editForm, sim_number: e.target.value })}
                  className="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1">IMEI</label>
                <input
                  type="text"
                  value={editForm.imei}
                  onChange={(e) => setEditForm({ ...editForm, imei: e.target.value })}
                  className="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1">Firmware Version</label>
                <input
                  type="text"
                  value={editForm.firmware_version}
                  onChange={(e) => setEditForm({ ...editForm, firmware_version: e.target.value })}
                  className="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1">Notes</label>
                <textarea
                  value={editForm.notes}
                  onChange={(e) => setEditForm({ ...editForm, notes: e.target.value })}
                  rows={3}
                  className="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                />
              </div>
              <div className="flex gap-2 pt-2">
                <button
                  onClick={() => setEditingModule(null)}
                  className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors"
                >
                  Cancel
                </button>
                <button
                  onClick={() => updateModuleMutation.mutate({ moduleId: editingModule.id, data: editForm })}
                  disabled={updateModuleMutation.isPending}
                  className="flex-1 px-4 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                  {updateModuleMutation.isPending ? 'Saving...' : 'Save Changes'}
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* Delete Confirmation Modal */}
      {deleteModule && (
        <>
          <div
            className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40"
            onClick={() => setDeleteModule(null)}
          />
          <div className="fixed inset-x-4 top-[20%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-md bg-white rounded-2xl shadow-xl z-50 overflow-hidden">
            <div className="p-5 border-b border-slate-200">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-full bg-red-100 flex items-center justify-center">
                  <AlertTriangle className="h-5 w-5 text-red-600" />
                </div>
                <div>
                  <h3 className="text-lg font-semibold text-slate-800">Delete Module</h3>
                  <p className="text-sm text-slate-500">This action cannot be undone</p>
                </div>
              </div>
            </div>
            <div className="p-5 space-y-4">
              <p className="text-sm text-slate-600">
                Are you sure you want to delete module <span className="font-semibold">{deleteModule.module_id}</span>?
                {deleteModule.device && (
                  <span className="block mt-2 text-red-600">
                    This module is currently linked to a device. Please unlink it first before deleting.
                  </span>
                )}
              </p>
              <div className="flex gap-2">
                <button
                  onClick={() => setDeleteModule(null)}
                  className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors"
                >
                  Cancel
                </button>
                <button
                  onClick={() => deleteMutation.mutate(deleteModule.id)}
                  disabled={deleteMutation.isPending || !!deleteModule.device}
                  className="flex-1 px-4 py-2.5 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                  {deleteMutation.isPending ? 'Deleting...' : 'Delete Module'}
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* Link to Device Modal */}
      {linkingModule && (
        <>
          <div
            className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40"
            onClick={() => {
              setLinkingModule(null)
              setDeviceSearch('')
              setSelectedDeviceId(null)
            }}
          />
          <div className="fixed inset-x-4 top-[15%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-lg bg-white rounded-2xl shadow-xl z-50 overflow-hidden">
            <div className="p-5 border-b border-slate-200">
              <h3 className="text-lg font-semibold text-slate-800">Link Module to Device</h3>
              <p className="text-sm text-slate-500 mt-1">{linkingModule.module_id}</p>
            </div>
            <div className="p-5 space-y-4">
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-2">Search for Device</label>
                <div className="relative">
                  <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
                  <input
                    type="text"
                    value={deviceSearch}
                    onChange={(e) => setDeviceSearch(e.target.value)}
                    placeholder="Search by brand, model, or serial..."
                    className="w-full pl-10 pr-4 py-2.5 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                  />
                </div>
              </div>

              {/* Device Results */}
              {deviceSearch.length >= 2 && (
                <div className="max-h-64 overflow-y-auto border border-slate-200 rounded-lg">
                  {devicesLoading ? (
                    <div className="flex items-center justify-center py-8">
                      <Loader2 className="h-5 w-5 animate-spin text-blue-500" />
                    </div>
                  ) : devices.length === 0 ? (
                    <div className="p-4 text-center text-sm text-slate-500">
                      No devices found
                    </div>
                  ) : (
                    <div className="divide-y divide-slate-100">
                      {devices.map((device) => (
                        <button
                          key={device.id}
                          onClick={() => setSelectedDeviceId(device.id)}
                          className={`w-full p-3 text-left hover:bg-slate-50 transition-colors ${
                            selectedDeviceId === device.id ? 'bg-blue-50' : ''
                          }`}
                        >
                          <div className="flex items-center justify-between">
                            <div>
                              <p className="font-medium text-slate-800">
                                {device.brand} {device.model}
                              </p>
                              <p className="text-xs text-slate-500 font-mono">
                                {device.serial_number}
                              </p>
                              {device.iot_module && (
                                <p className="text-xs text-amber-600 mt-1">
                                  Already has module: {device.iot_module.module_id}
                                </p>
                              )}
                            </div>
                            {selectedDeviceId === device.id && (
                              <Check className="h-5 w-5 text-blue-600" />
                            )}
                          </div>
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              )}

              {deviceSearch.length < 2 && (
                <div className="p-4 bg-slate-50 rounded-lg text-center">
                  <Search className="h-6 w-6 text-slate-400 mx-auto mb-2" />
                  <p className="text-sm text-slate-500">
                    Type at least 2 characters to search for devices
                  </p>
                </div>
              )}

              <div className="flex gap-2 pt-2">
                <button
                  onClick={() => {
                    setLinkingModule(null)
                    setDeviceSearch('')
                    setSelectedDeviceId(null)
                  }}
                  className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors"
                >
                  Cancel
                </button>
                <button
                  onClick={() => {
                    if (selectedDeviceId) {
                      linkModuleMutation.mutate({ deviceId: selectedDeviceId, moduleId: linkingModule.id })
                    }
                  }}
                  disabled={!selectedDeviceId || linkModuleMutation.isPending}
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
