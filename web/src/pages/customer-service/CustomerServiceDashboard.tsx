import { Link } from 'react-router-dom'
import { useState, useEffect } from 'react'
import {
  ShoppingCart,
  Users,
  Package,
  TrendingUp,
  FileText,
  ArrowRight,
  Building2,
  Clock,
  Play,
  Square,
} from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import apiClient from '@/api/client'
import { useCurrentShift, useStartShift, useCancelShift } from '@/hooks/useShifts'
import { ShiftEndModal } from '@/components/shifts/ShiftEndModal'
import { ShiftBreakControl } from '@/components/shifts/ShiftBreakControl'
import { differenceInSeconds } from 'date-fns'

interface Branch {
  id: number
  name: string
  code: string
}

interface DashboardStats {
  branch: Branch | null
  totalSales: number
  totalRevenue: number
  totalCustomers: number
  stockCount: number
  activeDevices: number
  pendingWarranties: number
  todaySales: number
}

interface ShiftStartModalProps {
  onClose: () => void
  onStart: (data: { notes?: string }) => void
  isLoading: boolean
}

function ShiftStartModal({ onClose, onStart, isLoading }: ShiftStartModalProps) {
  const [notes, setNotes] = useState('')

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    onStart({
      notes: notes || undefined,
    })
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-xl max-w-md w-full p-6">
        <h2 className="text-xl font-bold text-slate-900 mb-4">Start Your Shift</h2>
        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">
              Notes (optional)
            </label>
            <textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="Any notes for this shift..."
              rows={3}
              className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500"
            />
          </div>
          <div className="flex gap-3">
            <button
              type="button"
              onClick={onClose}
              className="flex-1 px-4 py-2 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={isLoading}
              className="flex-1 px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 disabled:opacity-50 transition-colors"
            >
              {isLoading ? 'Starting...' : 'Start Shift'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

interface ShiftCancelModalProps {
  onClose: () => void
  onCancel: (data: { notes?: string }) => void
  isLoading: boolean
}

function ShiftCancelModal({ onClose, onCancel, isLoading }: ShiftCancelModalProps) {
  const [notes, setNotes] = useState('')

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    onCancel({
      notes: notes || undefined,
    })
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-xl max-w-md w-full p-6">
        <div className="mb-4">
          <div className="flex items-center gap-3 mb-2">
            <div className="p-2 bg-red-100 rounded-lg">
              <Square className="h-5 w-5 text-red-600" />
            </div>
            <h2 className="text-xl font-bold text-slate-900">Cancel Shift</h2>
          </div>
          <p className="text-sm text-slate-600 mt-3">
            Are you sure you want to cancel this shift? This action cannot be undone.
          </p>
          <p className="text-xs text-red-600 mt-2">
            Note: Sales made during this shift will not be deleted.
          </p>
        </div>
        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">
              Reason for cancellation (optional)
            </label>
            <textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="Please explain why you're cancelling this shift..."
              rows={3}
              className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500"
            />
          </div>
          <div className="flex gap-3">
            <button
              type="button"
              onClick={onClose}
              disabled={isLoading}
              className="flex-1 px-4 py-2 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors disabled:opacity-50"
            >
              Keep Shift
            </button>
            <button
              type="submit"
              disabled={isLoading}
              className="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50 transition-colors"
            >
              {isLoading ? 'Cancelling...' : 'Cancel Shift'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

export default function CustomerServiceDashboard() {
  const [showStartModal, setShowStartModal] = useState(false)
  const [showEndModal, setShowEndModal] = useState(false)
  const [showCancelModal, setShowCancelModal] = useState(false)
  const [clock, setClock] = useState(() => new Date())

  const { data: stats, isLoading } = useQuery({
    queryKey: ['customer-service-dashboard'],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: DashboardStats }>(
        '/customer-service/dashboard'
      )
      return response.data.data
    },
  })

  const { data: currentShift, isLoading: shiftLoading } = useCurrentShift()
  const startShift = useStartShift()
  const cancelShift = useCancelShift()

  // Live shift counter. The interval only runs while a shift is active, and the
  // elapsed value is derived during render rather than set from the effect body.
  useEffect(() => {
    if (!currentShift || currentShift.status !== 'active') return

    const interval = setInterval(() => setClock(new Date()), 1000)

    return () => clearInterval(interval)
  }, [currentShift])

  const elapsedSeconds =
    currentShift?.status === 'active'
      ? Math.max(0, differenceInSeconds(clock, new Date(currentShift.started_at)))
      : 0

  const formatElapsedTime = (seconds: number) => {
    const hours = Math.floor(seconds / 3600)
    const minutes = Math.floor((seconds % 3600) / 60)
    const secs = seconds % 60

    if (hours > 0) {
      return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
    }
    return `${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
  }

  const handleStartShift = (data: { notes?: string }) => {
    startShift.mutate(data, {
      onSuccess: () => {
        setShowStartModal(false)
      },
    })
  }

  const handleCancelShift = (data: { notes?: string }) => {
    cancelShift.mutate(data, {
      onSuccess: () => {
        setShowCancelModal(false)
      },
    })
  }

  const statCards = [
    {
      label: 'My Total Sales',
      value: isLoading ? '...' : (stats?.totalSales || 0).toString(),
      icon: ShoppingCart,
      color: 'from-blue-400 to-blue-600',
      bgColor: 'bg-blue-50',
    },
    {
      label: 'Total Revenue',
      value: isLoading ? '...' : `MWK ${(stats?.totalRevenue || 0).toLocaleString()}`,
      icon: TrendingUp,
      color: 'from-emerald-400 to-emerald-600',
      bgColor: 'bg-emerald-50',
    },
    {
      label: 'Customers Served',
      value: isLoading ? '...' : (stats?.totalCustomers || 0).toString(),
      icon: Users,
      color: 'from-purple-400 to-purple-600',
      bgColor: 'bg-purple-50',
    },
    {
      label: 'Stock Count',
      value: isLoading ? '...' : (stats?.stockCount || 0).toString(),
      icon: Package,
      color: 'from-amber-400 to-amber-600',
      bgColor: 'bg-amber-50',
    },
  ]

  const quickActions = [
    {
      label: 'New Sale',
      description: 'Create a new sale',
      icon: ShoppingCart,
      href: '/customer-service/sales/new',
      color: 'from-blue-400 to-blue-600',
    },
    {
      label: 'My Sales',
      description: 'View your sales history',
      icon: ShoppingCart,
      href: '/customer-service/sales',
      color: 'from-teal-400 to-teal-600',
    },
    {
      label: 'View Devices',
      description: 'Browse all devices',
      icon: Package,
      href: '/customer-service/devices',
      color: 'from-purple-400 to-purple-600',
    },
    {
      label: 'View Customers',
      description: 'Manage customers',
      icon: Users,
      href: '/customer-service/customers',
      color: 'from-orange-400 to-orange-600',
    },
  ]

  return (
    <div className="space-y-6">
      {/* Welcome Section with Shift Controls */}
      <div className="bg-gradient-to-r from-teal-500 to-teal-600 rounded-xl p-6 text-white">
        <div className="flex items-start justify-between mb-4">
          <div>
            <h1 className="text-2xl font-bold mb-2">Welcome back!</h1>
            <p className="text-teal-100">
              Manage your sales and help customers with their devices.
            </p>
          </div>
          {stats?.branch && (
            <div className="flex items-center gap-2 bg-white/20 rounded-lg px-4 py-2">
              <Building2 className="h-5 w-5" />
              <div className="text-right">
                <p className="text-xs text-teal-100">{stats.branch.code}</p>
                <p className="font-semibold">{stats.branch.name}</p>
              </div>
            </div>
          )}
        </div>

        {/* Shift Status and Controls */}
        <div className="bg-white/10 rounded-lg p-4 backdrop-blur-sm">
          {shiftLoading ? (
            <div className="flex items-center gap-3">
              <div className="animate-spin h-5 w-5 border-2 border-white border-t-transparent rounded-full" />
              <span>Loading shift status...</span>
            </div>
          ) : currentShift ? (
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <Clock className="h-6 w-6" />
                  <span className="font-semibold">Active Shift</span>
                  <span className="text-2xl font-bold font-mono bg-white/20 px-3 py-2 rounded-lg">
                    {formatElapsedTime(elapsedSeconds)}
                  </span>
                </div>
                <div className="flex gap-2">
                  <button
                    onClick={() => setShowEndModal(true)}
                    className="px-3 py-1.5 bg-white text-teal-600 rounded-lg text-sm font-medium hover:bg-teal-50 transition-colors"
                  >
                    End Shift
                  </button>
                  <button
                    onClick={() => setShowCancelModal(true)}
                    disabled={cancelShift.isPending}
                    className="px-3 py-1.5 bg-red-500 text-white rounded-lg text-sm font-medium hover:bg-red-600 disabled:opacity-50 transition-colors"
                  >
                    Cancel
                  </button>
                </div>
              </div>
              <ShiftBreakControl shift={currentShift} />
              <div className="grid grid-cols-3 gap-4 text-sm">
                <div>
                  <p className="text-teal-100">Sales</p>
                  <p className="font-semibold">{currentShift.sales_count || 0}</p>
                </div>
                <div>
                  <p className="text-teal-100">Revenue</p>
                  <p className="font-semibold">MWK {currentShift.revenue.toLocaleString()}</p>
                </div>
                <div>
                  <p className="text-teal-100">Cash Sales</p>
                  <p className="font-semibold">MWK {currentShift.cash_sales.toLocaleString()}</p>
                </div>
              </div>
            </div>
          ) : (
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Square className="h-5 w-5 opacity-70" />
                <span>No active shift</span>
              </div>
              <button
                onClick={() => setShowStartModal(true)}
                className="flex items-center gap-2 px-4 py-2 bg-white text-teal-600 rounded-lg font-medium hover:bg-teal-50 transition-colors"
              >
                <Play className="h-4 w-4" />
                Start Shift
              </button>
            </div>
          )}
        </div>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        {statCards.map((stat, index) => (
          <div
            key={index}
            className={`${stat.bgColor} rounded-xl p-6 border border-slate-200`}
          >
            <div className="flex items-center justify-between mb-4">
              <div className={`p-3 rounded-lg bg-gradient-to-br ${stat.color}`}>
                <stat.icon className="h-6 w-6 text-white" />
              </div>
            </div>
            <p className="text-sm text-slate-600 mb-1">{stat.label}</p>
            <p className="text-2xl font-bold text-slate-900">{stat.value}</p>
          </div>
        ))}
      </div>

      {/* Quick Actions */}
      <div>
        <h2 className="text-lg font-semibold text-slate-900 mb-4">Quick Actions</h2>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          {quickActions.map((action, index) => (
            <Link
              key={index}
              to={action.href}
              className="block p-4 bg-white rounded-xl border border-slate-200 hover:border-teal-300 hover:shadow-md transition-all"
            >
              <div className={`p-3 rounded-lg bg-gradient-to-br ${action.color} w-12 h-12 mb-3`}>
                <action.icon className="h-6 w-6 text-white" />
              </div>
              <h3 className="font-semibold text-slate-900 mb-1">{action.label}</h3>
              <p className="text-sm text-slate-500 mb-3">{action.description}</p>
              <ArrowRight className="h-4 w-4 text-teal-600" />
            </Link>
          ))}
          <Link
            to="/customer-service/shifts"
            className="block p-4 bg-white rounded-xl border border-slate-200 hover:border-teal-300 hover:shadow-md transition-all"
          >
            <div className="p-3 rounded-lg bg-gradient-to-br from-indigo-400 to-indigo-600 w-12 h-12 mb-3">
              <Clock className="h-6 w-6 text-white" />
            </div>
            <h3 className="font-semibold text-slate-900 mb-1">Shift History</h3>
            <p className="text-sm text-slate-500 mb-3">View your shift history</p>
            <ArrowRight className="h-4 w-4 text-teal-600" />
          </Link>
        </div>
      </div>

      {/* Pending Items */}
      {stats && (stats.pendingWarranties || 0) > 0 && (
        <div className="bg-amber-50 border border-amber-200 rounded-xl p-6">
          <div className="flex items-center justify-between mb-4">
            <div>
              <h3 className="text-lg font-semibold text-amber-900">Pending Warranty Claims</h3>
              <p className="text-sm text-amber-700">
                You have {stats.pendingWarranties} warranty claim(s) awaiting attention
              </p>
            </div>
            <FileText className="h-8 w-8 text-amber-600" />
          </div>
          <Link
            to="/customer-service/warranties"
            className="inline-flex items-center gap-2 px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors"
          >
            View Claims
            <ArrowRight className="h-4 w-4" />
          </Link>
        </div>
      )}

      {/* Modals */}
      {showStartModal && (
        <ShiftStartModal
          onClose={() => setShowStartModal(false)}
          onStart={handleStartShift}
          isLoading={startShift.isPending}
        />
      )}

      {showEndModal && currentShift && <ShiftEndModal onClose={() => setShowEndModal(false)} />}

      {showCancelModal && (
        <ShiftCancelModal
          onClose={() => setShowCancelModal(false)}
          onCancel={handleCancelShift}
          isLoading={cancelShift.isPending}
        />
      )}
    </div>
  )
}
