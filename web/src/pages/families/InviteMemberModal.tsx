import { useState } from 'react'
import { X, UserPlus, Loader2, Crown, Shield, User, Baby } from 'lucide-react'
import { useInviteMember } from '@/hooks/useFamilies'
import type { FamilyRoleValue } from '@/types'
import { toast } from 'sonner'

interface InviteMemberModalProps {
  isOpen: boolean
  onClose: () => void
  familyId: number
}

const roles: { value: FamilyRoleValue; label: string; description: string; icon: typeof Crown }[] = [
  { value: 'guardian', label: 'Guardian', description: 'Can manage members and view all devices', icon: Shield },
  { value: 'member', label: 'Member', description: 'Standard access to family features', icon: User },
  { value: 'child', label: 'Child', description: 'Limited access, location always shared', icon: Baby },
]

export default function InviteMemberModal({ isOpen, onClose, familyId }: InviteMemberModalProps) {
  const [email, setEmail] = useState('')
  const [role, setRole] = useState<FamilyRoleValue>('member')
  const [nickname, setNickname] = useState('')

  const inviteMember = useInviteMember()

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!email.trim()) return

    try {
      await inviteMember.mutateAsync({
        familyId,
        data: {
          email: email.trim(),
          role,
          nickname: nickname.trim() || undefined,
        },
      })
      toast.success('Invitation sent!')
      onClose()
      setEmail('')
      setRole('member')
      setNickname('')
    } catch {
      toast.error('Failed to send invitation. Make sure the email is registered.')
    }
  }

  if (!isOpen) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      <div className="absolute inset-0 bg-black/50" onClick={onClose} />
      <div className="relative bg-white rounded-2xl shadow-xl w-full max-w-md mx-4">
        <div className="flex items-center justify-between p-5 border-b border-slate-100">
          <div className="flex items-center gap-3">
            <div className="p-2 bg-teal-50 rounded-lg">
              <UserPlus className="h-5 w-5 text-teal-600" />
            </div>
            <h2 className="text-lg font-semibold text-slate-800">Invite Member</h2>
          </div>
          <button
            onClick={onClose}
            className="p-2 hover:bg-slate-100 rounded-lg transition-colors"
          >
            <X className="h-5 w-5 text-slate-500" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-5 space-y-4">
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1.5">
              Email Address
            </label>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="member@example.com"
              className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500"
              required
            />
            <p className="mt-1.5 text-xs text-slate-500">
              The person must have a registered account
            </p>
          </div>

          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1.5">
              Role
            </label>
            <div className="space-y-2">
              {roles.map((r) => {
                const Icon = r.icon
                return (
                  <label
                    key={r.value}
                    className={`flex items-center gap-3 p-3 rounded-lg border cursor-pointer transition-colors ${
                      role === r.value
                        ? 'border-teal-500 bg-teal-50'
                        : 'border-slate-200 hover:bg-slate-50'
                    }`}
                  >
                    <input
                      type="radio"
                      name="role"
                      value={r.value}
                      checked={role === r.value}
                      onChange={(e) => setRole(e.target.value as FamilyRoleValue)}
                      className="sr-only"
                    />
                    <div className={`p-2 rounded-lg ${role === r.value ? 'bg-teal-100' : 'bg-slate-100'}`}>
                      <Icon className={`h-4 w-4 ${role === r.value ? 'text-teal-600' : 'text-slate-500'}`} />
                    </div>
                    <div>
                      <div className="font-medium text-sm text-slate-800">{r.label}</div>
                      <div className="text-xs text-slate-500">{r.description}</div>
                    </div>
                  </label>
                )
              })}
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1.5">
              Nickname (Optional)
            </label>
            <input
              type="text"
              value={nickname}
              onChange={(e) => setNickname(e.target.value)}
              placeholder="e.g., Mom, Dad, Junior"
              className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500"
              maxLength={50}
            />
          </div>

          <div className="flex justify-end gap-3 pt-2">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={inviteMember.isPending || !email.trim()}
              className="inline-flex items-center gap-2 px-4 py-2.5 bg-teal-500 text-white rounded-lg text-sm font-medium hover:bg-teal-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {inviteMember.isPending ? (
                <>
                  <Loader2 className="h-4 w-4 animate-spin" />
                  Sending...
                </>
              ) : (
                'Send Invitation'
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
