import { useState } from 'react'
import {
  Bell,
  BellOff,
  Check,
  CheckCheck,
  Trash2,
  Loader2,
  AlertTriangle,
  MapPin,
  Shield,
  Smartphone,
  CreditCard,
} from 'lucide-react'
import { useNotifications, useMarkAsRead, useMarkAllAsRead, useDeleteNotification } from '@/hooks/useNotifications'
import type { Notification } from '@/types'

const notificationIcons: Record<string, typeof Bell> = {
  'geofence_entry': Shield,
  'geofence_exit': Shield,
  'device_location': MapPin,
  'theft_alert': AlertTriangle,
  'device_status': Smartphone,
  'payment': CreditCard,
  'default': Bell,
}

const notificationColors: Record<string, { bg: string; icon: string }> = {
  'geofence_entry': { bg: 'bg-blue-50', icon: 'text-blue-500' },
  'geofence_exit': { bg: 'bg-amber-50', icon: 'text-amber-500' },
  'device_location': { bg: 'bg-teal-50', icon: 'text-teal-500' },
  'theft_alert': { bg: 'bg-red-50', icon: 'text-red-500' },
  'device_status': { bg: 'bg-slate-100', icon: 'text-slate-500' },
  'payment': { bg: 'bg-emerald-50', icon: 'text-emerald-500' },
  'default': { bg: 'bg-slate-100', icon: 'text-slate-500' },
}

export default function NotificationsPage() {
  const [filter, setFilter] = useState<'all' | 'unread'>('all')

  const { data, isLoading, error } = useNotifications({
    per_page: 50,
    unread_only: filter === 'unread',
  })
  const markAsRead = useMarkAsRead()
  const markAllAsRead = useMarkAllAsRead()
  const deleteNotification = useDeleteNotification()

  const notifications = data?.notifications || []
  const unreadCount = data?.unread_count || 0

  const handleMarkAsRead = (id: string) => {
    markAsRead.mutate(id)
  }

  const handleMarkAllAsRead = () => {
    markAllAsRead.mutate()
  }

  const handleDelete = (id: string) => {
    deleteNotification.mutate(id)
  }

  const getNotificationType = (type: string): string => {
    // Extract type from notification class name
    const parts = type.split('\\')
    const className = parts[parts.length - 1]
    if (className.includes('Geofence') && className.includes('Entry')) return 'geofence_entry'
    if (className.includes('Geofence') && className.includes('Exit')) return 'geofence_exit'
    if (className.includes('Location')) return 'device_location'
    if (className.includes('Theft')) return 'theft_alert'
    if (className.includes('Device')) return 'device_status'
    if (className.includes('Payment')) return 'payment'
    return 'default'
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            Notifications
          </h1>
          <p className="text-sm text-slate-500 mt-1">
            {unreadCount > 0
              ? `You have ${unreadCount} unread notification${unreadCount !== 1 ? 's' : ''}`
              : 'All caught up!'}
          </p>
        </div>
        {unreadCount > 0 && (
          <button
            onClick={handleMarkAllAsRead}
            disabled={markAllAsRead.isPending}
            className="inline-flex items-center gap-2 px-4 py-2.5 border border-slate-200 text-slate-700 rounded-xl text-sm font-medium hover:bg-slate-50 transition-colors disabled:opacity-50"
          >
            {markAllAsRead.isPending ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <CheckCheck className="h-4 w-4" />
            )}
            Mark all as read
          </button>
        )}
      </div>

      {/* Filters */}
      <div className="flex items-center gap-2">
        <button
          onClick={() => setFilter('all')}
          className={`inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
            filter === 'all'
              ? 'bg-slate-900 text-white'
              : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
          }`}
        >
          <Bell className="h-4 w-4" />
          All
        </button>
        <button
          onClick={() => setFilter('unread')}
          className={`inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
            filter === 'unread'
              ? 'bg-slate-900 text-white'
              : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
          }`}
        >
          <BellOff className="h-4 w-4" />
          Unread
          {unreadCount > 0 && (
            <span className="ml-1 px-1.5 py-0.5 bg-teal-500 text-white text-xs rounded-full">
              {unreadCount}
            </span>
          )}
        </button>
      </div>

      {/* Notifications List */}
      <div className="bg-white rounded-xl border border-slate-200/60 overflow-hidden">
        {isLoading ? (
          <div className="flex items-center justify-center py-20">
            <Loader2 className="h-8 w-8 text-teal-500 animate-spin" />
          </div>
        ) : error ? (
          <div className="flex flex-col items-center justify-center py-20 text-center">
            <AlertTriangle className="h-10 w-10 text-red-400 mb-3" />
            <p className="text-slate-600">Failed to load notifications</p>
            <p className="text-sm text-slate-400">Please try again later</p>
          </div>
        ) : notifications.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-20 text-center">
            <div className="p-4 bg-slate-100 rounded-full mb-4">
              <Bell className="h-10 w-10 text-slate-400" />
            </div>
            <h3 className="text-lg font-medium text-slate-800 mb-1">
              {filter === 'unread' ? 'No unread notifications' : 'No notifications yet'}
            </h3>
            <p className="text-sm text-slate-500 max-w-sm">
              {filter === 'unread'
                ? "You're all caught up! No unread notifications."
                : 'When you receive alerts about your devices, they will appear here.'}
            </p>
          </div>
        ) : (
          <div className="divide-y divide-slate-100">
            {notifications.map((notification) => (
              <NotificationItem
                key={notification.id}
                notification={notification}
                type={getNotificationType(notification.type)}
                onMarkAsRead={() => handleMarkAsRead(notification.id)}
                onDelete={() => handleDelete(notification.id)}
                isMarking={markAsRead.isPending}
                isDeleting={deleteNotification.isPending}
              />
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

function NotificationItem({
  notification,
  type,
  onMarkAsRead,
  onDelete,
  isMarking,
  isDeleting,
}: {
  notification: Notification
  type: string
  onMarkAsRead: () => void
  onDelete: () => void
  isMarking: boolean
  isDeleting: boolean
}) {
  const Icon = notificationIcons[type] || notificationIcons.default
  const colors = notificationColors[type] || notificationColors.default
  const isUnread = !notification.read_at
  const data = notification.data as Record<string, unknown>

  return (
    <div
      className={`flex items-start gap-4 p-4 transition-colors ${
        isUnread ? 'bg-teal-50/30' : 'hover:bg-slate-50/50'
      }`}
    >
      {/* Icon */}
      <div className={`p-3 rounded-xl ${colors.bg}`}>
        <Icon className={`h-5 w-5 ${colors.icon}`} />
      </div>

      {/* Content */}
      <div className="flex-1 min-w-0">
        <div className="flex items-start justify-between gap-4">
          <div>
            <p className={`text-sm ${isUnread ? 'text-slate-800 font-medium' : 'text-slate-700'}`}>
              {(data.message as string) || (data.title as string) || notification.type.split('\\').pop()}
            </p>
            {typeof data.description === 'string' && data.description && (
              <p className="text-sm text-slate-500 mt-0.5">
                {data.description}
              </p>
            )}
            <p className="text-xs text-slate-400 mt-1">
              {formatTimeAgo(new Date(notification.created_at))}
            </p>
          </div>

          {/* Actions */}
          <div className="flex items-center gap-1">
            {isUnread && (
              <button
                onClick={onMarkAsRead}
                disabled={isMarking}
                className="p-2 text-slate-400 hover:text-teal-600 hover:bg-teal-50 rounded-lg transition-colors disabled:opacity-50"
                title="Mark as read"
              >
                <Check className="h-4 w-4" />
              </button>
            )}
            <button
              onClick={onDelete}
              disabled={isDeleting}
              className="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors disabled:opacity-50"
              title="Delete"
            >
              <Trash2 className="h-4 w-4" />
            </button>
          </div>
        </div>
      </div>

      {/* Unread indicator */}
      {isUnread && (
        <div className="w-2 h-2 rounded-full bg-teal-500 mt-2" />
      )}
    </div>
  )
}

function formatTimeAgo(date: Date): string {
  const now = new Date()
  const diffInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000)

  if (diffInSeconds < 60) return 'Just now'
  if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)}m ago`
  if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)}h ago`
  if (diffInSeconds < 604800) return `${Math.floor(diffInSeconds / 86400)}d ago`

  return date.toLocaleDateString()
}
