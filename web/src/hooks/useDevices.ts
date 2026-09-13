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

/**
 * Latest locations for a whole set of devices, in one request.
 *
 * Replaces the per-card `useDeviceLatestLocation` fan-out: a page rendering N
 * device cards used to hold N parallel polling queries on N staggered timers.
 * Here the request count is one regardless of how many devices are shown, so
 * page load no longer degrades as a customer adds devices.
 */
export function useDeviceLatestLocations(ids: number[]) {
  // Dedupe and sort so the query key is derived from the *set* of ids, not the
  // order the caller happened to render them in. Without this, a reorder would
  // produce a new key, miss the cache, and refetch everything.
  const uniqueIds = Array.from(new Set(ids)).sort((a, b) => a - b)
  const idsKey = uniqueIds.join(',')

  return useQuery({
    queryKey: ['device-latest-locations', idsKey],
    queryFn: () => devicesApi.getLatestLocations(uniqueIds),
    enabled: uniqueIds.length > 0,
    // React Query does not poll while the tab is hidden
    // (refetchIntervalInBackground defaults to false), so an idle tab left open
    // on the office wall stops hitting the API.
    refetchInterval: 30000,
    // Hold the previous map while the next batch is in flight, so markers do
    // not blink out of existence every 30 seconds.
    placeholderData: (previous) => previous,
  })
}
