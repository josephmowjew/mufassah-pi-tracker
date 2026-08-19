import { useEffect, useRef } from 'react'
import { toast } from 'sonner'
import { useNotifications, useMarkAsRead } from '@/hooks/useNotifications'
import { useAuthStore } from '@/stores/auth-store'
import { useInventoryPinStore } from '@/stores/inventory-pin-store'
import InventoryPinRevealDialog from '@/components/admin/InventoryPinRevealDialog'

const SHOWN_KEY = 'shown-inventory-pin-notifications'

function getShownNotificationIds(): Set<string> {
  try {
    const stored = JSON.parse(sessionStorage.getItem(SHOWN_KEY) || '[]')
    return new Set(Array.isArray(stored) ? stored.filter((id): id is string => typeof id === 'string') : [])
  } catch {
    return new Set()
  }
}

export default function InventoryPinAlerts() {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated)
  const isAdmin = useAuthStore((state) => state.isAdmin)
  const openReveal = useInventoryPinStore((state) => state.openReveal)
  const clearAll = useInventoryPinStore((state) => state.clearAll)
  const markAsRead = useMarkAsRead()
  const shownIds = useRef(getShownNotificationIds())
  const { data } = useNotifications(
    { unread_only: true, per_page: 20, type: 'inventory_action_pin' },
    isAuthenticated && isAdmin() ? 5000 : false,
    isAuthenticated && isAdmin(),
  )

  useEffect(() => {
    if (!isAuthenticated) {
      clearAll()
      sessionStorage.removeItem(SHOWN_KEY)
      shownIds.current.clear()
    }
  }, [clearAll, isAuthenticated])

  useEffect(() => {
    if (!isAuthenticated || !isAdmin()) return

    for (const notification of data?.notifications || []) {
      const item = notification.data as Record<string, unknown>
      const expiresAt = String(item.expires_at || '')
      if (item.status !== 'active' || !expiresAt || new Date(expiresAt).getTime() <= Date.now() || shownIds.current.has(notification.id)) continue

      shownIds.current.add(notification.id)
      sessionStorage.setItem(SHOWN_KEY, JSON.stringify([...shownIds.current]))
      toast.info(notification.title || 'Approval PIN available', {
        id: `inventory-pin-${notification.id}`,
        description: String(item.target_label || notification.message || 'A secure approval PIN is ready.'),
        duration: 15000,
        action: {
          label: 'Reveal PIN',
          onClick: () => openReveal({
            challengeId: Number(item.challenge_id),
            actionLabel: String(item.action_label || 'Inventory action'),
            targetLabel: String(item.target_label || ''),
            expiresAt,
            notificationId: notification.id,
          }),
        },
        cancel: {
          label: 'Dismiss',
          onClick: () => markAsRead.mutate(notification.id),
        },
      })
    }
  }, [data, isAdmin, isAuthenticated, markAsRead, openReveal])

  return <InventoryPinRevealDialog />
}
