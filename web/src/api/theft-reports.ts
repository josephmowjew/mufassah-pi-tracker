import apiClient, { type ApiResponse, type PaginatedResponse } from './client'
import type { TheftReport, TheftReportStatusValue } from '@/types'

export interface CreateTheftReportData {
  device_id: number
  theft_date: string
  theft_location?: string
  theft_location_latitude?: number
  theft_location_longitude?: number
  description: string
  police_report_number?: string
  police_station?: string
}

export interface UpdateTheftReportData extends Partial<CreateTheftReportData> {
  status?: TheftReportStatusValue
  investigation_notes?: string
}

export interface TheftReportFilters {
  page?: number
  per_page?: number
  status?: TheftReportStatusValue
}

export const theftReportsApi = {
  // Get all theft reports
  getTheftReports: async (filters: TheftReportFilters = {}): Promise<PaginatedResponse<TheftReport>> => {
    const params = new URLSearchParams()
    if (filters.page) params.append('page', String(filters.page))
    if (filters.per_page) params.append('per_page', String(filters.per_page))
    if (filters.status) params.append('status', filters.status)

    const response = await apiClient.get<PaginatedResponse<TheftReport>>(`/theft-reports?${params}`)
    return response.data
  },

  // Get single theft report
  getTheftReport: async (id: number): Promise<TheftReport> => {
    const response = await apiClient.get<ApiResponse<TheftReport>>(`/theft-reports/${id}`)
    return response.data.data
  },

  // Create theft report
  createTheftReport: async (data: CreateTheftReportData): Promise<TheftReport> => {
    const response = await apiClient.post<ApiResponse<TheftReport>>('/theft-reports', data)
    return response.data.data
  },

  // Update theft report
  updateTheftReport: async (id: number, data: UpdateTheftReportData): Promise<TheftReport> => {
    const response = await apiClient.put<ApiResponse<TheftReport>>(`/theft-reports/${id}`, data)
    return response.data.data
  },

  // Cancel theft report
  cancelTheftReport: async (id: number): Promise<TheftReport> => {
    const response = await apiClient.post<ApiResponse<TheftReport>>(`/theft-reports/${id}/cancel`)
    return response.data.data
  },

  // Upload documents
  uploadDocuments: async (id: number, documents: File[]): Promise<TheftReport> => {
    const formData = new FormData()
    documents.forEach((doc) => formData.append('documents[]', doc))
    const response = await apiClient.post<ApiResponse<TheftReport>>(`/theft-reports/${id}/documents`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return response.data.data
  },
}

export default theftReportsApi
