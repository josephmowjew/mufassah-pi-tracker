import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { useAuthStore, ROLES } from '@/stores/auth-store'
import authApi, {
  type ForgotPasswordData,
  type ResetPasswordData,
  type ChangePasswordData,
  type UpdateProfileData,
} from '@/api/auth'
import type { LoginCredentials, RegisterData, User } from '@/types'

/**
 * Determine the appropriate dashboard redirect based on user roles
 */
export function getDefaultRedirectPath(user: User): string {
  const userRoles = user.roles?.map(r => typeof r === 'string' ? r : r.name) || []

  // Customer service role goes to their own portal
  if (userRoles.some(role => role === ROLES.CUSTOMER_SERVICE)) {
    return '/customer-service'
  }

  // Admin roles go to admin panel
  const isAdmin = userRoles.some(role =>
    role === ROLES.SUPER_ADMIN || role === ROLES.STORE_ADMIN
  )
  return isAdmin ? '/admin' : '/dashboard'
}

export function useLogin() {
  const { login } = useAuthStore()
  const navigate = useNavigate()

  return useMutation({
    mutationFn: (credentials: LoginCredentials) => authApi.login(credentials),
    onSuccess: (data) => {
      login(data.user, data.token)

      const redirectPath = getDefaultRedirectPath(data.user)
      // Use setTimeout to ensure state is updated before navigation
      setTimeout(() => {
        navigate(redirectPath, { replace: true })
      }, 0)
    },
  })
}

export function useRegister() {
  const { login } = useAuthStore()
  const navigate = useNavigate()

  return useMutation({
    mutationFn: (data: RegisterData) => authApi.register(data),
    onSuccess: (data) => {
      login(data.user, data.token)
      navigate(getDefaultRedirectPath(data.user))
    },
  })
}

export function useLogout() {
  const { logout } = useAuthStore()
  const navigate = useNavigate()

  return useMutation({
    mutationFn: () => authApi.logout(),
    onSuccess: () => {
      logout()
      navigate('/login')
    },
    onError: () => {
      // Even if the API call fails, log out locally
      logout()
      navigate('/login')
    },
  })
}

export function useForgotPassword() {
  return useMutation({
    mutationFn: (data: ForgotPasswordData) => authApi.forgotPassword(data),
  })
}

export function useResetPassword() {
  const navigate = useNavigate()

  return useMutation({
    mutationFn: (data: ResetPasswordData) => authApi.resetPassword(data),
    onSuccess: () => {
      navigate('/login')
    },
  })
}

export function useVerifyEmail() {
  return useMutation({
    mutationFn: ({ id, hash, expires, signature }: { id: number; hash: string; expires: string; signature: string }) =>
      authApi.verifyEmail(id, hash, expires, signature),
  })
}

export function useResendVerification() {
  return useMutation({
    mutationFn: () => authApi.resendVerification(),
  })
}

export function useUser() {
  const { isAuthenticated, setUser, user } = useAuthStore()

  return useQuery({
    queryKey: ['user'],
    queryFn: async () => {
      const token = useAuthStore.getState().token
      const freshUser = await authApi.getUser()

      // A cancelled query may finish after another account has signed in.
      if (useAuthStore.getState().token !== token) return freshUser

      // Merge with existing user data to preserve roles/permissions if they're missing in the response
      // This prevents overwriting complete login data with incomplete data from /user endpoint
      if (user && user.roles && user.roles.length > 0) {
        const mergedUser = {
          ...freshUser,
          roles: freshUser.roles && freshUser.roles.length > 0 ? freshUser.roles : user.roles,
          permissions: freshUser.permissions && freshUser.permissions.length > 0 ? freshUser.permissions : user.permissions,
        }
        setUser(mergedUser)
      } else {
        setUser(freshUser)
      }

      return freshUser
    },
    enabled: isAuthenticated,
    staleTime: 1000 * 60 * 5, // 5 minutes
    retry: false,
  })
}

export function useUpdateProfile() {
  const queryClient = useQueryClient()
  const { setUser } = useAuthStore()

  return useMutation({
    mutationFn: (data: UpdateProfileData) => authApi.updateProfile(data),
    onSuccess: (user) => {
      setUser(user)
      queryClient.setQueryData(['user'], user)
    },
  })
}

export function useChangePassword() {
  return useMutation({
    mutationFn: (data: ChangePasswordData) => authApi.changePassword(data),
  })
}

export function useUploadAvatar() {
  const queryClient = useQueryClient()
  const { setUser } = useAuthStore()

  return useMutation({
    mutationFn: (file: File) => authApi.uploadAvatar(file),
    onSuccess: (user) => {
      setUser(user)
      queryClient.setQueryData(['user'], user)
    },
  })
}

export function useDeleteAvatar() {
  const queryClient = useQueryClient()
  const { setUser } = useAuthStore()

  return useMutation({
    mutationFn: () => authApi.deleteAvatar(),
    onSuccess: (user) => {
      setUser(user)
      queryClient.setQueryData(['user'], user)
    },
  })
}
