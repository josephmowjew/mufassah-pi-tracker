import { useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import {
  ArrowLeft,
  Users,
  Crown,
  Shield,
  User,
  Baby,
  Copy,
  RefreshCw,
  UserPlus,
  Settings,
  Trash2,
  MoreVertical,
  Check,
  X,
  Loader2,
  AlertTriangle,
  LogOut,
} from 'lucide-react'
import {
  useFamily,
  useFamilyMembers,
  useDeleteFamily,
  useLeaveFamily,
  useRegenerateCode,
  useRemoveMember,
  useApproveMember,
} from '@/hooks/useFamilies'
import type { FamilyMember, FamilyRoleValue } from '@/types'
import { toast } from 'sonner'
import InviteMemberModal from './InviteMemberModal'

const roleIcons: Record<FamilyRoleValue, typeof Crown> = {
  admin: Crown,
  guardian: Shield,
  member: User,
  child: Baby,
}

const roleColors: Record<FamilyRoleValue, { bg: string; text: string; border: string }> = {
  admin: { bg: 'bg-amber-50', text: 'text-amber-700', border: 'border-amber-200' },
  guardian: { bg: 'bg-blue-50', text: 'text-blue-700', border: 'border-blue-200' },
  member: { bg: 'bg-slate-100', text: 'text-slate-700', border: 'border-slate-200' },
  child: { bg: 'bg-pink-50', text: 'text-pink-700', border: 'border-pink-200' },
}

export default function FamilyDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const familyId = parseInt(id || '0')

  const [showInviteModal, setShowInviteModal] = useState(false)
  const [memberMenuOpen, setMemberMenuOpen] = useState<number | null>(null)
  const [codeCopied, setCodeCopied] = useState(false)

  const { data: family, isLoading, error } = useFamily(familyId)
  const { data: members } = useFamilyMembers(familyId)

  const deleteFamily = useDeleteFamily()
  const leaveFamily = useLeaveFamily()
  const regenerateCode = useRegenerateCode()
  const removeMember = useRemoveMember()
  const approveMember = useApproveMember()

  const membership = family?.my_membership
  const canManageMembers = membership?.can_manage_members || false
  const canManageSettings = membership?.can_manage_settings || false

  const handleCopyCode = async () => {
    if (family?.code) {
      await navigator.clipboard.writeText(family.code)
      setCodeCopied(true)
      toast.success('Invite code copied!')
      setTimeout(() => setCodeCopied(false), 2000)
    }
  }

  const handleRegenerateCode = async () => {
    if (confirm('Generate a new invite code? The old code will no longer work.')) {
      try {
        await regenerateCode.mutateAsync(familyId)
        toast.success('New invite code generated!')
      } catch {
        toast.error('Failed to regenerate code')
      }
    }
  }

  const handleDeleteFamily = async () => {
    if (confirm(`Are you sure you want to delete "${family?.name}"? This action cannot be undone.`)) {
      try {
        await deleteFamily.mutateAsync(familyId)
        toast.success('Family deleted')
        navigate('/families')
      } catch {
        toast.error('Failed to delete family')
      }
    }
  }

  const handleLeaveFamily = async () => {
    if (confirm(`Are you sure you want to leave "${family?.name}"?`)) {
      try {
        await leaveFamily.mutateAsync(familyId)
        toast.success('You have left the family')
        navigate('/families')
      } catch {
        toast.error('Failed to leave family')
      }
    }
  }

  const handleRemoveMember = async (member: FamilyMember) => {
    if (confirm(`Remove ${member.display_name} from the family?`)) {
      try {
        await removeMember.mutateAsync({ familyId, memberId: member.id })
        toast.success(`${member.display_name} has been removed`)
      } catch {
        toast.error('Failed to remove member')
      }
    }
    setMemberMenuOpen(null)
  }

  const handleApproveMember = async (member: FamilyMember) => {
    try {
      await approveMember.mutateAsync({ familyId, memberId: member.id })
      toast.success(`${member.display_name} has been approved`)
    } catch {
      toast.error('Failed to approve member')
    }
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-20">
        <Loader2 className="h-8 w-8 text-teal-500 animate-spin" />
      </div>
    )
  }

  if (error || !family) {
    return (
      <div className="flex flex-col items-center justify-center py-20 text-center">
        <AlertTriangle className="h-10 w-10 text-red-400 mb-3" />
        <p className="text-slate-600">Failed to load family</p>
        <Link to="/families" className="text-teal-600 hover:underline mt-2">
          Back to families
        </Link>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Link
          to="/families"
          className="p-2 hover:bg-slate-100 rounded-lg transition-colors"
        >
          <ArrowLeft className="h-5 w-5 text-slate-500" />
        </Link>
        <div className="flex-1">
          <div className="flex items-center gap-3">
            {family.avatar ? (
              <img
                src={family.avatar}
                alt={family.name}
                className="h-12 w-12 rounded-full object-cover border-2 border-slate-100"
              />
            ) : (
              <div className="h-12 w-12 rounded-full bg-gradient-to-br from-teal-400 to-teal-600 flex items-center justify-center text-white text-lg font-semibold">
                {family.name.charAt(0)}
              </div>
            )}
            <div>
              <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
                {family.name}
              </h1>
              <p className="text-sm text-slate-500">
                {family.members_count || 0} members
                {family.is_owner && ' • You are the owner'}
              </p>
            </div>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {canManageMembers && (
            <button
              onClick={() => setShowInviteModal(true)}
              className="inline-flex items-center gap-2 px-4 py-2.5 bg-teal-500 text-white rounded-xl text-sm font-medium hover:bg-teal-600 transition-colors shadow-sm"
            >
              <UserPlus className="h-4 w-4" />
              Invite Member
            </button>
          )}
          {!family.is_owner && (
            <button
              onClick={handleLeaveFamily}
              className="inline-flex items-center gap-2 px-4 py-2.5 bg-red-50 text-red-600 rounded-xl text-sm font-medium hover:bg-red-100 transition-colors"
            >
              <LogOut className="h-4 w-4" />
              Leave
            </button>
          )}
        </div>
      </div>

      {/* Invite Code (for guardians+) */}
      {family.code && (
        <div className="bg-white rounded-xl border border-slate-200/60 p-5">
          <div className="flex items-center justify-between">
            <div>
              <h3 className="text-sm font-medium text-slate-700 mb-1">Invite Code</h3>
              <p className="text-xs text-slate-500">Share this code with family members to let them join</p>
            </div>
            <div className="flex items-center gap-2">
              <div className="px-4 py-2 bg-slate-50 rounded-lg font-mono text-lg tracking-widest text-slate-800 border border-slate-200">
                {family.code}
              </div>
              <button
                onClick={handleCopyCode}
                className="p-2 hover:bg-slate-100 rounded-lg transition-colors"
                title="Copy code"
              >
                {codeCopied ? (
                  <Check className="h-5 w-5 text-emerald-500" />
                ) : (
                  <Copy className="h-5 w-5 text-slate-500" />
                )}
              </button>
              {canManageSettings && (
                <button
                  onClick={handleRegenerateCode}
                  disabled={regenerateCode.isPending}
                  className="p-2 hover:bg-slate-100 rounded-lg transition-colors"
                  title="Generate new code"
                >
                  <RefreshCw className={`h-5 w-5 text-slate-500 ${regenerateCode.isPending ? 'animate-spin' : ''}`} />
                </button>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Members */}
      <div className="bg-white rounded-xl border border-slate-200/60">
        <div className="px-5 py-4 border-b border-slate-100">
          <h2 className="text-lg font-semibold text-slate-800 flex items-center gap-2">
            <Users className="h-5 w-5 text-slate-400" />
            Members
          </h2>
        </div>
        <div className="divide-y divide-slate-100">
          {members?.map((member) => {
            const roleValue = member.role.value
            const RoleIcon = roleIcons[roleValue]
            const colors = roleColors[roleValue]
            const isOwner = family.owner.id === member.user_id

            return (
              <div
                key={member.id}
                className="flex items-center justify-between px-5 py-4 hover:bg-slate-50/50 transition-colors"
              >
                <div className="flex items-center gap-3">
                  <div className="h-10 w-10 rounded-full bg-gradient-to-br from-slate-200 to-slate-300 flex items-center justify-center text-slate-600 text-sm font-semibold">
                    {member.user?.name?.charAt(0) || '?'}
                  </div>
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="font-medium text-slate-800">
                        {member.display_name}
                      </span>
                      {isOwner && (
                        <span className="text-xs text-slate-500">(Owner)</span>
                      )}
                      {member.is_pending && (
                        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200">
                          Pending
                        </span>
                      )}
                    </div>
                    <div className="flex items-center gap-2 mt-1">
                      <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${colors.bg} ${colors.text} border ${colors.border}`}>
                        <RoleIcon className="h-3 w-3" />
                        {member.role.label}
                      </span>
                      {member.user?.email && (
                        <span className="text-xs text-slate-400">
                          {member.user.email}
                        </span>
                      )}
                    </div>
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  {member.is_pending && canManageMembers && (
                    <button
                      onClick={() => handleApproveMember(member)}
                      className="inline-flex items-center gap-1 px-3 py-1.5 bg-emerald-50 text-emerald-700 rounded-lg text-xs font-medium hover:bg-emerald-100 transition-colors"
                    >
                      <Check className="h-3.5 w-3.5" />
                      Approve
                    </button>
                  )}
                  {canManageMembers && !isOwner && member.is_active && (
                    <div className="relative">
                      <button
                        onClick={() => setMemberMenuOpen(memberMenuOpen === member.id ? null : member.id)}
                        className="p-2 hover:bg-slate-100 rounded-lg transition-colors"
                      >
                        <MoreVertical className="h-4 w-4 text-slate-500" />
                      </button>
                      {memberMenuOpen === member.id && (
                        <>
                          <div
                            className="fixed inset-0 z-40"
                            onClick={() => setMemberMenuOpen(null)}
                          />
                          <div className="absolute right-0 mt-1 w-40 bg-white border border-slate-200 rounded-lg shadow-lg z-50 py-1">
                            <button
                              onClick={() => handleRemoveMember(member)}
                              className="flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 w-full"
                            >
                              <X className="h-4 w-4" />
                              Remove
                            </button>
                          </div>
                        </>
                      )}
                    </div>
                  )}
                </div>
              </div>
            )
          })}
        </div>
      </div>

      {/* Danger Zone (Owner only) */}
      {family.is_owner && (
        <div className="bg-red-50 rounded-xl border border-red-200 p-5">
          <h3 className="text-lg font-semibold text-red-800 flex items-center gap-2 mb-2">
            <Settings className="h-5 w-5" />
            Danger Zone
          </h3>
          <p className="text-sm text-red-600 mb-4">
            Deleting a family is permanent and cannot be undone. All members will be removed.
          </p>
          <button
            onClick={handleDeleteFamily}
            disabled={deleteFamily.isPending}
            className="inline-flex items-center gap-2 px-4 py-2.5 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 transition-colors disabled:opacity-50"
          >
            {deleteFamily.isPending ? (
              <>
                <Loader2 className="h-4 w-4 animate-spin" />
                Deleting...
              </>
            ) : (
              <>
                <Trash2 className="h-4 w-4" />
                Delete Family
              </>
            )}
          </button>
        </div>
      )}

      {/* Invite Modal */}
      <InviteMemberModal
        isOpen={showInviteModal}
        onClose={() => setShowInviteModal(false)}
        familyId={familyId}
      />
    </div>
  )
}
