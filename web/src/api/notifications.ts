import apiClient, { type ApiResponse } from './client'
import type { Notification } from '@/types'

export interface NotificationsResponse {
  notifications: Notification[]
  unread_count: number
}

export interface NotificationFilters {
  page?: number
  per_page?: number
  unread_only?: boolean
}

export const notificationsApi = {
  // Get all notifications
  getNotifications: async (filters: NotificationFilters = {}): Promise<NotificationsResponse> => {
    const params = new URLSearchParams()
    if (filters.page) params.append('page', String(filters.page))
    if (filters.per_page) params.append('per_page', String(filters.per_page))
    if (filters.unread_only) params.append('unread_only', 'true')

    const response = await apiClient.get<ApiResponse<NotificationsResponse>>(`/notifications?${params}`)
    const payload = response.data.data
    const notifications = Array.isArray(payload.notifications)
      ? payload.notifications
      : ((payload.notifications as unknown as { data?: Notification[] })?.data || [])
    return { ...payload, notifications }
  },

  // Get unread count
  getUnreadCount: async (): Promise<number> => {
    const response = await apiClient.get<ApiResponse<{ unread_count: number }>>('/notifications/unread-count')
    return response.data.data.unread_count
  },

  // Mark notification as read
  markAsRead: async (id: string): Promise<void> => {
    await apiClient.post(`/notifications/${id}/read`)
  },

  // Mark all as read
  markAllAsRead: async (): Promise<void> => {
    await apiClient.post('/notifications/read-all')
  },

  // Delete notification
  deleteNotification: async (id: string): Promise<void> => {
    await apiClient.delete(`/notifications/${id}`)
  },
}

export default notificationsApi
