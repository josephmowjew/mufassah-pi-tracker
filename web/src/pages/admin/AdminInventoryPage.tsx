import { useState, useEffect, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Search, Package, Plus, Loader2, LayoutGrid, List, Layers } from 'lucide-react'
import { Authorize } from '@/components/auth/Authorize'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { PERMISSIONS, ROLES, useAuthStore } from '@/stores/auth-store'
import apiClient from '@/api/client'
import { toast } from 'sonner'
import type { Branch, Device, DeviceDeletionImpact, GroupedInventoryItem, InventoryBatch } from '@/types'
import ActionChallengePinModal, { type ActionChallengeInfo } from '@/components/admin/ActionChallengePinModal'
import BranchScopeFilter from '@/components/admin/BranchScopeFilter'
import StockViewTable from '@/components/inventory/StockViewTable'
import SerialViewTable from '@/components/inventory/SerialViewTable'
import ProductDetailsModal from '@/components/inventory/ProductDetailsModal'
import BulkAddDeviceModal from '@/components/inventory/BulkAddDeviceModal'
import { CheckCircle, Clock, Tag, RotateCcw, AlertTriangle } from 'lucide-react'
import { appendBranchScope } from '@/lib/branch-scope'
import { useInventoryPinStore } from '@/stores/inventory-pin-store'
import DeviceDeletionDialog from '@/components/admin/DeviceDeletionDialog'
import DeviceDeletionImpactModal from '@/components/admin/DeviceDeletionImpactModal'
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

interface InventoryStats {
  summary: {
    total: number
    in_stock: number
    reserved: number
    sold: number
    returned: number
    faulty: number
    available: number
  }
  by_category: Array<{
    category_id: number | null
    category_name: string
    counts: Record<string, number>
  }>
}

type ViewMode = 'stock' | 'serial' | 'batches'

const INVENTORY_DEVICE_DELETE_RETURN_PATH = '/admin/inventory'

const deletionChallengeCopy = (deviceCount = 1) => ({
  title: deviceCount > 1 ? 'Confirm bulk device deletion' : 'Confirm device deletion',
  description: deviceCount > 1
    ? 'The reviewed impact will be rechecked. Enter the PIN sent by email or revealed securely in notifications to remove all selected devices.'
    : 'The reviewed impact will be rechecked. Enter the PIN sent by email or revealed securely in notifications to continue.',
})

const ADMINISTRATIVE_SALE_TITLE = 'Confirm administrative sale'
const ADMINISTRATIVE_SALE_DESCRIPTION = 'This will mark the device sold and create an inventory financial movement without creating a POS sale or payment.'
const ADMINISTRATIVE_SALE_RETURN_PATH = '/admin/inventory'

const formatMoney = (value: number | string | null | undefined) => {
  const amount = Number(value || 0)
  return `MWK ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

const formatDate = (value: string | null | undefined) => {
  if (!value) return '-'
  return new Date(value).toLocaleDateString()
}

export default function AdminInventoryPage() {
  const queryClient = useQueryClient()
  const isSuperAdmin = useAuthStore((state) => state.isSuperAdmin())
  const currentUser = useAuthStore((state) => state.user)
  const setActiveChallenge = useInventoryPinStore((state) => state.setActiveChallenge)
  const clearActiveChallenge = useInventoryPinStore((state) => state.clearActiveChallenge)
  const clearPin = useInventoryPinStore((state) => state.clearPin)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [inventoryStatus, setInventoryStatus] = useState('')
  const [selectedBranchId, setSelectedBranchId] = useState('')
  const [selectedDevice, setSelectedDevice] = useState<Device | null>(null)
  const [showBulkAddModal, setShowBulkAddModal] = useState(false)
  const [showStatusModal, setShowStatusModal] = useState(false)
  const [selectedProduct, setSelectedProduct] = useState<GroupedInventoryItem | null>(null)
  const [selectedBatch, setSelectedBatch] = useState<InventoryBatch | null>(null)
  const [newStatus, setNewStatus] = useState('')
  const [statusNotes, setStatusNotes] = useState('')
  const [batchSellingPrice, setBatchSellingPrice] = useState('')
  const [showBatchPriceConfirm, setShowBatchPriceConfirm] = useState(false)
  const [administrativeSaleDevice, setAdministrativeSaleDevice] = useState<Device | null>(null)
  const [administrativeSaleBatch, setAdministrativeSaleBatch] = useState<InventoryBatch | null>(null)
  const [administrativeSaleAmount, setAdministrativeSaleAmount] = useState('')
  const [administrativeSaleReason, setAdministrativeSaleReason] = useState('')
  const [administrativeSaleNotes, setAdministrativeSaleNotes] = useState('')
  const [saleChallenge, setSaleChallenge] = useState<ActionChallengeInfo | null>(() => {
    const activeChallenge = Object.values(useInventoryPinStore.getState().activeChallenges)
      .find((item) => item.returnPath === ADMINISTRATIVE_SALE_RETURN_PATH && item.challenge.action === 'administrative_sale')
    return activeChallenge?.challenge || null
  })
  const [saleChallengeError, setSaleChallengeError] = useState<string | null>(null)
  const [deleteTargets, setDeleteTargets] = useState<Device[]>([])
  const [deleteImpact, setDeleteImpact] = useState<DeviceDeletionImpact | null>(null)
  const [deleteImpactError, setDeleteImpactError] = useState<string | null>(null)
  const [deleteImpactConfirmed, setDeleteImpactConfirmed] = useState(false)
  const [deleteReason, setDeleteReason] = useState('')
  const [deleteNotes, setDeleteNotes] = useState('')
  const [deleteWarning, setDeleteWarning] = useState<string | null>(null)
  const [deleteChallenge, setDeleteChallenge] = useState<ActionChallengeInfo | null>(() => {
    const activeChallenge = Object.values(useInventoryPinStore.getState().activeChallenges)
      .find((item) => item.returnPath === INVENTORY_DEVICE_DELETE_RETURN_PATH && ['device_deletion', 'bulk_device_deletion'].includes(item.challenge.action))
    return activeChallenge?.challenge || null
  })
  const [deleteChallengeError, setDeleteChallengeError] = useState<string | null>(null)
  const deleteImpactRequestId = useRef(0)

  // View mode with localStorage persistence
  const [viewMode, setViewMode] = useState<ViewMode>(() => {
    const saved = localStorage.getItem('inventory-view-mode')
    return (saved === 'stock' || saved === 'serial' || saved === 'batches') ? saved : 'stock'
  })

  useEffect(() => {
    localStorage.setItem('inventory-view-mode', viewMode)
  }, [viewMode])

  // Fetch inventory stats
  const { data: stats } = useQuery({
    queryKey: ['inventory-stats', selectedBranchId],
    queryFn: async () => {
      const params = new URLSearchParams()
      appendBranchScope(params, selectedBranchId)
      const response = await apiClient.get<{ success: boolean; data: InventoryStats }>(`/admin/inventory/stats?${params.toString()}`)
      return response.data.data
    },
  })

  // Fetch branches
  const { data: branches } = useQuery({
    queryKey: ['branches'],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: Branch[] }>('/admin/branches?per_page=100')
      return response.data.data
    },
  })

  // Fetch grouped inventory data
  const { data: groupedData, isLoading: groupedLoading } = useQuery({
    queryKey: ['inventory-grouped', search, inventoryStatus, selectedBranchId],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (search) params.append('search', search)
      if (inventoryStatus) params.append('inventory_status', inventoryStatus)
      appendBranchScope(params, selectedBranchId)

      const response = await apiClient.get<{ success: boolean; data: { data: GroupedInventoryItem[]; total: number } }>(
        `/admin/inventory/grouped?${params.toString()}`
      )
      return response.data.data.data
    },
    enabled: viewMode === 'stock',
  })

  // Fetch inventory items (serial view)
  const { data: serialData, isLoading: serialLoading } = useQuery({
    queryKey: ['admin-inventory', search, page, inventoryStatus, selectedBranchId],
    queryFn: async () => {
      const params = new URLSearchParams()
      params.append('page', page.toString())
      params.append('per_page', '15')
      if (search) params.append('search', search)
      if (inventoryStatus) params.append('inventory_status', inventoryStatus)
      appendBranchScope(params, selectedBranchId)

      const response = await apiClient.get<{ success: boolean; data: Device[]; meta: PaginatedResponse['meta'] }>(
        `/admin/inventory?${params.toString()}`
      )
      return response.data
    },
    enabled: viewMode === 'serial',
  })

  const { data: batchesData, isLoading: batchesLoading } = useQuery({
    queryKey: ['inventory-batches', search, page, selectedBranchId],
    queryFn: async () => {
      const params = new URLSearchParams()
      params.append('page', page.toString())
      params.append('per_page', '15')
      if (search) params.append('search', search)
      appendBranchScope(params, selectedBranchId)

      const response = await apiClient.get<{ success: boolean; data: InventoryBatch[]; meta: PaginatedResponse['meta'] }>(
        `/admin/inventory/batches?${params.toString()}`
      )
      return response.data
    },
    enabled: viewMode === 'batches',
  })

  const { data: selectedBatchDetails } = useQuery({
    queryKey: ['inventory-batch', selectedBatch?.id],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: InventoryBatch }>(
        `/admin/inventory/batches/${selectedBatch?.id}`
      )
      return response.data.data
    },
    enabled: !!selectedBatch?.id,
  })

  const batchForModal = selectedBatchDetails || selectedBatch

  const updateBatchPriceMutation = useMutation({
    mutationFn: async ({ id, selling_price }: { id: number; selling_price: number }) => apiClient.put(`/admin/inventory/batches/${id}/selling-price`, { selling_price }),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['inventory-batches'] }); queryClient.invalidateQueries({ queryKey: ['inventory-batch'] }); setBatchSellingPrice(''); setShowBatchPriceConfirm(false); toast.success('Batch selling price updated for future sales') },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => toast.error(error.response?.data?.message || 'Failed to update batch selling price'),
  })

  const updateBatchSellingPrice = () => {
    if (!batchForModal || !batchSellingPrice) return
    setShowBatchPriceConfirm(true)
  }

  const confirmBatchSellingPrice = () => {
    if (!batchForModal || !batchSellingPrice) return
    updateBatchPriceMutation.mutate({ id: batchForModal.id, selling_price: Number(batchSellingPrice) })
  }

  const openAdministrativeSale = (device: Device, batch: InventoryBatch | null = null) => {
    const deviceCost = device.purchase_price ?? batch?.buying_price
    if (deviceCost === null || deviceCost === undefined || !Number.isFinite(Number(deviceCost)) || Number(deviceCost) <= 0) {
      toast.error('This device has no resolvable cost and cannot be sold administratively')
      setAdministrativeSaleDevice(null)
      setAdministrativeSaleBatch(null)
      return
    }

    setAdministrativeSaleDevice(device)
    setAdministrativeSaleBatch(batch)
    const batchTargetPrice = batch?.target_selling_price
    const defaultPrice = batchTargetPrice !== null && batchTargetPrice !== undefined && Number(batchTargetPrice) > 0
      ? Number(batchTargetPrice)
      : Number(device.purchase_price ?? batch?.buying_price)
    setAdministrativeSaleAmount(Number.isFinite(defaultPrice) && defaultPrice > 0 ? String(defaultPrice) : '')
    setAdministrativeSaleReason('')
    setAdministrativeSaleNotes('')
    setSaleChallengeError(null)
  }

  const saleCost = Number(administrativeSaleDevice?.purchase_price ?? administrativeSaleBatch?.buying_price ?? 0)
  const saleAmount = Number(administrativeSaleAmount || 0)
  const saleAmountValid = administrativeSaleAmount.trim() !== '' && Number.isFinite(saleAmount) && saleAmount > 0
  const hasAdministrativeBatchPrice = administrativeSaleBatch?.target_selling_price !== null
    && administrativeSaleBatch?.target_selling_price !== undefined
    && Number(administrativeSaleBatch.target_selling_price) > 0
  const projectedSaleProfit = saleAmount - saleCost

  const requestAdministrativeSaleMutation = useMutation({
    mutationFn: async ({ deviceId, amount, reason, notes }: { deviceId: number; amount: number; reason: string; notes?: string }) => {
      const response = await apiClient.post<{ success: boolean; data: ActionChallengeInfo }>(`/admin/inventory/${deviceId}/administrative-sale/request`, {
        selling_price: amount,
        reason,
        notes: notes || undefined,
      })
      return response.data.data
    },
    onSuccess: (challenge) => {
      setSaleChallenge(challenge)
      setActiveChallenge({
        challenge,
        title: ADMINISTRATIVE_SALE_TITLE,
        description: ADMINISTRATIVE_SALE_DESCRIPTION,
        returnPath: ADMINISTRATIVE_SALE_RETURN_PATH,
      })
      setSaleChallengeError(null)
      setAdministrativeSaleDevice(null)
      queryClient.invalidateQueries({ queryKey: ['notifications'] })
      queryClient.invalidateQueries({ queryKey: ['notifications-unread-count'] })
      toast.success('Verification PIN sent by email and available securely in notifications')
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Unable to request administrative sale')
    },
  })

  const verifyAdministrativeSaleMutation = useMutation({
    mutationFn: async ({ challengeId, pin }: { challengeId: number; pin: string }) => {
      const response = await apiClient.post(`/admin/action-challenges/${challengeId}/verify`, { pin })
      return response.data
    },
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({ queryKey: ['admin-inventory'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-grouped'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-stats'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-batches'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-batch'] })
      queryClient.invalidateQueries({ queryKey: ['stock-movements'] })
      setSaleChallenge(null)
      clearPin(variables.challengeId)
      clearActiveChallenge(variables.challengeId)
      setSaleChallengeError(null)
      setAdministrativeSaleBatch(null)
      toast.success('Administrative sale recorded with profit/loss tracking')
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      setSaleChallengeError(error.response?.data?.message || 'Invalid or expired PIN')
    },
  })

  const resendAdministrativeSaleMutation = useMutation({
    mutationFn: async (challengeId: number) => {
      const response = await apiClient.post<{ success: boolean; data: ActionChallengeInfo }>(`/admin/action-challenges/${challengeId}/resend`)
      return response.data.data
    },
    onSuccess: (challenge) => {
      setSaleChallenge(challenge)
      clearPin(challenge.challenge_id)
      setActiveChallenge({
        challenge,
        title: ADMINISTRATIVE_SALE_TITLE,
        description: ADMINISTRATIVE_SALE_DESCRIPTION,
        returnPath: ADMINISTRATIVE_SALE_RETURN_PATH,
      })
      setSaleChallengeError(null)
      queryClient.invalidateQueries({ queryKey: ['notifications'] })
      queryClient.invalidateQueries({ queryKey: ['notifications-unread-count'] })
      toast.success('A new PIN was sent and is available securely in notifications')
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      setSaleChallengeError(error.response?.data?.message || 'Unable to resend PIN')
    },
  })

  const loadDeviceDeleteImpactMutation = useMutation({
    mutationFn: async ({ devices }: { devices: Device[]; requestId: number }) => {
      const response = await apiClient.post<{ success: boolean; data: DeviceDeletionImpact }>('/admin/devices/deletion-impact', {
        device_ids: devices.map((device) => device.id),
      })
      return response.data.data
    },
    onSuccess: (impact, variables) => {
      if (variables.requestId !== deleteImpactRequestId.current) return
      setDeleteImpact(impact)
      setDeleteImpactError(null)
      setDeleteImpactConfirmed(false)
    },
    onError: (error, variables) => {
      if (variables.requestId !== deleteImpactRequestId.current) return
      setDeleteImpact(null)
      setDeleteImpactError(getApiErrorMessage(error, 'Unable to load the device deletion impact.'))
    },
  })

  const requestDeleteImpact = (devices: Device[]) => {
    const requestId = ++deleteImpactRequestId.current
    loadDeviceDeleteImpactMutation.mutate({ devices, requestId })
  }

  const requestDeviceDeleteMutation = useMutation({
    mutationFn: async ({ devices, reason, notes, confirmHistoryLoss, impactFingerprint }: { devices: Device[]; reason: string; notes?: string; confirmHistoryLoss?: boolean; impactFingerprint?: string }) => {
      const deviceIds = devices.map((device) => device.id)
      const endpoint = deviceIds.length > 1
        ? '/admin/devices/bulk-delete/request'
        : `/admin/devices/${deviceIds[0]}/delete/request`
      const body = deviceIds.length > 1
        ? {
            device_ids: deviceIds,
            reason,
            notes: notes || undefined,
            confirm_impact: true,
            impact_fingerprint: impactFingerprint,
            confirm_history_loss: confirmHistoryLoss || undefined,
          }
        : {
            reason,
            notes: notes || undefined,
            confirm_impact: true,
            impact_fingerprint: impactFingerprint,
            confirm_history_loss: confirmHistoryLoss || undefined,
          }

      const response = await apiClient.post<{ success: boolean; data: ActionChallengeInfo }>(endpoint, body)
      return response.data.data
    },
    onSuccess: (challenge, variables) => {
      const copy = deletionChallengeCopy(variables.devices.length)
      setDeleteChallenge(challenge)
      setActiveChallenge({ challenge, ...copy, returnPath: INVENTORY_DEVICE_DELETE_RETURN_PATH })
      setDeleteChallengeError(null)
      setDeleteWarning(null)
      setSelectedProduct(null)
      queryClient.invalidateQueries({ queryKey: ['notifications'] })
      queryClient.invalidateQueries({ queryKey: ['notifications-unread-count'] })
      toast.success(`Verification PIN sent for ${variables.devices.length} device${variables.devices.length === 1 ? '' : 's'} by email and available securely in notifications`)
    },
    onError: (error) => {
      const responseData = (error as { response?: { data?: { impact?: DeviceDeletionImpact; impact_stale?: boolean } } }).response?.data
      if (responseData?.impact) {
        setDeleteImpact(responseData.impact)
      }
      if (responseData?.impact_stale) {
        setDeleteImpactConfirmed(false)
        toast.error('The deletion impact changed. Review the updated preview before continuing.')
        return
      }
      if (requiresApiConfirmation(error)) {
        setDeleteImpactConfirmed(true)
        setDeleteWarning(getApiErrorMessage(error, 'Some selected devices have inventory history. Confirm if you still want to delete them.'))
        return
      }
      setDeleteWarning(null)
      toast.error(getApiErrorMessage(error, 'Unable to request device deletion'))
    },
  })

  const verifyDeviceDeleteMutation = useMutation({
    mutationFn: async ({ challengeId, pin }: { challengeId: number; pin: string }) => {
      const response = await apiClient.post(`/admin/action-challenges/${challengeId}/verify`, { pin })
      return response.data
    },
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({ queryKey: ['admin-devices'] })
      queryClient.invalidateQueries({ queryKey: ['admin-dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['branches'] })
      queryClient.invalidateQueries({ queryKey: ['admin-inventory'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-devices'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-grouped'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-stats'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-batches'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-batch'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-receipts'] })
      queryClient.invalidateQueries({ queryKey: ['stock-movements'] })
      queryClient.invalidateQueries({ queryKey: ['product-options'] })
      queryClient.invalidateQueries({ queryKey: ['device-categories-with-stock'] })
      queryClient.invalidateQueries({ queryKey: ['customer-service-device-categories-with-stock'] })
      queryClient.invalidateQueries({ queryKey: ['customer-service-product-options'] })
      queryClient.invalidateQueries({ queryKey: ['admin-device-stats'] })
      queryClient.invalidateQueries({ queryKey: ['iot-modules'] })
      queryClient.invalidateQueries({ queryKey: ['devices-for-linking'] })
      queryClient.invalidateQueries({ queryKey: ['geofences'] })
      queryClient.invalidateQueries({ queryKey: ['device-geofences'] })
      queryClient.invalidateQueries({ queryKey: ['catalog-brands'] })
      queryClient.invalidateQueries({ queryKey: ['catalog-models'] })
      queryClient.invalidateQueries({ queryKey: ['notifications'] })
      queryClient.invalidateQueries({ queryKey: ['notifications-unread-count'] })
      const deletedCount = deleteChallenge?.device_count || deleteTargets.length || 1
      toast.success(`${deletedCount} device${deletedCount === 1 ? '' : 's'} deleted successfully`)
      setDeleteChallenge(null)
      clearPin(variables.challengeId)
      clearActiveChallenge(variables.challengeId)
      setDeleteChallengeError(null)
      setDeleteWarning(null)
      setDeleteTargets([])
      setDeleteImpact(null)
      setDeleteImpactError(null)
      setDeleteImpactConfirmed(false)
      setDeleteReason('')
      setDeleteNotes('')
      setSelectedProduct(null)
    },
    onError: (error) => {
      const attemptsRemaining = (error as { response?: { data?: { attempts_remaining?: number } } }).response?.data?.attempts_remaining
      if (typeof attemptsRemaining === 'number') {
        setDeleteChallenge((current) => current ? { ...current, attempts_remaining: attemptsRemaining } : current)
      }
      setDeleteChallengeError(getApiErrorMessage(error, 'Invalid or expired PIN'))
    },
  })

  const resendDeviceDeleteMutation = useMutation({
    mutationFn: async (challengeId: number) => {
      const response = await apiClient.post<{ success: boolean; data: ActionChallengeInfo }>(`/admin/action-challenges/${challengeId}/resend`)
      return response.data.data
    },
    onSuccess: (challenge) => {
      const copy = deletionChallengeCopy(challenge.device_count)
      setDeleteChallenge(challenge)
      clearPin(challenge.challenge_id)
      setActiveChallenge({ challenge, ...copy, returnPath: INVENTORY_DEVICE_DELETE_RETURN_PATH })
      setDeleteChallengeError(null)
      queryClient.invalidateQueries({ queryKey: ['notifications'] })
      queryClient.invalidateQueries({ queryKey: ['notifications-unread-count'] })
      toast.success('A new PIN was sent and is available securely in notifications')
    },
    onError: (error) => {
      setDeleteChallengeError(getApiErrorMessage(error, 'Unable to resend PIN'))
    },
  })

  const openDeleteDialog = (targets: Device[]) => {
    setDeleteTargets(targets)
    setDeleteImpact(null)
    setDeleteImpactError(null)
    setDeleteImpactConfirmed(false)
    setDeleteReason('')
    setDeleteNotes('')
    setDeleteWarning(null)
    requestDeleteImpact(targets)
  }

  const closeDeleteDialog = () => {
    deleteImpactRequestId.current += 1
    setDeleteTargets([])
    setDeleteImpact(null)
    setDeleteImpactError(null)
    setDeleteImpactConfirmed(false)
    setDeleteWarning(null)
    setDeleteReason('')
    setDeleteNotes('')
  }

  // Update inventory status mutation
  const updateStatusMutation = useMutation({
    mutationFn: async ({ deviceId, status, notes }: { deviceId: number; status: string; notes?: string }) => {
      await apiClient.put(`/admin/inventory/${deviceId}/status`, { inventory_status: status, notes })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-inventory'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-grouped'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-stats'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-devices'] })
      toast.success('Inventory status updated')
      setShowStatusModal(false)
      setSelectedDevice(null)
      setNewStatus('')
      setStatusNotes('')
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Failed to update status')
    },
  })

  const handleOpenStatusModal = (device: Device) => {
    setSelectedDevice(device)
    const currentStatus = typeof device.inventory_status === 'string'
      ? device.inventory_status
      : device.inventory_status?.value || 'in_stock'
    setNewStatus(currentStatus)
    setShowStatusModal(true)
  }

  const handleViewProductDetails = (product: GroupedInventoryItem) => {
    setSelectedProduct(product)
  }

  const devices = serialData?.data || []
  const meta = serialData?.meta
  const groupedItems = groupedData || []
  const isLoading = viewMode === 'stock' ? groupedLoading : viewMode === 'serial' ? serialLoading : batchesLoading
  const batches = batchesData?.data || []
  const batchesMeta = batchesData?.meta
  const selectedBranchLabel = selectedBranchId
    ? (branches?.find((branch) => String(branch.id) === selectedBranchId)?.name || 'Selected branch')
    : isSuperAdmin
      ? 'All branches'
      : (currentUser?.branch?.name || 'Assigned branch')

  return (
    <Authorize permission={PERMISSIONS.ACCESS_ADMIN_PANEL} fallback={<div className="p-6">Access denied</div>}>
      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold text-slate-900">Inventory Management</h1>
            <p className="text-slate-500 text-sm mt-1">Manage device stock and inventory</p>
          </div>
          <Authorize permission={PERMISSIONS.MANAGE_INVENTORY}>
            <button
              onClick={() => setShowBulkAddModal(true)}
              className="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors"
            >
              <Plus className="h-4 w-4" />
              Receive Stock
            </button>
          </Authorize>
        </div>

        {/* Stats Cards */}
        {stats && (
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
            <div className="bg-white rounded-xl border border-slate-200 p-4">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-lg bg-slate-100 flex items-center justify-center">
                  <Package className="h-5 w-5 text-slate-600" />
                </div>
                <div>
                  <p className="text-2xl font-bold text-slate-900">{stats.summary.total}</p>
                  <p className="text-xs text-slate-500">Total</p>
                </div>
              </div>
            </div>
            <div className="bg-white rounded-xl border border-slate-200 p-4">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-lg bg-emerald-100 flex items-center justify-center">
                  <CheckCircle className="h-5 w-5 text-emerald-600" />
                </div>
                <div>
                  <p className="text-2xl font-bold text-emerald-600">{stats.summary.in_stock}</p>
                  <p className="text-xs text-slate-500">In Stock</p>
                </div>
              </div>
            </div>
            <div className="bg-white rounded-xl border border-slate-200 p-4">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-lg bg-amber-100 flex items-center justify-center">
                  <Clock className="h-5 w-5 text-amber-600" />
                </div>
                <div>
                  <p className="text-2xl font-bold text-amber-600">{stats.summary.reserved}</p>
                  <p className="text-xs text-slate-500">Reserved</p>
                </div>
              </div>
            </div>
            <div className="bg-white rounded-xl border border-slate-200 p-4">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-lg bg-blue-100 flex items-center justify-center">
                  <Tag className="h-5 w-5 text-blue-600" />
                </div>
                <div>
                  <p className="text-2xl font-bold text-blue-600">{stats.summary.sold}</p>
                  <p className="text-xs text-slate-500">Sold</p>
                </div>
              </div>
            </div>
            <div className="bg-white rounded-xl border border-slate-200 p-4">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-lg bg-purple-100 flex items-center justify-center">
                  <RotateCcw className="h-5 w-5 text-purple-600" />
                </div>
                <div>
                  <p className="text-2xl font-bold text-purple-600">{stats.summary.returned}</p>
                  <p className="text-xs text-slate-500">Returned</p>
                </div>
              </div>
            </div>
            <div className="bg-white rounded-xl border border-slate-200 p-4">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-lg bg-red-100 flex items-center justify-center">
                  <AlertTriangle className="h-5 w-5 text-red-600" />
                </div>
                <div>
                  <p className="text-2xl font-bold text-red-600">{stats.summary.faulty}</p>
                  <p className="text-xs text-slate-500">Faulty</p>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Filters & View Toggle */}
        <div className="bg-white rounded-xl border border-slate-200 p-4">
          <div className="flex flex-col sm:flex-row gap-4">
            <div className="flex-1 relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
              <input
                type="text"
                placeholder={
                  viewMode === 'batches'
                    ? "Search by batch, brand, model, location..."
                    : viewMode === 'stock'
                      ? "Search by brand, model..."
                      : "Search by serial number, brand, model..."
                }
                value={search}
                onChange={(e) => { setSearch(e.target.value); setPage(1) }}
                className="w-full pl-10 pr-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
              />
            </div>
            <BranchScopeFilter
              value={selectedBranchId}
              branches={branches}
              onChange={(value) => { setSelectedBranchId(value); setPage(1) }}
              className="w-full sm:w-64"
            />
            {viewMode !== 'batches' && (
              <select
                value={inventoryStatus}
                onChange={(e) => { setInventoryStatus(e.target.value); setPage(1) }}
                className="px-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">All Status</option>
                <option value="in_stock">In Stock</option>
                <option value="reserved">Reserved</option>
                <option value="sold">Sold</option>
                <option value="returned">Returned</option>
                <option value="faulty">Faulty</option>
              </select>
            )}

            {/* View Toggle */}
            <div className="flex rounded-lg border border-slate-200 p-1 bg-slate-50">
              <button
                onClick={() => setViewMode('stock')}
                className={`flex items-center gap-2 px-3 py-1.5 rounded-md text-sm font-medium transition-colors ${
                  viewMode === 'stock'
                    ? 'bg-white text-blue-600 shadow-sm'
                    : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                <LayoutGrid className="h-4 w-4" />
                Stock View
              </button>
              <button
                onClick={() => setViewMode('serial')}
                className={`flex items-center gap-2 px-3 py-1.5 rounded-md text-sm font-medium transition-colors ${
                  viewMode === 'serial'
                    ? 'bg-white text-blue-600 shadow-sm'
                    : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                <List className="h-4 w-4" />
                Serial View
              </button>
              <button
                onClick={() => setViewMode('batches')}
                className={`flex items-center gap-2 px-3 py-1.5 rounded-md text-sm font-medium transition-colors ${
                  viewMode === 'batches'
                    ? 'bg-white text-blue-600 shadow-sm'
                    : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                <Layers className="h-4 w-4" />
                Batches
              </button>
            </div>
          </div>
        </div>

        {/* Inventory View */}
        <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
          {viewMode === 'stock' ? (
            <StockViewTable
              data={groupedItems}
              isLoading={isLoading}
              onViewDetails={handleViewProductDetails}
            />
          ) : viewMode === 'serial' ? (
            <SerialViewTable
              devices={devices}
              isLoading={isLoading}
              onChangeStatus={handleOpenStatusModal}
              onAdministrativeSale={(device) => openAdministrativeSale(device)}
              meta={meta}
              onPageChange={setPage}
            />
          ) : (
            <div className="overflow-x-auto">
              {isLoading ? (
                <div className="flex items-center justify-center py-16">
                  <Loader2 className="h-8 w-8 animate-spin text-blue-500" />
                </div>
              ) : batches.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-16">
                  <Layers className="h-10 w-10 text-slate-300 mb-2" />
                  <p className="text-sm text-slate-500">No inventory batches found</p>
                </div>
              ) : (
                <table className="w-full">
                  <thead className="bg-slate-50 border-b border-slate-200">
                    <tr>
                      <th className="text-left px-4 py-3 text-xs font-medium text-slate-500 uppercase">Batch</th>
                      <th className="text-left px-4 py-3 text-xs font-medium text-slate-500 uppercase">Product</th>
                      <th className="text-left px-4 py-3 text-xs font-medium text-slate-500 uppercase">Branch</th>
                      <th className="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">Received</th>
                      <th className="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">Stock</th>
                      <th className="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">Reserved</th>
                      <th className="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">Sold</th>
                      <th className="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">Cost</th>
                      <th className="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">Target</th>
                      <th className="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">Revenue</th>
                      <th className="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">Profit</th>
                      <th className="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">Margin</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-200">
                    {batches.map((batch) => (
                      <tr
                        key={batch.id}
                        onClick={() => setSelectedBatch(batch)}
                        className="hover:bg-slate-50 cursor-pointer"
                      >
                        <td className="px-4 py-3 font-medium text-slate-900">{batch.batch_number}</td>
                        <td className="px-4 py-3 text-sm text-slate-600">
                          {batch.brand} {batch.model}{batch.color ? ` - ${batch.color}` : ''}
                        </td>
                        <td className="px-4 py-3 text-sm text-slate-600">{batch.branch?.name || '-'}</td>
                        <td className="px-4 py-3 text-sm text-right">{batch.quantity_received}</td>
                        <td className="px-4 py-3 text-sm text-right text-emerald-700">{batch.summary.in_stock}</td>
                        <td className="px-4 py-3 text-sm text-right text-amber-700">{batch.summary.reserved}</td>
                        <td className="px-4 py-3 text-sm text-right text-blue-700">{batch.summary.sold}</td>
                        <td className="px-4 py-3 text-sm text-right">{formatMoney(batch.buying_price)}</td>
                        <td className="px-4 py-3 text-sm text-right">{formatMoney(batch.target_selling_price)}</td>
                        <td className="px-4 py-3 text-sm text-right">{formatMoney(batch.summary.realized_revenue)}</td>
                        <td className="px-4 py-3 text-sm text-right font-medium text-emerald-700">{formatMoney(batch.summary.realized_gross_profit)}</td>
                        <td className="px-4 py-3 text-sm text-right">{batch.summary.margin === null ? '-' : `${batch.summary.margin}%`}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
              {batchesMeta && batchesMeta.last_page > 1 && (
                <div className="flex items-center justify-between px-6 py-4 border-t border-slate-200">
                  <p className="text-sm text-slate-500">
                    Showing {((batchesMeta.current_page - 1) * batchesMeta.per_page) + 1} to {Math.min(batchesMeta.current_page * batchesMeta.per_page, batchesMeta.total)} of {batchesMeta.total}
                  </p>
                  <div className="flex gap-2">
                    <button
                      onClick={() => setPage(p => Math.max(1, p - 1))}
                      disabled={batchesMeta.current_page === 1}
                      className="px-3 py-1 text-sm border border-slate-200 rounded-lg hover:bg-slate-50 disabled:opacity-50"
                    >
                      Previous
                    </button>
                    <button
                      onClick={() => setPage(p => Math.min(batchesMeta.last_page, p + 1))}
                      disabled={batchesMeta.current_page === batchesMeta.last_page}
                      className="px-3 py-1 text-sm border border-slate-200 rounded-lg hover:bg-slate-50 disabled:opacity-50"
                    >
                      Next
                    </button>
                  </div>
                </div>
              )}
            </div>
          )}
        </div>

        {/* Bulk Add Device Modal */}
        {showBulkAddModal && (
          <BulkAddDeviceModal onClose={() => setShowBulkAddModal(false)} />
        )}

        {/* Product Details Modal */}
        {selectedProduct && (
          <ProductDetailsModal
            product={selectedProduct}
            branchId={selectedBranchId}
            branchLabel={selectedBranchLabel}
            inventoryStatus={inventoryStatus}
            onClose={() => setSelectedProduct(null)}
            onChangeStatus={handleOpenStatusModal}
            onDeleteDevices={openDeleteDialog}
          />
        )}

        <Authorize role={ROLES.SUPER_ADMIN}>
          {deleteTargets.length > 0 && !deleteImpactConfirmed && (
            <DeviceDeletionImpactModal
              elevated
              impact={deleteImpact}
              isLoading={loadDeviceDeleteImpactMutation.isPending}
              error={deleteImpactError}
              onConfirm={() => setDeleteImpactConfirmed(true)}
              onCancel={closeDeleteDialog}
              onRetry={() => requestDeleteImpact(deleteTargets)}
            />
          )}

          {deleteTargets.length > 0 && !deleteChallenge && deleteImpactConfirmed && deleteImpact && (
            <DeviceDeletionDialog
              elevated
              devices={deleteTargets}
              reason={deleteReason}
              notes={deleteNotes}
              warning={deleteWarning || (deleteImpact.devices.requires_history_confirmation > 0 ? 'These selected devices have inventory history. Proceeding will record any required stock write-off and keep the audit movements.' : null)}
              isSubmitting={requestDeviceDeleteMutation.isPending}
              onReasonChange={setDeleteReason}
              onNotesChange={setDeleteNotes}
              onSubmit={() => requestDeviceDeleteMutation.mutate({
                devices: deleteTargets,
                reason: deleteReason,
                notes: deleteNotes,
                impactFingerprint: deleteImpact.fingerprint,
                confirmHistoryLoss: Boolean(deleteWarning) || deleteImpact.devices.requires_history_confirmation > 0,
              })}
              onCancel={closeDeleteDialog}
              onDismissWarning={() => { setDeleteWarning(null); setDeleteImpactConfirmed(false); requestDeleteImpact(deleteTargets) }}
            />
          )}

          {deleteChallenge && (
            <ActionChallengePinModal
              challenge={deleteChallenge}
              title={deletionChallengeCopy(deleteChallenge.device_count).title}
              description={deletionChallengeCopy(deleteChallenge.device_count).description}
              error={deleteChallengeError}
              isVerifying={verifyDeviceDeleteMutation.isPending}
              isResending={resendDeviceDeleteMutation.isPending}
              onVerify={(pin) => verifyDeviceDeleteMutation.mutate({ challengeId: deleteChallenge.challenge_id, pin })}
              onResend={() => resendDeviceDeleteMutation.mutate(deleteChallenge.challenge_id)}
              onClose={() => {
                 if (deleteChallenge) {
                   clearActiveChallenge(deleteChallenge.challenge_id)
                   clearPin(deleteChallenge.challenge_id)
                 }
                 setDeleteChallenge(null)
                 setDeleteChallengeError(null)
                 setDeleteWarning(null)
                 setDeleteImpactConfirmed(false)
                 if (deleteTargets.length > 0) requestDeleteImpact(deleteTargets)
               }}
            />
          )}
        </Authorize>

        {batchForModal && (
          <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <div className="bg-white rounded-xl max-w-6xl w-full max-h-[90vh] overflow-y-auto">
              <div className="p-6 border-b border-slate-200 flex items-start justify-between gap-4">
                <div>
                  <h2 className="text-lg font-semibold text-slate-900">{batchForModal.batch_number}</h2>
                  <p className="text-sm text-slate-500">
                    {batchForModal.brand} {batchForModal.model}{batchForModal.color ? ` - ${batchForModal.color}` : ''}
                  </p>
                  {batchForModal.receipt && (
                    <p className="text-xs text-slate-500 mt-1">
                      Receipt {batchForModal.receipt.receipt_number}
                      {batchForModal.receipt.reference_number ? ` | Ref ${batchForModal.receipt.reference_number}` : ''}
                    </p>
                  )}
                </div>
                <button onClick={() => setSelectedBatch(null)} className="text-sm text-slate-500 hover:text-slate-900">Close</button>
              </div>
              <div className="p-6 space-y-6">
                <div className="grid grid-cols-2 md:grid-cols-6 gap-3">
                  <div className="p-3 bg-slate-50 rounded-lg">
                    <p className="text-xs text-slate-500">Received</p>
                    <p className="font-semibold">{batchForModal.quantity_received}</p>
                  </div>
                  <div className="p-3 bg-emerald-50 rounded-lg">
                    <p className="text-xs text-emerald-700">In Stock</p>
                    <p className="font-semibold text-emerald-700">{batchForModal.summary.in_stock}</p>
                  </div>
                  <div className="p-3 bg-amber-50 rounded-lg">
                    <p className="text-xs text-amber-700">Reserved</p>
                    <p className="font-semibold text-amber-700">{batchForModal.summary.reserved}</p>
                  </div>
                  <div className="p-3 bg-blue-50 rounded-lg">
                    <p className="text-xs text-blue-700">Sold</p>
                    <p className="font-semibold text-blue-700">{batchForModal.summary.sold}</p>
                  </div>
                  <div className="p-3 bg-purple-50 rounded-lg">
                    <p className="text-xs text-purple-700">Returned</p>
                    <p className="font-semibold text-purple-700">{batchForModal.summary.returned}</p>
                  </div>
                  <div className="p-3 bg-red-50 rounded-lg">
                    <p className="text-xs text-red-700">Faulty</p>
                    <p className="font-semibold text-red-700">{batchForModal.summary.faulty}</p>
                  </div>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                  <div className="border border-slate-200 rounded-lg p-4">
                    <h3 className="font-medium text-slate-900 mb-3">Product & Source</h3>
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                      <dt className="text-slate-500">Category</dt>
                      <dd className="text-slate-900">{batchForModal.category?.name || '-'}</dd>
                      <dt className="text-slate-500">Branch</dt>
                      <dd className="text-slate-900">{batchForModal.branch?.name || '-'}</dd>
                      <dt className="text-slate-500">Purchase Date</dt>
                      <dd className="text-slate-900">{formatDate(batchForModal.purchase_date)}</dd>
                      <dt className="text-slate-500">Purchase Location</dt>
                      <dd className="text-slate-900">{batchForModal.purchase_location || '-'}</dd>
                      <dt className="text-slate-500">Created</dt>
                      <dd className="text-slate-900">{formatDate(batchForModal.created_at)}</dd>
                      <dt className="text-slate-500">Notes</dt>
                      <dd className="text-slate-900">{batchForModal.notes || '-'}</dd>
                    </dl>
                  </div>

                  <div className="border border-slate-200 rounded-lg p-4">
                    <h3 className="font-medium text-slate-900 mb-3">Receipt</h3>
                    {batchForModal.receipt ? (
                      <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <dt className="text-slate-500">Receipt No.</dt>
                        <dd className="text-slate-900">{batchForModal.receipt.receipt_number}</dd>
                        <dt className="text-slate-500">Supplier</dt>
                        <dd className="text-slate-900">{batchForModal.receipt.supplier || '-'}</dd>
                        <dt className="text-slate-500">Reference</dt>
                        <dd className="text-slate-900">{batchForModal.receipt.reference_number || '-'}</dd>
                        <dt className="text-slate-500">Received By</dt>
                        <dd className="text-slate-900">{batchForModal.receipt.received_by?.name || '-'}</dd>
                        <dt className="text-slate-500">Received At</dt>
                        <dd className="text-slate-900">{formatDate(batchForModal.receipt.received_at)}</dd>
                        <dt className="text-slate-500">Receipt Notes</dt>
                        <dd className="text-slate-900">{batchForModal.receipt.notes || '-'}</dd>
                      </dl>
                    ) : (
                      <p className="text-sm text-slate-500">Standalone or legacy batch without a receipt.</p>
                    )}
                  </div>

                  <div className="border border-slate-200 rounded-lg p-4">
                    <h3 className="font-medium text-slate-900 mb-3">Unit Economics</h3>
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                      <dt className="text-slate-500">Buying Price</dt>
                      <dd className="text-slate-900">{formatMoney(batchForModal.buying_price)}</dd>
                      <dt className="text-slate-500">Selling Price for This Batch</dt>
                      <dd className="text-slate-900 flex items-center gap-2">{formatMoney(batchForModal.target_selling_price)}
                        <Authorize permission={PERMISSIONS.MANAGE_INVENTORY}><button onClick={() => setBatchSellingPrice(String(batchForModal.target_selling_price))} className="text-xs text-blue-600">Edit</button></Authorize>
                      </dd>
                      <dt className="text-slate-500">Expected Revenue</dt>
                      <dd className="text-slate-900">{formatMoney(Number(batchForModal.target_selling_price) * batchForModal.quantity_received)}</dd>
                      <dt className="text-slate-500">Expected Profit</dt>
                      <dd className="text-blue-700 font-medium">{formatMoney(batchForModal.summary.expected_gross_profit)}</dd>
                      <dt className="text-slate-500">POS Revenue</dt>
                      <dd className="text-slate-900">{formatMoney(batchForModal.summary.pos_revenue ?? batchForModal.summary.realized_revenue)}</dd>
                      <dt className="text-slate-500">Administrative Sale Value</dt>
                      <dd className="text-violet-700 font-medium">{formatMoney(batchForModal.summary.administrative_sale_value)}</dd>
                      <dt className="text-slate-500">Combined Realized Value</dt>
                      <dd className="text-slate-900">{formatMoney(batchForModal.summary.combined_inventory_realized_value ?? batchForModal.summary.realized_revenue)}</dd>
                      <dt className="text-slate-500">Combined Profit/Loss</dt>
                      <dd className={`font-medium ${(batchForModal.summary.combined_gross_profit_loss ?? batchForModal.summary.realized_gross_profit) < 0 ? 'text-red-700' : 'text-emerald-700'}`}>{formatMoney(batchForModal.summary.combined_gross_profit_loss ?? batchForModal.summary.realized_gross_profit)}</dd>
                      <dt className="text-slate-500">Margin</dt>
                      <dd className="text-slate-900">{batchForModal.summary.margin === null ? '-' : `${batchForModal.summary.margin}%`}</dd>
                    </dl>
                  </div>
                </div>
                {batchSellingPrice && (
                  <div className="mt-4 flex items-end gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <label className="flex-1 text-sm font-medium text-slate-700">New selling price (MWK)<input type="number" min="0" step="0.01" value={batchSellingPrice} onChange={(e) => setBatchSellingPrice(e.target.value)} className="mt-1 w-full rounded border border-slate-300 px-3 py-2" /></label>
                    <button onClick={() => setBatchSellingPrice('')} className="px-3 py-2 text-slate-600">Cancel</button><button onClick={updateBatchSellingPrice} className="px-3 py-2 rounded bg-blue-600 text-white">Save price</button>
                  </div>
                )}

                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                  <div className="p-3 bg-slate-50 rounded-lg">
                    <p className="text-xs text-slate-500">Total Cost</p>
                    <p className="font-semibold">{formatMoney(batchForModal.summary.total_cost)}</p>
                  </div>
                  <div className="p-3 bg-slate-50 rounded-lg">
                    <p className="text-xs text-slate-500">POS Revenue</p>
                    <p className="font-semibold">{formatMoney(batchForModal.summary.pos_revenue ?? batchForModal.summary.realized_revenue)}</p>
                  </div>
                  <div className="p-3 bg-violet-50 rounded-lg">
                    <p className="text-xs text-violet-700">Admin Sale Value</p>
                    <p className="font-semibold text-violet-700">{formatMoney(batchForModal.summary.administrative_sale_value)}</p>
                  </div>
                  <div className={`p-3 rounded-lg ${(batchForModal.summary.combined_gross_profit_loss ?? batchForModal.summary.realized_gross_profit) < 0 ? 'bg-red-50' : 'bg-emerald-50'}`}>
                    <p className={`text-xs ${(batchForModal.summary.combined_gross_profit_loss ?? batchForModal.summary.realized_gross_profit) < 0 ? 'text-red-700' : 'text-emerald-700'}`}>Combined Profit/Loss</p>
                    <p className={`font-semibold ${(batchForModal.summary.combined_gross_profit_loss ?? batchForModal.summary.realized_gross_profit) < 0 ? 'text-red-700' : 'text-emerald-700'}`}>{formatMoney(batchForModal.summary.combined_gross_profit_loss ?? batchForModal.summary.realized_gross_profit)}</p>
                  </div>
                  <div className="p-3 bg-blue-50 rounded-lg">
                    <p className="text-xs text-blue-700">Expected Profit</p>
                    <p className="font-semibold text-blue-700">{formatMoney(batchForModal.summary.expected_gross_profit)}</p>
                  </div>
                </div>
                <div>
                  <div className="flex items-center justify-between mb-3">
                    <h3 className="font-medium text-slate-900">Devices In This Batch</h3>
                    <p className="text-sm text-slate-500">{batchForModal.devices?.length || 0} serials loaded</p>
                  </div>
                  <div className="overflow-x-auto border border-slate-200 rounded-lg">
                    <table className="w-full">
                      <thead className="bg-slate-50 border-b border-slate-200">
                        <tr>
                          <th className="text-left px-4 py-3 text-xs font-medium text-slate-500 uppercase">Serial</th>
                          <th className="text-left px-4 py-3 text-xs font-medium text-slate-500 uppercase">Status</th>
                          <th className="text-left px-4 py-3 text-xs font-medium text-slate-500 uppercase">Owner</th>
                          <th className="text-left px-4 py-3 text-xs font-medium text-slate-500 uppercase">Sold By</th>
                          <th className="text-left px-4 py-3 text-xs font-medium text-slate-500 uppercase">Sold At</th>
                          <th className="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">Cost</th>
                          <th className="text-left px-4 py-3 text-xs font-medium text-slate-500 uppercase">Code</th>
                          <th className="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">Actions</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-200">
                        {(batchForModal.devices || []).map((device) => {
                          const status = typeof device.inventory_status === 'string' ? device.inventory_status : device.inventory_status?.label
                          return (
                            <tr key={device.id}>
                              <td className="px-4 py-3 text-sm font-medium text-slate-900">{device.serial_number}</td>
                              <td className="px-4 py-3 text-sm text-slate-600">{status || '-'}</td>
                              <td className="px-4 py-3 text-sm text-slate-600">{device.owner?.name || '-'}</td>
                              <td className="px-4 py-3 text-sm text-slate-600">{device.sold_by?.name || '-'}</td>
                              <td className="px-4 py-3 text-sm text-slate-600">{formatDate(device.sold_at)}</td>
                              <td className="px-4 py-3 text-sm text-right text-slate-600">{formatMoney(device.purchase_price)}</td>
                              <td className="px-4 py-3 text-sm text-slate-500">{device.unique_device_code || device.device_code || '-'}</td>
                              <td className="px-4 py-3 text-right">
                                <Authorize permission={PERMISSIONS.MANAGE_INVENTORY}>
                                  {(['in_stock', 'returned'].includes(typeof device.inventory_status === 'string' ? device.inventory_status : device.inventory_status?.value || '')) && (
                                    <button
                                      onClick={(event) => { event.stopPropagation(); openAdministrativeSale(device, batchForModal) }}
                                      className="rounded-lg bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-100"
                                    >
                                      Administrative Sale
                                    </button>
                                  )}
                                </Authorize>
                              </td>
                            </tr>
                          )
                        })}
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Status Change Modal */}
        {showStatusModal && selectedDevice && (
          <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <div className="bg-white rounded-xl max-w-md w-full">
              <div className="p-6 border-b border-slate-200">
                <h2 className="text-lg font-semibold text-slate-900">Change Inventory Status</h2>
                <p className="text-sm text-slate-500 mt-1">{selectedDevice.brand} {selectedDevice.model}</p>
              </div>
              <form
                onSubmit={(e) => {
                  e.preventDefault()
                  updateStatusMutation.mutate({
                    deviceId: selectedDevice.id,
                    status: newStatus,
                    notes: statusNotes || undefined,
                  })
                }}
                className="p-6 space-y-4"
              >
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Status</label>
                  <select
                    value={newStatus}
                    onChange={(e) => setNewStatus(e.target.value)}
                    className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="in_stock">In Stock</option>
                    <option value="reserved">Reserved</option>
                    <option value="returned">Returned</option>
                    <option value="faulty">Faulty</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Notes</label>
                  <textarea
                    value={statusNotes}
                    onChange={(e) => setStatusNotes(e.target.value)}
                    rows={3}
                    placeholder="Add any notes about this status change..."
                    className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div className="flex justify-end gap-3 pt-4">
                  <button
                    type="button"
                    onClick={() => {
                      setShowStatusModal(false)
                      setSelectedDevice(null)
                      setNewStatus('')
                      setStatusNotes('')
                    }}
                    className="px-4 py-2 text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={updateStatusMutation.isPending}
                    className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors disabled:opacity-50"
                  >
                    {updateStatusMutation.isPending ? (
                      <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                      'Update Status'
                    )}
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {administrativeSaleDevice && (
          <div className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/60 p-4">
            <div className="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl">
              <div className="border-b border-slate-200 p-5">
                <h2 className="text-lg font-semibold text-slate-900">Administrative Sale</h2>
                <p className="mt-1 text-sm text-slate-500">{administrativeSaleDevice.brand} {administrativeSaleDevice.model} · {administrativeSaleDevice.serial_number}</p>
              </div>
              <form
                className="space-y-4 p-5"
                onSubmit={(event) => {
                  event.preventDefault()
                  requestAdministrativeSaleMutation.mutate({
                    deviceId: administrativeSaleDevice.id,
                    amount: saleAmount,
                    reason: administrativeSaleReason,
                    notes: administrativeSaleNotes || undefined,
                  })
                }}
              >
                  <div className="grid grid-cols-2 gap-3">
                  <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-500">Unit cost</p><p className="font-semibold text-slate-900">{formatMoney(saleCost)}</p><p className="mt-1 text-xs text-slate-500">Device cost or batch buying price</p></div>
                  <div className="rounded-lg bg-blue-50 p-3"><p className="text-xs text-blue-700">{hasAdministrativeBatchPrice ? 'Batch target' : 'Purchase cost fallback'}</p><p className="font-semibold text-blue-800">{hasAdministrativeBatchPrice ? formatMoney(administrativeSaleBatch?.target_selling_price) : formatMoney(saleCost)}</p><p className="mt-1 text-xs text-blue-700">{hasAdministrativeBatchPrice ? 'Used as the default amount' : 'Used because no valid batch target price is available'}</p></div>
                </div>
                <div>
                  <label htmlFor="administrative-sale-amount" className="mb-1 block text-sm font-medium text-slate-700">Actual sale amount (MWK)</label>
                  <input id="administrative-sale-amount" type="number" min="0" step="0.01" value={administrativeSaleAmount} onChange={(event) => {
                    const value = event.target.value
                    if (value === '' || (Number.isFinite(Number(value)) && Number(value) >= 0)) {
                      setAdministrativeSaleAmount(value)
                    }
                  }} className="w-full rounded-lg border border-slate-300 px-3 py-2 placeholder:text-slate-400 focus:border-blue-500 focus:outline-none" placeholder="Enter actual sale amount" required />
                  {administrativeSaleAmount.trim() !== '' && !saleAmountValid && <p className="mt-1 text-xs text-red-600">Sale amount must be greater than zero.</p>}
                </div>
                <div className={`rounded-lg p-3 ${projectedSaleProfit < 0 ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700'}`}>
                  <p className="text-xs font-medium">Projected {projectedSaleProfit < 0 ? 'loss' : 'profit'}</p>
                  <p className="text-lg font-semibold">{formatMoney(Math.abs(projectedSaleProfit))}{projectedSaleProfit < 0 ? ' loss' : ''}</p>
                </div>
                <div>
                  <label htmlFor="administrative-sale-reason" className="mb-1 block text-sm font-medium text-slate-700">Reason <span className="text-red-500">*</span></label>
                  <input id="administrative-sale-reason" value={administrativeSaleReason} onChange={(event) => setAdministrativeSaleReason(event.target.value)} className="w-full rounded-lg border border-slate-300 px-3 py-2 placeholder:text-slate-400 focus:border-blue-500 focus:outline-none" placeholder="Why is this being recorded outside POS?" required />
                </div>
                <div>
                  <label htmlFor="administrative-sale-notes" className="mb-1 block text-sm font-medium text-slate-700">Notes</label>
                  <textarea id="administrative-sale-notes" value={administrativeSaleNotes} onChange={(event) => setAdministrativeSaleNotes(event.target.value)} rows={2} className="w-full rounded-lg border border-slate-300 px-3 py-2 placeholder:text-slate-400 focus:border-blue-500 focus:outline-none" placeholder="Optional supporting notes" />
                </div>
                <div className="flex justify-end gap-2 pt-1">
                  <button type="button" onClick={() => setAdministrativeSaleDevice(null)} className="rounded-lg px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-100">Cancel</button>
                  <button type="submit" disabled={!administrativeSaleReason.trim() || !saleAmountValid || requestAdministrativeSaleMutation.isPending} className="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">
                    {requestAdministrativeSaleMutation.isPending ? 'Sending PIN...' : 'Send approval PIN'}
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {saleChallenge && (
          <ActionChallengePinModal
            challenge={saleChallenge}
            title={ADMINISTRATIVE_SALE_TITLE}
            description={ADMINISTRATIVE_SALE_DESCRIPTION}
            error={saleChallengeError}
            isVerifying={verifyAdministrativeSaleMutation.isPending}
            isResending={resendAdministrativeSaleMutation.isPending}
            onVerify={(pin) => verifyAdministrativeSaleMutation.mutate({ challengeId: saleChallenge.challenge_id, pin })}
            onResend={() => resendAdministrativeSaleMutation.mutate(saleChallenge.challenge_id)}
            onClose={() => { setSaleChallenge(null); setSaleChallengeError(null) }}
          />
        )}
        <ConfirmDialog
          isOpen={showBatchPriceConfirm}
          onClose={() => setShowBatchPriceConfirm(false)}
          onConfirm={confirmBatchSellingPrice}
          title="Update this batch's selling price?"
          message={batchForModal ? `The price will change from ${formatMoney(batchForModal.target_selling_price)} to ${formatMoney(batchSellingPrice)} for ${batchForModal.summary.in_stock} in-stock devices. Completed and reserved sales stay unchanged.` : ''}
          confirmText="Update batch price"
          cancelText="Keep current price"
          variant="warning"
          isLoading={updateBatchPriceMutation.isPending}
        />
      </div>
    </Authorize>
  )
}
