import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useRegister } from '@/hooks/useAuth'
import Input from '@/components/ui/Input'
import PasswordInput from '@/components/ui/PasswordInput'
import Button from '@/components/ui/Button'
import Alert from '@/components/ui/Alert'

const registerSchema = z
  .object({
    name: z
      .string()
      .min(2, 'Name must be at least 2 characters')
      .max(255, 'Name cannot exceed 255 characters'),
    email: z
      .string()
      .email('Please enter a valid email address'),
    phone_number: z
      .string()
      .regex(/^(\+265|265|0)?[0-9]{9}$/, 'Please enter a valid Malawi phone number')
      .optional()
      .or(z.literal('')),
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

type RegisterFormData = z.infer<typeof registerSchema>

interface ApiError {
  response?: {
    data?: {
      message?: string
      errors?: Record<string, string[]>
    }
  }
}

export default function RegisterPage() {
  const [apiError, setApiError] = useState<string | null>(null)
  const registerMutation = useRegister()

  const {
    register,
    handleSubmit,
    formState: { errors },
    setError,
  } = useForm<RegisterFormData>({
    resolver: zodResolver(registerSchema),
    defaultValues: {
      name: '',
      email: '',
      phone_number: '',
      password: '',
      password_confirmation: '',
    },
  })

  const onSubmit = async (data: RegisterFormData) => {
    setApiError(null)

    // Clean up phone number - remove empty string
    const submitData = {
      ...data,
      phone_number: data.phone_number || undefined,
    }

    registerMutation.mutate(submitData, {
      onError: (error) => {
        const err = error as ApiError
        const errorData = err.response?.data

        // Handle validation errors from API
        if (errorData?.errors) {
          Object.entries(errorData.errors).forEach(([field, messages]) => {
            if (field in registerSchema.shape) {
              setError(field as keyof RegisterFormData, {
                type: 'server',
                message: messages[0],
              })
            }
          })
        } else {
          setApiError(errorData?.message || 'Registration failed. Please try again.')
        }
      },
    })
  }

  return (
    <div>
      <h2 className="text-2xl font-bold mb-2">Create an account</h2>
      <p className="text-muted-foreground mb-8">Get started with device tracking</p>

      {apiError && (
        <Alert variant="error" className="mb-6">
          {apiError}
        </Alert>
      )}

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
        <Input
          {...register('name')}
          label="Full Name"
          placeholder="John Doe"
          error={errors.name?.message}
          required
        />

        <Input
          {...register('email')}
          type="email"
          label="Email Address"
          placeholder="you@example.com"
          error={errors.email?.message}
          required
        />

        <Input
          {...register('phone_number')}
          type="tel"
          label="Phone Number"
          placeholder="+265 999 123 456"
          error={errors.phone_number?.message}
          helperText="Optional - Used for SMS notifications"
        />

        <PasswordInput
          {...register('password')}
          label="Password"
          placeholder="Create a strong password"
          error={errors.password?.message}
          required
        />

        <PasswordInput
          {...register('password_confirmation')}
          label="Confirm Password"
          placeholder="Confirm your password"
          error={errors.password_confirmation?.message}
          required
        />

        <div className="text-sm text-muted-foreground">
          By creating an account, you agree to our{' '}
          <Link to="/terms" className="text-primary hover:underline">
            Terms of Service
          </Link>{' '}
          and{' '}
          <Link to="/privacy" className="text-primary hover:underline">
            Privacy Policy
          </Link>
          .
        </div>

        <Button
          type="submit"
          className="w-full"
          isLoading={registerMutation.isPending}
        >
          Create Account
        </Button>
      </form>

      <p className="text-center text-sm text-muted-foreground mt-6">
        Already have an account?{' '}
        <Link to="/login" className="text-primary hover:underline">
          Sign in
        </Link>
      </p>
    </div>
  )
}
