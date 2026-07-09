import apiClient, { type ApiResponse, type PaginatedResponse } from './client'
import type { Device, DeviceStatus, DeviceStatusValue, Geofence, Notification } from '@/types'

// Helper to extract status value from either string or object format
function getStatusValue(status: DeviceStatus): DeviceStatusValue {
  if (typeof status === 'string') return status
  return status.value
}

export interface DashboardStats {
  totalDevices: number
  activeDevices: number
  geofencesCount: number
  alertsToday: number
  recentDevices: Device[]
  recentNotifications: Notification[]
}

export const dashboardApi = {
  // Get dashboard stats by aggregating multiple API calls
  getStats: async (): Promise<DashboardStats> => {
    // Fetch devices, geofences, and notifications in parallel
    const [devicesResponse, geofencesResponse, notificationsResponse] = await Promise.all([
      apiClient.get<PaginatedResponse<Device>>('/devices?per_page=5'),
      apiClient.get<ApiResponse<Geofence[]>>('/geofences'),
      apiClient.get<ApiResponse<{ notifications: Notification[]; unread_count: number }>>('/notifications?per_page=10'),
    ])

    const devices = devicesResponse.data
    const geofences = geofencesResponse.data.data || []
    const notifications = notificationsResponse.data.data?.notifications || []

    // Count active devices
    const activeDevices = devices.data?.filter(d => getStatusValue(d.status) === 'active').length || 0

    // Count today's alerts (notifications from today)
    const today = new Date()
    today.setHours(0, 0, 0, 0)
    const alertsToday = notifications.filter(n => {
      const notifDate = new Date(n.created_at)
      return notifDate >= today
    }).length

    return {
      totalDevices: devices.meta?.total || devices.data?.length || 0,
      activeDevices,
      geofencesCount: geofences.length,
      alertsToday,
      recentDevices: devices.data?.slice(0, 5) || [],
      recentNotifications: notifications.slice(0, 5),
    }
  },

  // Get recent activity/notifications
  getRecentActivity: async (limit: number = 10): Promise<Notification[]> => {
    const response = await apiClient.get<ApiResponse<{ notifications: Notification[] }>>(`/notifications?per_page=${limit}`)
    return response.data.data?.notifications || []
  },
}

export default dashboardApi
