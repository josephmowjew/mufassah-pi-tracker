import { useState } from 'react'
import { Link } from 'react-router-dom'
import {
  Plus,
  Users,
  UserPlus,
  Crown,
  Shield,
  User,
  Baby,
  MoreVertical,
  Eye,
  LogOut,
  Mail,
  Loader2,
  AlertTriangle,
} from 'lucide-react'
import { useFamilies, useFamilyInvitations, useLeaveFamily } from '@/hooks/useFamilies'
import type { Family, FamilyRoleValue } from '@/types'
import CreateFamilyModal from './CreateFamilyModal'
import JoinFamilyModal from './JoinFamilyModal'
import InvitationsModal from './InvitationsModal'

const roleIcons: Record<FamilyRoleValue, typeof Crown> = {
  admin: Crown,
  guardian: Shield,
  member: User,
  child: Baby,
}

const roleColors: Record<FamilyRoleValue, { bg: string; text: string }> = {
  admin: { bg: 'bg-amber-50', text: 'text-amber-700' },
  guardian: { bg: 'bg-blue-50', text: 'text-blue-700' },
  member: { bg: 'bg-slate-100', text: 'text-slate-700' },
  child: { bg: 'bg-pink-50', text: 'text-pink-700' },
}

export default function FamiliesPage() {
  const [showCreateModal, setShowCreateModal] = useState(false)
  const [showJoinModal, setShowJoinModal] = useState(false)
  const [showInvitationsModal, setShowInvitationsModal] = useState(false)
  const [menuOpen, setMenuOpen] = useState<number | null>(null)

  const { data: families, isLoading, error } = useFamilies()
  const { data: invitations } = useFamilyInvitations()
  const leaveFamily = useLeaveFamily()

  const handleLeave = async (family: Family) => {
    if (family.is_owner) {
      alert('As the owner, you cannot leave. Transfer ownership first or delete the family.')
      return
    }
    if (confirm(`Are you sure you want to leave "${family.name}"?`)) {
      leaveFamily.mutate(family.id)
    }
    setMenuOpen(null)
  }

  const pendingCount = invitations?.length || 0

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            My Families
          </h1>
          <p className="text-sm text-slate-500 mt-1">
            Manage your family groups and share device tracking
          </p>
        </div>
        <div className="flex items-center gap-2">
          {pendingCount > 0 && (
            <button
              onClick={() => setShowInvitationsModal(true)}
              className="relative inline-flex items-center gap-2 px-4 py-2.5 bg-amber-50 text-amber-700 rounded-xl text-sm font-medium hover:bg-amber-100 transition-colors border border-amber-200"
            >
              <Mail className="h-4 w-4" />
              Invitations
              <span className="absolute -top-1 -right-1 h-5 w-5 bg-amber-500 text-white text-xs rounded-full flex items-center justify-center">
                {pendingCount}
              </span>
            </button>
          )}
          <button
            onClick={() => setShowJoinModal(true)}
            className="inline-flex items-center gap-2 px-4 py-2.5 bg-slate-100 text-slate-700 rounded-xl text-sm font-medium hover:bg-slate-200 transition-colors"
          >
            <UserPlus className="h-4 w-4" />
            Join Family
          </button>
          <button
            onClick={() => setShowCreateModal(true)}
            className="inline-flex items-center gap-2 px-4 py-2.5 bg-teal-500 text-white rounded-xl text-sm font-medium hover:bg-teal-600 transition-colors shadow-sm"
          >
            <Plus className="h-4 w-4" />
            Create Family
          </button>
        </div>
      </div>

      {/* Families Grid */}
      {isLoading ? (
        <div className="flex items-center justify-center py-20">
          <Loader2 className="h-8 w-8 text-teal-500 animate-spin" />
        </div>
      ) : error ? (
        <div className="flex flex-col items-center justify-center py-20 text-center bg-white rounded-xl border border-slate-200/60">
          <AlertTriangle className="h-10 w-10 text-red-400 mb-3" />
          <p className="text-slate-600">Failed to load families</p>
          <p className="text-sm text-slate-400">Please try again later</p>
        </div>
      ) : families?.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-20 text-center bg-white rounded-xl border border-slate-200/60">
          <div className="p-4 bg-slate-100 rounded-full mb-4">
            <Users className="h-10 w-10 text-slate-400" />
          </div>
          <h3 className="text-lg font-medium text-slate-800 mb-1">No families yet</h3>
          <p className="text-sm text-slate-500 mb-6 max-w-sm">
            Create a family to share device tracking with your loved ones, or join an existing family with an invite code.
          </p>
          <div className="flex items-center gap-3">
            <button
              onClick={() => setShowJoinModal(true)}
              className="inline-flex items-center gap-2 px-4 py-2 bg-slate-100 text-slate-700 rounded-lg text-sm font-medium hover:bg-slate-200 transition-colors"
            >
              <UserPlus className="h-4 w-4" />
              Join Family
            </button>
            <button
              onClick={() => setShowCreateModal(true)}
              className="inline-flex items-center gap-2 px-4 py-2 bg-teal-500 text-white rounded-lg text-sm font-medium hover:bg-teal-600 transition-colors"
            >
              <Plus className="h-4 w-4" />
              Create Family
            </button>
          </div>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {families?.map((family) => {
            const membership = family.my_membership
            const roleValue = membership?.role.value || 'member'
            const RoleIcon = roleIcons[roleValue]
            const colors = roleColors[roleValue]

            return (
              <div
                key={family.id}
                className="bg-white rounded-xl border border-slate-200/60 p-5 hover:shadow-md transition-shadow"
              >
                <div className="flex items-start justify-between mb-4">
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
                      <Link
                        to={`/families/${family.id}`}
                        className="font-semibold text-slate-800 hover:text-teal-600 transition-colors"
                      >
                        {family.name}
                      </Link>
                      <div className="flex items-center gap-2 mt-1">
                        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${colors.bg} ${colors.text}`}>
                          <RoleIcon className="h-3 w-3" />
                          {membership?.role.label || 'Member'}
                        </span>
                        {family.is_owner && (
                          <span className="text-xs text-slate-500">Owner</span>
                        )}
                      </div>
                    </div>
                  </div>
                  <div className="relative">
                    <button
                      onClick={() => setMenuOpen(menuOpen === family.id ? null : family.id)}
                      className="p-2 hover:bg-slate-100 rounded-lg transition-colors"
                    >
                      <MoreVertical className="h-4 w-4 text-slate-500" />
                    </button>
                    {menuOpen === family.id && (
                      <>
                        <div
                          className="fixed inset-0 z-40"
                          onClick={() => setMenuOpen(null)}
                        />
                        <div className="absolute right-0 mt-1 w-40 bg-white border border-slate-200 rounded-lg shadow-lg z-50 py-1">
                          <Link
                            to={`/families/${family.id}`}
                            className="flex items-center gap-2 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
                            onClick={() => setMenuOpen(null)}
                          >
                            <Eye className="h-4 w-4" />
                            View Details
                          </Link>
                          {!family.is_owner && (
                            <button
                              onClick={() => handleLeave(family)}
                              className="flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 w-full"
                            >
                              <LogOut className="h-4 w-4" />
                              Leave Family
                            </button>
                          )}
                        </div>
                      </>
                    )}
                  </div>
                </div>

                <div className="flex items-center justify-between text-sm">
                  <div className="flex items-center gap-1.5 text-slate-500">
                    <Users className="h-4 w-4" />
                    <span>{family.members_count || 0} members</span>
                  </div>
                  <Link
                    to={`/families/${family.id}`}
                    className="text-teal-600 hover:text-teal-700 font-medium"
                  >
                    View &rarr;
                  </Link>
                </div>
              </div>
            )
          })}
        </div>
      )}

      {/* Modals */}
      <CreateFamilyModal
        isOpen={showCreateModal}
        onClose={() => setShowCreateModal(false)}
      />
      <JoinFamilyModal
        isOpen={showJoinModal}
        onClose={() => setShowJoinModal(false)}
      />
      <InvitationsModal
        isOpen={showInvitationsModal}
        onClose={() => setShowInvitationsModal(false)}
      />
    </div>
  )
}
