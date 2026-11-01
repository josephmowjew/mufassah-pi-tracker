import { formatMalawiDate } from '@/lib/datetime'
import { useState, useRef } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Camera, User, Lock, Trash2 } from 'lucide-react'
import { useAuthStore } from '@/stores/auth-store'
import {
  useUpdateProfile,
  useChangePassword,
  useUploadAvatar,
  useDeleteAvatar,
} from '@/hooks/useAuth'
import Input from '@/components/ui/Input'
import PasswordInput from '@/components/ui/PasswordInput'
import Button from '@/components/ui/Button'
import Alert from '@/components/ui/Alert'

const profileSchema = z.object({
  name: z
    .string()
    .min(2, 'Name must be at least 2 characters')
    .max(255, 'Name cannot exceed 255 characters'),
  phone_number: z
    .string()
    .regex(/^((\+265|265|0)?[0-9]{9})?$/, 'Please enter a valid Malawi phone number')
    .optional()
    .or(z.literal('')),
  address: z.string().max(500, 'Address cannot exceed 500 characters').optional().or(z.literal('')),
  city: z.string().max(100, 'City cannot exceed 100 characters').optional().or(z.literal('')),
})

const passwordSchema = z
  .object({
    current_password: z.string().min(1, 'Current password is required'),
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

type ProfileFormData = z.infer<typeof profileSchema>
type PasswordFormData = z.infer<typeof passwordSchema>

interface ApiError {
  response?: {
    data?: {
      message?: string
      errors?: Record<string, string[]>
    }
  }
}

export default function ProfilePage() {
  const { user } = useAuthStore()
  const fileInputRef = useRef<HTMLInputElement>(null)

  // Profile state
  const [isEditingProfile, setIsEditingProfile] = useState(false)
  const [profileSuccess, setProfileSuccess] = useState<string | null>(null)
  const [profileError, setProfileError] = useState<string | null>(null)

  // Password state
  const [passwordSuccess, setPasswordSuccess] = useState<string | null>(null)
  const [passwordError, setPasswordError] = useState<string | null>(null)

  // Avatar state
  const [avatarError, setAvatarError] = useState<string | null>(null)

  // Mutations
  const updateProfileMutation = useUpdateProfile()
  const changePasswordMutation = useChangePassword()
  const uploadAvatarMutation = useUploadAvatar()
  const deleteAvatarMutation = useDeleteAvatar()

  // Profile form
  const {
    register: registerProfile,
    handleSubmit: handleProfileSubmit,
    formState: { errors: profileErrors },
    reset: resetProfile,
  } = useForm<ProfileFormData>({
    resolver: zodResolver(profileSchema),
    defaultValues: {
      name: user?.name || '',
      phone_number: user?.phone_number || '',
      address: user?.address || '',
      city: user?.city || '',
    },
  })

  // Password form
  const {
    register: registerPassword,
    handleSubmit: handlePasswordSubmit,
    formState: { errors: passwordErrors },
    reset: resetPassword,
  } = useForm<PasswordFormData>({
    resolver: zodResolver(passwordSchema),
    defaultValues: {
      current_password: '',
      password: '',
      password_confirmation: '',
    },
  })

  const onProfileSubmit = (data: ProfileFormData) => {
    setProfileError(null)
    setProfileSuccess(null)
    updateProfileMutation.mutate(
      {
        name: data.name,
        phone_number: data.phone_number || undefined,
        address: data.address || undefined,
        city: data.city || undefined,
      },
      {
        onSuccess: () => {
          setProfileSuccess('Profile updated successfully!')
          setIsEditingProfile(false)
        },
        onError: (error) => {
          const err = error as ApiError
          setProfileError(
            err.response?.data?.message || 'Failed to update profile.'
          )
        },
      }
    )
  }

  const onPasswordSubmit = (data: PasswordFormData) => {
    setPasswordError(null)
    setPasswordSuccess(null)
    changePasswordMutation.mutate(data, {
      onSuccess: () => {
        setPasswordSuccess('Password changed successfully!')
        resetPassword()
      },
      onError: (error) => {
        const err = error as ApiError
        setPasswordError(
          err.response?.data?.message || 'Failed to change password.'
        )
      },
    })
  }

  const handleAvatarClick = () => {
    fileInputRef.current?.click()
  }

  const handleAvatarChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    if (!file) return

    // Validate file type
    if (!file.type.startsWith('image/')) {
      setAvatarError('Please select an image file.')
      return
    }

    // Validate file size (max 2MB)
    if (file.size > 2 * 1024 * 1024) {
      setAvatarError('Image must be less than 2MB.')
      return
    }

    setAvatarError(null)
    uploadAvatarMutation.mutate(file, {
      onError: (error) => {
        const err = error as ApiError
        setAvatarError(
          err.response?.data?.message || 'Failed to upload avatar.'
        )
      },
    })
  }

  const handleDeleteAvatar = () => {
    if (!user?.avatar) return
    setAvatarError(null)
    deleteAvatarMutation.mutate(undefined, {
      onError: (error) => {
        const err = error as ApiError
        setAvatarError(
          err.response?.data?.message || 'Failed to delete avatar.'
        )
      },
    })
  }

  const cancelProfileEdit = () => {
    setIsEditingProfile(false)
    setProfileError(null)
    resetProfile({
      name: user?.name || '',
      phone_number: user?.phone_number || '',
      address: user?.address || '',
      city: user?.city || '',
    })
  }

  const getInitials = (name: string | undefined) => {
    if (!name) return 'U'
    return name
      .split(' ')
      .map((n) => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2)
  }

  return (
    <div className="space-y-8">
      <h1 className="text-2xl font-bold">Profile Settings</h1>

      {/* Avatar Section */}
      <div className="card p-6">
        <h2 className="text-lg font-semibold mb-4 flex items-center gap-2">
          <User className="h-5 w-5" />
          Profile Picture
        </h2>

        {avatarError && (
          <Alert variant="error" className="mb-4">
            {avatarError}
          </Alert>
        )}

        <div className="flex items-center gap-6">
          <div className="relative">
            {user?.avatar ? (
              <img
                src={user.avatar}
                alt={user.name}
                className="h-24 w-24 rounded-full object-cover"
              />
            ) : (
              <div className="h-24 w-24 rounded-full bg-primary flex items-center justify-center text-primary-foreground text-2xl font-bold">
                {getInitials(user?.name)}
              </div>
            )}
            <button
              type="button"
              onClick={handleAvatarClick}
              disabled={uploadAvatarMutation.isPending}
              className="absolute bottom-0 right-0 p-2 bg-primary text-primary-foreground rounded-full hover:bg-primary/90 disabled:opacity-50"
            >
              <Camera className="h-4 w-4" />
            </button>
          </div>
          <div className="space-y-2">
            <p className="text-sm text-muted-foreground">
              Recommended: Square image, at least 200x200 pixels
            </p>
            <div className="flex gap-2">
              <Button
                size="sm"
                variant="outline"
                onClick={handleAvatarClick}
                isLoading={uploadAvatarMutation.isPending}
              >
                Upload new photo
              </Button>
              {user?.avatar && (
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={handleDeleteAvatar}
                  isLoading={deleteAvatarMutation.isPending}
                >
                  <Trash2 className="h-4 w-4 mr-1" />
                  Remove
                </Button>
              )}
            </div>
          </div>
          <input
            ref={fileInputRef}
            type="file"
            accept="image/*"
            onChange={handleAvatarChange}
            className="hidden"
          />
        </div>
      </div>

      {/* Profile Information Section */}
      <div className="card p-6">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold flex items-center gap-2">
            <User className="h-5 w-5" />
            Personal Information
          </h2>
          {!isEditingProfile && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => setIsEditingProfile(true)}
            >
              Edit
            </Button>
          )}
        </div>

        {profileSuccess && (
          <Alert variant="success" className="mb-4">
            {profileSuccess}
          </Alert>
        )}

        {profileError && (
          <Alert variant="error" className="mb-4">
            {profileError}
          </Alert>
        )}

        {isEditingProfile ? (
          <form onSubmit={handleProfileSubmit(onProfileSubmit)} className="space-y-4">
            <Input
              {...registerProfile('name')}
              label="Full Name"
              placeholder="John Doe"
              error={profileErrors.name?.message}
              required
            />

            <Input
              {...registerProfile('phone_number')}
              type="tel"
              label="Phone Number"
              placeholder="+265 999 123 456"
              error={profileErrors.phone_number?.message}
            />

            <Input
              {...registerProfile('address')}
              label="Address"
              placeholder="123 Main Street"
              error={profileErrors.address?.message}
            />

            <Input
              {...registerProfile('city')}
              label="City"
              placeholder="Lilongwe"
              error={profileErrors.city?.message}
            />

            <div className="flex gap-3">
              <Button type="submit" isLoading={updateProfileMutation.isPending}>
                Save Changes
              </Button>
              <Button
                type="button"
                variant="outline"
                onClick={cancelProfileEdit}
              >
                Cancel
              </Button>
            </div>
          </form>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label className="block text-sm font-medium text-muted-foreground mb-1">
                Full Name
              </label>
              <p className="text-foreground">{user?.name || '-'}</p>
            </div>
            <div>
              <label className="block text-sm font-medium text-muted-foreground mb-1">
                Email
              </label>
              <p className="text-foreground">{user?.email || '-'}</p>
            </div>
            <div>
              <label className="block text-sm font-medium text-muted-foreground mb-1">
                Phone Number
              </label>
              <p className="text-foreground">{user?.phone_number || 'Not set'}</p>
            </div>
            <div>
              <label className="block text-sm font-medium text-muted-foreground mb-1">
                City
              </label>
              <p className="text-foreground">{user?.city || 'Not set'}</p>
            </div>
            <div className="sm:col-span-2">
              <label className="block text-sm font-medium text-muted-foreground mb-1">
                Address
              </label>
              <p className="text-foreground">{user?.address || 'Not set'}</p>
            </div>
          </div>
        )}
      </div>

      {/* Password Section */}
      <div className="card p-6">
        <h2 className="text-lg font-semibold mb-4 flex items-center gap-2">
          <Lock className="h-5 w-5" />
          Change Password
        </h2>

        {passwordSuccess && (
          <Alert variant="success" className="mb-4">
            {passwordSuccess}
          </Alert>
        )}

        {passwordError && (
          <Alert variant="error" className="mb-4">
            {passwordError}
          </Alert>
        )}

        <form onSubmit={handlePasswordSubmit(onPasswordSubmit)} className="space-y-4 max-w-md">
          <PasswordInput
            {...registerPassword('current_password')}
            label="Current Password"
            placeholder="Enter your current password"
            error={passwordErrors.current_password?.message}
            required
          />

          <PasswordInput
            {...registerPassword('password')}
            label="New Password"
            placeholder="Create a strong password"
            error={passwordErrors.password?.message}
            required
          />

          <PasswordInput
            {...registerPassword('password_confirmation')}
            label="Confirm New Password"
            placeholder="Confirm your new password"
            error={passwordErrors.password_confirmation?.message}
            required
          />

          <Button type="submit" isLoading={changePasswordMutation.isPending}>
            Change Password
          </Button>
        </form>
      </div>

      {/* Account Info Section */}
      <div className="card p-6">
        <h2 className="text-lg font-semibold mb-4">Account Information</h2>
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <label className="block text-sm font-medium text-muted-foreground mb-1">
              Account Type
            </label>
            <p className="text-foreground capitalize">
              {(() => {
                const role = user?.roles?.[0]
                if (!role) return 'Customer'
                const roleName = typeof role === 'string' ? role : role.name
                return roleName.replace('_', ' ')
              })()}
            </p>
          </div>
          <div>
            <label className="block text-sm font-medium text-muted-foreground mb-1">
              Email Verified
            </label>
            <p className="text-foreground">
              {user?.email_verified_at ? (
                <span className="text-green-600 dark:text-green-400">Verified</span>
              ) : (
                <span className="text-yellow-600 dark:text-yellow-400">Not verified</span>
              )}
            </p>
          </div>
          <div>
            <label className="block text-sm font-medium text-muted-foreground mb-1">
              Member Since
            </label>
            <p className="text-foreground">
              {user?.created_at
                ? formatMalawiDate(user.created_at, 'en-US', {
                    month: 'long',
                    day: 'numeric',
                    year: 'numeric',
                  })
                : '-'}
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
