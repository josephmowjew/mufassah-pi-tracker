import apiClient, { type ApiResponse } from './client'
import type { User, AuthResponse, LoginCredentials, RegisterData } from '@/types'

export interface ForgotPasswordData {
  email: string
}

export interface ResetPasswordData {
  email: string
  token: string
  password: string
  password_confirmation: string
}

export interface ChangePasswordData {
  current_password: string
  password: string
  password_confirmation: string
}

export interface UpdateProfileData {
  name?: string
  phone_number?: string
  address?: string
  city?: string
}

// Auth API functions
export const authApi = {
  login: async (credentials: LoginCredentials): Promise<AuthResponse> => {
    const response = await apiClient.post<ApiResponse<AuthResponse>>('/auth/login', credentials)
    return response.data.data
  },

  register: async (data: RegisterData): Promise<AuthResponse> => {
    const response = await apiClient.post<ApiResponse<AuthResponse>>('/auth/register', data)
    return response.data.data
  },

  logout: async (): Promise<void> => {
    await apiClient.post('/auth/logout')
  },

  refreshToken: async (): Promise<AuthResponse> => {
    const response = await apiClient.post<ApiResponse<AuthResponse>>('/auth/refresh')
    return response.data.data
  },

  forgotPassword: async (data: ForgotPasswordData): Promise<{ message: string }> => {
    const response = await apiClient.post<ApiResponse<null>>('/auth/forgot-password', data)
    return { message: response.data.message || 'Password reset link sent' }
  },

  resetPassword: async (data: ResetPasswordData): Promise<{ message: string }> => {
    const response = await apiClient.post<ApiResponse<null>>('/auth/reset-password', data)
    return { message: response.data.message || 'Password reset successfully' }
  },

  verifyEmail: async (id: number, hash: string, expires: string, signature: string): Promise<{ message: string }> => {
    const response = await apiClient.get<ApiResponse<null>>(
      `/auth/email/verify/${id}/${hash}?expires=${expires}&signature=${signature}`
    )
    return { message: response.data.message || 'Email verified successfully' }
  },

  resendVerification: async (): Promise<{ message: string }> => {
    const response = await apiClient.post<ApiResponse<null>>('/auth/email/resend')
    return { message: response.data.message || 'Verification email sent' }
  },

  getUser: async (): Promise<User> => {
    const response = await apiClient.get<ApiResponse<User>>('/user')
    return response.data.data
  },

  updateProfile: async (data: UpdateProfileData): Promise<User> => {
    const response = await apiClient.put<ApiResponse<User>>('/user', data)
    return response.data.data
  },

  changePassword: async (data: ChangePasswordData): Promise<{ message: string }> => {
    const response = await apiClient.put<ApiResponse<null>>('/user/password', data)
    return { message: response.data.message || 'Password changed successfully' }
  },

  uploadAvatar: async (file: File): Promise<User> => {
    const formData = new FormData()
    formData.append('avatar', file)
    const response = await apiClient.post<ApiResponse<User>>('/user/avatar', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return response.data.data
  },

  deleteAvatar: async (): Promise<User> => {
    const response = await apiClient.delete<ApiResponse<User>>('/user/avatar')
    return response.data.data
  },
}

export default authApi
