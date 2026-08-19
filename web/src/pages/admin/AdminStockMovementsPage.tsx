import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, ArrowLeftRight, Eye, Filter, Loader2, Package, RotateCcw, Search, X } from 'lucide-react'
import { toast } from 'sonner'
import apiClient, { type PaginatedResponse } from '@/api/client'
import Authorize from '@/components/auth/Authorize'
import { PERMISSIONS } from '@/stores/auth-store'
import type { Branch, Device, GroupedInventoryItem, InventoryMovement, InventoryMovementSummary, InventoryStatusValue, StockTransferRequest } from '@/types'
import { useAuthStore } from '@/stores/auth-store'
import { appendBranchScope } from '@/lib/branch-scope'

const movementTypes = [
  'receipt',
  'reservation',
  'reservation_cancelled',
  'sale',
  'sale_cancelled',
  'refund',
  'transfer',
  'adjustment_in',
  'adjustment_out',
  'faulty',
  'returned',
  'write_off',
  'administrative_sale',
]

const statusOptions: InventoryStatusValue[] = ['in_stock', 'reserved', 'returned', 'faulty']
type TransferSelectionMode = 'devices' | 'quantity'

const label = (value: string | null | undefined) => {
  if (!value) return '-'
  return value.split('_').map((part) => part.charAt(0).toUpperCase() + part.slice(1)).join(' ')
}

const formatDate = (value: string | null | undefined) => {
  if (!value) return '-'
  return new Date(value).toLocaleString()
}

const formatMoney = (value: number | string | null | undefined) => {
  if (value === null || value === undefined) return '-'
  return `MWK ${Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

const transferProductKey = (product: Pick<GroupedInventoryItem, 'category_id' | 'brand' | 'model' | 'color'>) =>
  [product.category_id, product.brand, product.model, product.color ?? ''].join('::')

const transferProductLabel = (product: Pick<GroupedInventoryItem, 'brand' | 'model' | 'color'>) =>
  product.brand + ' ' + product.model + (product.color ? ' - ' + product.color : '')

const extractErrors = (error: unknown) => {
  const response = error as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }
  return response.response?.data?.errors || {}
}

export default function AdminStockMovementsPage() {
  const queryClient = useQueryClient()
  const { user, getUserRoles } = useAuthStore()
  const isSuperAdmin = getUserRoles().includes('super_admin')
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [branchId, setBranchId] = useState('')
  const [movementType, setMovementType] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [selectedMovement, setSelectedMovement] = useState<InventoryMovement | null>(null)
  const [showTransferModal, setShowTransferModal] = useState(false)
  const [showAdjustModal, setShowAdjustModal] = useState(false)
  const [selectedDeviceIds, setSelectedDeviceIds] = useState<number[]>([])
  const [sourceBranchId, setSourceBranchId] = useState('')
  const [destinationBranchId, setDestinationBranchId] = useState('')
  const [transferSelectionMode, setTransferSelectionMode] = useState<TransferSelectionMode>('devices')
  const [transferQuantities, setTransferQuantities] = useState<Record<string, string>>({})
  const [quantityErrors, setQuantityErrors] = useState<Record<string, string>>({})
  const [adjustStatus, setAdjustStatus] = useState<InventoryStatusValue>('in_stock')
  const [reason, setReason] = useState('')
  const [notes, setNotes] = useState('')
  const [formErrors, setFormErrors] = useState<Record<string, string[]>>({})
  const [decisionTarget, setDecisionTarget] = useState<StockTransferRequest | null>(null)
  const [decisionReason, setDecisionReason] = useState('')
  const [transferOverrideWarning, setTransferOverrideWarning] = useState<string | null>(null)

  const movementQuery = useMemo(() => {
    const params = new URLSearchParams({ page: page.toString(), per_page: '15' })
    if (search) params.append('search', search)
    appendBranchScope(params, branchId)
    if (movementType) params.append('movement_type', movementType)
    if (dateFrom) params.append('date_from', dateFrom)
    if (dateTo) params.append('date_to', dateTo)
    return params.toString()
  }, [branchId, dateFrom, dateTo, movementType, page, search])

  const summaryQuery = useMemo(() => {
    const params = new URLSearchParams()
    appendBranchScope(params, branchId)
    if (movementType) params.append('movement_type', movementType)
    if (dateFrom) params.append('date_from', dateFrom)
    if (dateTo) params.append('date_to', dateTo)
    if (search) params.append('search', search)
    return params.toString()
  }, [branchId, dateFrom, dateTo, movementType, search])

  const { data: branches } = useQuery({
    queryKey: ['branch-options'],
    queryFn: async () => {
      const response = await apiClient.get<{ data: Branch[] }>('/admin/branch-options')
      return response.data.data
    },
  })

  const { data: accessibleBranches } = useQuery({
    queryKey: ['accessible-branches'],
    queryFn: async () => {
      const response = await apiClient.get<{ data: Branch[] }>('/admin/branches?per_page=100')
      return response.data.data
    },
    enabled: !isSuperAdmin,
  })

  useEffect(() => {
    if (!isSuperAdmin && showTransferModal && !sourceBranchId && accessibleBranches?.[0]) {
      setSourceBranchId(accessibleBranches[0].id.toString())
    }
  }, [accessibleBranches, isSuperAdmin, showTransferModal, sourceBranchId])

  const { data: movementsData, isLoading } = useQuery({
    queryKey: ['stock-movements', movementQuery],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: InventoryMovement[]; meta: PaginatedResponse<InventoryMovement>['meta'] }>(
        `/admin/stock-movements?${movementQuery}`
      )
      return { data: response.data.data, meta: response.data.meta }
    },
  })

  const { data: summary } = useQuery({
    queryKey: ['stock-movements-summary', summaryQuery],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: InventoryMovementSummary }>(
        `/admin/stock-movements/summary${summaryQuery ? `?${summaryQuery}` : ''}`
      )
      return response.data.data
    },
  })

  const { data: availableDevices, isLoading: devicesLoading } = useQuery({
    queryKey: ['stock-movement-devices', showTransferModal ? sourceBranchId : branchId, transferSelectionMode],
    queryFn: async () => {
      const params = new URLSearchParams({ available_only: '1', per_page: '100' })
      const selectedBranchId = showTransferModal ? sourceBranchId : branchId
      if (selectedBranchId) params.append('branch_id', selectedBranchId)
      const response = await apiClient.get<{ success: boolean; data: Device[] }>(`/admin/inventory?${params}`)
      return response.data.data
    },
    enabled: ((showTransferModal && !!sourceBranchId && transferSelectionMode === 'devices') || showAdjustModal),
  })

  const { data: transferQuantityOptions = [], isLoading: quantityOptionsLoading } = useQuery({
    queryKey: ['stock-transfer-quantity-options', sourceBranchId],
    queryFn: async () => {
      const params = new URLSearchParams({ branch_id: sourceBranchId, available_only: '1' })
      const response = await apiClient.get<{ success: boolean; data: { data: GroupedInventoryItem[]; total: number } }>(
        `/admin/inventory/grouped?${params.toString()}`
      )
      return response.data.data.data
    },
    enabled: showTransferModal && !!sourceBranchId && transferSelectionMode === 'quantity',
  })

  const detailQuery = useQuery({
    queryKey: ['stock-movement', selectedMovement?.id],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: InventoryMovement }>(
        `/admin/stock-movements/${selectedMovement?.id}`
      )
      return response.data.data
    },
    enabled: !!selectedMovement,
  })

  const { data: transferRequests = [] } = useQuery({
    queryKey: ['stock-transfer-requests'],
    queryFn: async () => (await apiClient.get<{ data: StockTransferRequest[] }>('/admin/stock-movements/transfer-requests?per_page=50')).data.data,
  })

  const invalidateStockQueries = () => {
    queryClient.invalidateQueries({ queryKey: ['stock-movements'] })
    queryClient.invalidateQueries({ queryKey: ['stock-movements-summary'] })
    queryClient.invalidateQueries({ queryKey: ['stock-movement-devices'] })
    queryClient.invalidateQueries({ queryKey: ['stock-transfer-quantity-options'] })
    queryClient.invalidateQueries({ queryKey: ['inventory'] })
    queryClient.invalidateQueries({ queryKey: ['inventory-stats'] })
    queryClient.invalidateQueries({ queryKey: ['inventory-batches'] })
    queryClient.invalidateQueries({ queryKey: ['admin-reports-stock'] })
    queryClient.invalidateQueries({ queryKey: ['stock-transfer-requests'] })
  }

  const quantitySelections = useMemo(
    () => transferQuantityOptions.flatMap((option) => {
      const raw = transferQuantities[transferProductKey(option)]?.trim() || ''
      return raw ? [{ option, quantity: Number(raw) }] : []
    }),
    [transferQuantities, transferQuantityOptions]
  )

  const validateQuantityTransfer = () => {
    const nextErrors: Record<string, string> = {}
    let totalQuantity = 0

    transferQuantityOptions.forEach((option) => {
      const key = transferProductKey(option)
      const raw = transferQuantities[key]?.trim() || ''
      if (!raw) return

      const quantity = Number(raw)
      const available = option.available ?? option.in_stock + option.returned
      if (!/^\d+$/.test(raw)) {
        nextErrors[key] = raw.startsWith('-') ? 'Quantity cannot be negative.' : 'Enter a whole number of devices.'
      } else if (!Number.isInteger(quantity) || quantity < 1) {
        nextErrors[key] = 'Quantity must be at least 1.'
      } else if (quantity > available) {
        nextErrors[key] = `Only ${available} available; enter ${available} or fewer.`
      } else {
        totalQuantity += quantity
      }
    })

    if (totalQuantity > 100) {
      nextErrors._total = 'The total quantity cannot exceed 100 devices per transfer.'
    } else if (totalQuantity === 0 && Object.keys(nextErrors).length === 0) {
      nextErrors._total = 'Enter a quantity for at least one product.'
    }

    setQuantityErrors(nextErrors)
    setFormErrors((current) => {
      const next = { ...current }
      delete next.product_quantities
      if (Object.keys(nextErrors).length > 0) {
        next.product_quantities = [nextErrors._total || 'Correct the highlighted quantities.']
      }
      return next
    })

    return Object.keys(nextErrors).length === 0 && totalQuantity > 0
  }

  const submitTransfer = () => {
    if (transferSelectionMode === 'quantity' && !validateQuantityTransfer()) return
    if (transferSelectionMode === 'devices' && selectedDeviceIds.length === 0) {
      setFormErrors((current) => ({ ...current, device_ids: ['Select at least one device to transfer.'] }))
      return
    }
    transferMutation.mutate({})
  }

  const transferMutation = useMutation({
    mutationFn: async ({ override = false }: { override?: boolean } = {}) => {
      const selection = transferSelectionMode === 'quantity'
        ? {
            product_quantities: quantitySelections.map(({ option, quantity }) => ({
              category_id: option.category_id,
              brand: option.brand,
              model: option.model,
              color: option.color,
              quantity,
            })),
          }
        : { device_ids: selectedDeviceIds }

      return apiClient.post('/admin/stock-movements/transfer-requests', {
        ...selection,
        from_branch_id: sourceBranchId ? Number(sourceBranchId) : undefined,
        to_branch_id: destinationBranchId ? Number(destinationBranchId) : undefined,
        reason,
        notes: notes || undefined,
        override_destination_receipt: override || undefined,
      })
    },
    onSuccess: (response) => {
      toast.success(response.data?.data?.is_override ? 'Stock transferred without destination receipt' : 'Stock transfer request submitted')
      closeActionModals()
      invalidateStockQueries()
    },
    onError: (error) => {
      const response = error as { response?: { data?: { requires_override?: boolean; warning?: string } } }
      if (response.response?.data?.requires_override) {
        setTransferOverrideWarning(response.response.data.warning || 'A super-admin override will transfer the stock immediately without a destination receiving step.')
      } else {
        setTransferOverrideWarning(null)
      }
      const errors = extractErrors(error)
      const mappedQuantityErrors: Record<string, string> = {}
      Object.entries(errors).forEach(([field, messages]) => {
        const match = field.match(/^product_quantities\.(\d+)\.quantity$/)
        const option = match ? quantitySelections[Number(match[1])]?.option : undefined
        if (option && messages?.[0]) mappedQuantityErrors[transferProductKey(option)] = messages[0]
      })
      setQuantityErrors(mappedQuantityErrors)
      setFormErrors(errors)
    },
  })

  const decisionMutation = useMutation({
    mutationFn: async ({ request, action }: { request: StockTransferRequest; action: 'approve' | 'reject' | 'cancel' }) => {
      await apiClient.post(`/admin/stock-movements/transfer-requests/${request.id}/${action}`, action === 'reject' ? { reason: decisionReason } : {})
    },
    onSuccess: () => { toast.success('Transfer request updated'); setDecisionTarget(null); setDecisionReason(''); invalidateStockQueries() },
    onError: (error) => setFormErrors(extractErrors(error)),
  })

  const adjustMutation = useMutation({
    mutationFn: async () => {
      await apiClient.post('/admin/stock-movements/adjustments', {
        device_ids: selectedDeviceIds,
        inventory_status: adjustStatus,
        reason,
        notes: notes || undefined,
      })
    },
    onSuccess: () => {
      toast.success('Stock adjusted successfully')
      closeActionModals()
      invalidateStockQueries()
    },
    onError: (error) => setFormErrors(extractErrors(error)),
  })

  const closeActionModals = () => {
    setShowTransferModal(false)
    setShowAdjustModal(false)
    setSelectedDeviceIds([])
    setSourceBranchId('')
    setDestinationBranchId('')
    setTransferSelectionMode('devices')
    setTransferQuantities({})
    setQuantityErrors({})
    setAdjustStatus('in_stock')
    setReason('')
    setNotes('')
    setFormErrors({})
    setTransferOverrideWarning(null)
  }

  const toggleDevice = (deviceId: number) => {
    setSelectedDeviceIds((current) =>
      current.includes(deviceId) ? current.filter((id) => id !== deviceId) : [...current, deviceId]
    )
    setFormErrors((current) => ({ ...current, device_ids: [] }))
  }

  const movements = movementsData?.data || []
  const meta = movementsData?.meta
  const detail = detailQuery.data || selectedMovement

  return (
    <Authorize permission={PERMISSIONS.VIEW_STOCK_MOVEMENTS} fallback={<div className="p-6">Access denied</div>}>
      <div className="space-y-6 p-6">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="text-2xl font-bold text-slate-900">Stock Movements</h1>
            <p className="mt-1 text-sm text-slate-500">Audit stock intake, sales, transfers, and adjustments by branch</p>
          </div>
          <Authorize permission={PERMISSIONS.MANAGE_STOCK_MOVEMENTS}>
            <div className="flex flex-wrap gap-2">
              <button
                onClick={() => {
                  if (!isSuperAdmin) {
                    setSourceBranchId(accessibleBranches?.[0]?.id.toString() || '')
                  }
                  setShowTransferModal(true)
                }}
                className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
              >
                <ArrowLeftRight className="h-4 w-4" />
                Request Transfer
              </button>
              <button
                onClick={() => setShowAdjustModal(true)}
                className="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
              >
                <RotateCcw className="h-4 w-4" />
                Adjust Stock
              </button>
            </div>
          </Authorize>
        </div>

        {summary && (
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-6">
            <SummaryTile label="Received" value={summary.by_type.receipt || 0} tone="emerald" />
            <SummaryTile label="Sold" value={summary.by_type.sale || 0} tone="blue" />
            <SummaryTile label="Admin Sales" value={summary.by_type.administrative_sale || 0} tone="indigo" />
            <SummaryTile label="Transfers" value={summary.by_type.transfer || 0} tone="indigo" />
            <SummaryTile label="Reserved" value={summary.by_type.reservation || 0} tone="amber" />
            <SummaryTile label="Adjusted" value={(summary.by_type.adjustment_in || 0) + (summary.by_type.adjustment_out || 0)} tone="slate" />
            <SummaryTile label="Total Qty" value={summary.total_quantity} tone="slate" />
          </div>
        )}

        <div className="rounded-lg border border-slate-200 bg-white">
          <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <div><h2 className="font-semibold text-slate-900">Transfer Requests</h2><p className="text-sm text-slate-500">Standard requests move stock after destination approval; super-admin overrides complete immediately.</p></div>
            {transferRequests.filter((request) => request.status === 'pending').length > 0 && <span className="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-800">{transferRequests.filter((request) => request.status === 'pending').length} pending</span>}
          </div>
          <div className="divide-y divide-slate-100">
            {transferRequests.length === 0 ? <p className="px-4 py-6 text-sm text-slate-500">No transfer requests.</p> : transferRequests.map((request) => {
              const canApprove = getUserRoles().includes('super_admin') || request.to_branch?.manager_id === user?.id
              const canCancel = request.requested_by_id === user?.id
              return <div key={request.id} className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm"><div className="min-w-48"><p className="font-medium text-slate-900">{request.request_number}</p><p className="text-slate-500">{request.from_branch?.name} to {request.to_branch?.name} · {request.item_count || 0} device(s)</p></div><p className="flex-1 text-slate-600">{request.reason}</p><RequestStatus status={request.status} />{request.is_override && <span className="rounded-full bg-violet-100 px-2 py-1 text-xs font-medium text-violet-800">Super-admin override</span>}{request.status === 'pending' && canApprove && <><button onClick={() => decisionMutation.mutate({ request, action: 'approve' })} className="rounded-md bg-emerald-600 px-3 py-1.5 text-white hover:bg-emerald-700">Approve</button><button onClick={() => setDecisionTarget(request)} className="rounded-md border border-red-200 px-3 py-1.5 text-red-700 hover:bg-red-50">Reject</button></>}{request.status === 'pending' && canCancel && <button onClick={() => decisionMutation.mutate({ request, action: 'cancel' })} className="rounded-md border border-slate-200 px-3 py-1.5 text-slate-700">Cancel</button>}</div>
            })}
          </div>
        </div>

        <div className="rounded-lg border border-slate-200 bg-white p-4">
          <div className="grid gap-3 lg:grid-cols-[1fr_160px_180px_160px_160px]">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <input
                value={search}
                onChange={(event) => { setSearch(event.target.value); setPage(1) }}
                placeholder="Search movement, product, serial, batch, receipt, sale..."
                className="w-full rounded-lg border border-slate-200 py-2 pl-9 pr-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
              />
            </div>
            {isSuperAdmin && <select value={branchId} onChange={(event) => { setBranchId(event.target.value); setPage(1) }} className="rounded-lg border border-slate-200 px-3 py-2 text-sm">
              <option value="">All Branches</option>
              {branches?.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
            </select>}
            <select value={movementType} onChange={(event) => { setMovementType(event.target.value); setPage(1) }} className="rounded-lg border border-slate-200 px-3 py-2 text-sm">
              <option value="">All Movement Types</option>
              {movementTypes.map((type) => <option key={type} value={type}>{label(type)}</option>)}
            </select>
            <input type="date" value={dateFrom} onChange={(event) => { setDateFrom(event.target.value); setPage(1) }} className="rounded-lg border border-slate-200 px-3 py-2 text-sm" />
            <input type="date" value={dateTo} onChange={(event) => { setDateTo(event.target.value); setPage(1) }} className="rounded-lg border border-slate-200 px-3 py-2 text-sm" />
          </div>
        </div>

        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
          {isLoading ? (
            <div className="flex items-center justify-center py-16">
              <Loader2 className="h-8 w-8 animate-spin text-blue-500" />
            </div>
          ) : movements.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-16">
              <Filter className="mb-2 h-10 w-10 text-slate-300" />
              <p className="text-sm text-slate-500">No stock movements found</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead className="border-b border-slate-200 bg-slate-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-slate-500">Date</th>
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-slate-500">Movement</th>
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-slate-500">Type</th>
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-slate-500">Product</th>
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-slate-500">Serial</th>
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-slate-500">From</th>
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-slate-500">To/Branch</th>
                    <th className="px-4 py-3 text-right text-xs font-medium uppercase text-slate-500">Qty</th>
                    <th className="px-4 py-3 text-right text-xs font-medium uppercase text-slate-500">Amount</th>
                    <th className="px-4 py-3 text-right text-xs font-medium uppercase text-slate-500">Profit/Loss</th>
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-slate-500">Reference</th>
                    <th className="px-4 py-3 text-right text-xs font-medium uppercase text-slate-500">Action</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200">
                  {movements.map((movement) => (
                    <tr key={movement.id} className="hover:bg-slate-50">
                      <td className="whitespace-nowrap px-4 py-3 text-sm text-slate-600">{formatDate(movement.created_at)}</td>
                      <td className="whitespace-nowrap px-4 py-3 text-sm font-medium text-slate-900">{movement.movement_number}</td>
                      <td className="whitespace-nowrap px-4 py-3"><MovementBadge type={movement.movement_type} /></td>
                      <td className="px-4 py-3 text-sm text-slate-700">{movement.brand} {movement.model}{movement.color ? ` - ${movement.color}` : ''}</td>
                      <td className="whitespace-nowrap px-4 py-3 text-sm text-slate-600">{movement.device?.serial_number || '-'}</td>
                      <td className="whitespace-nowrap px-4 py-3 text-sm text-slate-600">{movement.from_branch?.name || '-'}</td>
                      <td className="whitespace-nowrap px-4 py-3 text-sm text-slate-600">{movement.to_branch?.name || movement.branch?.name || '-'}</td>
                      <td className="px-4 py-3 text-right text-sm text-slate-700">{movement.quantity}</td>
                      <td className="whitespace-nowrap px-4 py-3 text-right text-sm text-slate-700">{formatMoney(movement.total_amount)}</td>
                      <td className={`whitespace-nowrap px-4 py-3 text-right text-sm font-medium ${Number(movement.profit_amount || 0) < 0 ? 'text-red-700' : 'text-emerald-700'}`}>{formatMoney(movement.profit_amount)}</td>
                      <td className="whitespace-nowrap px-4 py-3 text-sm text-slate-600">{movement.sale?.sale_number || movement.receipt?.receipt_number || movement.batch?.batch_number || '-'}</td>
                      <td className="px-4 py-3 text-right">
                        <button onClick={() => setSelectedMovement(movement)} className="inline-flex rounded-md p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-800">
                          <Eye className="h-4 w-4" />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between border-t border-slate-200 px-6 py-4">
              <p className="text-sm text-slate-500">Page {meta.current_page} of {meta.last_page}</p>
              <div className="flex gap-2">
                <button onClick={() => setPage((value) => Math.max(1, value - 1))} disabled={meta.current_page === 1} className="rounded-lg border border-slate-200 px-3 py-1 text-sm disabled:opacity-50">Previous</button>
                <button onClick={() => setPage((value) => Math.min(meta.last_page, value + 1))} disabled={meta.current_page === meta.last_page} className="rounded-lg border border-slate-200 px-3 py-1 text-sm disabled:opacity-50">Next</button>
              </div>
            </div>
          )}
        </div>

        {(showTransferModal || showAdjustModal) && (
          <ActionModal
            title={showTransferModal ? 'Request Stock Transfer' : 'Adjust Stock'}
            branches={branches || []}
            devices={availableDevices || []}
            devicesLoading={devicesLoading}
            selectedDeviceIds={selectedDeviceIds}
            toggleDevice={toggleDevice}
            sourceBranchId={sourceBranchId}
            canChooseSourceBranch={isSuperAdmin}
            setSourceBranchId={(value) => {
              setSourceBranchId(value)
              setSelectedDeviceIds([])
              setTransferQuantities({})
              setQuantityErrors({})
              setTransferOverrideWarning(null)
              setFormErrors((current) => ({ ...current, device_ids: [] }))
            }}
            destinationBranchId={destinationBranchId}
            setDestinationBranchId={(value) => {
              setDestinationBranchId(value)
              setTransferOverrideWarning(null)
            }}
            adjustStatus={adjustStatus}
            setAdjustStatus={setAdjustStatus}
            reason={reason}
            setReason={setReason}
            notes={notes}
            setNotes={setNotes}
            errors={formErrors}
            isTransfer={showTransferModal}
            isSubmitting={transferMutation.isPending || adjustMutation.isPending}
            transferSelectionMode={transferSelectionMode}
            setTransferSelectionMode={(mode) => {
              setTransferSelectionMode(mode)
              setSelectedDeviceIds([])
              setTransferQuantities({})
              setQuantityErrors({})
              setFormErrors({})
              setTransferOverrideWarning(null)
            }}
            quantityOptions={transferQuantityOptions}
            quantityOptionsLoading={quantityOptionsLoading}
            transferQuantities={transferQuantities}
            setTransferQuantity={(key, value) => {
              setTransferQuantities((current) => ({ ...current, [key]: value }))
              setQuantityErrors((current) => ({ ...current, [key]: '' }))
              setFormErrors((current) => ({ ...current, product_quantities: [] }))
              setTransferOverrideWarning(null)
            }}
            quantityErrors={quantityErrors}
            overrideWarning={transferOverrideWarning}
            onOverride={() => transferMutation.mutate({ override: true })}
            onDismissOverrideWarning={() => setTransferOverrideWarning(null)}
            onClose={closeActionModals}
            onSubmit={() => showTransferModal ? submitTransfer() : adjustMutation.mutate()}
          />
        )}

        {selectedMovement && detail && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div className="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-lg bg-white shadow-xl">
              <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                <div>
                  <h2 className="text-lg font-semibold text-slate-900">{detail.movement_number}</h2>
                  <p className="text-sm text-slate-500">{label(detail.movement_type)} movement details</p>
                </div>
                <button onClick={() => setSelectedMovement(null)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100">
                  <X className="h-5 w-5" />
                </button>
              </div>
              <div className="grid gap-4 p-6 sm:grid-cols-2">
                <Detail label="Date" value={formatDate(detail.created_at)} />
                <Detail label="Type" value={label(detail.movement_type)} />
                <Detail label="Product" value={`${detail.brand || ''} ${detail.model || ''}${detail.color ? ` - ${detail.color}` : ''}`.trim()} />
                <Detail label="Serial" value={detail.device?.serial_number || '-'} />
                <Detail label="Previous Status" value={label(detail.previous_status)} />
                <Detail label="New Status" value={label(detail.new_status)} />
                <Detail label="Branch" value={detail.branch?.name || '-'} />
                <Detail label="From Branch" value={detail.from_branch?.name || '-'} />
                <Detail label="To Branch" value={detail.to_branch?.name || '-'} />
                <Detail label="Batch" value={detail.batch?.batch_number || '-'} />
                <Detail label="Receipt" value={detail.receipt?.receipt_number || '-'} />
                <Detail label="Sale" value={detail.sale?.sale_number || '-'} />
                <Detail label="Unit Cost" value={formatMoney(detail.unit_cost)} />
                <Detail label="Unit Price" value={formatMoney(detail.unit_price)} />
                <Detail label="Total Cost" value={formatMoney(detail.total_cost)} />
                <Detail label="Total Amount" value={formatMoney(detail.total_amount)} />
                <Detail label="Profit/Loss" value={formatMoney(detail.profit_amount)} />
                {detail.movement_type === 'administrative_sale' && <Detail label="Pricing Source" value={typeof detail.metadata?.pricing_source === 'string' ? detail.metadata.pricing_source : '-'} />}
                <Detail label="Created By" value={detail.created_by?.name || '-'} />
                <Detail label="Backfilled" value={detail.is_backfilled ? 'Yes' : 'No'} />
                <div className="sm:col-span-2">
                  <Detail label="Reason" value={detail.reason || '-'} />
                </div>
                <div className="sm:col-span-2">
                  <Detail label="Notes" value={detail.notes || '-'} />
                </div>
              </div>
            </div>
          </div>
        )}
        {decisionTarget && <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"><div className="w-full max-w-md rounded-lg bg-white p-6"><h2 className="text-lg font-semibold">Reject {decisionTarget.request_number}</h2><textarea value={decisionReason} onChange={(event) => setDecisionReason(event.target.value)} className="mt-4 w-full rounded-lg border border-slate-200 p-3" placeholder="Reason for rejection" /><div className="mt-4 flex justify-end gap-2"><button onClick={() => setDecisionTarget(null)} className="rounded-md px-3 py-2">Cancel</button><button disabled={!decisionReason || decisionMutation.isPending} onClick={() => decisionMutation.mutate({ request: decisionTarget, action: 'reject' })} className="rounded-md bg-red-600 px-3 py-2 text-white disabled:opacity-50">Reject request</button></div></div></div>}
      </div>
    </Authorize>
  )
}

function RequestStatus({ status }: { status: StockTransferRequest['status'] }) {
  const colors = { pending: 'bg-amber-100 text-amber-800', approved: 'bg-emerald-100 text-emerald-800', rejected: 'bg-red-100 text-red-800', cancelled: 'bg-slate-100 text-slate-700' }
  return <span className={`rounded-full px-2 py-1 text-xs font-medium ${colors[status]}`}>{label(status)}</span>
}

function SummaryTile({ label, value, tone }: { label: string; value: number; tone: 'emerald' | 'blue' | 'indigo' | 'amber' | 'slate' }) {
  const tones = {
    emerald: 'bg-emerald-50 text-emerald-700',
    blue: 'bg-blue-50 text-blue-700',
    indigo: 'bg-indigo-50 text-indigo-700',
    amber: 'bg-amber-50 text-amber-700',
    slate: 'bg-slate-50 text-slate-700',
  }

  return (
    <div className="rounded-lg border border-slate-200 bg-white p-4">
      <div className={`mb-2 flex h-9 w-9 items-center justify-center rounded-lg ${tones[tone]}`}>
        <Package className="h-4 w-4" />
      </div>
      <p className="text-2xl font-bold text-slate-900">{value}</p>
      <p className="text-xs text-slate-500">{label}</p>
    </div>
  )
}

function MovementBadge({ type }: { type: string }) {
  const tone = type === 'sale'
    ? 'bg-blue-100 text-blue-700'
    : type === 'administrative_sale'
      ? 'bg-violet-100 text-violet-700'
    : type === 'receipt'
      ? 'bg-emerald-100 text-emerald-700'
      : type === 'transfer'
        ? 'bg-indigo-100 text-indigo-700'
        : type.includes('cancel')
          ? 'bg-amber-100 text-amber-700'
          : 'bg-slate-100 text-slate-700'

  return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${tone}`}>{label(type)}</span>
}

function Detail({ label: title, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-xs font-medium uppercase text-slate-500">{title}</p>
      <p className="mt-1 text-sm text-slate-900">{value || '-'}</p>
    </div>
  )
}

interface ActionModalProps {
  title: string
  branches: Branch[]
  devices: Device[]
  devicesLoading: boolean
  selectedDeviceIds: number[]
  toggleDevice: (deviceId: number) => void
  sourceBranchId: string
  canChooseSourceBranch: boolean
  setSourceBranchId: (value: string) => void
  destinationBranchId: string
  setDestinationBranchId: (value: string) => void
  adjustStatus: InventoryStatusValue
  setAdjustStatus: (value: InventoryStatusValue) => void
  reason: string
  setReason: (value: string) => void
  notes: string
  setNotes: (value: string) => void
  errors: Record<string, string[]>
  isTransfer: boolean
  isSubmitting: boolean
  transferSelectionMode: TransferSelectionMode
  setTransferSelectionMode: (mode: TransferSelectionMode) => void
  quantityOptions: GroupedInventoryItem[]
  quantityOptionsLoading: boolean
  transferQuantities: Record<string, string>
  setTransferQuantity: (key: string, value: string) => void
  quantityErrors: Record<string, string>
  overrideWarning: string | null
  onOverride: () => void
  onDismissOverrideWarning: () => void
  onClose: () => void
  onSubmit: () => void
}

function ActionModal(props: ActionModalProps) {
  const {
    title,
    branches,
    devices,
    devicesLoading,
    selectedDeviceIds,
    toggleDevice,
    sourceBranchId,
    canChooseSourceBranch,
    setSourceBranchId,
    destinationBranchId,
    setDestinationBranchId,
    adjustStatus,
    setAdjustStatus,
    reason,
    setReason,
    notes,
    setNotes,
    errors,
    isTransfer,
    isSubmitting,
    transferSelectionMode,
    setTransferSelectionMode,
    quantityOptions,
    quantityOptionsLoading,
    transferQuantities,
    setTransferQuantity,
    quantityErrors,
    overrideWarning,
    onOverride,
    onDismissOverrideWarning,
    onClose,
    onSubmit,
  } = props
  const branchNameById = new Map(branches.map((branch) => [branch.id, branch.name]))

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="max-h-[90vh] w-full max-w-4xl overflow-y-auto rounded-lg bg-white shadow-xl">
        <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
          <h2 className="text-lg font-semibold text-slate-900">{title}</h2>
          <button onClick={onClose} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100">
            <X className="h-5 w-5" />
          </button>
        </div>
        <div className="space-y-5 p-6">
          {isTransfer ? (
            <div className="grid gap-4 sm:grid-cols-2">
              <FieldError label="Source Branch" htmlFor="transfer-source-branch" errors={errors.from_branch_id || errors.branch_id}>
                <select id="transfer-source-branch" value={sourceBranchId} onChange={(event) => setSourceBranchId(event.target.value)} disabled={!canChooseSourceBranch} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-600">
                  <option value="">Select source branch</option>
                  {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                </select>
              </FieldError>
              <FieldError label="Destination Branch" htmlFor="transfer-destination-branch" errors={errors.to_branch_id}>
                <select id="transfer-destination-branch" value={destinationBranchId} onChange={(event) => setDestinationBranchId(event.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                  <option value="">Select destination branch</option>
                  {branches
                    .filter((branch) => branch.id.toString() !== sourceBranchId)
                    .map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                </select>
              </FieldError>
            </div>
          ) : (
            <FieldError label="New Inventory Status" htmlFor="adjust-inventory-status" errors={errors.inventory_status}>
              <select id="adjust-inventory-status" value={adjustStatus} onChange={(event) => setAdjustStatus(event.target.value as InventoryStatusValue)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                {statusOptions.map((status) => <option key={status} value={status}>{label(status)}</option>)}
              </select>
            </FieldError>
          )}

          <FieldError label="Reason" htmlFor="inventory-action-reason" errors={errors.reason}>
            <input
              id="inventory-action-reason"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              placeholder={isTransfer ? 'e.g. Restock the Lilongwe branch' : 'e.g. Damaged during inspection'}
              className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm placeholder:text-slate-400"
            />
          </FieldError>

          <FieldError label="Notes" htmlFor="inventory-action-notes" errors={errors.notes}>
            <textarea
              id="inventory-action-notes"
              value={notes}
              onChange={(event) => setNotes(event.target.value)}
              rows={3}
              placeholder="Optional details for the receiving branch"
              className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm placeholder:text-slate-400"
            />
          </FieldError>

          {isTransfer && (
            <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
              <p className="mb-2 text-sm font-medium text-slate-700">How do you want to select stock?</p>
              <div className="flex flex-wrap gap-2">
                <button
                  type="button"
                  onClick={() => setTransferSelectionMode('devices')}
                  className={`rounded-md px-3 py-1.5 text-sm font-medium ${transferSelectionMode === 'devices' ? 'bg-blue-600 text-white' : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-100'}`}
                >
                  Select individual devices
                </button>
                <button
                  type="button"
                  onClick={() => setTransferSelectionMode('quantity')}
                  className={`rounded-md px-3 py-1.5 text-sm font-medium ${transferSelectionMode === 'quantity' ? 'bg-blue-600 text-white' : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-100'}`}
                >
                  Select by quantity
                </button>
              </div>
              <p className="mt-2 text-xs text-slate-500">
                Quantity selection chooses available units from the selected source branch and validates the count again when submitted.
              </p>
            </div>
          )}

          {isTransfer && overrideWarning && (
            <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
              <div className="flex items-start gap-3">
                <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                <div className="flex-1">
                  <p className="font-semibold">Super-admin override required</p>
                  <p className="mt-1">{overrideWarning}</p>
                  <p className="mt-2">The devices will leave the source branch immediately, the transfer will be marked approved, and the destination will be responsible for the stock without a receiving confirmation.</p>
                  <div className="mt-3 flex flex-wrap gap-2">
                    <button onClick={onDismissOverrideWarning} disabled={isSubmitting} className="rounded-md border border-amber-300 bg-white px-3 py-1.5 font-medium text-amber-900 hover:bg-amber-100 disabled:opacity-60">Choose another destination</button>
                    <button onClick={onOverride} disabled={isSubmitting} className="inline-flex items-center gap-2 rounded-md bg-amber-600 px-3 py-1.5 font-medium text-white hover:bg-amber-700 disabled:opacity-60">
                      {isSubmitting && <Loader2 className="h-4 w-4 animate-spin" />}
                      Proceed without receipt
                    </button>
                  </div>
                </div>
              </div>
            </div>
          )}

          <div>
            <div className="mb-2 flex items-center justify-between">
              <p className="text-sm font-medium text-slate-700">{isTransfer && transferSelectionMode === 'quantity' ? 'Product quantities' : 'Devices'}</p>
              <p className="text-xs text-slate-500">
                {isTransfer && transferSelectionMode === 'quantity'
                  ? `${Object.values(transferQuantities).reduce((total, value) => total + (Number(value) || 0), 0)} requested`
                  : `${selectedDeviceIds.length} selected`}
              </p>
            </div>
            {errors.device_ids?.length > 0 && (
              <div className="mb-2 flex items-start gap-2 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                <AlertTriangle className="mt-0.5 h-4 w-4" />
                <span>{errors.device_ids[0]}</span>
              </div>
            )}
            {isTransfer && transferSelectionMode === 'quantity' && errors.product_quantities?.length > 0 && (
              <div className="mb-2 flex items-start gap-2 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                <AlertTriangle className="mt-0.5 h-4 w-4" />
                <span>{errors.product_quantities[0]}</span>
              </div>
            )}
            <div className="max-h-72 overflow-y-auto rounded-lg border border-slate-200">
              {isTransfer && transferSelectionMode === 'quantity' ? (
                quantityOptionsLoading ? (
                  <div className="flex items-center justify-center py-10">
                    <Loader2 className="h-6 w-6 animate-spin text-blue-500" />
                  </div>
                ) : !sourceBranchId ? (
                  <p className="p-4 text-sm text-slate-500">Select a source branch to load available product quantities</p>
                ) : quantityOptions.length === 0 ? (
                  <p className="p-4 text-sm text-slate-500">No available products found for the selected branch</p>
                ) : (
                  <div className="divide-y divide-slate-200">
                    {quantityOptions.map((option) => {
                      const key = transferProductKey(option)
                      const inputId = `transfer-quantity-${key.replace(/[^a-zA-Z0-9_-]/g, '-')}`
                      const errorId = `${inputId}-error`
                      const available = option.available ?? option.in_stock + option.returned
                      return (
                        <div key={key} className="flex items-center gap-3 px-4 py-3">
                          <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium text-slate-900">{transferProductLabel(option)}</p>
                            <p className="text-xs text-slate-500">{available} available in this branch ({option.in_stock} in stock, {option.returned} returned)</p>
                            {quantityErrors[key] && <p id={errorId} className="mt-1 text-xs text-red-600">{quantityErrors[key]}</p>}
                          </div>
                          <div className="shrink-0 text-right">
                            <label htmlFor={inputId} className="mb-1 block text-xs font-medium text-slate-600">
                              Quantity to transfer
                            </label>
                            <input
                              id={inputId}
                              aria-label={`Quantity for ${transferProductLabel(option)}`}
                              aria-describedby={quantityErrors[key] ? errorId : undefined}
                              aria-invalid={Boolean(quantityErrors[key])}
                              type="number"
                              min={1}
                              max={available}
                              step={1}
                              value={transferQuantities[key] ?? ''}
                              onChange={(event) => setTransferQuantity(key, event.target.value)}
                              placeholder="Enter quantity"
                              className={`w-32 rounded-lg border px-3 py-2 text-right text-sm placeholder:text-slate-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 ${quantityErrors[key] ? 'border-red-300 bg-red-50' : 'border-slate-200'}`}
                            />
                          </div>
                        </div>
                      )
                    })}
                  </div>
                )
              ) : devicesLoading ? (
                <div className="flex items-center justify-center py-10">
                  <Loader2 className="h-6 w-6 animate-spin text-blue-500" />
                </div>
              ) : isTransfer && !sourceBranchId ? (
                <p className="p-4 text-sm text-slate-500">Select a source branch to load eligible devices</p>
              ) : devices.length === 0 ? (
                <p className="p-4 text-sm text-slate-500">No eligible devices found for the selected branch</p>
              ) : (
                <div className="divide-y divide-slate-200">
                  {devices.map((device) => (
                    <label key={device.id} className="flex cursor-pointer items-center gap-3 px-4 py-3 hover:bg-slate-50">
                      <input type="checkbox" checked={selectedDeviceIds.includes(device.id)} onChange={() => toggleDevice(device.id)} className="h-4 w-4 rounded border-slate-300" />
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium text-slate-900">{device.brand} {device.model}{device.color ? ` - ${device.color}` : ''}</p>
                        <p className="text-xs text-slate-500">{device.serial_number} · {device.branch?.name || (device.branch_id ? branchNameById.get(device.branch_id) : null) || 'No branch'}</p>
                      </div>
                      <span className="text-xs text-slate-500">{label(typeof device.inventory_status === 'string' ? device.inventory_status : device.inventory_status?.value)}</span>
                    </label>
                  ))}
                </div>
              )}
            </div>
          </div>
        </div>
        <div className="flex justify-end gap-2 border-t border-slate-200 px-6 py-4">
          <button onClick={onClose} className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
          <button onClick={onSubmit} disabled={isSubmitting} className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
            {isSubmitting && <Loader2 className="h-4 w-4 animate-spin" />}
            {isTransfer ? 'Submit Request' : 'Save'}
          </button>
        </div>
      </div>
    </div>
  )
}

function FieldError({ label: title, htmlFor, errors, children }: { label: string; htmlFor: string; errors?: string[]; children: ReactNode }) {
  return (
    <div className="block">
      <label htmlFor={htmlFor} className="mb-1 block text-sm font-medium text-slate-700">{title}</label>
      {children}
      {errors?.length ? <span className="mt-1 block text-xs text-red-600">{errors[0]}</span> : null}
    </div>
  )
}
