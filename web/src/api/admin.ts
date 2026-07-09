import apiClient, { type ApiResponse, type PaginatedResponse } from './client'
import type { User, Subscription, Payment } from '@/types'

// Dashboard statistics types - matches backend response structure
export interface DashboardStats {
  users: {
    total: number
    new_this_month: number
    new_last_month: number
  }
  devices: {
    total: number
    active: number
    stolen: number
    recovered: number
    with_iot_module: number
  }
  subscriptions: {
    active: number
    total: number
    new_this_month: number
  }
  revenue: {
    total: number
    this_month: number
    last_month: number
  }
  theft_reports: {
    total: number
    active: number
    recovered: number
    recovery_rate: number
  }
  iot_modules: {
    total: number
    active: number
    linked: number
  }
}

export interface UserStats {
  total: number
  by_role: Record<string, number>
  with_subscriptions: number
  with_devices: number
  verified: number
  growth: Record<string, number>
}

export interface DeviceStats {
  total: number
  by_status: Record<string, number>
  by_category: Record<string, number>
  with_iot_module: number
  recently_active: number
  categories: { id: number; name: string; devices_count: number }[]
}

export interface RevenueStats {
  total_revenue: number
  total_transactions: number
  by_status: Record<string, { count: number; total: number }>
  by_method: Record<string, { count: number; total: number }>
  by_plan: Record<string, number>
  trend: Record<string, number>
}

export interface TheftReportStats {
  total: number
  by_status: Record<string, number>
  recovery_rate: number
  recent: { date: string; count: number }[]
}

export interface SubscriptionStats {
  total: number
  active: number
  by_plan: Record<string, number>
  by_status: Record<string, number>
}

export interface AdminUserFilters {
  page?: number
  per_page?: number
  search?: string
  role?: string
  status?: string
}

export const adminApi = {
  // Dashboard
  getDashboardStats: async (): Promise<DashboardStats> => {
    const response = await apiClient.get<ApiResponse<DashboardStats>>('/admin/dashboard')
    return response.data.data
  },

  getUserStats: async (): Promise<UserStats> => {
    const response = await apiClient.get<ApiResponse<UserStats>>('/admin/dashboard/users')
    return response.data.data
  },

  getDeviceStats: async (): Promise<DeviceStats> => {
    const response = await apiClient.get<ApiResponse<DeviceStats>>('/admin/dashboard/devices')
    return response.data.data
  },

  getRevenueStats: async (): Promise<RevenueStats> => {
    const response = await apiClient.get<ApiResponse<RevenueStats>>('/admin/dashboard/revenue')
    return response.data.data
  },

  getTheftReportStats: async (): Promise<TheftReportStats> => {
    const response = await apiClient.get<ApiResponse<TheftReportStats>>('/admin/dashboard/theft-reports')
    return response.data.data
  },

  getSubscriptionStats: async (): Promise<SubscriptionStats> => {
    const response = await apiClient.get<ApiResponse<SubscriptionStats>>('/admin/dashboard/subscriptions')
    return response.data.data
  },

  // Users Management
  getUsers: async (filters: AdminUserFilters = {}): Promise<PaginatedResponse<User>> => {
    const params = new URLSearchParams()
    if (filters.page) params.append('page', String(filters.page))
    if (filters.per_page) params.append('per_page', String(filters.per_page))
    if (filters.search) params.append('search', filters.search)
    if (filters.role) params.append('role', filters.role)
    if (filters.status) params.append('status', filters.status)
    const response = await apiClient.get<PaginatedResponse<User>>(`/admin/users?${params}`)
    return response.data
  },

  getUser: async (id: number): Promise<User> => {
    const response = await apiClient.get<ApiResponse<User>>(`/admin/users/${id}`)
    return response.data.data
  },

  createUser: async (data: Partial<User> & { password: string; role?: string }): Promise<User> => {
    const response = await apiClient.post<ApiResponse<User>>('/admin/users', data)
    return response.data.data
  },

  updateUser: async (id: number, data: Partial<User>): Promise<User> => {
    const response = await apiClient.put<ApiResponse<User>>(`/admin/users/${id}`, data)
    return response.data.data
  },

  deleteUser: async (id: number): Promise<void> => {
    await apiClient.delete(`/admin/users/${id}`)
  },

  suspendUser: async (id: number): Promise<User> => {
    const response = await apiClient.post<ApiResponse<User>>(`/admin/users/${id}/suspend`)
    return response.data.data
  },

  unsuspendUser: async (id: number): Promise<User> => {
    const response = await apiClient.post<ApiResponse<User>>(`/admin/users/${id}/unsuspend`)
    return response.data.data
  },

  assignRole: async (id: number, role: string): Promise<User> => {
    const response = await apiClient.post<ApiResponse<User>>(`/admin/users/${id}/assign-role`, { role })
    return response.data.data
  },

  getRoles: async (): Promise<{ id: number; name: string }[]> => {
    const response = await apiClient.get<ApiResponse<{ id: number; name: string }[]>>('/admin/roles')
    return response.data.data
  },

  // Subscriptions Management
  getSubscriptions: async (filters: { page?: number; status?: string } = {}): Promise<PaginatedResponse<Subscription>> => {
    const params = new URLSearchParams()
    if (filters.page) params.append('page', String(filters.page))
    if (filters.status) params.append('status', filters.status)
    const response = await apiClient.get<PaginatedResponse<Subscription>>(`/admin/subscriptions?${params}`)
    return response.data
  },

  suspendSubscription: async (id: number): Promise<Subscription> => {
    const response = await apiClient.post<ApiResponse<Subscription>>(`/admin/subscriptions/${id}/suspend`)
    return response.data.data
  },

  reactivateSubscription: async (id: number): Promise<Subscription> => {
    const response = await apiClient.post<ApiResponse<Subscription>>(`/admin/subscriptions/${id}/reactivate`)
    return response.data.data
  },

  // Payments
  getPayments: async (filters: { page?: number; status?: string } = {}): Promise<PaginatedResponse<Payment>> => {
    const params = new URLSearchParams()
    if (filters.page) params.append('page', String(filters.page))
    if (filters.status) params.append('status', filters.status)
    const response = await apiClient.get<PaginatedResponse<Payment>>(`/admin/payments?${params}`)
    return response.data
  },

  refundPayment: async (id: number, reason?: string): Promise<Payment> => {
    const response = await apiClient.post<ApiResponse<Payment>>(`/admin/payments/${id}/refund`, { reason })
    return response.data.data
  },

  // Reports
  getUsersReport: async (params?: { from?: string; to?: string }): Promise<unknown> => {
    const searchParams = new URLSearchParams()
    if (params?.from) searchParams.append('from', params.from)
    if (params?.to) searchParams.append('to', params.to)
    const response = await apiClient.get(`/admin/reports/users?${searchParams}`)
    return response.data
  },

  getDevicesReport: async (params?: { from?: string; to?: string }): Promise<unknown> => {
    const searchParams = new URLSearchParams()
    if (params?.from) searchParams.append('from', params.from)
    if (params?.to) searchParams.append('to', params.to)
    const response = await apiClient.get(`/admin/reports/devices?${searchParams}`)
    return response.data
  },

  getRevenueReport: async (params?: { from?: string; to?: string }): Promise<unknown> => {
    const searchParams = new URLSearchParams()
    if (params?.from) searchParams.append('from', params.from)
    if (params?.to) searchParams.append('to', params.to)
    const response = await apiClient.get(`/admin/reports/revenue?${searchParams}`)
    return response.data
  },

  getTheftReport: async (params?: { from?: string; to?: string }): Promise<unknown> => {
    const searchParams = new URLSearchParams()
    if (params?.from) searchParams.append('from', params.from)
    if (params?.to) searchParams.append('to', params.to)
    const response = await apiClient.get(`/admin/reports/theft?${searchParams}`)
    return response.data
  },
}

export default adminApi
