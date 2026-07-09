import { useState } from 'react'
import { X, UserPlus, Loader2 } from 'lucide-react'
import { useJoinFamily } from '@/hooks/useFamilies'
import { toast } from 'sonner'
import { useNavigate } from 'react-router-dom'

interface JoinFamilyModalProps {
  isOpen: boolean
  onClose: () => void
}

export default function JoinFamilyModal({ isOpen, onClose }: JoinFamilyModalProps) {
  const [code, setCode] = useState('')
  const navigate = useNavigate()
  const joinFamily = useJoinFamily()

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (code.length !== 8) return

    try {
      const family = await joinFamily.mutateAsync(code.toUpperCase())
      toast.success(`You've joined "${family.name}"!`)
      onClose()
      setCode('')
      navigate(`/families/${family.id}`)
    } catch {
      toast.error('Invalid invite code. Please check and try again.')
    }
  }

  if (!isOpen) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      <div className="absolute inset-0 bg-black/50" onClick={onClose} />
      <div className="relative bg-white rounded-2xl shadow-xl w-full max-w-md mx-4">
        <div className="flex items-center justify-between p-5 border-b border-slate-100">
          <div className="flex items-center gap-3">
            <div className="p-2 bg-blue-50 rounded-lg">
              <UserPlus className="h-5 w-5 text-blue-600" />
            </div>
            <h2 className="text-lg font-semibold text-slate-800">Join Family</h2>
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
              Invite Code
            </label>
            <input
              type="text"
              value={code}
              onChange={(e) => setCode(e.target.value.toUpperCase().slice(0, 8))}
              placeholder="Enter 8-character code"
              className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm font-mono tracking-widest text-center uppercase focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500"
              required
              maxLength={8}
            />
            <p className="mt-1.5 text-xs text-slate-500">
              Ask a family member for the invite code to join their group
            </p>
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
              disabled={joinFamily.isPending || code.length !== 8}
              className="inline-flex items-center gap-2 px-4 py-2.5 bg-teal-500 text-white rounded-lg text-sm font-medium hover:bg-teal-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {joinFamily.isPending ? (
                <>
                  <Loader2 className="h-4 w-4 animate-spin" />
                  Joining...
                </>
              ) : (
                'Join Family'
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
