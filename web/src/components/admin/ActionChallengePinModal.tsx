import { useEffect, useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import { Clock3, Loader2, Mail, ShieldCheck } from 'lucide-react'

export interface ActionChallengeInfo {
  challenge_id: number
  action: string
  masked_email: string
  expires_at: string
  attempts_remaining: number
  financial?: {
    unit_cost: number
    unit_price: number
    total_cost: number
    total_amount: number
    profit_amount: number
    pricing_source: string
  }
}

interface ActionChallengePinModalProps {
  challenge: ActionChallengeInfo
  title: string
  description?: string
  error?: string | null
  isVerifying?: boolean
  isResending?: boolean
  onVerify: (pin: string) => void
  onResend?: () => void
  onClose: () => void
}

export default function ActionChallengePinModal({
  challenge,
  title,
  description,
  error,
  isVerifying = false,
  isResending = false,
  onVerify,
  onResend,
  onClose,
}: ActionChallengePinModalProps) {
  const [pin, setPin] = useState('')
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(timer)
  }, [])

  const secondsRemaining = useMemo(() => Math.max(0, Math.floor((new Date(challenge.expires_at).getTime() - now) / 1000)), [challenge.expires_at, now])
  const expired = secondsRemaining <= 0
  const minutes = Math.floor(secondsRemaining / 60)
  const seconds = String(secondsRemaining % 60).padStart(2, '0')

  const submit = (event: FormEvent) => {
    event.preventDefault()
    if (pin.length === 6 && !expired && !isVerifying) onVerify(pin)
  }

  return (
    <div className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-900/60 p-4">
      <div className="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div className="border-b border-slate-200 p-5">
          <div className="flex items-start gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-blue-100 text-blue-700">
              <ShieldCheck className="h-5 w-5" />
            </div>
            <div>
              <h2 className="text-lg font-semibold text-slate-900">{title}</h2>
              <p className="mt-1 text-sm text-slate-500">{description || 'Enter the one-time PIN to confirm this sensitive action.'}</p>
            </div>
          </div>
        </div>
        <form onSubmit={submit} className="space-y-4 p-5">
          <div className="rounded-lg bg-slate-50 p-3 text-sm text-slate-600">
            <div className="flex items-center gap-2"><Mail className="h-4 w-4 text-slate-400" /> PIN sent to <span className="font-medium text-slate-800">{challenge.masked_email}</span></div>
            <div className={`mt-2 flex items-center gap-2 font-medium ${expired ? 'text-red-600' : 'text-amber-700'}`}>
              <Clock3 className="h-4 w-4" /> {expired ? 'PIN expired' : `Expires in ${minutes}:${seconds}`}
            </div>
          </div>
          <div>
            <label htmlFor="action-pin" className="mb-1 block text-sm font-medium text-slate-700">Six-digit PIN</label>
            <input
              id="action-pin"
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              value={pin}
              onChange={(event) => setPin(event.target.value.replace(/\D/g, '').slice(0, 6))}
              className="w-full rounded-lg border border-slate-300 px-4 py-3 text-center text-2xl tracking-[0.5em] focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
              placeholder="••••••"
              disabled={expired || isVerifying}
              autoFocus
            />
            <p className="mt-1 text-xs text-slate-500">{challenge.attempts_remaining} attempts remaining</p>
          </div>
          {error && <p className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{error}</p>}
          <div className="flex justify-between gap-2 pt-1">
            <button type="button" onClick={onClose} className="rounded-lg px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-100">Cancel</button>
            <div className="flex gap-2">
              {onResend && (
                <button type="button" onClick={onResend} disabled={isResending || !expired} className="rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50">
                  {isResending ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Resend PIN'}
                </button>
              )}
              <button type="submit" disabled={pin.length !== 6 || expired || isVerifying} className="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">
                {isVerifying ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Verify & complete'}
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  )
}
