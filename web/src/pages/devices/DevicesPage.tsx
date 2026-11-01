import { formatMalawiDate } from '@/lib/datetime'
import { useState, useRef } from 'react'
import { Link } from 'react-router-dom'
import {
  Plus,
  Search,
  Smartphone,
  MapPin,
  MoreVertical,
  Eye,
  Edit,
  Trash2,
  AlertTriangle,
  Filter,
  ChevronLeft,
  ChevronRight,
  Loader2,
} from 'lucide-react'
import { useDevices, useDeviceCategories, useDeleteDevice } from '@/hooks/useDevices'
import type { Device, DeviceStatus, DeviceStatusValue } from '@/types'
import AddDeviceModal from './AddDeviceModal'

const statusColors: Record<DeviceStatusValue, { bg: string; text: string; dot: string }> = {
  active: { bg: 'bg-emerald-50', text: 'text-emerald-700', dot: 'bg-emerald-500' },
  inactive: { bg: 'bg-slate-100', text: 'text-slate-600', dot: 'bg-slate-400' },
  stolen: { bg: 'bg-red-50', text: 'text-red-700', dot: 'bg-red-500' },
  recovered: { bg: 'bg-blue-50', text: 'text-blue-700', dot: 'bg-blue-500' },
}

// Helper to extract status value from either string or object format
function getStatusValue(status: DeviceStatus): DeviceStatusValue {
  if (typeof status === 'string') return status
  return status.value
}

// Helper to get status label
function getStatusLabel(status: DeviceStatus): string {
  if (typeof status === 'string') {
    return status.charAt(0).toUpperCase() + status.slice(1)
  }
  return status.label
}

export default function DevicesPage() {
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<string>('')
  const [categoryFilter, setCategoryFilter] = useState<string>('')
  const [page, setPage] = useState(1)
  const [showAddModal, setShowAddModal] = useState(false)
  const [menuOpen, setMenuOpen] = useState<number | null>(null)
  const [menuPosition, setMenuPosition] = useState<{ top: number; left: number } | null>(null)
  const menuButtonRefs = useRef<Map<number, HTMLButtonElement>>(new Map())

  const { data: devicesData, isLoading, error } = useDevices({
    page,
    per_page: 10,
    search: search || undefined,
    status: statusFilter || undefined,
    category_id: categoryFilter ? parseInt(categoryFilter) : undefined,
  })

  const { data: categories } = useDeviceCategories()
  const deleteDevice = useDeleteDevice()

  const handleDelete = async (id: number) => {
    if (confirm('Are you sure you want to delete this device?')) {
      deleteDevice.mutate(id)
    }
    setMenuOpen(null)
    setMenuPosition(null)
  }

  const handleMenuToggle = (deviceId: number) => {
    if (menuOpen === deviceId) {
      setMenuOpen(null)
      setMenuPosition(null)
    } else {
      const button = menuButtonRefs.current.get(deviceId)
      if (button) {
        const rect = button.getBoundingClientRect()
        setMenuPosition({
          top: rect.bottom + 4,
          left: rect.right - 160, // 160px is the menu width (w-40)
        })
      }
      setMenuOpen(deviceId)
    }
  }

  const closeMenu = () => {
    setMenuOpen(null)
    setMenuPosition(null)
  }

  const devices = devicesData?.data || []
  const meta = devicesData?.meta

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            My Devices
          </h1>
          <p className="text-sm text-slate-500 mt-1">
            Manage and track all your registered devices
          </p>
        </div>
        <button
          onClick={() => setShowAddModal(true)}
          className="inline-flex items-center gap-2 px-4 py-2.5 bg-teal-500 text-white rounded-xl text-sm font-medium hover:bg-teal-600 transition-colors shadow-sm"
        >
          <Plus className="h-4 w-4" />
          Add Device
        </button>
      </div>

      {/* Filters */}
      <div className="bg-white rounded-xl border border-slate-200/60 p-4">
        <div className="flex flex-col sm:flex-row gap-3">
          {/* Search */}
          <div className="relative flex-1">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
            <input
              type="text"
              placeholder="Search devices..."
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(1)
              }}
              className="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500"
            />
          </div>

          {/* Status Filter */}
          <div className="relative">
            <Filter className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
            <select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value)
                setPage(1)
              }}
              className="pl-10 pr-8 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 appearance-none cursor-pointer"
            >
              <option value="">All Status</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="stolen">Stolen</option>
              <option value="recovered">Recovered</option>
            </select>
          </div>

          {/* Category Filter */}
          <select
            value={categoryFilter}
            onChange={(e) => {
              setCategoryFilter(e.target.value)
              setPage(1)
            }}
            className="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 appearance-none cursor-pointer"
          >
            <option value="">All Categories</option>
            {categories?.map((cat) => (
              <option key={cat.id} value={cat.id}>
                {cat.name}
              </option>
            ))}
          </select>
        </div>
      </div>

      {/* Devices List */}
      <div className="bg-white rounded-xl border border-slate-200/60">
        {isLoading ? (
          <div className="flex items-center justify-center py-20">
            <Loader2 className="h-8 w-8 text-teal-500 animate-spin" />
          </div>
        ) : error ? (
          <div className="flex flex-col items-center justify-center py-20 text-center">
            <AlertTriangle className="h-10 w-10 text-red-400 mb-3" />
            <p className="text-slate-600">Failed to load devices</p>
            <p className="text-sm text-slate-400">Please try again later</p>
          </div>
        ) : devices.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-20 text-center">
            <div className="p-4 bg-slate-100 rounded-full mb-4">
              <Smartphone className="h-10 w-10 text-slate-400" />
            </div>
            <h3 className="text-lg font-medium text-slate-800 mb-1">No devices found</h3>
            <p className="text-sm text-slate-500 mb-6 max-w-sm">
              {search || statusFilter || categoryFilter
                ? 'Try adjusting your filters'
                : 'Get started by adding your first device'}
            </p>
            {!search && !statusFilter && !categoryFilter && (
              <button
                onClick={() => setShowAddModal(true)}
                className="inline-flex items-center gap-2 px-4 py-2 bg-teal-500 text-white rounded-lg text-sm font-medium hover:bg-teal-600 transition-colors"
              >
                <Plus className="h-4 w-4" />
                Add Your First Device
              </button>
            )}
          </div>
        ) : (
          <>
            {/* Table */}
            <div className="overflow-x-auto overflow-y-visible">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-slate-100 bg-slate-50/50">
                    <th className="text-left py-3 px-4 text-xs font-medium text-slate-500 uppercase tracking-wider">
                      Device
                    </th>
                    <th className="text-left py-3 px-4 text-xs font-medium text-slate-500 uppercase tracking-wider hidden md:table-cell">
                      Category
                    </th>
                    <th className="text-left py-3 px-4 text-xs font-medium text-slate-500 uppercase tracking-wider">
                      Status
                    </th>
                    <th className="text-left py-3 px-4 text-xs font-medium text-slate-500 uppercase tracking-wider hidden lg:table-cell">
                      Last Seen
                    </th>
                    <th className="text-right py-3 px-4 text-xs font-medium text-slate-500 uppercase tracking-wider">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {devices.map((device: Device) => (
                    <tr key={device.id} className="hover:bg-slate-50/50 transition-colors">
                      <td className="py-4 px-4">
                        <div className="flex items-center gap-3">
                          <div className="h-10 w-10 rounded-lg bg-gradient-to-br from-slate-100 to-slate-50 border border-slate-200 flex items-center justify-center">
                            <Smartphone className="h-5 w-5 text-slate-500" />
                          </div>
                          <div>
                            <Link
                              to={`/devices/${device.id}`}
                              className="font-medium text-slate-800 hover:text-teal-600 transition-colors"
                            >
                              {device.name}
                            </Link>
                            <p className="text-xs text-slate-500">
                              {device.brand} {device.model}
                            </p>
                          </div>
                        </div>
                      </td>
                      <td className="py-4 px-4 hidden md:table-cell">
                        <span className="text-sm text-slate-600">
                          {device.category?.name || 'N/A'}
                        </span>
                      </td>
                      <td className="py-4 px-4">
                        {(() => {
                          const statusValue = getStatusValue(device.status)
                          const colors = statusColors[statusValue] || statusColors.inactive
                          return (
                            <span
                              className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${colors.bg} ${colors.text}`}
                            >
                              <span className={`h-1.5 w-1.5 rounded-full ${colors.dot}`} />
                              {getStatusLabel(device.status)}
                            </span>
                          )
                        })()}
                      </td>
                      <td className="py-4 px-4 hidden lg:table-cell">
                        {device.last_seen_at ? (
                          <div className="flex items-center gap-1.5 text-sm text-slate-600">
                            <MapPin className="h-3.5 w-3.5 text-slate-400" />
                            {formatMalawiDate(device.last_seen_at)}
                          </div>
                        ) : (
                          <span className="text-sm text-slate-400">Never</span>
                        )}
                      </td>
                      <td className="py-4 px-4 text-right">
                        <button
                          ref={(el) => {
                            if (el) menuButtonRefs.current.set(device.id, el)
                          }}
                          onClick={() => handleMenuToggle(device.id)}
                          className="p-2 hover:bg-slate-100 rounded-lg transition-colors"
                        >
                          <MoreVertical className="h-4 w-4 text-slate-500" />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Pagination */}
            {meta && meta.last_page > 1 && (
              <div className="flex items-center justify-between px-4 py-3 border-t border-slate-100">
                <p className="text-sm text-slate-500">
                  Showing {meta.from} to {meta.to} of {meta.total} devices
                </p>
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                    disabled={page === 1}
                    className="p-2 rounded-lg border border-slate-200 hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    <ChevronLeft className="h-4 w-4" />
                  </button>
                  <span className="text-sm text-slate-600">
                    Page {page} of {meta.last_page}
                  </span>
                  <button
                    onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                    disabled={page === meta.last_page}
                    className="p-2 rounded-lg border border-slate-200 hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    <ChevronRight className="h-4 w-4" />
                  </button>
                </div>
              </div>
            )}
          </>
        )}
      </div>

      {/* Actions Dropdown Menu - Fixed Position */}
      {menuOpen !== null && menuPosition && (
        <>
          <div
            className="fixed inset-0 z-40"
            onClick={closeMenu}
          />
          <div
            className="fixed w-40 bg-white border border-slate-200 rounded-lg shadow-lg z-50 py-1"
            style={{ top: menuPosition.top, left: menuPosition.left }}
          >
            <Link
              to={`/devices/${menuOpen}`}
              className="flex items-center gap-2 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
              onClick={closeMenu}
            >
              <Eye className="h-4 w-4" />
              View Details
            </Link>
            <Link
              to={`/devices/${menuOpen}?edit=true`}
              className="flex items-center gap-2 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
              onClick={closeMenu}
            >
              <Edit className="h-4 w-4" />
              Edit
            </Link>
            <button
              onClick={() => handleDelete(menuOpen)}
              className="flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 w-full"
            >
              <Trash2 className="h-4 w-4" />
              Delete
            </button>
          </div>
        </>
      )}

      {/* Add Device Modal */}
      <AddDeviceModal
        isOpen={showAddModal}
        onClose={() => setShowAddModal(false)}
      />
    </div>
  )
}
