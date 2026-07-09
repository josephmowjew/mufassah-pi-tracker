import axios, { type AxiosError, type InternalAxiosRequestConfig } from 'axios'

const API_URL = import.meta.env.VITE_API_URL || '/api/v1'
const API_BASE_URL = API_URL.replace('/api/v1', '') || ''

// Helper to construct storage URLs for images/files
export function getStorageUrl(path: string | null | undefined): string {
  if (!path) return ''
  // If it's already a full URL, return as is
  if (path.startsWith('http://') || path.startsWith('https://')) {
    return path
  }
  // Construct the full storage URL
  return `${API_BASE_URL}/storage/${path}`
}

// Main API client for API requests
export const apiClient = axios.create({
  baseURL: API_URL,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  withCredentials: true,
  withXSRFToken: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
})

// Base client for CSRF cookie endpoint
const baseClient = axios.create({
  baseURL: API_BASE_URL,
  withCredentials: true,
})

// Track if CSRF cookie has been fetched
let csrfInitialized = false

// Function to fetch CSRF cookie
export async function initializeCsrf(): Promise<void> {
  if (csrfInitialized) return

  try {
    await baseClient.get('/sanctum/csrf-cookie')
    csrfInitialized = true
  } catch (error) {
    console.error('Failed to fetch CSRF cookie:', error)
    throw error
  }
}

// Request interceptor to add auth token and ensure CSRF
apiClient.interceptors.request.use(
  async (config: InternalAxiosRequestConfig) => {
    // Add auth token if available
    const token = localStorage.getItem('auth_token')
    if (token && config.headers) {
      config.headers.Authorization = `Bearer ${token}`
    }

    // Ensure CSRF cookie is fetched for state-changing methods
    const methodsNeedingCsrf = ['post', 'put', 'patch', 'delete']
    if (config.method && methodsNeedingCsrf.includes(config.method.toLowerCase())) {
      await initializeCsrf()
    }

    return config
  },
  (error) => Promise.reject(error)
)

// Response interceptor for error handling
apiClient.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    // Handle 401 Unauthorized - redirect to login
    if (error.response?.status === 401) {
      localStorage.removeItem('auth_token')
      localStorage.removeItem('auth-storage')
      // Only redirect if not already on auth pages
      if (!window.location.pathname.startsWith('/login') &&
          !window.location.pathname.startsWith('/register')) {
        window.location.href = '/login'
      }
    }

    // Handle 403 Forbidden
    if (error.response?.status === 403) {
      console.error('Access forbidden')
    }

    // Handle 419 CSRF token mismatch - refetch and retry once
    if (error.response?.status === 419) {
      csrfInitialized = false
      console.warn('CSRF token mismatch, refetching...')
    }

    // Handle 422 Validation errors
    if (error.response?.status === 422) {
      return Promise.reject(error)
    }

    // Handle 500 Server errors
    if (error.response?.status === 500) {
      console.error('Server error occurred')
    }

    return Promise.reject(error)
  }
)

// Type-safe API response
export interface ApiResponse<T> {
  success: boolean
  message?: string
  data: T
}

export interface PaginatedResponse<T> {
  success: boolean
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number
    to: number
  }
  links: {
    first: string
    last: string
    prev: string | null
    next: string | null
  }
}

export interface ApiError {
  success: false
  message: string
  errors?: Record<string, string[]>
}

export default apiClient
