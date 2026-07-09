import { useState } from 'react'
import { Link, useSearchParams, useNavigate } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { CheckCircle } from 'lucide-react'
import { useResetPassword } from '@/hooks/useAuth'
import PasswordInput from '@/components/ui/PasswordInput'
import Input from '@/components/ui/Input'
import Button from '@/components/ui/Button'
import Alert from '@/components/ui/Alert'

const resetPasswordSchema = z
  .object({
    email: z.string().email('Please enter a valid email address'),
    password: z
      .string()
      .min(8, 'Password must be at least 8 characters')
      .regex(/[A-Z]/, 'Password must contain at least one uppercase letter')
      .regex(/[a-z]/, 'Password must contain at least one lowercase letter')
      .regex(/[0-9]/, 'Password must contain at least one number'),
    password_confirmation: z.string(),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: 'Passwords do not match',
    path: ['password_confirmation'],
  })

type ResetPasswordFormData = z.infer<typeof resetPasswordSchema>

export default function ResetPasswordPage() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const [isSuccess, setIsSuccess] = useState(false)
  const [apiError, setApiError] = useState<string | null>(null)
  const resetPasswordMutation = useResetPassword()

  const token = searchParams.get('token') || ''
  const emailFromUrl = searchParams.get('email') || ''

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<ResetPasswordFormData>({
    resolver: zodResolver(resetPasswordSchema),
    defaultValues: {
      email: emailFromUrl,
      password: '',
      password_confirmation: '',
    },
  })

  const onSubmit = (data: ResetPasswordFormData) => {
    if (!token) {
      setApiError('Invalid reset link. Please request a new one.')
      return
    }

    setApiError(null)
    resetPasswordMutation.mutate(
      {
        ...data,
        token,
      },
      {
        onSuccess: () => {
          setIsSuccess(true)
          // Redirect to login after 3 seconds
          setTimeout(() => {
            navigate('/login')
          }, 3000)
        },
        onError: (error) => {
          const err = error as { response?: { data?: { message?: string } } }
          setApiError(
            err.response?.data?.message ||
              'Failed to reset password. The link may have expired.'
          )
        },
      }
    )
  }

  if (!token) {
    return (
      <div className="text-center">
        <Alert variant="error" className="mb-6">
          Invalid or missing reset token. Please request a new password reset link.
        </Alert>
        <Link
          to="/forgot-password"
          className="text-primary hover:underline"
        >
          Request new reset link
        </Link>
      </div>
    )
  }

  if (isSuccess) {
    return (
      <div className="text-center">
        <div className="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/20">
          <CheckCircle className="h-8 w-8 text-green-600 dark:text-green-400" />
        </div>
        <h2 className="text-2xl font-bold mb-2">Password reset successful</h2>
        <p className="text-muted-foreground mb-6">
          Your password has been reset successfully. You'll be redirected to the login page shortly.
        </p>
        <Link
          to="/login"
          className="text-primary hover:underline"
        >
          Go to login now
        </Link>
      </div>
    )
  }

  return (
    <div>
      <h2 className="text-2xl font-bold mb-2">Reset password</h2>
      <p className="text-muted-foreground mb-8">
        Enter your new password below
      </p>

      {apiError && (
        <Alert variant="error" className="mb-6">
          {apiError}
        </Alert>
      )}

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
        <Input
          {...register('email')}
          type="email"
          label="Email Address"
          placeholder="you@example.com"
          error={errors.email?.message}
          required
        />

        <PasswordInput
          {...register('password')}
          label="New Password"
          placeholder="Create a strong password"
          error={errors.password?.message}
          required
        />

        <PasswordInput
          {...register('password_confirmation')}
          label="Confirm New Password"
          placeholder="Confirm your password"
          error={errors.password_confirmation?.message}
          required
        />

        <Button
          type="submit"
          className="w-full"
          isLoading={resetPasswordMutation.isPending}
        >
          Reset Password
        </Button>
      </form>

      <p className="text-center text-sm text-muted-foreground mt-6">
        Remember your password?{' '}
        <Link to="/login" className="text-primary hover:underline">
          Sign in
        </Link>
      </p>
    </div>
  )
}
