import { Navigate } from 'react-router-dom'
import { useAuthStore } from '@/stores/auth-store'
import { getDefaultRedirectPath } from '@/hooks/useAuth'
import LoadingSpinner from '@/components/ui/LoadingSpinner'

/**
 * Redirects users to their appropriate dashboard based on role.
 * - Admins (super_admin, store_admin) -> /admin
 * - Regular users -> /dashboard
 * - Unauthenticated -> /login
 */
export default function RoleBasedRedirect() {
  const { isAuthenticated, isLoading, user } = useAuthStore()

  if (isLoading) {
    return <LoadingSpinner fullScreen />
  }

  if (!isAuthenticated || !user) {
    return <Navigate to="/login" replace />
  }

  return <Navigate to={getDefaultRedirectPath(user)} replace />
}
