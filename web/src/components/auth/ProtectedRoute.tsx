import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuthStore } from '@/stores/auth-store'
import LoadingSpinner from '@/components/ui/LoadingSpinner'
import { getDefaultRedirectPath } from '@/hooks/useAuth'

interface ProtectedRouteProps {
  /** Required roles - user must have at least one */
  requiredRoles?: string[]
  /** Required permission(s) - user must have at least one */
  requiredPermission?: string | string[]
  /** Required permissions - user must have ALL of these */
  requiredPermissions?: string[]
  /** Where to redirect on unauthorized (default: role-based redirect) */
  unauthorizedRedirect?: string
}

export default function ProtectedRoute({
  requiredRoles,
  requiredPermission,
  requiredPermissions,
  unauthorizedRedirect,
}: ProtectedRouteProps) {
  const { isAuthenticated, isLoading, user, hasRole, hasPermission, hasAllPermissions } = useAuthStore()
  const location = useLocation()

  if (isLoading) {
    return <LoadingSpinner fullScreen />
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" state={{ from: location }} replace />
  }

  // Check role requirements (user must have at least one of the required roles)
  if (requiredRoles && requiredRoles.length > 0 && !hasRole(requiredRoles)) {
    return <Navigate to={unauthorizedRedirect || getDefaultRedirectPath(user!)} replace />
  }

  // Check single/any permission requirement
  if (requiredPermission && !hasPermission(requiredPermission)) {
    return <Navigate to={unauthorizedRedirect || getDefaultRedirectPath(user!)} replace />
  }

  // Check all permissions requirement
  if (requiredPermissions && requiredPermissions.length > 0 && !hasAllPermissions(requiredPermissions)) {
    return <Navigate to={unauthorizedRedirect || getDefaultRedirectPath(user!)} replace />
  }

  return <Outlet />
}
