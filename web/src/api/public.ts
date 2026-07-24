import apiClient, { type ApiResponse } from './client'
import type { Vacancy } from '@/types'

export interface PublicBranch {
  id: number
  name: string
  address: string | null
  city: string | null
  phone: string | null
  email: string | null
}

export async function getPublicBranches(): Promise<PublicBranch[]> {
  const response = await apiClient.get<ApiResponse<PublicBranch[]>>('/public/branches')
  return response.data.data
}

export async function getPublicVacancies(): Promise<Vacancy[]> {
  const response = await apiClient.get<ApiResponse<Vacancy[]>>('/public/vacancies')
  return response.data.data
}
