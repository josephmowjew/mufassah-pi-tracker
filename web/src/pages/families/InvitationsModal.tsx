import { X, Mail, Check, XIcon, Loader2, Users } from 'lucide-react'
import { useFamilyInvitations, useRespondToInvitation } from '@/hooks/useFamilies'
import { toast } from 'sonner'

interface InvitationsModalProps {
  isOpen: boolean
  onClose: () => void
}

export default function InvitationsModal({ isOpen, onClose }: InvitationsModalProps) {
  const { data: invitations, isLoading } = useFamilyInvitations()
  const respondToInvitation = useRespondToInvitation()

  const handleRespond = async (invitationId: number, accept: boolean, familyName: string) => {
    try {
      await respondToInvitation.mutateAsync({ invitationId, accept })
      toast.success(accept ? `You've joined "${familyName}"!` : 'Invitation declined')
    } catch {
      toast.error('Failed to respond to invitation')
    }
  }

  if (!isOpen) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      <div className="absolute inset-0 bg-black/50" onClick={onClose} />
      <div className="relative bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 max-h-[80vh] flex flex-col">
        <div className="flex items-center justify-between p-5 border-b border-slate-100">
          <div className="flex items-center gap-3">
            <div className="p-2 bg-amber-50 rounded-lg">
              <Mail className="h-5 w-5 text-amber-600" />
            </div>
            <h2 className="text-lg font-semibold text-slate-800">Family Invitations</h2>
          </div>
          <button
            onClick={onClose}
            className="p-2 hover:bg-slate-100 rounded-lg transition-colors"
          >
            <X className="h-5 w-5 text-slate-500" />
          </button>
        </div>

        <div className="flex-1 overflow-y-auto p-5">
          {isLoading ? (
            <div className="flex items-center justify-center py-10">
              <Loader2 className="h-6 w-6 text-teal-500 animate-spin" />
            </div>
          ) : !invitations || invitations.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-10 text-center">
              <div className="p-3 bg-slate-100 rounded-full mb-3">
                <Users className="h-6 w-6 text-slate-400" />
              </div>
              <p className="text-slate-600">No pending invitations</p>
              <p className="text-sm text-slate-400 mt-1">
                You're all caught up!
              </p>
            </div>
          ) : (
            <div className="space-y-3">
              {invitations.map((invitation) => (
                <div
                  key={invitation.id}
                  className="bg-slate-50 rounded-xl p-4 border border-slate-100"
                >
                  <div className="flex items-start gap-3 mb-3">
                    <div className="h-10 w-10 rounded-full bg-gradient-to-br from-teal-400 to-teal-600 flex items-center justify-center text-white text-sm font-semibold flex-shrink-0">
                      {invitation.family.name.charAt(0)}
                    </div>
                    <div className="flex-1 min-w-0">
                      <h3 className="font-medium text-slate-800 truncate">
                        {invitation.family.name}
                      </h3>
                      <p className="text-sm text-slate-500">
                        Invited as <span className="font-medium">{invitation.role.label}</span>
                      </p>
                      {invitation.invited_by && (
                        <p className="text-xs text-slate-400 mt-1">
                          By {invitation.invited_by.name}
                        </p>
                      )}
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <button
                      onClick={() => handleRespond(invitation.id, false, invitation.family.name)}
                      disabled={respondToInvitation.isPending}
                      className="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 bg-white border border-slate-200 text-slate-600 rounded-lg text-sm font-medium hover:bg-slate-50 transition-colors disabled:opacity-50"
                    >
                      <XIcon className="h-4 w-4" />
                      Decline
                    </button>
                    <button
                      onClick={() => handleRespond(invitation.id, true, invitation.family.name)}
                      disabled={respondToInvitation.isPending}
                      className="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 bg-teal-500 text-white rounded-lg text-sm font-medium hover:bg-teal-600 transition-colors disabled:opacity-50"
                    >
                      <Check className="h-4 w-4" />
                      Accept
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
