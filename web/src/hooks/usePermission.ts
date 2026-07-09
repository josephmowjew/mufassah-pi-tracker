import { useAuthStore, PERMISSIONS, ROLES } from '@/stores/auth-store'

/**
 * Hook for checking user permissions and roles
 * Provides convenient methods for authorization checks in components
 */
export function usePermission() {
  const {
    user,
    hasRole,
    hasPermission,
    hasAnyPermission,
    hasAllPermissions,
    isAdmin,
    isSuperAdmin,
    isCustomer,
    isCustomerService,
    getUserRoles,
    getUserPermissions,
  } = useAuthStore()

  return {
    // User info
    user,
    roles: getUserRoles(),
    permissions: getUserPermissions(),

    // Role checks
    hasRole,
    isAdmin: isAdmin(),
    isSuperAdmin: isSuperAdmin(),
    isCustomer: isCustomer(),
    isCustomerService: isCustomerService(),

    // Permission checks
    hasPermission,
    hasAnyPermission,
    hasAllPermissions,

    // Common permission shortcuts
    canViewDevices: hasPermission(PERMISSIONS.VIEW_DEVICES),
    canViewAllDevices: hasPermission(PERMISSIONS.VIEW_ALL_DEVICES),
    canCreateDevices: hasPermission(PERMISSIONS.CREATE_DEVICES),
    canUpdateDevices: hasPermission(PERMISSIONS.UPDATE_DEVICES),
    canDeleteDevices: hasPermission(PERMISSIONS.DELETE_DEVICES),

    canViewUsers: hasPermission(PERMISSIONS.VIEW_USERS),
    canCreateUsers: hasPermission(PERMISSIONS.CREATE_USERS),
    canUpdateUsers: hasPermission(PERMISSIONS.UPDATE_USERS),
    canDeleteUsers: hasPermission(PERMISSIONS.DELETE_USERS),
    canManageUserRoles: hasPermission(PERMISSIONS.MANAGE_USER_ROLES),
    canSuspendUsers: hasPermission(PERMISSIONS.SUSPEND_USERS),

    canViewGeofences: hasPermission(PERMISSIONS.VIEW_GEOFENCES),
    canCreateGeofences: hasPermission(PERMISSIONS.CREATE_GEOFENCES),
    canUpdateGeofences: hasPermission(PERMISSIONS.UPDATE_GEOFENCES),
    canDeleteGeofences: hasPermission(PERMISSIONS.DELETE_GEOFENCES),

    canViewTheftReports: hasPermission(PERMISSIONS.VIEW_THEFT_REPORTS),
    canViewAllTheftReports: hasPermission(PERMISSIONS.VIEW_ALL_THEFT_REPORTS),
    canCreateTheftReports: hasPermission(PERMISSIONS.CREATE_THEFT_REPORTS),
    canManageTheftInvestigations: hasPermission(PERMISSIONS.MANAGE_THEFT_INVESTIGATIONS),

    canViewSubscriptions: hasPermission(PERMISSIONS.VIEW_SUBSCRIPTIONS),
    canManageSubscriptions: hasPermission(PERMISSIONS.MANAGE_SUBSCRIPTIONS),

    canViewIotModules: hasPermission(PERMISSIONS.VIEW_IOT_MODULES),
    canCreateIotModules: hasPermission(PERMISSIONS.CREATE_IOT_MODULES),
    canAssignIotModules: hasPermission(PERMISSIONS.ASSIGN_IOT_MODULES),

    canAccessAdminPanel: hasPermission(PERMISSIONS.ACCESS_ADMIN_PANEL),
    canViewAnalytics: hasPermission(PERMISSIONS.VIEW_ANALYTICS),
    canManageSettings: hasPermission(PERMISSIONS.MANAGE_SETTINGS),
  }
}

// Re-export constants for convenience
export { PERMISSIONS, ROLES }
