import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import devicesApi, { type CreateDeviceData, type UpdateDeviceData, type DeviceFilters } from '@/api/devices'

export function useDevices(filters: DeviceFilters = {}) {
  return useQuery({
    queryKey: ['devices', filters],
    queryFn: () => devicesApi.getDevices(filters),
  })
}

export function useDevice(id: number) {
  return useQuery({
    queryKey: ['device', id],
    queryFn: () => devicesApi.getDevice(id),
    enabled: !!id,
  })
}

export function useDeviceCategories() {
  return useQuery({
    queryKey: ['device-categories'],
    queryFn: () => devicesApi.getCategories(),
    staleTime: 1000 * 60 * 30, // 30 minutes
  })
}

export function useCreateDevice() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: CreateDeviceData) => devicesApi.createDevice(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['devices'] })
    },
  })
}

export function useUpdateDevice() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: UpdateDeviceData }) =>
      devicesApi.updateDevice(id, data),
    onSuccess: (_, { id }) => {
      queryClient.invalidateQueries({ queryKey: ['devices'] })
      queryClient.invalidateQueries({ queryKey: ['device', id] })
    },
  })
}

export function useDeleteDevice() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => devicesApi.deleteDevice(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['devices'] })
    },
  })
}

export function useUpdateDeviceStatus() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) =>
      devicesApi.updateStatus(id, status),
    onSuccess: (_, { id }) => {
      queryClient.invalidateQueries({ queryKey: ['devices'] })
      queryClient.invalidateQueries({ queryKey: ['device', id] })
    },
  })
}

export function useUploadDevicePhotos() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, photos }: { id: number; photos: File[] }) =>
      devicesApi.uploadPhotos(id, photos),
    onSuccess: (_, { id }) => {
      queryClient.invalidateQueries({ queryKey: ['device', id] })
    },
  })
}

export function useDeleteDevicePhoto() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, photoIndex }: { id: number; photoIndex: number }) =>
      devicesApi.deletePhoto(id, photoIndex),
    onSuccess: (_, { id }) => {
      queryClient.invalidateQueries({ queryKey: ['device', id] })
    },
  })
}

export function useDeviceLocations(id: number, params?: { from?: string; to?: string }) {
  return useQuery({
    queryKey: ['device-locations', id, params],
    queryFn: () => devicesApi.getLocations(id, params),
    enabled: !!id,
  })
}

export function useDeviceLatestLocation(id: number) {
  return useQuery({
    queryKey: ['device-latest-location', id],
    queryFn: () => devicesApi.getLatestLocation(id),
    enabled: !!id,
    refetchInterval: 30000, // Refetch every 30 seconds
  })
}
