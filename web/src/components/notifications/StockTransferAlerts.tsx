import { useEffect, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { useNotifications } from '@/hooks/useNotifications'

export default function StockTransferAlerts() {
  const navigate = useNavigate()
  const knownIds = useRef<Set<string> | null>(null)
  const { data } = useNotifications({ unread_only: true, per_page: 20 })

  useEffect(() => {
    const notifications = data?.notifications || []
    const current = new Set(notifications.map((notification) => notification.id))
    if (knownIds.current) {
      notifications
        .filter((notification) => !knownIds.current?.has(notification.id) && notification.type === 'stock_transfer_request')
        .forEach((notification) => toast.info(notification.title || 'Stock transfer request', {
          description: notification.message,
          action: { label: 'Review', onClick: () => navigate('/admin/stock-movements') },
        }))
    }
    knownIds.current = current
  }, [data, navigate])

  return null
}
