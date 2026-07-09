import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import apiClient from '@/api/client'
import type { Shift } from '@/types'

interface ShiftResponse {
  success: boolean
  message: string
  data: Shift | null
}

interface ShiftsResponse {
  success: boolean
  message: string
  data: Shift[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
  links: {
    first: string
    last: string
    prev: string | null
    next: string | null
  }
}

export function useCurrentShift() {
  return useQuery({
    queryKey: ['current-shift'],
    queryFn: async () => {
      const response = await apiClient.get<ShiftResponse>('/customer-service/shifts/current')
      return response.data.data
    },
    refetchInterval: 60000, // Refetch every minute
  })
}

export function useShiftHistory(filters?: { status?: string; date_from?: string; date_to?: string }) {
  const params = new URLSearchParams()
  if (filters?.status) params.append('status', filters.status)
  if (filters?.date_from) params.append('date_from', filters.date_from)
  if (filters?.date_to) params.append('date_to', filters.date_to)

  return useQuery({
    queryKey: ['shifts-history', filters],
    queryFn: async () => {
      const response = await apiClient.get<ShiftsResponse>(
        `/customer-service/shifts/history?${params.toString()}`
      )
      return response.data
    },
  })
}

export function useShift(shiftId: number) {
  return useQuery({
    queryKey: ['shift', shiftId],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: Shift }>(
        `/customer-service/shifts/${shiftId}`
      )
      return response.data.data
    },
    enabled: !!shiftId,
  })
}

export function useStartShift() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (data: { opening_cash?: number; notes?: string }) => {
      const response = await apiClient.post<ShiftResponse>('/customer-service/shifts/start', data)
      return response.data.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['current-shift'] })
      queryClient.invalidateQueries({ queryKey: ['shifts-history'] })
      queryClient.invalidateQueries({ queryKey: ['customer-service-dashboard'] })
    },
  })
}

export function useEndShift() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (data: { closing_cash?: number; notes?: string }) => {
      const response = await apiClient.post<ShiftResponse>('/customer-service/shifts/end', data)
      return response.data.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['current-shift'] })
      queryClient.invalidateQueries({ queryKey: ['shifts-history'] })
      queryClient.invalidateQueries({ queryKey: ['customer-service-dashboard'] })
    },
  })
}

export function useCancelShift() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (data: { notes?: string }) => {
      const response = await apiClient.post<ShiftResponse>('/customer-service/shifts/cancel', data)
      return response.data.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['current-shift'] })
      queryClient.invalidateQueries({ queryKey: ['shifts-history'] })
      queryClient.invalidateQueries({ queryKey: ['customer-service-dashboard'] })
    },
  })
}
