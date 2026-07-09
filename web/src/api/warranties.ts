import apiClient, { type ApiResponse, type PaginatedResponse } from './client'
import type { Warranty, WarrantyClaim } from '@/types'

export interface WarrantyFilters {
  page?: number
  per_page?: number
  status?: string
  device_id?: number
}

export interface CreateWarrantyClaimData {
  issue_description: string
  documents?: File[]
}

export const warrantiesApi = {
  // Get all warranties for current user
  getWarranties: async (filters: WarrantyFilters = {}): Promise<PaginatedResponse<Warranty>> => {
    const params = new URLSearchParams()
    if (filters.page) params.append('page', String(filters.page))
    if (filters.per_page) params.append('per_page', String(filters.per_page))
    if (filters.status) params.append('status', filters.status)
    if (filters.device_id) params.append('device_id', String(filters.device_id))
    const response = await apiClient.get<PaginatedResponse<Warranty>>(`/warranties?${params}`)
    return response.data
  },

  // Get single warranty
  getWarranty: async (id: number): Promise<Warranty> => {
    const response = await apiClient.get<ApiResponse<Warranty>>(`/warranties/${id}`)
    return response.data.data
  },

  // Get warranty claims
  getWarrantyClaims: async (warrantyId: number): Promise<WarrantyClaim[]> => {
    const response = await apiClient.get<ApiResponse<WarrantyClaim[]>>(`/warranties/${warrantyId}/claims`)
    return response.data.data
  },

  // Submit warranty claim
  submitClaim: async (warrantyId: number, data: CreateWarrantyClaimData): Promise<WarrantyClaim> => {
    const formData = new FormData()
    formData.append('issue_description', data.issue_description)
    if (data.documents) {
      data.documents.forEach((doc) => formData.append('documents[]', doc))
    }
    const response = await apiClient.post<ApiResponse<WarrantyClaim>>(
      `/warranties/${warrantyId}/claims`,
      formData,
      { headers: { 'Content-Type': 'multipart/form-data' } }
    )
    return response.data.data
  },
}

export default warrantiesApi
