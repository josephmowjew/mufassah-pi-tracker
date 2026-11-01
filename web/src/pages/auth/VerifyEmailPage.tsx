import { malawiCalendarDate } from '@/lib/datetime'
import { useEffect, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { CheckCircle, XCircle, Loader2, Mail, MapPin } from 'lucide-react'
import { useVerifyEmail, useResendVerification } from '@/hooks/useAuth'
import Button from '@/components/ui/Button'
import Alert from '@/components/ui/Alert'

type VerificationStatus = 'verifying' | 'success' | 'error' | 'pending'

export default function VerifyEmailPage() {
  const { id, hash } = useParams<{ id: string; hash: string }>()
  const [searchParams] = useSearchParams()
  const [status, setStatus] = useState<VerificationStatus>('pending')
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [resendSuccess, setResendSuccess] = useState(false)

  const verifyEmailMutation = useVerifyEmail()
  const resendVerificationMutation = useResendVerification()

  // Get signature params from URL
  const expires = searchParams.get('expires')
  const signature = searchParams.get('signature')

  // Check if this is a verification link with all required params
  const hasVerificationParams = id && hash && expires && signature

  useEffect(() => {
    if (hasVerificationParams) {
      setStatus('verifying')
      verifyEmailMutation.mutate(
        { id: parseInt(id), hash, expires, signature },
        {
          onSuccess: () => {
            setStatus('success')
          },
          onError: (error) => {
            setStatus('error')
            const err = error as { response?: { data?: { message?: string } } }
            setErrorMessage(
              err.response?.data?.message || 'Email verification failed. The link may be invalid or expired.'
            )
          },
        }
      )
    }
  }, [id, hash, expires, signature])

  const handleResendVerification = () => {
    setResendSuccess(false)
    resendVerificationMutation.mutate(undefined, {
      onSuccess: () => {
        setResendSuccess(true)
      },
      onError: (error) => {
        const err = error as { response?: { data?: { message?: string } } }
        setErrorMessage(
          err.response?.data?.message || 'Failed to resend verification email.'
        )
      },
    })
  }

  const renderContent = () => {
    // Verification in progress
    if (status === 'verifying') {
      return (
        <div className="text-center">
          <div className="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-primary/10">
            <Loader2 className="h-8 w-8 text-primary animate-spin" />
          </div>
          <h2 className="text-2xl font-bold mb-2">Verifying your email</h2>
          <p className="text-muted-foreground">
            Please wait while we verify your email address...
          </p>
        </div>
      )
    }

    // Verification successful
    if (status === 'success') {
      return (
        <div className="text-center">
          <div className="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-green-100">
            <CheckCircle className="h-8 w-8 text-green-600" />
          </div>
          <h2 className="text-2xl font-bold mb-2">Email verified!</h2>
          <p className="text-muted-foreground mb-6">
            Your email has been verified successfully. You can now access all features.
          </p>
          <Link to="/dashboard">
            <Button>Go to Dashboard</Button>
          </Link>
        </div>
      )
    }

    // Verification failed
    if (status === 'error') {
      return (
        <div className="text-center">
          <div className="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-red-100">
            <XCircle className="h-8 w-8 text-red-600" />
          </div>
          <h2 className="text-2xl font-bold mb-2">Verification failed</h2>
          <p className="text-muted-foreground mb-6">
            {errorMessage}
          </p>
          <div className="space-y-4">
            <Button
              onClick={handleResendVerification}
              isLoading={resendVerificationMutation.isPending}
            >
              Resend Verification Email
            </Button>
            <p className="text-sm text-muted-foreground">
              <Link to="/login" className="text-primary hover:underline">
                Back to login
              </Link>
            </p>
          </div>
        </div>
      )
    }

    // Pending verification (no params in URL - user needs to verify email)
    return (
      <div className="text-center">
        <div className="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-primary/10">
          <Mail className="h-8 w-8 text-primary" />
        </div>
        <h2 className="text-2xl font-bold mb-2">Verify your email</h2>
        <p className="text-muted-foreground mb-6">
          We've sent a verification link to your email address. Please check your inbox and click the link to verify your account.
        </p>

        {resendSuccess && (
          <Alert variant="success" className="mb-6 text-left">
            Verification email sent! Please check your inbox.
          </Alert>
        )}

        {errorMessage && !resendSuccess && (
          <Alert variant="error" className="mb-6 text-left">
            {errorMessage}
          </Alert>
        )}

        <div className="space-y-4">
          <p className="text-sm text-muted-foreground">
            Didn't receive the email? Check your spam folder or
          </p>
          <Button
            variant="outline"
            onClick={handleResendVerification}
            isLoading={resendVerificationMutation.isPending}
          >
            Resend Verification Email
          </Button>
          <p className="text-sm text-muted-foreground">
            <Link to="/dashboard" className="text-primary hover:underline">
              Continue to Dashboard
            </Link>
            {' '}(some features may be limited)
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="min-h-screen bg-background flex flex-col items-center justify-center p-6">
      {/* Logo */}
      <div className="flex items-center gap-3 mb-10">
        <div className="h-12 w-12 rounded-xl bg-primary flex items-center justify-center">
          <MapPin className="h-6 w-6 text-white" strokeWidth={1.5} />
        </div>
        <div>
          <span className="text-lg font-semibold text-foreground" style={{ fontFamily: 'var(--font-heading)' }}>
            Mufasah
          </span>
          <p className="text-xs text-muted-foreground">Device Tracking</p>
        </div>
      </div>

      {/* Content Card */}
      <div className="w-full max-w-md bg-card rounded-xl border p-8 shadow-sm">
        {renderContent()}
      </div>

      {/* Footer */}
      <p className="mt-8 text-xs text-muted-foreground text-center">
        &copy; {malawiCalendarDate().getFullYear()} Mufasah Electronics. All rights reserved.
      </p>
    </div>
  )
}
