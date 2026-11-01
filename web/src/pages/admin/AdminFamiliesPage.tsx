import { formatMalawiDate } from '@/lib/datetime'
import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  Search,
  Users,
  Eye,
  Trash2,
  Loader2,
  Copy,
  RefreshCw,
  UserPlus,
  Crown,
  Shield,
  User,
  Baby,
  X,
  Check,
} from 'lucide-react'
import apiClient from '@/api/client'
import type { User as UserType, FamilyMember, FamilyRoleValue, FamilyMemberStatusValue } from '@/types'
import { toast } from 'sonner'

interface AdminFamily {
  id: number
  name: string
  code: string
  avatar: string | null
  settings: Record<string, unknown> | null
  owner: UserType | null
  members_count: number
  created_at: string
  updated_at: string
}

interface FamiliesResponse {
  data: AdminFamily[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

interface FamilyStatistics {
  total_members: number
  active_members: number
  pending_members: number
  removed_members: number
}

const roleIcons: Record<FamilyRoleValue, typeof Crown> = {
  admin: Crown,
  guardian: Shield,
  member: User,
  child: Baby,
}

const roleColors: Record<FamilyRoleValue, { bg: string; text: string }> = {
  admin: { bg: 'bg-amber-100', text: 'text-amber-700' },
  guardian: { bg: 'bg-blue-100', text: 'text-blue-700' },
  member: { bg: 'bg-slate-100', text: 'text-slate-700' },
  child: { bg: 'bg-pink-100', text: 'text-pink-700' },
}

const statusColors: Record<FamilyMemberStatusValue, { bg: string; text: string }> = {
  active: { bg: 'bg-emerald-100', text: 'text-emerald-700' },
  pending: { bg: 'bg-amber-100', text: 'text-amber-700' },
  removed: { bg: 'bg-red-100', text: 'text-red-700' },
}

export default function AdminFamiliesPage() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [selectedFamily, setSelectedFamily] = useState<AdminFamily | null>(null)
  const [addMemberModal, setAddMemberModal] = useState<AdminFamily | null>(null)
  const [confirmDelete, setConfirmDelete] = useState<AdminFamily | null>(null)
  const [copiedCode, setCopiedCode] = useState<number | null>(null)

  // New member form state
  const [newMemberUserId, setNewMemberUserId] = useState('')
  const [newMemberRole, setNewMemberRole] = useState<FamilyRoleValue>('member')
  const [newMemberNickname, setNewMemberNickname] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['admin-families', search, page],
    queryFn: async () => {
      const params = new URLSearchParams()
      params.append('page', page.toString())
      params.append('per_page', '15')
      if (search) params.append('search', search)

      const response = await apiClient.get<FamiliesResponse>(`/admin/families?${params.toString()}`)
      return response.data
    },
  })

  const { data: familyDetails, isLoading: detailsLoading } = useQuery({
    queryKey: ['admin-family', selectedFamily?.id],
    queryFn: async () => {
      if (!selectedFamily) return null
      const response = await apiClient.get<{
        success: boolean
        data: AdminFamily
        statistics: FamilyStatistics
      }>(`/admin/families/${selectedFamily.id}`)
      return response.data
    },
    enabled: !!selectedFamily,
  })

  const { data: familyMembers } = useQuery({
    queryKey: ['admin-family-members', selectedFamily?.id],
    queryFn: async () => {
      if (!selectedFamily) return []
      const response = await apiClient.get<{ data: FamilyMember[] }>(
        `/admin/families/${selectedFamily.id}/members`
      )
      return response.data.data
    },
    enabled: !!selectedFamily,
  })

  const regenerateCodeMutation = useMutation({
    mutationFn: async (familyId: number) => {
      const response = await apiClient.post<{ success: boolean; data: { code: string } }>(
        `/admin/families/${familyId}/regenerate-code`
      )
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-families'] })
      queryClient.invalidateQueries({ queryKey: ['admin-family'] })
      toast.success('Invite code regenerated')
    },
    onError: () => {
      toast.error('Failed to regenerate code')
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (familyId: number) => {
      await apiClient.delete(`/admin/families/${familyId}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-families'] })
      setConfirmDelete(null)
      setSelectedFamily(null)
      toast.success('Family deleted successfully')
    },
    onError: () => {
      toast.error('Failed to delete family')
    },
  })

  const addMemberMutation = useMutation({
    mutationFn: async ({ familyId, userId, role, nickname }: { familyId: number; userId: number; role: string; nickname?: string }) => {
      const response = await apiClient.post(`/admin/families/${familyId}/members`, {
        user_id: userId,
        role,
        nickname: nickname || undefined,
      })
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-families'] })
      queryClient.invalidateQueries({ queryKey: ['admin-family'] })
      queryClient.invalidateQueries({ queryKey: ['admin-family-members'] })
      setAddMemberModal(null)
      setNewMemberUserId('')
      setNewMemberRole('member')
      setNewMemberNickname('')
      toast.success('Member added successfully')
    },
    onError: () => {
      toast.error('Failed to add member')
    },
  })

  const removeMemberMutation = useMutation({
    mutationFn: async ({ familyId, memberId }: { familyId: number; memberId: number }) => {
      await apiClient.delete(`/admin/families/${familyId}/members/${memberId}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-families'] })
      queryClient.invalidateQueries({ queryKey: ['admin-family'] })
      queryClient.invalidateQueries({ queryKey: ['admin-family-members'] })
      toast.success('Member removed')
    },
    onError: () => {
      toast.error('Failed to remove member')
    },
  })

  const families = data?.data || []
  const meta = data?.meta

  const handleCopyCode = async (family: AdminFamily) => {
    await navigator.clipboard.writeText(family.code)
    setCopiedCode(family.id)
    toast.success('Invite code copied!')
    setTimeout(() => setCopiedCode(null), 2000)
  }

  const handleAddMember = () => {
    if (!addMemberModal || !newMemberUserId) return
    addMemberMutation.mutate({
      familyId: addMemberModal.id,
      userId: parseInt(newMemberUserId),
      role: newMemberRole,
      nickname: newMemberNickname,
    })
  }

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            Families
          </h1>
          <p className="text-slate-500 text-sm mt-1">Manage family groups and members</p>
        </div>
        <div className="flex items-center gap-4">
          <div className="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-600 text-sm font-medium">
            {meta?.total || 0} total
          </div>
        </div>
      </div>

      {/* Main Card */}
      <div className="bg-white rounded-xl border border-slate-200/60 shadow-sm">
        {/* Search Bar */}
        <div className="p-4 border-b border-slate-200/60">
          <div className="relative max-w-md">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
            <input
              type="text"
              placeholder="Search by name, code, or owner..."
              className="w-full pl-10 pr-4 py-2.5 rounded-lg border border-slate-200 bg-slate-50/50 text-sm placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 transition-colors"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(1)
              }}
            />
          </div>
        </div>

        {/* Content */}
        {isLoading ? (
          <div className="flex items-center justify-center py-16">
            <Loader2 className="h-8 w-8 animate-spin text-teal-500" />
          </div>
        ) : families.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-16">
            <div className="h-12 w-12 rounded-xl bg-slate-100 flex items-center justify-center mb-3">
              <Users className="h-6 w-6 text-slate-400" />
            </div>
            <p className="text-slate-500 text-sm">No families found</p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-slate-200/60">
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Family</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Owner</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Invite Code</th>
                    <th className="text-center py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Members</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Created</th>
                    <th className="text-right py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {families.map((family) => (
                    <tr key={family.id} className="hover:bg-slate-50/50 transition-colors">
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-3">
                          <div className="h-10 w-10 rounded-full bg-gradient-to-br from-teal-400 to-teal-600 flex items-center justify-center flex-shrink-0">
                            <span className="text-white font-medium text-sm">
                              {family.name.charAt(0).toUpperCase()}
                            </span>
                          </div>
                          <div className="min-w-0">
                            <p className="font-medium text-slate-800 truncate">{family.name}</p>
                            <p className="text-xs text-slate-400">ID: {family.id}</p>
                          </div>
                        </div>
                      </td>
                      <td className="py-3 px-4">
                        {family.owner ? (
                          <div className="min-w-0">
                            <p className="text-sm font-medium text-slate-700 truncate">{family.owner.name}</p>
                            <p className="text-xs text-slate-400 truncate">{family.owner.email}</p>
                          </div>
                        ) : (
                          <span className="text-sm text-slate-400">-</span>
                        )}
                      </td>
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-2">
                          <code className="px-2 py-1 bg-slate-100 rounded text-xs font-mono text-slate-700">
                            {family.code}
                          </code>
                          <button
                            onClick={() => handleCopyCode(family)}
                            className="p-1 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded transition-colors"
                            title="Copy code"
                          >
                            {copiedCode === family.id ? (
                              <Check className="h-3.5 w-3.5 text-emerald-500" />
                            ) : (
                              <Copy className="h-3.5 w-3.5" />
                            )}
                          </button>
                          <button
                            onClick={() => regenerateCodeMutation.mutate(family.id)}
                            disabled={regenerateCodeMutation.isPending}
                            className="p-1 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded transition-colors"
                            title="Regenerate code"
                          >
                            <RefreshCw className={`h-3.5 w-3.5 ${regenerateCodeMutation.isPending ? 'animate-spin' : ''}`} />
                          </button>
                        </div>
                      </td>
                      <td className="py-3 px-4 text-center">
                        <span className="inline-flex items-center gap-1 px-2 py-1 bg-slate-100 rounded-full text-xs font-medium text-slate-600">
                          <Users className="h-3 w-3" />
                          {family.members_count}
                        </span>
                      </td>
                      <td className="py-3 px-4 text-sm text-slate-500">
                        {formatMalawiDate(family.created_at)}
                      </td>
                      <td className="py-3 px-4">
                        <div className="flex items-center justify-end gap-1">
                          <button
                            onClick={() => setSelectedFamily(family)}
                            className="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                            title="View details"
                          >
                            <Eye className="h-4 w-4" />
                          </button>
                          <button
                            onClick={() => setAddMemberModal(family)}
                            className="p-2 text-slate-400 hover:text-teal-600 hover:bg-teal-50 rounded-lg transition-colors"
                            title="Add member"
                          >
                            <UserPlus className="h-4 w-4" />
                          </button>
                          <button
                            onClick={() => setConfirmDelete(family)}
                            className="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                            title="Delete family"
                          >
                            <Trash2 className="h-4 w-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Pagination */}
            {meta && meta.last_page > 1 && (
              <div className="flex items-center justify-between px-4 py-3 border-t border-slate-200/60">
                <p className="text-sm text-slate-500">
                  Page {meta.current_page} of {meta.last_page}
                </p>
                <div className="flex gap-2">
                  <button
                    className="px-3 py-1.5 text-sm font-medium text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    disabled={meta.current_page === 1}
                    onClick={() => setPage(page - 1)}
                  >
                    Previous
                  </button>
                  <button
                    className="px-3 py-1.5 text-sm font-medium text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    disabled={meta.current_page === meta.last_page}
                    onClick={() => setPage(page + 1)}
                  >
                    Next
                  </button>
                </div>
              </div>
            )}
          </>
        )}
      </div>

      {/* Family Details Modal */}
      {selectedFamily && (
        <>
          <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40" onClick={() => setSelectedFamily(null)} />
          <div className="fixed inset-x-4 top-[5%] bottom-[5%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-2xl bg-white rounded-2xl shadow-xl z-50 overflow-hidden flex flex-col">
            <div className="p-5 border-b border-slate-200 flex items-center justify-between">
              <h3 className="text-lg font-semibold text-slate-800">Family Details</h3>
              <button onClick={() => setSelectedFamily(null)} className="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg">
                <X className="h-5 w-5" />
              </button>
            </div>
            <div className="flex-1 overflow-y-auto p-5 space-y-5">
              {detailsLoading ? (
                <div className="flex items-center justify-center py-10">
                  <Loader2 className="h-6 w-6 animate-spin text-teal-500" />
                </div>
              ) : (
                <>
                  {/* Family Info */}
                  <div className="flex items-center gap-4">
                    <div className="h-14 w-14 rounded-full bg-gradient-to-br from-teal-400 to-teal-600 flex items-center justify-center">
                      <span className="text-white font-semibold text-xl">{selectedFamily.name.charAt(0).toUpperCase()}</span>
                    </div>
                    <div>
                      <p className="text-lg font-semibold text-slate-800">{selectedFamily.name}</p>
                      <p className="text-sm text-slate-500">Owned by {familyDetails?.data?.owner?.name || selectedFamily.owner?.name}</p>
                    </div>
                  </div>

                  {/* Stats */}
                  {familyDetails?.statistics && (
                    <div className="grid grid-cols-4 gap-3">
                      <div className="p-3 bg-slate-50 rounded-lg text-center">
                        <p className="text-2xl font-bold text-slate-800">{familyDetails.statistics.total_members}</p>
                        <p className="text-xs text-slate-500">Total</p>
                      </div>
                      <div className="p-3 bg-emerald-50 rounded-lg text-center">
                        <p className="text-2xl font-bold text-emerald-600">{familyDetails.statistics.active_members}</p>
                        <p className="text-xs text-emerald-600">Active</p>
                      </div>
                      <div className="p-3 bg-amber-50 rounded-lg text-center">
                        <p className="text-2xl font-bold text-amber-600">{familyDetails.statistics.pending_members}</p>
                        <p className="text-xs text-amber-600">Pending</p>
                      </div>
                      <div className="p-3 bg-red-50 rounded-lg text-center">
                        <p className="text-2xl font-bold text-red-600">{familyDetails.statistics.removed_members}</p>
                        <p className="text-xs text-red-600">Removed</p>
                      </div>
                    </div>
                  )}

                  {/* Invite Code */}
                  <div className="p-4 bg-slate-50 rounded-xl">
                    <div className="flex items-center justify-between">
                      <div>
                        <p className="text-sm font-medium text-slate-700">Invite Code</p>
                        <p className="text-xs text-slate-500">Share this code to let users join</p>
                      </div>
                      <div className="flex items-center gap-2">
                        <code className="px-3 py-1.5 bg-white border border-slate-200 rounded-lg font-mono text-lg tracking-widest text-slate-800">
                          {familyDetails?.data?.code || selectedFamily.code}
                        </code>
                        <button
                          onClick={() => handleCopyCode(selectedFamily)}
                          className="p-2 hover:bg-slate-200 rounded-lg transition-colors"
                        >
                          {copiedCode === selectedFamily.id ? (
                            <Check className="h-4 w-4 text-emerald-500" />
                          ) : (
                            <Copy className="h-4 w-4 text-slate-500" />
                          )}
                        </button>
                      </div>
                    </div>
                  </div>

                  {/* Members List */}
                  <div>
                    <div className="flex items-center justify-between mb-3">
                      <h4 className="text-sm font-semibold text-slate-700">Members</h4>
                      <button
                        onClick={() => setAddMemberModal(selectedFamily)}
                        className="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-teal-600 hover:bg-teal-50 rounded-lg transition-colors"
                      >
                        <UserPlus className="h-3.5 w-3.5" />
                        Add Member
                      </button>
                    </div>
                    <div className="space-y-2">
                      {familyMembers?.map((member) => {
                        const roleValue = member.role.value
                        const statusValue = member.status.value
                        const RoleIcon = roleIcons[roleValue]
                        const roleColor = roleColors[roleValue]
                        const statusColor = statusColors[statusValue]

                        return (
                          <div
                            key={member.id}
                            className="flex items-center justify-between p-3 bg-slate-50 rounded-lg"
                          >
                            <div className="flex items-center gap-3">
                              <div className="h-9 w-9 rounded-full bg-gradient-to-br from-slate-300 to-slate-400 flex items-center justify-center text-white text-sm font-medium">
                                {member.user?.name?.charAt(0) || '?'}
                              </div>
                              <div>
                                <div className="flex items-center gap-2">
                                  <span className="font-medium text-sm text-slate-800">{member.display_name}</span>
                                  <span className={`inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium ${roleColor.bg} ${roleColor.text}`}>
                                    <RoleIcon className="h-2.5 w-2.5" />
                                    {member.role.label}
                                  </span>
                                  <span className={`px-1.5 py-0.5 rounded text-[10px] font-medium ${statusColor.bg} ${statusColor.text}`}>
                                    {member.status.label}
                                  </span>
                                </div>
                                <p className="text-xs text-slate-400">{member.user?.email}</p>
                              </div>
                            </div>
                            {member.is_active && member.user_id !== selectedFamily.owner?.id && (
                              <button
                                onClick={() => {
                                  if (confirm(`Remove ${member.display_name} from the family?`)) {
                                    removeMemberMutation.mutate({
                                      familyId: selectedFamily.id,
                                      memberId: member.id,
                                    })
                                  }
                                }}
                                className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors"
                                title="Remove member"
                              >
                                <X className="h-4 w-4" />
                              </button>
                            )}
                          </div>
                        )
                      })}
                    </div>
                  </div>
                </>
              )}
            </div>
            <div className="p-4 border-t border-slate-200 flex justify-end gap-2">
              <button
                onClick={() => setConfirmDelete(selectedFamily)}
                className="px-4 py-2 text-red-600 hover:bg-red-50 text-sm font-medium rounded-lg transition-colors"
              >
                Delete Family
              </button>
              <button
                onClick={() => setSelectedFamily(null)}
                className="px-4 py-2 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors"
              >
                Close
              </button>
            </div>
          </div>
        </>
      )}

      {/* Add Member Modal */}
      {addMemberModal && (
        <>
          <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50" onClick={() => setAddMemberModal(null)} />
          <div className="fixed inset-x-4 top-[15%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-md bg-white rounded-2xl shadow-xl z-50 overflow-hidden">
            <div className="p-5 border-b border-slate-200">
              <div className="flex items-center justify-between">
                <h3 className="text-lg font-semibold text-slate-800">Add Member</h3>
                <button onClick={() => setAddMemberModal(null)} className="p-1 text-slate-400 hover:text-slate-600 rounded-lg">
                  <X className="h-5 w-5" />
                </button>
              </div>
              <p className="text-sm text-slate-500 mt-1">Add a user to "{addMemberModal.name}"</p>
            </div>
            <div className="p-5 space-y-4">
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">User ID</label>
                <input
                  type="number"
                  value={newMemberUserId}
                  onChange={(e) => setNewMemberUserId(e.target.value)}
                  placeholder="Enter user ID"
                  className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">Role</label>
                <select
                  value={newMemberRole}
                  onChange={(e) => setNewMemberRole(e.target.value as FamilyRoleValue)}
                  className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500"
                >
                  <option value="admin">Admin</option>
                  <option value="guardian">Guardian</option>
                  <option value="member">Member</option>
                  <option value="child">Child</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">Nickname (Optional)</label>
                <input
                  type="text"
                  value={newMemberNickname}
                  onChange={(e) => setNewMemberNickname(e.target.value)}
                  placeholder="e.g., Mom, Dad, Junior"
                  className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500"
                  maxLength={50}
                />
              </div>
              <div className="flex gap-2 pt-2">
                <button
                  onClick={() => setAddMemberModal(null)}
                  className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors"
                >
                  Cancel
                </button>
                <button
                  onClick={handleAddMember}
                  disabled={!newMemberUserId || addMemberMutation.isPending}
                  className="flex-1 px-4 py-2.5 bg-teal-500 text-white text-sm font-medium rounded-lg hover:bg-teal-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {addMemberMutation.isPending ? (
                    <Loader2 className="h-4 w-4 animate-spin mx-auto" />
                  ) : (
                    'Add Member'
                  )}
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* Confirm Delete Modal */}
      {confirmDelete && (
        <>
          <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50" onClick={() => setConfirmDelete(null)} />
          <div className="fixed inset-x-4 top-[20%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-sm bg-white rounded-2xl shadow-xl z-50 overflow-hidden">
            <div className="p-5 space-y-4">
              <div className="h-12 w-12 rounded-full bg-red-100 mx-auto flex items-center justify-center">
                <Trash2 className="h-6 w-6 text-red-600" />
              </div>
              <div className="text-center">
                <h3 className="text-lg font-semibold text-slate-800">Delete Family</h3>
                <p className="text-sm text-slate-500 mt-1">
                  Are you sure you want to delete "{confirmDelete.name}"? All members will be removed and this action cannot be undone.
                </p>
              </div>
              <div className="flex gap-2">
                <button
                  onClick={() => setConfirmDelete(null)}
                  disabled={deleteMutation.isPending}
                  className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors disabled:opacity-50"
                >
                  Cancel
                </button>
                <button
                  onClick={() => deleteMutation.mutate(confirmDelete.id)}
                  disabled={deleteMutation.isPending}
                  className="flex-1 px-4 py-2.5 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700 transition-colors disabled:opacity-50"
                >
                  {deleteMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin mx-auto" /> : 'Delete'}
                </button>
              </div>
            </div>
          </div>
        </>
      )}
    </div>
  )
}
