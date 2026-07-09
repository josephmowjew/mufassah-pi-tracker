import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import {
  Plus,
  Shield,
  MapPin,
  MoreVertical,
  Edit,
  Trash2,
  Eye,
  Loader2,
  AlertTriangle,
  Bell,
  BellOff,
  ToggleLeft,
  ToggleRight,
  Circle,
  Hexagon,
} from 'lucide-react'
import { toast } from 'sonner'
import { useGeofences, useDeleteGeofence, useToggleGeofence } from '@/hooks/useGeofences'
import { useDevices } from '@/hooks/useDevices'
import type { Geofence } from '@/types'
import CreateGeofenceModal from './CreateGeofenceModal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'

export default function GeofencesPage() {
  const [searchParams] = useSearchParams()
  const deviceFilter = searchParams.get('device')
  const [showCreateModal, setShowCreateModal] = useState(false)
  const [menuOpen, setMenuOpen] = useState<number | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<Geofence | null>(null)

  const { data: geofences, isLoading, error } = useGeofences()
  const { data: devicesData } = useDevices({ per_page: 100 })
  const deleteGeofence = useDeleteGeofence()
  const toggleGeofence = useToggleGeofence()

  // Filter by device if specified
  const filteredGeofences = deviceFilter
    ? geofences?.filter((g) => g.device_id === parseInt(deviceFilter))
    : geofences

  const handleDelete = (geofence: Geofence) => {
    setDeleteTarget(geofence)
    setMenuOpen(null)
  }

  const confirmDelete = () => {
    if (!deleteTarget) return
    deleteGeofence.mutate(deleteTarget.id, {
      onSuccess: () => {
        toast.success('Geofence deleted successfully')
        setDeleteTarget(null)
      },
      onError: () => {
        toast.error('Failed to delete geofence')
      },
    })
  }

  const handleToggle = (geofence: Geofence) => {
    toggleGeofence.mutate(geofence.id, {
      onSuccess: (updated) => {
        toast.success(updated.is_active ? 'Geofence activated' : 'Geofence deactivated')
      },
      onError: () => {
        toast.error('Failed to update geofence status')
      },
    })
    setMenuOpen(null)
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            Geofences
          </h1>
          <p className="text-sm text-slate-500 mt-1">
            Set up virtual boundaries and get alerts when devices enter or exit
          </p>
        </div>
        <button
          onClick={() => setShowCreateModal(true)}
          className="inline-flex items-center gap-2 px-4 py-2.5 bg-teal-500 text-white rounded-xl text-sm font-medium hover:bg-teal-600 transition-colors shadow-sm"
        >
          <Plus className="h-4 w-4" />
          Create Geofence
        </button>
      </div>

      {/* Filter indicator */}
      {deviceFilter && devicesData?.data && (
        <div className="flex items-center gap-2 text-sm text-slate-600 bg-slate-50 rounded-lg px-3 py-2">
          <span>Filtering by device:</span>
          <span className="font-medium">
            {devicesData.data.find((d) => d.id === parseInt(deviceFilter))?.name || `Device #${deviceFilter}`}
          </span>
          <Link to="/geofences" className="text-teal-600 hover:text-teal-700 ml-2">
            Clear filter
          </Link>
        </div>
      )}

      {/* Geofences List */}
      <div className="bg-white rounded-xl border border-slate-200/60 overflow-hidden">
        {isLoading ? (
          <div className="flex items-center justify-center py-20">
            <Loader2 className="h-8 w-8 text-teal-500 animate-spin" />
          </div>
        ) : error ? (
          <div className="flex flex-col items-center justify-center py-20 text-center">
            <AlertTriangle className="h-10 w-10 text-red-400 mb-3" />
            <p className="text-slate-600">Failed to load geofences</p>
            <p className="text-sm text-slate-400">Please try again later</p>
          </div>
        ) : !filteredGeofences || filteredGeofences.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-20 text-center">
            <div className="p-4 bg-violet-50 rounded-full mb-4">
              <Shield className="h-10 w-10 text-violet-400" />
            </div>
            <h3 className="text-lg font-medium text-slate-800 mb-1">No geofences yet</h3>
            <p className="text-sm text-slate-500 mb-6 max-w-sm">
              Create virtual boundaries around locations to get alerts when your devices enter or exit.
            </p>
            <button
              onClick={() => setShowCreateModal(true)}
              className="inline-flex items-center gap-2 px-4 py-2 bg-teal-500 text-white rounded-lg text-sm font-medium hover:bg-teal-600 transition-colors"
            >
              <Plus className="h-4 w-4" />
              Create Your First Geofence
            </button>
          </div>
        ) : (
          <div className="divide-y divide-slate-100">
            {filteredGeofences.map((geofence) => (
              <GeofenceItem
                key={geofence.id}
                geofence={geofence}
                menuOpen={menuOpen === geofence.id}
                onMenuToggle={() => setMenuOpen(menuOpen === geofence.id ? null : geofence.id)}
                onMenuClose={() => setMenuOpen(null)}
                onToggle={() => handleToggle(geofence)}
                onDelete={() => handleDelete(geofence)}
              />
            ))}
          </div>
        )}
      </div>

      {/* Create Modal */}
      <CreateGeofenceModal
        isOpen={showCreateModal}
        onClose={() => setShowCreateModal(false)}
        defaultDeviceId={deviceFilter ? parseInt(deviceFilter) : undefined}
      />

      {/* Delete Confirmation Dialog */}
      <ConfirmDialog
        isOpen={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        onConfirm={confirmDelete}
        title="Delete Geofence"
        message={`Are you sure you want to delete "${deleteTarget?.name}"? This action cannot be undone.`}
        confirmText="Delete"
        variant="danger"
        isLoading={deleteGeofence.isPending}
      />
    </div>
  )
}

function GeofenceItem({
  geofence,
  menuOpen,
  onMenuToggle,
  onMenuClose,
  onToggle,
  onDelete,
}: {
  geofence: Geofence
  menuOpen: boolean
  onMenuToggle: () => void
  onMenuClose: () => void
  onToggle: () => void
  onDelete: () => void
}) {
  return (
    <div className="flex items-center gap-4 p-4 hover:bg-slate-50/50 transition-colors">
      {/* Icon */}
      <div className={`p-3 rounded-xl ${geofence.is_active ? 'bg-violet-50' : 'bg-slate-100'}`}>
        {geofence.type === 'circle' ? (
          <Circle className={`h-5 w-5 ${geofence.is_active ? 'text-violet-500' : 'text-slate-400'}`} />
        ) : (
          <Hexagon className={`h-5 w-5 ${geofence.is_active ? 'text-violet-500' : 'text-slate-400'}`} />
        )}
      </div>

      {/* Info */}
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 mb-1">
          <h3 className="font-medium text-slate-800 truncate">{geofence.name}</h3>
          <span
            className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${
              geofence.is_active
                ? 'bg-emerald-50 text-emerald-700'
                : 'bg-slate-100 text-slate-500'
            }`}
          >
            {geofence.is_active ? 'Active' : 'Inactive'}
          </span>
        </div>
        <div className="flex items-center gap-4 text-sm text-slate-500">
          <span className="flex items-center gap-1">
            <MapPin className="h-3.5 w-3.5" />
            {geofence.radius_meters}m radius
          </span>
          {geofence.device && (
            <Link
              to={`/devices/${geofence.device_id}`}
              className="hover:text-teal-600 transition-colors"
            >
              {geofence.device.name}
            </Link>
          )}
        </div>
      </div>

      {/* Notification indicators */}
      <div className="hidden sm:flex items-center gap-2">
        <span
          className={`inline-flex items-center gap-1 px-2 py-1 rounded text-xs ${
            geofence.notify_on_entry
              ? 'bg-blue-50 text-blue-600'
              : 'bg-slate-50 text-slate-400'
          }`}
          title={geofence.notify_on_entry ? 'Entry alerts enabled' : 'Entry alerts disabled'}
        >
          <Bell className="h-3 w-3" />
          Entry
        </span>
        <span
          className={`inline-flex items-center gap-1 px-2 py-1 rounded text-xs ${
            geofence.notify_on_exit
              ? 'bg-amber-50 text-amber-600'
              : 'bg-slate-50 text-slate-400'
          }`}
          title={geofence.notify_on_exit ? 'Exit alerts enabled' : 'Exit alerts disabled'}
        >
          <BellOff className="h-3 w-3" />
          Exit
        </span>
      </div>

      {/* Actions */}
      <div className="relative">
        <button
          onClick={onMenuToggle}
          className="p-2 hover:bg-slate-100 rounded-lg transition-colors"
        >
          <MoreVertical className="h-4 w-4 text-slate-500" />
        </button>
        {menuOpen && (
          <>
            <div className="fixed inset-0 z-10" onClick={onMenuClose} />
            <div className="absolute right-0 mt-1 w-44 bg-white border border-slate-200 rounded-lg shadow-lg z-20 py-1">
              <Link
                to={`/geofences/${geofence.id}`}
                className="flex items-center gap-2 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
                onClick={onMenuClose}
              >
                <Eye className="h-4 w-4" />
                View Details
              </Link>
              <Link
                to={`/geofences/${geofence.id}/edit`}
                className="flex items-center gap-2 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
                onClick={onMenuClose}
              >
                <Edit className="h-4 w-4" />
                Edit
              </Link>
              <button
                onClick={onToggle}
                className="flex items-center gap-2 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50 w-full"
              >
                {geofence.is_active ? (
                  <>
                    <ToggleLeft className="h-4 w-4" />
                    Deactivate
                  </>
                ) : (
                  <>
                    <ToggleRight className="h-4 w-4" />
                    Activate
                  </>
                )}
              </button>
              <button
                onClick={onDelete}
                className="flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 w-full"
              >
                <Trash2 className="h-4 w-4" />
                Delete
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  )
}
