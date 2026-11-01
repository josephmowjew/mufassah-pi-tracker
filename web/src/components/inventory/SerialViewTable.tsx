import { formatMalawiDate } from '@/lib/datetime'
import type { Device } from '@/types'
import { Package, Smartphone, Hash, CheckCircle, Clock, Tag, RotateCcw, XCircle, Loader2 } from 'lucide-react'
import { Authorize } from '@/components/auth/Authorize'
import { PERMISSIONS } from '@/stores/auth-store'

interface SerialViewTableProps {
  devices: Device[]
  isLoading?: boolean
  onChangeStatus: (device: Device) => void
  onAdministrativeSale?: (device: Device) => void
  meta?: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
  onPageChange: (page: number) => void
}

export default function SerialViewTable({ devices, isLoading, onChangeStatus, onAdministrativeSale, meta, onPageChange }: SerialViewTableProps) {
  const getInventoryStatusBadge = (device: Device) => {
    const status = device.inventory_status
    const statusValue = typeof status === 'string' ? status : status?.value || 'in_stock'

    const statusConfig: Record<string, { bg: string; text: string; icon: typeof Package }> = {
      in_stock: { bg: 'bg-emerald-50', text: 'text-emerald-700', icon: CheckCircle },
      reserved: { bg: 'bg-amber-50', text: 'text-amber-700', icon: Clock },
      sold: { bg: 'bg-blue-50', text: 'text-blue-700', icon: Tag },
      returned: { bg: 'bg-purple-50', text: 'text-purple-700', icon: RotateCcw },
      faulty: { bg: 'bg-red-50', text: 'text-red-700', icon: XCircle },
    }
    const config = statusConfig[statusValue] || statusConfig.in_stock
    const Icon = config.icon
    const label = typeof status === 'object' ? status?.label : statusValue.replace('_', ' ')

    return (
      <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${config.bg} ${config.text}`}>
        <Icon className="h-3 w-3" />
        {label}
      </span>
    )
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-16">
        <Loader2 className="h-8 w-8 animate-spin text-blue-500" />
      </div>
    )
  }

  if (devices.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center py-16">
        <div className="h-12 w-12 rounded-xl bg-slate-100 flex items-center justify-center mb-3">
          <Package className="h-6 w-6 text-slate-400" />
        </div>
        <p className="text-slate-500 text-sm">No devices in inventory</p>
      </div>
    )
  }

  return (
    <>
      <div className="overflow-x-auto">
        <table className="w-full">
          <thead className="bg-slate-50 border-b border-slate-200">
            <tr>
              <th className="text-left px-6 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">Device</th>
              <th className="text-left px-6 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">Serial</th>
              <th className="text-left px-6 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">Category</th>
              <th className="text-left px-6 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">Branch</th>
              <th className="text-left px-6 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">Status</th>
              <th className="text-left px-6 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">IoT</th>
              <th className="text-left px-6 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">Added</th>
              <th className="text-right px-6 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-200">
            {devices.map((device) => (
              <tr key={device.id} className="hover:bg-slate-50 transition-colors">
                <td className="px-6 py-4">
                  <div className="flex items-center gap-3">
                    <div className="h-10 w-10 rounded-lg bg-blue-50 flex items-center justify-center">
                      <Smartphone className="h-5 w-5 text-blue-600" />
                    </div>
                    <div>
                      <p className="font-medium text-slate-900">{device.brand} {device.model}</p>
                      {device.color && <p className="text-xs text-slate-500">{device.color}</p>}
                    </div>
                  </div>
                </td>
                <td className="px-6 py-4">
                  <div className="flex items-center gap-1.5 text-sm text-slate-600">
                    <Hash className="h-3.5 w-3.5 text-slate-400" />
                    {device.serial_number}
                  </div>
                </td>
                <td className="px-6 py-4">
                  <span className="text-sm text-slate-600">{device.category?.name || '-'}</span>
                </td>
                <td className="px-6 py-4">
                  <span className="text-sm text-slate-600">{device.branch?.name || '-'}</span>
                </td>
                <td className="px-6 py-4">
                  {getInventoryStatusBadge(device)}
                </td>
                <td className="px-6 py-4">
                  {device.has_iot_module ? (
                    <span className="inline-flex items-center gap-1 text-xs text-emerald-600">
                      <CheckCircle className="h-3.5 w-3.5" /> Linked
                    </span>
                  ) : (
                    <span className="text-xs text-slate-400">Not linked</span>
                  )}
                </td>
                <td className="px-6 py-4">
                  <span className="text-sm text-slate-500">
                    {formatMalawiDate(device.created_at)}
                  </span>
                </td>
                <td className="px-6 py-4 text-right">
                  <Authorize permission={PERMISSIONS.MANAGE_INVENTORY}>
                    <div className="flex flex-col items-end gap-1.5">
                      <button
                        onClick={() => onChangeStatus(device)}
                        className="text-blue-600 hover:text-blue-800 text-sm font-medium"
                      >
                        Change Status
                      </button>
                      {onAdministrativeSale && ['in_stock', 'returned'].includes(typeof device.inventory_status === 'string' ? device.inventory_status : device.inventory_status?.value || '') && (
                        <button
                          onClick={() => onAdministrativeSale(device)}
                          className="text-violet-700 hover:text-violet-900 text-xs font-medium"
                        >
                          Administrative Sale
                        </button>
                      )}
                    </div>
                  </Authorize>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between px-6 py-4 border-t border-slate-200">
          <p className="text-sm text-slate-500">
            Showing {((meta.current_page - 1) * meta.per_page) + 1} to {Math.min(meta.current_page * meta.per_page, meta.total)} of {meta.total}
          </p>
          <div className="flex gap-2">
            <button
              onClick={() => onPageChange(Math.max(1, meta.current_page - 1))}
              disabled={meta.current_page === 1}
              className="px-3 py-1 text-sm border border-slate-200 rounded-lg hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              Previous
            </button>
            <button
              onClick={() => onPageChange(Math.min(meta.last_page, meta.current_page + 1))}
              disabled={meta.current_page === meta.last_page}
              className="px-3 py-1 text-sm border border-slate-200 rounded-lg hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              Next
            </button>
          </div>
        </div>
      )}
    </>
  )
}
