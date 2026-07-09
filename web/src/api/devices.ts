import apiClient, { type ApiResponse, type PaginatedResponse } from './client'
import type { Device, DeviceCategory, DeviceLocation } from '@/types'

export interface CreateDeviceData {
  category_id: number
  serial_number: string  // Required
  brand: string          // Required
  model: string          // Required
  color?: string
  purchase_date?: string
  purchase_price?: number
  purchase_location?: string
  description?: string
  notes?: string
}

export interface UpdateDeviceData extends Partial<CreateDeviceData> {
  status?: 'active' | 'inactive'
}

export interface DeviceFilters {
  page?: number
  per_page?: number
  status?: string
  category_id?: number
  search?: string
}

export const devicesApi = {
  // Get all devices with pagination and filters
  getDevices: async (filters: DeviceFilters = {}): Promise<PaginatedResponse<Device>> => {
    const params = new URLSearchParams()
    if (filters.page) params.append('page', String(filters.page))
    if (filters.per_page) params.append('per_page', String(filters.per_page))
    if (filters.status) params.append('status', filters.status)
    if (filters.category_id) params.append('category_id', String(filters.category_id))
    if (filters.search) params.append('search', filters.search)

    const response = await apiClient.get<PaginatedResponse<Device>>(`/devices?${params}`)
    return response.data
  },

  // Get single device
  getDevice: async (id: number): Promise<Device> => {
    const response = await apiClient.get<ApiResponse<Device>>(`/devices/${id}`)
    return response.data.data
  },

  // Create device
  createDevice: async (data: CreateDeviceData): Promise<Device> => {
    const response = await apiClient.post<ApiResponse<Device>>('/devices', data)
    return response.data.data
  },

  // Update device
  updateDevice: async (id: number, data: UpdateDeviceData): Promise<Device> => {
    const response = await apiClient.put<ApiResponse<Device>>(`/devices/${id}`, data)
    return response.data.data
  },

  // Delete device
  deleteDevice: async (id: number): Promise<void> => {
    await apiClient.delete(`/devices/${id}`)
  },

  // Update device status
  updateStatus: async (id: number, status: string): Promise<Device> => {
    const response = await apiClient.put<ApiResponse<Device>>(`/devices/${id}/status`, { status })
    return response.data.data
  },

  // Upload photos
  uploadPhotos: async (id: number, photos: File[]): Promise<Device> => {
    const formData = new FormData()
    photos.forEach((photo) => formData.append('photos[]', photo))
    const response = await apiClient.post<ApiResponse<Device>>(`/devices/${id}/photos`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return response.data.data
  },

  // Delete photo by index
  deletePhoto: async (id: number, photoIndex: number): Promise<void> => {
    await apiClient.delete(`/devices/${id}/photos`, {
      data: { photo_index: photoIndex },
    })
  },

  // Get device locations
  getLocations: async (id: number, params?: { from?: string; to?: string }): Promise<DeviceLocation[]> => {
    const searchParams = new URLSearchParams()
    if (params?.from) searchParams.append('from', params.from)
    if (params?.to) searchParams.append('to', params.to)
    const response = await apiClient.get<ApiResponse<DeviceLocation[]>>(`/devices/${id}/locations?${searchParams}`)
    return response.data.data
  },

  // Get latest location
  getLatestLocation: async (id: number): Promise<DeviceLocation | null> => {
    const response = await apiClient.get<ApiResponse<DeviceLocation | null>>(`/devices/${id}/locations/latest`)
    return response.data.data
  },

  // Get categories
  getCategories: async (options?: { withStock?: boolean }): Promise<DeviceCategory[]> => {
    const params = new URLSearchParams()
    if (options?.withStock) params.append('with_stock', 'true')
    const url = params.toString() ? `/device-categories?${params}` : '/device-categories'
    const response = await apiClient.get<ApiResponse<DeviceCategory[]>>(url)
    return response.data.data
  },
}

export default devicesApi
