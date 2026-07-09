import type { ReactNode } from 'react'
import { useAuthStore } from '@/stores/auth-store'

interface AuthorizeProps {
  children: ReactNode
  /** Required permission(s) - user must have at least one */
  permission?: string | string[]
  /** Required permissions - user must have ALL of these */
  permissions?: string[]
  /** Required role(s) - user must have at least one */
  role?: string | string[]
  /** Content to show when unauthorized (optional) */
  fallback?: ReactNode
  /** If true, disables children instead of hiding them */
  disableOnUnauthorized?: boolean
}

/**
 * Authorization wrapper component
 * Conditionally renders children based on user permissions/roles
 *
 * @example
 * // Single permission
 * <Authorize permission="delete-devices">
 *   <DeleteButton />
 * </Authorize>
 *
 * @example
 * // Multiple permissions (ANY)
 * <Authorize permission={['update-devices', 'manage-device-status']}>
 *   <EditButton />
 * </Authorize>
 *
 * @example
 * // Multiple permissions (ALL required)
 * <Authorize permissions={['view-users', 'update-users']}>
 *   <UserEditor />
 * </Authorize>
 *
 * @example
 * // Role-based
 * <Authorize role={['super_admin', 'store_admin']}>
 *   <AdminPanel />
 * </Authorize>
 *
 * @example
 * // With fallback
 * <Authorize permission="delete-devices" fallback={<span>No access</span>}>
 *   <DeleteButton />
 * </Authorize>
 */
export function Authorize({
  children,
  permission,
  permissions,
  role,
  fallback = null,
  disableOnUnauthorized = false,
}: AuthorizeProps) {
  const { hasRole, hasPermission, hasAllPermissions } = useAuthStore()

  // Check authorization
  let isAuthorized = true

  // Check role if provided
  if (role) {
    isAuthorized = isAuthorized && hasRole(role)
  }

  // Check single/any permission if provided
  if (permission) {
    isAuthorized = isAuthorized && hasPermission(permission)
  }

  // Check all permissions if provided
  if (permissions && permissions.length > 0) {
    isAuthorized = isAuthorized && hasAllPermissions(permissions)
  }

  if (!isAuthorized) {
    if (disableOnUnauthorized) {
      // Clone children and add disabled prop
      // This only works for elements that accept a disabled prop
      return <div style={{ opacity: 0.5, pointerEvents: 'none' }}>{children}</div>
    }
    return <>{fallback}</>
  }

  return <>{children}</>
}

/**
 * Higher-order component for authorization
 * Wraps a component with permission checks
 *
 * @example
 * const ProtectedDeleteButton = withAuthorization(DeleteButton, {
 *   permission: 'delete-devices'
 * })
 */
export function withAuthorization<P extends object>(
  WrappedComponent: React.ComponentType<P>,
  authProps: Omit<AuthorizeProps, 'children'>
) {
  return function AuthorizedComponent(props: P) {
    return (
      <Authorize {...authProps}>
        <WrappedComponent {...props} />
      </Authorize>
    )
  }
}

/**
 * Hook-based authorization check
 * Returns whether the user is authorized based on given criteria
 *
 * @example
 * const canDelete = useAuthorized({ permission: 'delete-devices' })
 */
export function useAuthorized(options: {
  permission?: string | string[]
  permissions?: string[]
  role?: string | string[]
}): boolean {
  const { hasRole, hasPermission, hasAllPermissions } = useAuthStore()

  let isAuthorized = true

  if (options.role) {
    isAuthorized = isAuthorized && hasRole(options.role)
  }

  if (options.permission) {
    isAuthorized = isAuthorized && hasPermission(options.permission)
  }

  if (options.permissions && options.permissions.length > 0) {
    isAuthorized = isAuthorized && hasAllPermissions(options.permissions)
  }

  return isAuthorized
}

export default Authorize
