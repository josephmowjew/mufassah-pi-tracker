import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import adminApi, { type AdminUserFilters } from '@/api/admin'

// Dashboard Stats
export function useAdminDashboard() {
  return useQuery({
    queryKey: ['admin-dashboard'],
    queryFn: () => adminApi.getDashboardStats(),
    staleTime: 1000 * 60 * 5, // 5 minutes
  })
}

export function useAdminUserStats() {
  return useQuery({
    queryKey: ['admin-user-stats'],
    queryFn: () => adminApi.getUserStats(),
    staleTime: 1000 * 60 * 5,
  })
}

export function useAdminDeviceStats() {
  return useQuery({
    queryKey: ['admin-device-stats'],
    queryFn: () => adminApi.getDeviceStats(),
    staleTime: 1000 * 60 * 5,
  })
}

export function useAdminRevenueStats() {
  return useQuery({
    queryKey: ['admin-revenue-stats'],
    queryFn: () => adminApi.getRevenueStats(),
    staleTime: 1000 * 60 * 5,
  })
}

export function useAdminTheftReportStats() {
  return useQuery({
    queryKey: ['admin-theft-stats'],
    queryFn: () => adminApi.getTheftReportStats(),
    staleTime: 1000 * 60 * 5,
  })
}

export function useAdminSubscriptionStats() {
  return useQuery({
    queryKey: ['admin-subscription-stats'],
    queryFn: () => adminApi.getSubscriptionStats(),
    staleTime: 1000 * 60 * 5,
  })
}

// Users Management
export function useAdminUsers(filters: AdminUserFilters = {}) {
  return useQuery({
    queryKey: ['admin-users', filters],
    queryFn: () => adminApi.getUsers(filters),
  })
}

export function useAdminUser(id: number) {
  return useQuery({
    queryKey: ['admin-user', id],
    queryFn: () => adminApi.getUser(id),
    enabled: !!id,
  })
}

export function useAdminRoles() {
  return useQuery({
    queryKey: ['admin-roles'],
    queryFn: () => adminApi.getRoles(),
    staleTime: 1000 * 60 * 30, // 30 minutes
  })
}

export function useCreateAdminUser() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: Parameters<typeof adminApi.createUser>[0]) => adminApi.createUser(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-users'] })
      queryClient.invalidateQueries({ queryKey: ['admin-dashboard'] })
    },
  })
}

export function useUpdateAdminUser() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: Parameters<typeof adminApi.updateUser>[1] }) =>
      adminApi.updateUser(id, data),
    onSuccess: (_, { id }) => {
      queryClient.invalidateQueries({ queryKey: ['admin-users'] })
      queryClient.invalidateQueries({ queryKey: ['admin-user', id] })
    },
  })
}

export function useDeleteAdminUser() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => adminApi.deleteUser(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-users'] })
      queryClient.invalidateQueries({ queryKey: ['admin-dashboard'] })
    },
  })
}

export function useSuspendUser() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => adminApi.suspendUser(id),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: ['admin-users'] })
      queryClient.invalidateQueries({ queryKey: ['admin-user', id] })
    },
  })
}

export function useUnsuspendUser() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => adminApi.unsuspendUser(id),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: ['admin-users'] })
      queryClient.invalidateQueries({ queryKey: ['admin-user', id] })
    },
  })
}

export function useAssignRole() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, role }: { id: number; role: string }) => adminApi.assignRole(id, role),
    onSuccess: (_, { id }) => {
      queryClient.invalidateQueries({ queryKey: ['admin-users'] })
      queryClient.invalidateQueries({ queryKey: ['admin-user', id] })
    },
  })
}

// Subscriptions Management
export function useAdminSubscriptions(filters: { page?: number; status?: string } = {}) {
  return useQuery({
    queryKey: ['admin-subscriptions', filters],
    queryFn: () => adminApi.getSubscriptions(filters),
  })
}

export function useSuspendSubscription() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => adminApi.suspendSubscription(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-subscriptions'] })
      queryClient.invalidateQueries({ queryKey: ['admin-dashboard'] })
    },
  })
}

export function useReactivateSubscription() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => adminApi.reactivateSubscription(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-subscriptions'] })
      queryClient.invalidateQueries({ queryKey: ['admin-dashboard'] })
    },
  })
}

// Payments
export function useAdminPayments(filters: { page?: number; status?: string } = {}) {
  return useQuery({
    queryKey: ['admin-payments', filters],
    queryFn: () => adminApi.getPayments(filters),
  })
}

export function useRefundPayment() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) => adminApi.refundPayment(id, reason),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-payments'] })
      queryClient.invalidateQueries({ queryKey: ['admin-dashboard'] })
    },
  })
}
