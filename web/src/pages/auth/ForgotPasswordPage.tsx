import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { ArrowLeft, Mail } from 'lucide-react'
import { useForgotPassword } from '@/hooks/useAuth'
import Input from '@/components/ui/Input'
import Button from '@/components/ui/Button'
import Alert from '@/components/ui/Alert'

const forgotPasswordSchema = z.object({
  email: z.string().email('Please enter a valid email address'),
})

type ForgotPasswordFormData = z.infer<typeof forgotPasswordSchema>

export default function ForgotPasswordPage() {
  const [isSuccess, setIsSuccess] = useState(false)
  const [apiError, setApiError] = useState<string | null>(null)
  const forgotPasswordMutation = useForgotPassword()

  const {
    register,
    handleSubmit,
    formState: { errors },
    getValues,
  } = useForm<ForgotPasswordFormData>({
    resolver: zodResolver(forgotPasswordSchema),
    defaultValues: {
      email: '',
    },
  })

  const onSubmit = (data: ForgotPasswordFormData) => {
    setApiError(null)
    forgotPasswordMutation.mutate(data, {
      onSuccess: () => {
        setIsSuccess(true)
      },
      onError: (error) => {
        const err = error as { response?: { data?: { message?: string } } }
        setApiError(err.response?.data?.message || 'Failed to send reset link. Please try again.')
      },
    })
  }

  if (isSuccess) {
    return (
      <div className="text-center">
        <div className="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-primary/10">
          <Mail className="h-8 w-8 text-primary" />
        </div>
        <h2 className="text-2xl font-bold mb-2">Check your email</h2>
        <p className="text-muted-foreground mb-6">
          We've sent a password reset link to{' '}
          <span className="font-medium text-foreground">{getValues('email')}</span>
        </p>
        <p className="text-sm text-muted-foreground mb-6">
          Didn't receive the email? Check your spam folder or{' '}
          <button
            type="button"
            onClick={() => setIsSuccess(false)}
            className="text-primary hover:underline"
          >
            try again
          </button>
        </p>
        <Link
          to="/login"
          className="inline-flex items-center gap-2 text-sm text-primary hover:underline"
        >
          <ArrowLeft className="h-4 w-4" />
          Back to login
        </Link>
      </div>
    )
  }

  return (
    <div>
      <h2 className="text-2xl font-bold mb-2">Forgot password?</h2>
      <p className="text-muted-foreground mb-8">
        No worries, we'll send you reset instructions
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

        <Button
          type="submit"
          className="w-full"
          isLoading={forgotPasswordMutation.isPending}
        >
          Send Reset Link
        </Button>
      </form>

      <p className="text-center text-sm text-muted-foreground mt-6">
        <Link
          to="/login"
          className="inline-flex items-center gap-2 text-primary hover:underline"
        >
          <ArrowLeft className="h-4 w-4" />
          Back to login
        </Link>
      </p>
    </div>
  )
}
