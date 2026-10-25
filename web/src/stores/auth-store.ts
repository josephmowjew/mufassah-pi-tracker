import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { clearSessionCache } from '@/lib/query-client'
import type { User } from '@/types'

// Role constants
export const ROLES = {
  SUPER_ADMIN: 'super_admin',
  STORE_ADMIN: 'store_admin',
  CUSTOMER_SERVICE: 'customer_service',
  CUSTOMER: 'customer',
} as const

// Permission constants (matching backend)
export const PERMISSIONS = {
  // Device permissions
  VIEW_DEVICES: 'view-devices',
  VIEW_ALL_DEVICES: 'view-all-devices',
  CREATE_DEVICES: 'create-devices',
  UPDATE_DEVICES: 'update-devices',
  DELETE_DEVICES: 'delete-devices',
  MANAGE_DEVICE_STATUS: 'manage-device-status',
  TRANSFER_DEVICE: 'transfer-device',

  // Location permissions
  VIEW_LOCATIONS: 'view-locations',
  VIEW_ALL_LOCATIONS: 'view-all-locations',
  CREATE_LOCATIONS: 'create-locations',

  // Geofence permissions
  VIEW_GEOFENCES: 'view-geofences',
  VIEW_ALL_GEOFENCES: 'view-all-geofences',
  CREATE_GEOFENCES: 'create-geofences',
  UPDATE_GEOFENCES: 'update-geofences',
  DELETE_GEOFENCES: 'delete-geofences',

  // User management permissions
  VIEW_USERS: 'view-users',
  CREATE_USERS: 'create-users',
  UPDATE_USERS: 'update-users',
  DELETE_USERS: 'delete-users',
  MANAGE_USER_ROLES: 'manage-user-roles',
  SUSPEND_USERS: 'suspend-users',

  // Theft report permissions
  VIEW_THEFT_REPORTS: 'view-theft-reports',
  VIEW_ALL_THEFT_REPORTS: 'view-all-theft-reports',
  CREATE_THEFT_REPORTS: 'create-theft-reports',
  UPDATE_THEFT_REPORTS: 'update-theft-reports',
  MANAGE_THEFT_INVESTIGATIONS: 'manage-theft-investigations',

  // Subscription & payment permissions
  VIEW_SUBSCRIPTIONS: 'view-subscriptions',
  VIEW_ALL_SUBSCRIPTIONS: 'view-all-subscriptions',
  MANAGE_SUBSCRIPTIONS: 'manage-subscriptions',
  VIEW_PAYMENTS: 'view-payments',
  VIEW_ALL_PAYMENTS: 'view-all-payments',
  PROCESS_PAYMENTS: 'process-payments',

  // IoT module permissions
  VIEW_IOT_MODULES: 'view-iot-modules',
  VIEW_ALL_IOT_MODULES: 'view-all-iot-modules',
  CREATE_IOT_MODULES: 'create-iot-modules',
  UPDATE_IOT_MODULES: 'update-iot-modules',
  DELETE_IOT_MODULES: 'delete-iot-modules',
  ASSIGN_IOT_MODULES: 'assign-iot-modules',

  // Sales & Inventory permissions
  VIEW_SALES: 'view-sales',
  MANAGE_SALES: 'manage-sales',
  CREATE_SALES: 'create-sales',
  COMPLETE_SALES: 'complete-sales',
  CANCEL_SALES: 'cancel-sales',
  REFUND_SALES: 'refund-sales',
  DELETE_SALES: 'delete-sales',
  VIEW_INVENTORY: 'view-inventory',
  MANAGE_INVENTORY: 'manage-inventory',
  VIEW_STOCK_MOVEMENTS: 'view-stock-movements',
  MANAGE_STOCK_MOVEMENTS: 'manage-stock-movements',

  // Warranty permissions
  VIEW_WARRANTIES: 'view-warranties',
  MANAGE_WARRANTIES: 'manage-warranties',

  // Admin permissions
  ACCESS_ADMIN_PANEL: 'access-admin-panel',
  VIEW_ANALYTICS: 'view-analytics',
  MANAGE_SETTINGS: 'manage-settings',
  VIEW_AUDIT_LOGS: 'view-audit-logs',

  // Community permissions
  VIEW_COMMUNITY_ALERTS: 'view-community-alerts',
  MANAGE_COMMUNITY_ALERTS: 'manage-community-alerts',
  VERIFY_TIPS: 'verify-tips',
  MANAGE_BOUNTY_CLAIMS: 'manage-bounty-claims',
  MANAGE_SUCCESS_STORIES: 'manage-success-stories',
} as const

interface AuthState {
  user: User | null
  token: string | null
  isAuthenticated: boolean
  isLoading: boolean
  setUser: (user: User | null) => void
  setToken: (token: string | null) => void
  login: (user: User, token: string) => void
  logout: () => void
  setLoading: (loading: boolean) => void
  // Role checks
  hasRole: (role: string | string[]) => boolean
  isAdmin: () => boolean
  isSuperAdmin: () => boolean
  isCustomer: () => boolean
  isCustomerService: () => boolean
  // Permission checks
  hasPermission: (permission: string | string[]) => boolean
  hasAnyPermission: (permissions: string[]) => boolean
  hasAllPermissions: (permissions: string[]) => boolean
  // Utility
  getUserRoles: () => string[]
  getUserPermissions: () => string[]
}

// Helper to extract role names from user roles (handles both string[] and Role[])
const extractRoleNames = (roles: User['roles']): string[] => {
  if (!roles) return []
  return roles.map((r) => (typeof r === 'string' ? r : r.name))
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      user: null,
      token: null,
      isAuthenticated: false,
      isLoading: false,

      setUser: (user) => set({ user, isAuthenticated: !!user }),

      setToken: (token) => {
        if (token) {
          localStorage.setItem('auth_token', token)
        } else {
          localStorage.removeItem('auth_token')
        }
        set({ token })
      },

      login: (user, token) => {
        clearSessionCache()
        localStorage.setItem('auth_token', token)
        set({ user, token, isAuthenticated: true, isLoading: false })
      },

      logout: () => {
        localStorage.removeItem('auth_token')
        clearSessionCache()
        set({ user: null, token: null, isAuthenticated: false })
      },

      setLoading: (isLoading) => set({ isLoading }),

      // Role checks
      hasRole: (role) => {
        const { user } = get()
        if (!user || !user.roles) return false
        const userRoles = extractRoleNames(user.roles)
        const checkRoles = Array.isArray(role) ? role : [role]
        return userRoles.some((r) => checkRoles.includes(r))
      },

      isAdmin: () => {
        const { hasRole } = get()
        return hasRole([ROLES.SUPER_ADMIN, ROLES.STORE_ADMIN])
      },

      isSuperAdmin: () => {
        const { hasRole } = get()
        return hasRole(ROLES.SUPER_ADMIN)
      },

      isCustomer: () => {
        const { hasRole } = get()
        return hasRole(ROLES.CUSTOMER)
      },

      isCustomerService: () => {
        const { hasRole } = get()
        return hasRole(ROLES.CUSTOMER_SERVICE)
      },

      // Permission checks
      hasPermission: (permission) => {
        const { user, isAdmin } = get()
        // Admin roles (super_admin and store_admin) have all permissions
        if (isAdmin()) return true
        if (!user || !user.permissions) return false
        const checkPermissions = Array.isArray(permission) ? permission : [permission]
        return user.permissions.some((p) => checkPermissions.includes(p))
      },

      hasAnyPermission: (permissions) => {
        const { user, isAdmin } = get()
        if (isAdmin()) return true
        if (!user || !user.permissions) return false
        return permissions.some((p) => user.permissions!.includes(p))
      },

      hasAllPermissions: (permissions) => {
        const { user, isAdmin } = get()
        if (isAdmin()) return true
        if (!user || !user.permissions) return false
        return permissions.every((p) => user.permissions!.includes(p))
      },

      // Utility
      getUserRoles: () => {
        const { user } = get()
        return extractRoleNames(user?.roles)
      },

      getUserPermissions: () => {
        const { user } = get()
        return user?.permissions || []
      },
    }),
    {
      name: 'auth-storage',
      partialize: (state) => ({
        user: state.user,
        token: state.token,
        isAuthenticated: state.isAuthenticated,
      }),
    }
  )
)
