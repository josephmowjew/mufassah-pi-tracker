import apiClient, { type ApiResponse } from './client'
import type { Geofence, GeofenceEvent } from '@/types'

export interface CreateGeofenceData {
  device_id: number
  name: string
  type: 'circle' | 'polygon'
  center_latitude: number
  center_longitude: number
  radius_meters: number
  coordinates?: { lat: number; lng: number }[]
  is_active?: boolean
  notify_on_entry?: boolean
  notify_on_exit?: boolean
}

export interface UpdateGeofenceData extends Partial<CreateGeofenceData> {}

export const geofencesApi = {
  // Get all geofences for current user
  getGeofences: async (): Promise<Geofence[]> => {
    const response = await apiClient.get<ApiResponse<Geofence[]>>('/geofences')
    return response.data.data
  },

  // Get geofences for a specific device
  getDeviceGeofences: async (deviceId: number): Promise<Geofence[]> => {
    const response = await apiClient.get<ApiResponse<Geofence[]>>(`/devices/${deviceId}/geofences`)
    return response.data.data
  },

  // Get single geofence
  getGeofence: async (id: number): Promise<Geofence> => {
    const response = await apiClient.get<ApiResponse<Geofence>>(`/geofences/${id}`)
    return response.data.data
  },

  // Create geofence
  createGeofence: async (data: CreateGeofenceData): Promise<Geofence> => {
    const response = await apiClient.post<ApiResponse<Geofence>>('/geofences', data)
    return response.data.data
  },

  // Update geofence
  updateGeofence: async (id: number, data: UpdateGeofenceData): Promise<Geofence> => {
    const response = await apiClient.put<ApiResponse<Geofence>>(`/geofences/${id}`, data)
    return response.data.data
  },

  // Delete geofence
  deleteGeofence: async (id: number): Promise<void> => {
    await apiClient.delete(`/geofences/${id}`)
  },

  // Toggle geofence active status
  toggleGeofence: async (id: number): Promise<Geofence> => {
    const response = await apiClient.post<ApiResponse<Geofence>>(`/geofences/${id}/toggle`)
    return response.data.data
  },

  // Get geofence events
  getGeofenceEvents: async (id: number): Promise<GeofenceEvent[]> => {
    const response = await apiClient.get<ApiResponse<GeofenceEvent[]>>(`/geofences/${id}/events`)
    return response.data.data
  },
}

export default geofencesApi
