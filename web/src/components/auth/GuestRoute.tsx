import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuthStore } from '@/stores/auth-store'
import { getDefaultRedirectPath } from '@/hooks/useAuth'
import LoadingSpinner from '@/components/ui/LoadingSpinner'

export default function GuestRoute() {
  const { isAuthenticated, isLoading, user } = useAuthStore()
  const location = useLocation()

  if (isLoading) {
    return <LoadingSpinner fullScreen />
  }

  if (isAuthenticated && user) {
    // Redirect to where they came from, or role-appropriate dashboard
    const from = (location.state as { from?: Location })?.from?.pathname
    const defaultPath = getDefaultRedirectPath(user)
    return <Navigate to={from || defaultPath} replace />
  }

  return <Outlet />
}
