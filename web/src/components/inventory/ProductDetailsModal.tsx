import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import type { GroupedInventoryItem, Device } from '@/types'
import {
  X,
  Package,
  Hash,
  Calendar,
  MapPin,
  DollarSign,
  Barcode,
  Smartphone,
  TrendingUp,
  AlertTriangle,
  CheckCircle2,
  Clock,
  Tag,
  RotateCcw,
  XCircle,
  FileText,
  ShieldAlert,
  Eye,
  Wifi,
  WifiOff,
  Palette,
  Info,
  Activity,
  Store,
  Trash2
} from 'lucide-react'
import { Authorize } from '@/components/auth/Authorize'
import { ROLES } from '@/stores/auth-store'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import apiClient from '@/api/client'
import { appendBranchScope } from '@/lib/branch-scope'

interface ProductDetailsModalProps {
  product: GroupedInventoryItem
  branchId?: string
  branchLabel?: string
  inventoryStatus?: string
  onClose: () => void
  onChangeStatus: (device: Device) => void
  onDeleteDevices: (devices: Device[]) => void
}

export default function ProductDetailsModal({ product, branchId = '', branchLabel, inventoryStatus = '', onClose, onChangeStatus, onDeleteDevices }: ProductDetailsModalProps) {
  // Fetch devices matching this product combination, honoring the active inventory filters
  const { data: devices, isLoading } = useQuery({
    queryKey: ['inventory-devices', product.category_id, product.brand, product.model, product.color, branchId, inventoryStatus],
    queryFn: async () => {
      const params = new URLSearchParams({
        category_id: product.category_id.toString(),
        per_page: '100',
      })
      if (inventoryStatus) params.append('inventory_status', inventoryStatus)
      appendBranchScope(params, branchId)

      const response = await apiClient.get<{ success: boolean; data: Device[] }>(`/admin/inventory?${params}`)

      // Filter to exact match
      const filtered = response.data.data.filter((d: Device) => {
        const brandMatch = d.brand === product.brand
        const modelMatch = d.model === product.model
        const deviceColor = d.color || null
        const productColor = product.color || null
        const colorMatch = deviceColor === productColor

        return brandMatch && modelMatch && colorMatch
      })

      return filtered
    },
  })

  const getDeviceStatus = (device: Device): string => {
    if (typeof device.inventory_status === 'string') {
      return device.inventory_status
    }
    return device.inventory_status?.value || 'in_stock'
  }

  const isProtectedFromDeletion = (device: Device): boolean => {
    const status = getDeviceStatus(device)
    const trackingStatus = typeof device.status === 'string' ? device.status : device.status?.value
    return device.is_sold === true
      || status === 'sold'
      || status === 'reserved'
      || device.is_stolen === true
      || trackingStatus === 'stolen'
      || Boolean(device.owner_id)
      || Boolean(device.sale_id)
  }

  const [selectedDeviceIds, setSelectedDeviceIds] = useState<number[]>([])
  const deviceList = devices || []
  const selectableDeviceList = deviceList.filter((device) => !isProtectedFromDeletion(device))
  const validSelectedDeviceIds = selectedDeviceIds.filter((id) => selectableDeviceList.some((device) => device.id === id))
  const selectedDevices = selectableDeviceList.filter((device) => validSelectedDeviceIds.includes(device.id))
  const selectedCount = selectedDevices.length
  const allLoadedSelected = selectableDeviceList.length > 0 && selectableDeviceList.every((device) => validSelectedDeviceIds.includes(device.id))
  const someSelected = selectedCount > 0 && !allLoadedSelected
  const isDeviceListTruncated = product.total > deviceList.length

  const toggleDeviceSelection = (device: Device) => {
    if (validSelectedDeviceIds.includes(device.id)) {
      setSelectedDeviceIds(validSelectedDeviceIds.filter((id) => id !== device.id))
      return
    }
    if (validSelectedDeviceIds.length >= 50) {
      toast.error('You can select up to 50 devices at a time.')
      return
    }
    setSelectedDeviceIds([...validSelectedDeviceIds, device.id])
  }

  const toggleLoadedSelection = () => {
    if (allLoadedSelected) {
      setSelectedDeviceIds([])
      return
    }

    const additions = selectableDeviceList.filter((device) => !validSelectedDeviceIds.includes(device.id)).map((device) => device.id)
    if (validSelectedDeviceIds.length + additions.length > 50) {
      toast.error('You can select up to 50 devices at a time.')
    }
    setSelectedDeviceIds([...validSelectedDeviceIds, ...additions].slice(0, 50))
  }

  const clearSelection = () => setSelectedDeviceIds([])

  const getStatusInfo = (status: string) => {
    const statuses: Record<string, { label: string; icon: typeof CheckCircle2; bg: string; text: string; border: string }> = {
      in_stock: { label: 'In Stock', icon: CheckCircle2, bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200' },
      reserved: { label: 'Reserved', icon: Clock, bg: 'bg-amber-50', text: 'text-amber-700', border: 'border-amber-200' },
      sold: { label: 'Sold', icon: Tag, bg: 'bg-blue-50', text: 'text-blue-700', border: 'border-blue-200' },
      returned: { label: 'Returned', icon: RotateCcw, bg: 'bg-purple-50', text: 'text-purple-700', border: 'border-purple-200' },
      faulty: { label: 'Faulty', icon: XCircle, bg: 'bg-red-50', text: 'text-red-700', border: 'border-red-200' },
    }
    return statuses[status] || { label: status, icon: Package, bg: 'bg-slate-50', text: 'text-slate-700', border: 'border-slate-200' }
  }

  const getTrackingStatus = (device: Device): string => {
    if (typeof device.status === 'string') {
      return device.status
    }
    return device.status?.value || 'inactive'
  }

  const formatDate = (dateString: string | null | undefined) => {
    if (!dateString) return null
    return new Date(dateString).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric'
    })
  }

  const formatDateTime = (dateString: string | null | undefined) => {
    if (!dateString) return null
    return new Date(dateString).toLocaleString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    })
  }

  const formatPrice = (price: number | string | null | undefined) => {
    if (!price) return null
    const num = typeof price === 'string' ? parseFloat(price) : price
    return `MWK ${num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
  }

  return (
    <>
      {/* Backdrop */}
      <div
        className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-40 animate-in fade-in duration-200"
        onClick={onClose}
      />

      {/* Modal */}
      <div className="fixed inset-x-4 top-[3%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-5xl max-h-[94vh] bg-white rounded-2xl shadow-2xl z-50 overflow-hidden flex flex-col animate-in zoom-in-95 slide-in-from-bottom-4 duration-300">
        {/* Header */}
        <div className="flex-shrink-0 bg-gradient-to-r from-slate-800 to-slate-900 text-white p-6">
          <div className="flex items-start justify-between">
            <div className="flex items-start gap-4">
              <div className="h-14 w-14 rounded-xl bg-white/10 backdrop-blur flex items-center justify-center">
                <Smartphone className="h-7 w-7 text-white" />
              </div>
              <div>
                <h3 className="text-xl font-bold">{product.brand} {product.model}</h3>
                <div className="mt-1 flex flex-wrap items-center gap-3 text-sm text-slate-300">
                  <span className="flex items-center gap-1">
                    <Package className="h-4 w-4" />
                    {product.category_name}
                  </span>
                  {product.color && (
                    <>
                      <span className="text-slate-500">•</span>
                      <span className="flex items-center gap-1.5">
                        <Palette className="h-4 w-4" />
                        {product.color}
                      </span>
                    </>
                  )}
                  {branchLabel && (
                    <>
                      <span className="text-slate-500">•</span>
                      <span className="flex items-center gap-1.5 rounded-full bg-white/10 px-2.5 py-0.5 text-xs font-medium text-white">
                        <Store className="h-3.5 w-3.5" />
                        {branchLabel}
                      </span>
                    </>
                  )}
                </div>
              </div>
            </div>
            <button
              onClick={onClose}
              className="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-colors"
            >
              <X className="h-5 w-5" />
            </button>
          </div>

          {/* Stock Summary Cards */}
          <div className="mt-5 grid grid-cols-2 sm:grid-cols-5 gap-3">
            <div className={cn(
              'px-4 py-3 rounded-xl transition-all',
              product.in_stock > 0 ? 'bg-emerald-500/20 border border-emerald-400/30' : 'bg-white/5 border border-white/10'
            )}>
              <div className="flex items-center gap-2">
                <CheckCircle2 className={cn('h-4 w-4', product.in_stock > 0 ? 'text-emerald-400' : 'text-slate-500')} />
                <p className="text-xs text-slate-300 font-medium">In Stock</p>
              </div>
              <p className={cn('text-2xl font-bold mt-1', product.in_stock > 0 ? 'text-white' : 'text-slate-500')}>{product.in_stock}</p>
            </div>
            <div className={cn(
              'px-4 py-3 rounded-xl transition-all',
              product.reserved > 0 ? 'bg-amber-500/20 border border-amber-400/30' : 'bg-white/5 border border-white/10'
            )}>
              <div className="flex items-center gap-2">
                <Clock className={cn('h-4 w-4', product.reserved > 0 ? 'text-amber-400' : 'text-slate-500')} />
                <p className="text-xs text-slate-300 font-medium">Reserved</p>
              </div>
              <p className={cn('text-2xl font-bold mt-1', product.reserved > 0 ? 'text-white' : 'text-slate-500')}>{product.reserved}</p>
            </div>
            <div className={cn(
              'px-4 py-3 rounded-xl transition-all',
              product.sold > 0 ? 'bg-blue-500/20 border border-blue-400/30' : 'bg-white/5 border border-white/10'
            )}>
              <div className="flex items-center gap-2">
                <Tag className={cn('h-4 w-4', product.sold > 0 ? 'text-blue-400' : 'text-slate-500')} />
                <p className="text-xs text-slate-300 font-medium">Sold</p>
              </div>
              <p className={cn('text-2xl font-bold mt-1', product.sold > 0 ? 'text-white' : 'text-slate-500')}>{product.sold}</p>
            </div>
            <div className={cn(
              'px-4 py-3 rounded-xl transition-all',
              product.returned > 0 ? 'bg-purple-500/20 border border-purple-400/30' : 'bg-white/5 border border-white/10'
            )}>
              <div className="flex items-center gap-2">
                <RotateCcw className={cn('h-4 w-4', product.returned > 0 ? 'text-purple-400' : 'text-slate-500')} />
                <p className="text-xs text-slate-300 font-medium">Returned</p>
              </div>
              <p className={cn('text-2xl font-bold mt-1', product.returned > 0 ? 'text-white' : 'text-slate-500')}>{product.returned}</p>
            </div>
            <div className={cn(
              'px-4 py-3 rounded-xl transition-all',
              product.faulty > 0 ? 'bg-red-500/20 border border-red-400/30' : 'bg-white/5 border border-white/10'
            )}>
              <div className="flex items-center gap-2">
                <XCircle className={cn('h-4 w-4', product.faulty > 0 ? 'text-red-400' : 'text-slate-500')} />
                <p className="text-xs text-slate-300 font-medium">Faulty</p>
              </div>
              <p className={cn('text-2xl font-bold mt-1', product.faulty > 0 ? 'text-white' : 'text-slate-500')}>{product.faulty}</p>
            </div>
          </div>

          {/* Price Info */}
          {product.avg_price > 0 && (
            <div className="mt-4 flex items-center gap-4 text-sm">
              <div className="flex items-center gap-2 px-3 py-1.5 bg-white/10 rounded-lg">
                <DollarSign className="h-4 w-4 text-emerald-400" />
                <span className="text-slate-300">Average Price:</span>
                <span className="font-bold text-white">{formatPrice(product.avg_price)}</span>
              </div>
              {product.min_price !== product.max_price && product.min_price && product.max_price && (
                <div className="flex items-center gap-2 px-3 py-1.5 bg-white/10 rounded-lg">
                  <TrendingUp className="h-4 w-4 text-blue-400" />
                  <span className="text-slate-300">Price Range:</span>
                  <span className="font-medium text-white">{formatPrice(product.min_price)} - {formatPrice(product.max_price)}</span>
                </div>
              )}
            </div>
          )}
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto bg-slate-50">
          <div className="p-6">
            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div className="flex items-center gap-2">
                <h4 className="font-semibold text-slate-800 flex items-center gap-2">
                  <Barcode className="h-5 w-5 text-slate-400" />
                  Individual Units
                </h4>
                <span className="text-sm text-slate-500">{deviceList.length} device{deviceList.length !== 1 ? 's' : ''} found</span>
              </div>
              <Authorize role={ROLES.SUPER_ADMIN}>
                <div className="flex flex-wrap items-center gap-2">
                  <label className="inline-flex cursor-pointer items-center gap-2 text-xs font-medium text-slate-600">
                    <input
                      type="checkbox"
                      aria-label="Select up to 50 eligible loaded devices"
                      checked={allLoadedSelected}
                      disabled={isLoading || selectableDeviceList.length === 0}
                      aria-checked={someSelected ? 'mixed' : allLoadedSelected}
                      onChange={toggleLoadedSelection}
                      className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                    />
                    Select up to 50 eligible loaded
                  </label>
                  {selectedCount > 0 && (
                    <>
                      <span className="text-xs font-semibold text-blue-700">{selectedCount} selected</span>
                      <button
                        type="button"
                        onClick={clearSelection}
                        className="rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100"
                      >
                        Clear
                      </button>
                      <button
                        type="button"
                        onClick={() => onDeleteDevices(selectedDevices)}
                        disabled={selectedDevices.length === 0}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                      >
                        <Trash2 className="h-3.5 w-3.5" />
                        Delete selected
                      </button>
                    </>
                  )}
                </div>
              </Authorize>
            </div>

            {devices && isDeviceListTruncated && !isLoading && (
              <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                Showing the first {deviceList.length} matching units. Narrow the filters or delete the remaining units in a separate batch.
              </div>
            )}

            {isLoading ? (
              <div className="flex flex-col items-center justify-center py-16">
                <div className="relative">
                  <div className="animate-spin rounded-full h-12 w-12 border-4 border-blue-200 border-t-blue-600"></div>
                  <Package className="absolute inset-0 m-auto h-5 w-5 text-blue-600" />
                </div>
                <p className="text-slate-500 mt-4 animate-pulse">Loading devices...</p>
              </div>
            ) : devices && devices.length > 0 ? (
              <div className="space-y-4">
                {deviceList.map((device: Device) => {
                  const status = getDeviceStatus(device)
                  const statusInfo = getStatusInfo(status)
                  const StatusIcon = statusInfo.icon
                  const trackingStatus = getTrackingStatus(device)
                  const isSelected = validSelectedDeviceIds.includes(device.id)
                  const canSelect = !isProtectedFromDeletion(device)

                  return (
                    <div
                      key={device.id}
                      className={cn(
                        'bg-white rounded-xl border-2 overflow-hidden transition-all hover:shadow-lg',
                        statusInfo.border,
                        isSelected && 'ring-2 ring-blue-500 ring-offset-2'
                      )}
                    >
                      {/* Device Header */}
                      <div className={cn('px-5 py-4 border-b', statusInfo.border, statusInfo.bg)}>
                        <div className="flex items-center justify-between">
                          <div className="flex items-center gap-3">
                            <Authorize role={ROLES.SUPER_ADMIN}>
                              <input
                                type="checkbox"
                                aria-label={`Select device ${device.serial_number}`}
                                checked={isSelected}
                                disabled={!canSelect}
                                title={canSelect ? 'Select device for deletion' : 'Protected inventory cannot be deleted'}
                                onChange={() => toggleDeviceSelection(device)}
                                className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-50"
                              />
                            </Authorize>
                            <div className={cn(
                              'h-10 w-10 rounded-lg flex items-center justify-center',
                              statusInfo.bg, 'border', statusInfo.border
                            )}>
                              <StatusIcon className={cn('h-5 w-5', statusInfo.text)} />
                            </div>
                            <div>
                              <div className="flex items-center gap-2">
                                <p className="font-bold text-slate-900 text-lg">{device.serial_number}</p>
                                <span className={cn(
                                  'px-2.5 py-0.5 rounded-full text-xs font-bold',
                                  statusInfo.bg, statusInfo.text
                                )}>
                                  {statusInfo.label}
                                </span>
                                {!canSelect && (
                                  <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">
                                    Protected
                                  </span>
                                )}
                              </div>
                              <p className="text-sm text-slate-500 flex items-center gap-1 mt-0.5">
                                <Hash className="h-3 w-3" />
                                {device.unique_device_code}
                              </p>
                            </div>
                          </div>

                          <button
                            onClick={() => onChangeStatus(device)}
                            className="px-4 py-2 bg-slate-800 text-white text-sm font-medium rounded-lg hover:bg-slate-700 transition-colors shadow-sm"
                          >
                            Change Status
                          </button>
                        </div>
                      </div>

                      {/* Device Details Grid */}
                      <div className="p-5">
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                          {/* Basic Information */}
                          <div className="space-y-3">
                            <h5 className="text-xs font-semibold text-slate-400 uppercase tracking-wider flex items-center gap-1">
                              <Info className="h-3 w-3" />
                              Basic Information
                            </h5>

                            <div className="space-y-2">
                              <div className="flex items-center justify-between p-2 bg-slate-50 rounded-lg">
                                <span className="text-xs text-slate-500">Device ID</span>
                                <span className="text-sm font-medium text-slate-700">#{device.id}</span>
                              </div>
                              <div className="flex items-center justify-between p-2 bg-slate-50 rounded-lg">
                                <span className="text-xs text-slate-500">Brand</span>
                                <span className="text-sm font-medium text-slate-700">{device.brand}</span>
                              </div>
                              <div className="flex items-center justify-between p-2 bg-slate-50 rounded-lg">
                                <span className="text-xs text-slate-500">Model</span>
                                <span className="text-sm font-medium text-slate-700">{device.model}</span>
                              </div>
                              {device.color && (
                                <div className="flex items-center justify-between p-2 bg-slate-50 rounded-lg">
                                  <span className="text-xs text-slate-500">Color</span>
                                  <span className="text-sm font-medium text-slate-700 flex items-center gap-1.5">
                                    <div
                                      className="h-3 w-3 rounded-full border border-slate-300"
                                      style={{
                                        backgroundColor: device.color?.toLowerCase() === 'white' ? '#f1f5f9' :
                                          device.color?.toLowerCase() === 'black' ? '#1e293b' :
                                          device.color?.toLowerCase() === 'gold' ? '#f59e0b' :
                                          device.color?.toLowerCase() === 'silver' ? '#94a3b8' :
                                          device.color?.toLowerCase() === 'blue' ? '#3b82f6' :
                                          device.color?.toLowerCase() === 'red' ? '#ef4444' :
                                          device.color?.toLowerCase() === 'green' ? '#22c55e' :
                                          device.color?.toLowerCase() === 'space gray' ? '#4b5563' :
                                          '#9ca3af'
                                      }}
                                    />
                                    {device.color}
                                  </span>
                                </div>
                              )}
                              {device.category && (
                                <div className="flex items-center justify-between p-2 bg-slate-50 rounded-lg">
                                  <span className="text-xs text-slate-500">Category</span>
                                  <span className="text-sm font-medium text-slate-700">{device.category.name}</span>
                                </div>
                              )}
                              <div className="flex items-center justify-between p-2 bg-slate-50 rounded-lg">
                                <span className="text-xs text-slate-500">Branch</span>
                                <span className="text-sm font-medium text-slate-700">{device.branch?.name || '-'}</span>
                              </div>
                            </div>
                          </div>

                          {/* Purchase Information */}
                          <div className="space-y-3">
                            <h5 className="text-xs font-semibold text-slate-400 uppercase tracking-wider flex items-center gap-1">
                              <Store className="h-3 w-3" />
                              Purchase Information
                            </h5>

                            <div className="space-y-2">
                              {device.purchase_price && (
                                <div className="flex items-center justify-between p-2 bg-green-50 rounded-lg">
                                  <span className="text-xs text-green-600 flex items-center gap-1">
                                    <DollarSign className="h-3 w-3" />
                                    Purchase Price
                                  </span>
                                  <span className="text-sm font-bold text-green-700">{formatPrice(device.purchase_price)}</span>
                                </div>
                              )}
                              {device.purchase_date && (
                                <div className="flex items-center justify-between p-2 bg-slate-50 rounded-lg">
                                  <span className="text-xs text-slate-500 flex items-center gap-1">
                                    <Calendar className="h-3 w-3" />
                                    Purchase Date
                                  </span>
                                  <span className="text-sm font-medium text-slate-700">{formatDate(device.purchase_date)}</span>
                                </div>
                              )}
                              {device.purchase_location && (
                                <div className="flex items-center justify-between p-2 bg-slate-50 rounded-lg">
                                  <span className="text-xs text-slate-500 flex items-center gap-1">
                                    <MapPin className="h-3 w-3" />
                                    Location
                                  </span>
                                  <span className="text-sm font-medium text-slate-700 truncate max-w-[150px]" title={device.purchase_location}>
                                    {device.purchase_location}
                                  </span>
                                </div>
                              )}
                              {!device.purchase_price && !device.purchase_date && !device.purchase_location && (
                                <p className="text-sm text-slate-400 italic p-2">No purchase information available</p>
                              )}
                            </div>
                          </div>

                          {/* Tracking & IoT Status */}
                          <div className="space-y-3">
                            <h5 className="text-xs font-semibold text-slate-400 uppercase tracking-wider flex items-center gap-1">
                              <Activity className="h-3 w-3" />
                              Tracking & IoT Status
                            </h5>

                            <div className="space-y-2">
                              <div className={cn(
                                'flex items-center justify-between p-2 rounded-lg',
                                device.has_iot_module ? 'bg-emerald-50' : 'bg-slate-50'
                              )}>
                                <span className={cn(
                                  'text-xs flex items-center gap-1',
                                  device.has_iot_module ? 'text-emerald-600' : 'text-slate-500'
                                )}>
                                  {device.has_iot_module ? <Wifi className="h-3 w-3" /> : <WifiOff className="h-3 w-3" />}
                                  IoT Module
                                </span>
                                <span className={cn(
                                  'text-sm font-medium',
                                  device.has_iot_module ? 'text-emerald-700' : 'text-slate-500'
                                )}>
                                  {device.has_iot_module ? 'Connected' : 'Not Linked'}
                                </span>
                              </div>

                              <div className={cn(
                                'flex items-center justify-between p-2 rounded-lg',
                                trackingStatus === 'active' ? 'bg-blue-50' :
                                trackingStatus === 'stolen' ? 'bg-red-50' : 'bg-slate-50'
                              )}>
                                <span className="text-xs text-slate-500 flex items-center gap-1">
                                  <Eye className="h-3 w-3" />
                                  Tracking Status
                                </span>
                                <span className={cn(
                                  'px-2 py-0.5 rounded-full text-xs font-medium capitalize',
                                  trackingStatus === 'active' ? 'bg-blue-100 text-blue-700' :
                                  trackingStatus === 'stolen' ? 'bg-red-100 text-red-700' :
                                  trackingStatus === 'recovered' ? 'bg-green-100 text-green-700' :
                                  'bg-slate-100 text-slate-600'
                                )}>
                                  {trackingStatus}
                                </span>
                              </div>

                              {device.is_stolen && (
                                <div className="flex items-center gap-2 p-2 bg-red-50 border border-red-200 rounded-lg">
                                  <ShieldAlert className="h-4 w-4 text-red-600" />
                                  <span className="text-sm font-medium text-red-700">Reported as Stolen</span>
                                </div>
                              )}

                              {device.last_seen_at && (
                                <div className="flex items-center justify-between p-2 bg-slate-50 rounded-lg">
                                  <span className="text-xs text-slate-500">Last Seen</span>
                                  <span className="text-sm font-medium text-slate-700">{formatDateTime(device.last_seen_at)}</span>
                                </div>
                              )}
                            </div>
                          </div>
                        </div>

                        {/* Sales Information (if sold) */}
                        {device.is_sold && (
                          <div className="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                            <h5 className="text-xs font-semibold text-blue-600 uppercase tracking-wider mb-3 flex items-center gap-1">
                              <Tag className="h-3 w-3" />
                              Sale Information
                            </h5>
                            <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
                              {device.sold_at && (
                                <div>
                                  <p className="text-xs text-blue-500">Sold On</p>
                                  <p className="text-sm font-medium text-blue-800">{formatDateTime(device.sold_at)}</p>
                                </div>
                              )}
                              {device.sold_by && (
                                <div>
                                  <p className="text-xs text-blue-500">Sold By</p>
                                  <p className="text-sm font-medium text-blue-800">{device.sold_by.name}</p>
                                </div>
                              )}
                              {device.owner && (
                                <div>
                                  <p className="text-xs text-blue-500">Customer</p>
                                  <p className="text-sm font-medium text-blue-800">{device.owner.name}</p>
                                </div>
                              )}
                            </div>
                          </div>
                        )}

                        {/* Description */}
                        {device.description && (
                          <div className="mt-4 p-3 bg-slate-50 rounded-lg">
                            <p className="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1 flex items-center gap-1">
                              <FileText className="h-3 w-3" />
                              Description
                            </p>
                            <p className="text-sm text-slate-700">{device.description}</p>
                          </div>
                        )}

                        {/* Notes */}
                        {device.notes && (
                          <div className="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                            <p className="text-xs font-semibold text-amber-600 uppercase tracking-wider mb-1 flex items-center gap-1">
                              <AlertTriangle className="h-3 w-3" />
                              Notes
                            </p>
                            <p className="text-sm text-amber-800">{device.notes}</p>
                          </div>
                        )}

                        {/* Timestamps */}
                        <div className="mt-4 pt-4 border-t border-slate-100 flex items-center justify-between text-xs text-slate-400">
                          <span>Created: {formatDateTime(device.created_at)}</span>
                          <span>Updated: {formatDateTime(device.updated_at)}</span>
                        </div>
                      </div>
                    </div>
                  )
                })}
              </div>
            ) : (
              <div className="flex flex-col items-center justify-center py-16 text-center bg-white rounded-xl border-2 border-dashed border-slate-200">
                <div className="h-16 w-16 rounded-full bg-slate-100 flex items-center justify-center mb-4">
                  <Package className="h-8 w-8 text-slate-400" />
                </div>
                <p className="text-slate-600 font-semibold">No devices found</p>
                <p className="text-sm text-slate-400 mt-1 max-w-xs">
                  There are no individual devices matching this exact product configuration
                </p>
              </div>
            )}
          </div>
        </div>

        {/* Footer */}
        <div className="flex-shrink-0 p-4 border-t border-slate-200 bg-white">
          <div className="flex items-center justify-between">
            <div className="text-sm text-slate-600">
              Showing <span className="font-bold text-slate-900">{devices?.length || 0}</span> of{' '}
              <span className="font-bold text-slate-900">{product.total}</span> total units
            </div>
            <button
              onClick={onClose}
              className="px-5 py-2.5 bg-slate-900 text-white text-sm font-medium rounded-xl hover:bg-slate-800 transition-colors shadow-sm"
            >
              Close
            </button>
          </div>
        </div>
      </div>
    </>
  )
}
