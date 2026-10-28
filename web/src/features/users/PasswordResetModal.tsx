import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import {
  AlertTriangle,
  CheckCircle,
  Copy,
  Eye,
  EyeOff,
  KeyRound,
  Loader2,
  Lock,
  Mail,
  RefreshCw,
  X,
} from 'lucide-react'
import apiClient from '@/api/client'
import { toast } from 'sonner'
import type { User as UserType } from '@/types'

type Mode = 'link' | 'manual'

interface PasswordResetModalProps {
  user: UserType
  onClose: () => void
  /** Called after a successful action so the parent can refresh the list. */
  onDone?: () => void
}

interface ResetPasswordPayload {
  password: string
  password_confirmation: string
  notify: boolean
  revoke_sessions: boolean
}

const UPPERCASE = 'ABCDEFGHJKLMNPQRSTUVWXYZ'
const LOWERCASE = 'abcdefghijkmnopqrstuvwxyz'
const DIGITS = '23456789'
const SYMBOLS = '!@#$%^&*-_'
const ALL_CHARS = UPPERCASE + LOWERCASE + DIGITS + SYMBOLS

/**
 * Cryptographically random password, shaped so it satisfies a stricter
 * Password::defaults() than the current min(8): one of each character class,
 * ambiguous glyphs (0/O, 1/l/I) removed because an admin may read this aloud.
 */
function generatePassword(length = 14): string {
  const pick = (pool: string): string => {
    const buffer = new Uint32Array(1)
    crypto.getRandomValues(buffer)
    return pool[buffer[0] % pool.length]
  }

  const required = [pick(UPPERCASE), pick(LOWERCASE), pick(DIGITS), pick(SYMBOLS)]
  const filler = Array.from({ length: Math.max(length - required.length, 0) }, () => pick(ALL_CHARS))
  const chars = [...required, ...filler]

  for (let i = chars.length - 1; i > 0; i -= 1) {
    const buffer = new Uint32Array(1)
    crypto.getRandomValues(buffer)
    const j = buffer[0] % (i + 1)
    ;[chars[i], chars[j]] = [chars[j], chars[i]]
  }

  return chars.join('')
}

function readErrorMessage(error: unknown, fallback: string): string {
  const data = (
    error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
  )?.response?.data

  if (data?.errors) {
    const first = Object.values(data.errors)[0]
    if (first?.[0]) return first[0]
  }

  return data?.message || fallback
}

async function copyToClipboard(value: string) {
  try {
    await navigator.clipboard.writeText(value)
    toast.success('Password copied to clipboard')
  } catch {
    toast.error('Could not copy automatically — select the password and copy it manually.')
  }
}

export default function PasswordResetModal({ user, onClose, onDone }: PasswordResetModalProps) {
  const hasEmail = Boolean(user.email)
  const isSuspended = user.is_suspended ?? Boolean(user.suspended_at)
  // The API refuses to mail a reset link to a suspended account - a suspended
  // user cannot sign in, so the link would be dead on arrival. Offering the
  // tab anyway would be a guaranteed 422.
  const canSendLink = hasEmail && !isSuspended

  const [mode, setMode] = useState<Mode>(canSendLink ? 'link' : 'manual')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [showPassword, setShowPassword] = useState(true)
  const [notify, setNotify] = useState(hasEmail)
  const [revokeSessions, setRevokeSessions] = useState(true)
  const [issuedPassword, setIssuedPassword] = useState<string | null>(null)
  const [issuedEmail, setIssuedEmail] = useState<boolean | null>(null)

  const sendLinkMutation = useMutation({
    mutationFn: async () => {
      const response = await apiClient.post(`/admin/users/${user.id}/send-password-reset`)
      return response.data as { message?: string }
    },
    onSuccess: (data) => {
      toast.success(data?.message || `Reset link sent to ${user.email}.`)
      onDone?.()
      onClose()
    },
    onError: (error: unknown) => {
      toast.error(readErrorMessage(error, 'Could not send the reset link.'))
    },
  })

  const setPasswordMutation = useMutation({
    mutationFn: async (payload: ResetPasswordPayload) => {
      const response = await apiClient.post(`/admin/users/${user.id}/reset-password`, payload)
      return response.data as { message?: string; data?: { emailed?: boolean } }
    },
    onSuccess: (data, variables) => {
      // Read the password off the submitted variables rather than component
      // state: the state can move on between mutate() and the callback.
      setIssuedPassword(variables.password)
      setIssuedEmail(Boolean(data?.data?.emailed))
      onDone?.()
    },
    onError: (error: unknown) => {
      toast.error(readErrorMessage(error, 'Could not update the password.'))
    },
  })

  const handleGenerate = () => {
    const generated = generatePassword()
    setPassword(generated)
    setConfirmation(generated)
    setShowPassword(true)
  }

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault()

    if (password.length < 8) {
      toast.error('Password must be at least 8 characters.')
      return
    }

    if (password !== confirmation) {
      toast.error('The two passwords do not match.')
      return
    }

    setPasswordMutation.mutate({
      password,
      password_confirmation: confirmation,
      notify,
      revoke_sessions: revokeSessions,
    })
  }

  const isBusy = sendLinkMutation.isPending || setPasswordMutation.isPending

  return (
    <>
      <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40" onClick={isBusy ? undefined : onClose} />
      <div className="fixed inset-x-4 top-[8%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-lg bg-white rounded-2xl shadow-xl z-50 overflow-hidden max-h-[88vh] overflow-y-auto">
        <div className="p-5 border-b border-slate-200 sticky top-0 bg-white z-10">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2.5">
              <div className="h-9 w-9 rounded-lg bg-blue-50 flex items-center justify-center">
                <KeyRound className="h-4.5 w-4.5 text-blue-600" />
              </div>
              <div>
                <h3 className="text-lg font-semibold text-slate-800 leading-tight">Reset password</h3>
                <p className="text-xs text-slate-500">{user.name}</p>
              </div>
            </div>
            <button
              onClick={onClose}
              disabled={isBusy}
              className="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors disabled:opacity-50"
            >
              <X className="h-5 w-5" />
            </button>
          </div>
        </div>

        {issuedPassword ? (
          <div className="p-5 space-y-4">
            <div className="flex flex-col items-center text-center">
              <div className="h-12 w-12 rounded-full bg-emerald-100 flex items-center justify-center mb-3">
                <CheckCircle className="h-6 w-6 text-emerald-600" />
              </div>
              <h4 className="text-base font-semibold text-slate-800">Password updated</h4>
              <p className="text-sm text-slate-500 mt-1">
                {issuedEmail
                  ? `${user.name} has been emailed the new password at ${user.email}.`
                  : hasEmail
                    ? `Password changed. No email was sent — pass this password on directly.`
                    : `${user.name} has no email address on file — pass this password on directly.`}
              </p>
            </div>

            <div className="p-3 bg-slate-50 border border-slate-200 rounded-lg">
              <p className="text-xs text-slate-500 mb-1.5">New password</p>
              <div className="flex items-center gap-2">
                <code className="flex-1 font-mono text-sm text-slate-800 break-all">{issuedPassword}</code>
                <button
                  type="button"
                  onClick={() => copyToClipboard(issuedPassword)}
                  className="p-2 text-slate-500 hover:text-blue-600 hover:bg-white rounded-lg transition-colors"
                  title="Copy password"
                >
                  <Copy className="h-4 w-4" />
                </button>
              </div>
            </div>

            <p className="text-xs text-slate-500">
              Sessions on other devices were {revokeSessions ? 'signed out' : 'left active'}. Ask the user to change
              this password after signing in.
            </p>

            <button
              onClick={onClose}
              className="w-full px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors"
            >
              Done
            </button>
          </div>
        ) : (
          <>
            <div className="px-5 pt-4">
              <div className="flex p-1 bg-slate-100 rounded-lg">
                <button
                  type="button"
                  onClick={() => setMode('link')}
                  disabled={!canSendLink}
                  className={`flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-medium rounded-md transition-colors ${
                    mode === 'link' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                  } disabled:opacity-40 disabled:cursor-not-allowed`}
                >
                  <Mail className="h-3.5 w-3.5" />
                  Email reset link
                </button>
                <button
                  type="button"
                  onClick={() => setMode('manual')}
                  className={`flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-medium rounded-md transition-colors ${
                    mode === 'manual' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                  }`}
                >
                  <Lock className="h-3.5 w-3.5" />
                  Set password
                </button>
              </div>
            </div>

            {!canSendLink && (
              <div className="mx-5 mt-4 flex items-start gap-2 p-3 rounded-lg bg-amber-50 border border-amber-200">
                <AlertTriangle className="h-4 w-4 text-amber-600 flex-shrink-0 mt-0.5" />
                <p className="text-xs text-amber-800">
                  {isSuspended
                    ? 'This account is suspended, so a reset link cannot be sent. Set a password directly, or unsuspend the account first.'
                    : 'This account has no email address, so a reset link cannot be sent. Set a password directly and pass it on.'}
                </p>
              </div>
            )}

            {mode === 'link' ? (
              <div className="p-5 space-y-4">
                <p className="text-sm text-slate-600">
                  We will email a single-use reset link to <strong className="text-slate-800">{user.email}</strong>.
                  The link expires in 60 minutes and {user.name} chooses the new password themselves.
                </p>
                <p className="text-xs text-slate-500">
                  No password is stored or shared with you on this path. Any earlier link stops working once a new one
                  is requested.
                </p>
                <div className="flex gap-3 pt-1">
                  <button
                    type="button"
                    onClick={onClose}
                    disabled={isBusy}
                    className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors disabled:opacity-50"
                  >
                    Cancel
                  </button>
                  <button
                    type="button"
                    onClick={() => sendLinkMutation.mutate()}
                    disabled={isBusy}
                    className="flex-1 px-4 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors disabled:opacity-50 inline-flex items-center justify-center gap-2"
                  >
                    {sendLinkMutation.isPending ? (
                      <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                      <Mail className="h-4 w-4" />
                    )}
                    Send reset link
                  </button>
                </div>
              </div>
            ) : (
              <form onSubmit={handleSubmit} className="p-5 space-y-4">
                <div>
                  <div className="flex items-center justify-between mb-1.5">
                    <label className="block text-sm font-medium text-slate-700">
                      New password <span className="text-red-500">*</span>
                    </label>
                    <button
                      type="button"
                      onClick={handleGenerate}
                      className="inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-700"
                    >
                      <RefreshCw className="h-3 w-3" />
                      Generate
                    </button>
                  </div>
                  <div className="relative">
                    <input
                      type={showPassword ? 'text' : 'password'}
                      value={password}
                      onChange={(event) => setPassword(event.target.value)}
                      className="w-full pl-3.5 pr-10 py-2.5 rounded-lg border border-slate-200 bg-slate-50/50 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors"
                      placeholder="Minimum 8 characters"
                      minLength={8}
                      required
                      autoComplete="new-password"
                    />
                    <button
                      type="button"
                      onClick={() => setShowPassword((visible) => !visible)}
                      className="absolute right-2 top-1/2 -translate-y-1/2 p-1.5 text-slate-400 hover:text-slate-600 rounded-md"
                      title={showPassword ? 'Hide password' : 'Show password'}
                    >
                      {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                    </button>
                  </div>
                </div>

                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1.5">
                    Confirm password <span className="text-red-500">*</span>
                  </label>
                  <input
                    type={showPassword ? 'text' : 'password'}
                    value={confirmation}
                    onChange={(event) => setConfirmation(event.target.value)}
                    className="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 bg-slate-50/50 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors"
                    placeholder="Re-enter password"
                    required
                    autoComplete="new-password"
                  />
                </div>

                <div className="space-y-2.5 p-3 bg-slate-50 rounded-lg">
                  <label className="flex items-start gap-2.5 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={revokeSessions}
                      onChange={(event) => setRevokeSessions(event.target.checked)}
                      className="mt-0.5 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500/30"
                    />
                    <span className="text-xs text-slate-600">
                      <span className="font-medium text-slate-700">Sign out all devices</span> — revokes every active
                      session for this account.
                    </span>
                  </label>
                  <label
                    className={`flex items-start gap-2.5 ${hasEmail ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'}`}
                  >
                    <input
                      type="checkbox"
                      checked={notify && hasEmail}
                      onChange={(event) => setNotify(event.target.checked)}
                      disabled={!hasEmail}
                      className="mt-0.5 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500/30"
                    />
                    <span className="text-xs text-slate-600">
                      <span className="font-medium text-slate-700">Email the new password</span>
                      {hasEmail ? (
                        <> — sends the password to {user.email} as confirmation.</>
                      ) : (
                        <> — unavailable, no email address on file.</>
                      )}
                    </span>
                  </label>
                </div>

                <div className="flex items-start gap-2 p-3 rounded-lg bg-amber-50 border border-amber-200">
                  <AlertTriangle className="h-4 w-4 text-amber-600 flex-shrink-0 mt-0.5" />
                  <p className="text-xs text-amber-800">
                    The password is sent in plain text and stays in the user&apos;s inbox. Ask them to change it as
                    soon as they sign in.
                  </p>
                </div>

                <div className="flex gap-3 pt-1">
                  <button
                    type="button"
                    onClick={onClose}
                    disabled={isBusy}
                    className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors disabled:opacity-50"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={isBusy}
                    className="flex-1 px-4 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors disabled:opacity-50 inline-flex items-center justify-center gap-2"
                  >
                    {setPasswordMutation.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
                    Update password
                  </button>
                </div>
              </form>
            )}
          </>
        )}
      </div>
    </>
  )
}
