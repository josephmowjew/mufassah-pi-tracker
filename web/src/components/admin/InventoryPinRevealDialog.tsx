import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Check, Clock3, Copy, Eye, EyeOff, KeyRound, Loader2, X } from 'lucide-react'
import { toast } from 'sonner'
import notificationsApi from '@/api/notifications'
import { getApiErrorMessage } from '@/lib/utils'
import { useInventoryPinStore, type InventoryPinRevealContext } from '@/stores/inventory-pin-store'

export default function InventoryPinRevealDialog() {
  const context = useInventoryPinStore((state) => state.revealContext)

  if (!context) return null

  return <InventoryPinRevealContent key={`${context.challengeId}-${context.expiresAt}-${context.notificationId || ''}`} context={context} />
}

function InventoryPinRevealContent({ context }: { context: InventoryPinRevealContext }) {
  const queryClient = useQueryClient()
  const closeReveal = useInventoryPinStore((state) => state.closeReveal)
  const setRevealedPin = useInventoryPinStore((state) => state.setRevealedPin)
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [revealedPin, setRevealedPinValue] = useState<string | null>(null)
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(timer)
  }, [])

  const secondsRemaining = useMemo(() => Math.max(0, Math.floor((new Date(context.expiresAt).getTime() - now) / 1000)), [context.expiresAt, now])
  const expired = secondsRemaining <= 0
  const countdown = `${Math.floor(secondsRemaining / 60)}:${String(secondsRemaining % 60).padStart(2, '0')}`

  const revealMutation = useMutation({
    mutationFn: () => notificationsApi.revealInventoryPin(context.challengeId, password),
    onSuccess: async (data) => {
      setRevealedPinValue(data.pin)
      setRevealedPin(data.challenge_id, data.pin, data.expires_at)
      setPassword('')
      if (context.notificationId) {
        await notificationsApi.markAsRead(context.notificationId).catch(() => undefined)
      }
      queryClient.invalidateQueries({ queryKey: ['notifications'] })
      queryClient.invalidateQueries({ queryKey: ['notifications-unread-count'] })
      toast.success('PIN revealed and filled into the matching confirmation dialog')
    },
    onError: (error) => toast.error(getApiErrorMessage(error, 'Unable to reveal the PIN')),
  })

  const copyPin = async () => {
    if (!revealedPin) return
    try {
      await navigator.clipboard.writeText(revealedPin)
      toast.success('PIN copied')
    } catch {
      toast.error('Unable to copy the PIN. You can enter it manually.')
    }
  }

  return (
    <div className="fixed inset-0 z-[90] flex items-center justify-center bg-slate-900/65 p-4 backdrop-blur-sm">
      <div className="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div className="flex items-start justify-between border-b border-slate-200 p-5">
          <div className="flex gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-violet-100 text-violet-700">
              <KeyRound className="h-5 w-5" />
            </div>
            <div>
              <h2 className="text-lg font-semibold text-slate-900">Reveal approval PIN</h2>
              <p className="mt-1 text-sm text-slate-500">{context.actionLabel}</p>
            </div>
          </div>
          <button type="button" onClick={closeReveal} className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Close PIN reveal dialog">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="space-y-4 p-5">
          {context.targetLabel && <p className="rounded-lg bg-slate-50 p-3 text-sm text-slate-700">{context.targetLabel}</p>}
          <div className={`flex items-center gap-2 text-sm font-medium ${expired ? 'text-red-600' : 'text-amber-700'}`}>
            <Clock3 className="h-4 w-4" /> {expired ? 'PIN expired' : `Expires in ${countdown}`}
          </div>

          {revealedPin ? (
            <div className="space-y-3">
              <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-center">
                <div className="mb-2 flex items-center justify-center gap-2 text-sm font-medium text-emerald-700">
                  <Check className="h-4 w-4" /> PIN ready to use
                </div>
                <p className="font-mono text-3xl font-semibold tracking-[0.35em] text-slate-900">{revealedPin}</p>
              </div>
              <div className="flex justify-end gap-2">
                <button type="button" onClick={copyPin} className="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
                  <Copy className="h-4 w-4" /> Copy PIN
                </button>
                <button type="button" onClick={closeReveal} className="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">Use PIN</button>
              </div>
            </div>
          ) : (
            <form onSubmit={(event) => { event.preventDefault(); if (!expired && password) revealMutation.mutate() }} className="space-y-4">
              <div>
                <label htmlFor="pin-reveal-password" className="mb-1 block text-sm font-medium text-slate-700">Current password</label>
                <div className="relative">
                  <input
                    id="pin-reveal-password"
                    type={showPassword ? 'text' : 'password'}
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                    autoComplete="current-password"
                    className="w-full rounded-lg border border-slate-300 px-4 py-3 pr-11 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
                    placeholder="Enter your current password"
                    disabled={expired || revealMutation.isPending}
                    autoFocus
                  />
                  <button type="button" onClick={() => setShowPassword((value) => !value)} className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700" aria-label={showPassword ? 'Hide password' : 'Show password'}>
                    {showPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                  </button>
                </div>
                <p className="mt-1.5 text-xs text-slate-500">For security, your password is required each time the PIN is revealed.</p>
              </div>
              <div className="flex justify-end gap-2">
                <button type="button" onClick={closeReveal} className="rounded-lg px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-100">Cancel</button>
                <button type="submit" disabled={!password || expired || revealMutation.isPending} className="inline-flex items-center gap-2 rounded-lg bg-violet-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-50">
                  {revealMutation.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
                  Reveal PIN
                </button>
              </div>
            </form>
          )}
        </div>
      </div>
    </div>
  )
}
