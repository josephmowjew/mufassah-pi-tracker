import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useLogin } from '@/hooks/useAuth'
import Input from '@/components/ui/Input'
import PasswordInput from '@/components/ui/PasswordInput'
import Button from '@/components/ui/Button'
import Alert from '@/components/ui/Alert'

const loginSchema = z.object({
  email: z.string().email('Please enter a valid email'),
  password: z.string().min(1, 'Password is required'),
  remember: z.boolean().optional(),
})

type LoginFormData = z.infer<typeof loginSchema>

export default function LoginPage() {
  const [apiError, setApiError] = useState<string | null>(null)
  const loginMutation = useLogin()

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<LoginFormData>({
    resolver: zodResolver(loginSchema),
    defaultValues: {
      email: '',
      password: '',
      remember: false,
    },
  })

  const onSubmit = (data: LoginFormData) => {
    setApiError(null)
    loginMutation.mutate(data, {
      onError: (error) => {
        const err = error as { response?: { data?: { message?: string } } }
        setApiError(err.response?.data?.message || 'Login failed. Please try again.')
      },
    })
  }

  return (
    <div>
      <h2 className="text-2xl font-bold mb-2">Welcome back</h2>
      <p className="text-muted-foreground mb-8">Sign in to your account to continue</p>

      {apiError && (
        <Alert variant="error" className="mb-6">
          {apiError}
        </Alert>
      )}

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
        <Input
          {...register('email')}
          type="email"
          label="Email"
          placeholder="you@example.com"
          error={errors.email?.message}
          required
        />

        <PasswordInput
          {...register('password')}
          label="Password"
          placeholder="Enter your password"
          error={errors.password?.message}
          required
        />

        <div className="flex items-center justify-between">
          <label className="flex items-center space-x-2 cursor-pointer">
            <input
              {...register('remember')}
              type="checkbox"
              className="rounded border-gray-300"
            />
            <span className="text-sm">Remember me</span>
          </label>
          <Link
            to="/forgot-password"
            className="text-sm text-primary hover:underline"
          >
            Forgot password?
          </Link>
        </div>

        <Button
          type="submit"
          className="w-full"
          isLoading={loginMutation.isPending}
        >
          Sign in
        </Button>
      </form>

      <p className="text-center text-sm text-muted-foreground mt-6">
        Don't have an account?{' '}
        <Link to="/register" className="text-primary hover:underline">
          Sign up
        </Link>
      </p>
    </div>
  )
}
