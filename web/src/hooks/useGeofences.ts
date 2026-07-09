import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import geofencesApi, { type CreateGeofenceData, type UpdateGeofenceData } from '@/api/geofences'

export function useGeofences() {
  return useQuery({
    queryKey: ['geofences'],
    queryFn: () => geofencesApi.getGeofences(),
  })
}

export function useDeviceGeofences(deviceId: number) {
  return useQuery({
    queryKey: ['device-geofences', deviceId],
    queryFn: () => geofencesApi.getDeviceGeofences(deviceId),
    enabled: !!deviceId,
  })
}

export function useGeofence(id: number) {
  return useQuery({
    queryKey: ['geofence', id],
    queryFn: () => geofencesApi.getGeofence(id),
    enabled: !!id,
  })
}

export function useCreateGeofence() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: CreateGeofenceData) => geofencesApi.createGeofence(data),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['geofences'] })
      queryClient.invalidateQueries({ queryKey: ['device-geofences', variables.device_id] })
    },
  })
}

export function useUpdateGeofence() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: UpdateGeofenceData }) =>
      geofencesApi.updateGeofence(id, data),
    onSuccess: (_, { id }) => {
      queryClient.invalidateQueries({ queryKey: ['geofences'] })
      queryClient.invalidateQueries({ queryKey: ['geofence', id] })
    },
  })
}

export function useDeleteGeofence() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => geofencesApi.deleteGeofence(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['geofences'] })
    },
  })
}

export function useToggleGeofence() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => geofencesApi.toggleGeofence(id),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: ['geofences'] })
      queryClient.invalidateQueries({ queryKey: ['geofence', id] })
    },
  })
}

export function useGeofenceEvents(id: number) {
  return useQuery({
    queryKey: ['geofence-events', id],
    queryFn: () => geofencesApi.getGeofenceEvents(id),
    enabled: !!id,
  })
}
