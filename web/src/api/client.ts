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
  timeout: 10000,
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
    // Network errors and timeouts have no response object
    if (!error.response) {
      if (error.code === 'ECONNABORTED') {
        console.error('Request timed out')
      } else {
        console.error('Network error — you appear to be offline')
      }
      return Promise.reject(error)
    }

    // Handle 401 Unauthorized - redirect to login
    if (error.response.status === 401) {
      localStorage.removeItem('auth_token')
      localStorage.removeItem('auth-storage')
      // Only redirect if not already on auth pages
      if (!window.location.pathname.startsWith('/login') &&
          !window.location.pathname.startsWith('/register')) {
        window.location.href = '/login'
      }
    }

    // Handle 403 Forbidden
    if (error.response.status === 403) {
      // An unverified email is a recoverable state, not a permission denial: the
      // API returns EMAIL_NOT_VERIFIED so the UI can offer to resend the link
      // rather than showing a dead end. Pair with authApi.resendVerification().
      if (isEmailNotVerifiedError(error)) {
        console.warn('Email address is not verified')
      } else {
        console.error('Access forbidden')
      }
    }

    // Handle 419 CSRF token mismatch - refetch and retry once
    if (error.response.status === 419) {
      const originalConfig = error.config as InternalAxiosRequestConfig & { _retryCount?: number }
      if (!originalConfig) return Promise.reject(error)

      // Prevent infinite retry loops
      if (originalConfig._retryCount) {
        return Promise.reject(error)
      }
      originalConfig._retryCount = 1

      csrfInitialized = false
      console.warn('CSRF token mismatch, refetching...')

      try {
        await initializeCsrf()
        return apiClient.request(originalConfig)
      } catch (retryError) {
        return Promise.reject(retryError)
      }
    }

    // Handle 422 Validation errors
    if (error.response.status === 422) {
      return Promise.reject(error)
    }

    // Handle 500 Server errors
    if (error.response.status === 500) {
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
  /** Machine-readable discriminator, e.g. `EMAIL_NOT_VERIFIED`. */
  code?: string
  errors?: Record<string, string[]>
}

/**
 * True when the API refused the request because the account's email address is
 * not verified (HTTP 403 with `code: 'EMAIL_NOT_VERIFIED'`).
 *
 * The response also carries `resend_endpoint` and `status_endpoint`; pair this
 * with `authApi.resendVerification()` so the user has a way forward.
 */
export function isEmailNotVerifiedError(error: unknown): boolean {
  if (!axios.isAxiosError(error)) return false

  return (
    error.response?.status === 403 &&
    (error.response.data as { code?: string } | undefined)?.code === 'EMAIL_NOT_VERIFIED'
  )
}

export default apiClient
