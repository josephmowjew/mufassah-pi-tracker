import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Search, Edit, Trash2, Shield, Ban, Mail, Loader2, CheckCircle, XCircle, Users, Eye, RefreshCw, MailCheck, X, KeyRound, SlidersHorizontal } from 'lucide-react'
import { Authorize } from '@/components/auth/Authorize'
import { PERMISSIONS } from '@/hooks/usePermission'
import apiClient from '@/api/client'
import PasswordResetModal from '@/features/users/PasswordResetModal'
import type { User as UserType } from '@/types'
import { toast } from 'sonner'

interface UsersResponse {
  data: UserType[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

interface Role {
  id: number
  name: string
}

interface BranchOption {
  id: number
  name: string
  is_active?: boolean
}

interface UserFormData {
  name: string
  email: string
  phone_number: string
  national_id: string
  password: string
  password_confirmation: string
  role: string
  branch_id: string
}

const initialFormData: UserFormData = {
  name: '',
  email: '',
  phone_number: '',
  national_id: '',
  password: '',
  password_confirmation: '',
  role: 'customer',
  branch_id: '',
}

const userContactLabel = (user: Pick<UserType, 'email' | 'phone_number'>) =>
  user.email || user.phone_number || 'No contact details'

/** Mirrors App\Models\User::STAFF_ROLES - keep the two lists in step. */
const STAFF_ROLES = ['super_admin', 'store_admin', 'customer_service']

const ROLE_LABELS: Record<string, string> = {
  super_admin: 'Super admin',
  store_admin: 'Store admin',
  customer_service: 'Customer service',
  customer: 'Customer',
}

const roleLabel = (role: string) => ROLE_LABELS[role] ?? role.replace(/_/g, ' ')

export default function AdminUsersPage() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [roleFilter, setRoleFilter] = useState('')
  const [typeFilter, setTypeFilter] = useState('')
  const [page, setPage] = useState(1)
  const [selectedUser, setSelectedUser] = useState<UserType | null>(null)
  const [roleModalUser, setRoleModalUser] = useState<UserType | null>(null)
  const [passwordResetUser, setPasswordResetUser] = useState<UserType | null>(null)
  const [confirmAction, setConfirmAction] = useState<{ type: 'suspend' | 'unsuspend' | 'delete' | 'verify'; user: UserType } | null>(null)
  const [showCreateModal, setShowCreateModal] = useState(false)
  const [editingUser, setEditingUser] = useState<UserType | null>(null)
  const [formData, setFormData] = useState<UserFormData>(initialFormData)
  const [formErrors, setFormErrors] = useState<Partial<Record<keyof UserFormData, string>>>({})

  const hasActiveFilters = Boolean(search || roleFilter || typeFilter)

  const { data, isLoading } = useQuery({
    queryKey: ['admin-users', search, roleFilter, typeFilter, page],
    queryFn: async () => {
      const params = new URLSearchParams()
      params.append('page', page.toString())
      params.append('per_page', '15')
      if (search) params.append('search', search)
      if (roleFilter) params.append('role', roleFilter)
      if (typeFilter) params.append('type', typeFilter)

      const response = await apiClient.get<{ success: boolean; data: UserType[]; meta: UsersResponse['meta'] }>(
        `/admin/users?${params.toString()}`
      )
      return response.data
    },
  })

  const { data: rolesData } = useQuery({
    queryKey: ['admin-roles'],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: Role[] }>('/admin/roles')
      return response.data.data
    },
  })

  const { data: branchesData, isLoading: branchesLoading } = useQuery({
    queryKey: ['branches'],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: BranchOption[] }>('/admin/branches')
      return response.data.data
    },
  })

  const suspendMutation = useMutation({
    mutationFn: async (userId: number) => {
      await apiClient.post(`/admin/users/${userId}/suspend`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-users'] })
      setConfirmAction(null)
    },
  })

  const unsuspendMutation = useMutation({
    mutationFn: async (userId: number) => {
      await apiClient.post(`/admin/users/${userId}/unsuspend`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-users'] })
      setConfirmAction(null)
    },
  })

  const verifyEmailMutation = useMutation({
    mutationFn: async (userId: number) => {
      await apiClient.post(`/admin/users/${userId}/verify-email`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-users'] })
      setConfirmAction(null)
    },
  })

  const assignRoleMutation = useMutation({
    mutationFn: async ({ userId, role }: { userId: number; role: string }) => {
      await apiClient.post(`/admin/users/${userId}/assign-role`, { role })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-users'] })
      setRoleModalUser(null)
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (userId: number) => {
      await apiClient.delete(`/admin/users/${userId}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-users'] })
      setConfirmAction(null)
    },
  })

  const createUserMutation = useMutation({
    mutationFn: async (data: UserFormData) => {
      const response = await apiClient.post('/admin/users', data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-users'] })
      setShowCreateModal(false)
      setFormData(initialFormData)
      setFormErrors({})
      toast.success('User created successfully')
    },
    onError: (error: { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }) => {
      if (error.response?.data?.errors) {
        const errors: Partial<Record<keyof UserFormData, string>> = {}
        Object.entries(error.response.data.errors).forEach(([key, messages]) => {
          errors[key as keyof UserFormData] = messages[0]
        })
        setFormErrors(errors)
      } else {
        toast.error(error.response?.data?.message || 'Failed to create user')
      }
    },
  })

  const updateUserMutation = useMutation({
    mutationFn: async ({ userId, data }: { userId: number; data: Partial<UserFormData> }) => {
      const response = await apiClient.put(`/admin/users/${userId}`, data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-users'] })
      setEditingUser(null)
      setFormData(initialFormData)
      setFormErrors({})
      toast.success('User updated successfully')
    },
    onError: (error: { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }) => {
      if (error.response?.data?.errors) {
        const errors: Partial<Record<keyof UserFormData, string>> = {}
        Object.entries(error.response.data.errors).forEach(([key, messages]) => {
          errors[key as keyof UserFormData] = messages[0]
        })
        setFormErrors(errors)
      } else {
        toast.error(error.response?.data?.message || 'Failed to update user')
      }
    },
  })

  const handleOpenCreateModal = () => {
    setFormData(initialFormData)
    setFormErrors({})
    setShowCreateModal(true)
  }

  const handleOpenEditModal = (user: UserType) => {
    const userRole = getUserRole(user)
    setFormData({
      name: user.name,
      email: user.email || '',
      phone_number: user.phone_number || '',
      national_id: user.national_id || '',
      password: '',
      password_confirmation: '',
      role: userRole,
      branch_id: user.branch_id ? user.branch_id.toString() : '',
    })
    setFormErrors({})
    setEditingUser(user)
  }

  const handleCloseModals = () => {
    setShowCreateModal(false)
    setEditingUser(null)
    setFormData(initialFormData)
    setFormErrors({})
  }

  const handleFormChange = (field: keyof UserFormData, value: string) => {
    setFormData(prev => ({ ...prev, [field]: value }))
    if (formErrors[field]) {
      setFormErrors(prev => ({ ...prev, [field]: undefined }))
    }
  }

  const handleCreateSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    createUserMutation.mutate(formData)
  }

  const handleUpdateSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!editingUser) return

    // No password here by design - the edit form does not expose one, so a
    // PUT can never silently change credentials. See the Reset password action.
    const updateData: Partial<UserFormData> = {
      name: formData.name,
      email: formData.email,
      phone_number: formData.phone_number,
      national_id: formData.national_id,
      role: formData.role,
      branch_id: formData.branch_id,
    }
    updateUserMutation.mutate({ userId: editingUser.id, data: updateData })
  }

  const users = data?.data || []
  const meta = data?.meta

  const getUserRole = (user: UserType): string => {
    const roles = user.roles || []
    if (roles.length === 0) return 'customer'
    return typeof roles[0] === 'string' ? roles[0] : roles[0].name
  }

  const isStaffUser = (user: UserType): boolean =>
    user.is_staff ?? STAFF_ROLES.includes(getUserRole(user))

  const getRoleBadge = (user: UserType) => {
    const role = getUserRole(user)
    const roleConfig: Record<string, { bg: string; text: string }> = {
      super_admin: { bg: 'bg-blue-100', text: 'text-blue-700' },
      store_admin: { bg: 'bg-blue-100', text: 'text-blue-700' },
      customer_service: { bg: 'bg-teal-100', text: 'text-teal-700' },
      customer: { bg: 'bg-slate-100', text: 'text-slate-700' },
    }
    const config = roleConfig[role] || roleConfig.customer
    return (
      <span className={`px-2.5 py-1 rounded-full text-xs font-medium ${config.bg} ${config.text}`}>
        {roleLabel(role)}
      </span>
    )
  }

  /** Staff / customer split - the coarse grouping the type filter works on. */
  const getAccountTypeChip = (user: UserType) =>
    isStaffUser(user) ? (
      <span className="text-[10px] font-semibold uppercase tracking-wider text-indigo-500">Staff</span>
    ) : (
      <span className="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Customer</span>
    )

  const isUserSuspended = (user: UserType): boolean =>
    user.is_suspended ?? Boolean(user.suspended_at)

  const handleConfirmAction = () => {
    if (!confirmAction) return

    switch (confirmAction.type) {
      case 'suspend':
        suspendMutation.mutate(confirmAction.user.id)
        break
      case 'unsuspend':
        unsuspendMutation.mutate(confirmAction.user.id)
        break
      case 'verify':
        verifyEmailMutation.mutate(confirmAction.user.id)
        break
      case 'delete':
        deleteMutation.mutate(confirmAction.user.id)
        break
    }
  }

  const isActionLoading = suspendMutation.isPending || unsuspendMutation.isPending || verifyEmailMutation.isPending || deleteMutation.isPending

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            Users
          </h1>
          <p className="text-slate-500 text-sm mt-1">Manage user accounts and permissions</p>
        </div>
        <div className="flex items-center gap-4">
          <div className="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-600 text-sm font-medium">
            {meta?.total || 0} total
          </div>
          <Authorize permission={PERMISSIONS.CREATE_USERS}>
            <button
              onClick={handleOpenCreateModal}
              className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 transition-colors shadow-sm"
            >
              <Plus className="h-4 w-4" />
              Add User
            </button>
          </Authorize>
        </div>
      </div>

      {/* Main Card */}
      <div className="bg-white rounded-xl border border-slate-200/60 shadow-sm">
        {/* Filters */}
        <div className="p-4 border-b border-slate-200/60">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
            <div className="relative flex-1 max-w-md">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
              <input
                type="text"
                placeholder="Search by name, email or phone..."
                className="w-full pl-10 pr-4 py-2.5 rounded-lg border border-slate-200 bg-slate-50/50 text-sm placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors"
                value={search}
                onChange={(e) => {
                  setSearch(e.target.value)
                  setPage(1)
                }}
              />
            </div>

            <div className="flex items-center gap-2 flex-wrap">
              <div className="inline-flex items-center gap-1.5 text-slate-400 pl-1">
                <SlidersHorizontal className="h-4 w-4" />
              </div>

              <select
                value={typeFilter}
                onChange={(e) => {
                  const nextType = e.target.value
                  setTypeFilter(nextType)
                  // Only drop the role when the new type contradicts it, so
                  // switching staff -> customer keeps a customer role selected
                  // instead of silently resetting the whole filter row.
                  if (roleFilter && nextType && STAFF_ROLES.includes(roleFilter) !== (nextType === 'staff')) {
                    setRoleFilter('')
                  }
                  setPage(1)
                }}
                aria-label="Filter by account type"
                className="px-3 py-2.5 rounded-lg border border-slate-200 bg-slate-50/50 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors"
              >
                <option value="">All account types</option>
                <option value="staff">Staff</option>
                <option value="customer">Customers</option>
              </select>

              <select
                value={roleFilter}
                onChange={(e) => {
                  setRoleFilter(e.target.value)
                  setPage(1)
                }}
                aria-label="Filter by role"
                className="px-3 py-2.5 rounded-lg border border-slate-200 bg-slate-50/50 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors"
              >
                <option value="">All roles</option>
                {/* Only roles that can match the chosen type: offering
                    "Customer" under "Staff" builds a filter that can never
                    return a row. */}
                {(rolesData || [])
                  .filter(
                    (role) =>
                      !typeFilter ||
                      STAFF_ROLES.includes(role.name) === (typeFilter === 'staff')
                  )
                  .map((role) => (
                    <option key={role.id} value={role.name}>
                      {roleLabel(role.name)}
                    </option>
                  ))}
              </select>

              {hasActiveFilters && (
                <button
                  type="button"
                  onClick={() => {
                    setSearch('')
                    setRoleFilter('')
                    setTypeFilter('')
                    setPage(1)
                  }}
                  className="inline-flex items-center gap-1 px-2.5 py-2.5 text-sm font-medium text-slate-500 hover:text-slate-700 transition-colors"
                >
                  <X className="h-3.5 w-3.5" />
                  Clear
                </button>
              )}
            </div>
          </div>
        </div>

        {/* Content */}
        {isLoading ? (
          <div className="flex items-center justify-center py-16">
            <Loader2 className="h-8 w-8 animate-spin text-blue-500" />
          </div>
        ) : users.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-16">
            <div className="h-12 w-12 rounded-xl bg-slate-100 flex items-center justify-center mb-3">
              <Users className="h-6 w-6 text-slate-400" />
            </div>
            <p className="text-slate-500 text-sm">No users found</p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-slate-200/60">
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">User</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Role</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Verified</th>
                    <th className="text-left py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Joined</th>
                    <th className="text-right py-3 px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {users.map((user) => {
                    const suspended = isUserSuspended(user)
                    return (
                      <tr key={user.id} className={`hover:bg-slate-50/50 transition-colors ${suspended ? 'opacity-60' : ''}`}>
                        <td className="py-3 px-4">
                          <div className="flex items-center gap-3">
                            <div className={`h-10 w-10 rounded-full bg-gradient-to-br ${suspended ? 'from-slate-400 to-slate-500' : 'from-blue-500 to-blue-600'} flex items-center justify-center flex-shrink-0`}>
                              <span className="text-white font-medium text-sm">
                                {user.name?.charAt(0).toUpperCase()}
                              </span>
                            </div>
                            <div className="min-w-0">
                              <div className="flex items-center gap-2">
                                <p className="font-medium text-slate-800 truncate">{user.name}</p>
                                {suspended && (
                                  <span className="px-1.5 py-0.5 bg-red-100 text-red-700 text-[10px] font-medium rounded">
                                    SUSPENDED
                                  </span>
                                )}
                              </div>
                              <p className="text-sm text-slate-500 flex items-center gap-1 truncate">
                                <Mail className="h-3 w-3 flex-shrink-0" />
                                {userContactLabel(user)}
                              </p>
                            </div>
                          </div>
                        </td>
                        <td className="py-3 px-4">
                          <div className="flex flex-col items-start gap-1">
                            {getRoleBadge(user)}
                            {getAccountTypeChip(user)}
                          </div>
                        </td>
                        <td className="py-3 px-4">
                          {user.email ? user.email_verified_at ? (
                            <div className="flex items-center gap-1.5 text-emerald-600">
                              <CheckCircle className="h-4 w-4" />
                              <span className="text-xs font-medium">Verified</span>
                            </div>
                          ) : (
                            <div className="flex items-center gap-1.5 text-slate-400">
                              <XCircle className="h-4 w-4" />
                              <span className="text-xs font-medium">Pending</span>
                            </div>
                          ) : (
                            <span className="text-xs font-medium text-slate-400">No email</span>
                          )}
                        </td>
                        <td className="py-3 px-4 text-sm text-slate-500">
                          {new Date(user.created_at).toLocaleDateString()}
                        </td>
                        <td className="py-3 px-4">
                          <div className="flex items-center justify-end gap-1">
                            <button
                              onClick={() => setSelectedUser(user)}
                              className="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                              title="View details"
                            >
                              <Eye className="h-4 w-4" />
                            </button>
                            <Authorize permission={PERMISSIONS.UPDATE_USERS}>
                              <button
                                onClick={() => handleOpenEditModal(user)}
                                className="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                                title="Edit user"
                              >
                                <Edit className="h-4 w-4" />
                              </button>
                            </Authorize>
                            <Authorize permission={PERMISSIONS.MANAGE_USER_ROLES}>
                              <button
                                onClick={() => setRoleModalUser(user)}
                                className="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                                title="Manage roles"
                              >
                                <Shield className="h-4 w-4" />
                              </button>
                            </Authorize>
                            <Authorize permission={PERMISSIONS.UPDATE_USERS}>
                              <button
                                onClick={() => setPasswordResetUser(user)}
                                className="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
                                title="Reset password"
                              >
                                <KeyRound className="h-4 w-4" />
                              </button>
                            </Authorize>
                            {user.email && !user.email_verified_at && (
                              <Authorize permission={PERMISSIONS.UPDATE_USERS}>
                                <button
                                  onClick={() => setConfirmAction({ type: 'verify', user })}
                                  className="p-2 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors"
                                  title="Verify email"
                                >
                                  <MailCheck className="h-4 w-4" />
                                </button>
                              </Authorize>
                            )}
                            <Authorize permission={PERMISSIONS.SUSPEND_USERS}>
                              {suspended ? (
                                <button
                                  onClick={() => setConfirmAction({ type: 'unsuspend', user })}
                                  className="p-2 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors"
                                  title="Unsuspend user"
                                >
                                  <RefreshCw className="h-4 w-4" />
                                </button>
                              ) : (
                                <button
                                  onClick={() => setConfirmAction({ type: 'suspend', user })}
                                  className="p-2 text-slate-400 hover:text-amber-600 hover:bg-amber-50 rounded-lg transition-colors"
                                  title="Suspend user"
                                >
                                  <Ban className="h-4 w-4" />
                                </button>
                              )}
                            </Authorize>
                            <Authorize permission={PERMISSIONS.DELETE_USERS}>
                              <button
                                onClick={() => setConfirmAction({ type: 'delete', user })}
                                className="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                title="Delete user"
                              >
                                <Trash2 className="h-4 w-4" />
                              </button>
                            </Authorize>
                          </div>
                        </td>
                      </tr>
                    )
                  })}
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

      {/* User Details Modal */}
      {selectedUser && (
        <>
          <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40" onClick={() => setSelectedUser(null)} />
          <div className="fixed inset-x-4 top-[10%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-lg bg-white rounded-2xl shadow-xl z-50 overflow-hidden">
            <div className="p-5 border-b border-slate-200">
              <div className="flex items-center justify-between">
                <h3 className="text-lg font-semibold text-slate-800">User Details</h3>
                <button onClick={() => setSelectedUser(null)} className="p-1 text-slate-400 hover:text-slate-600 rounded-lg">×</button>
              </div>
            </div>
            <div className="p-5 space-y-4">
              <div className="flex items-center gap-4">
                <div className="h-14 w-14 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center">
                  <span className="text-white font-semibold text-xl">{selectedUser.name?.charAt(0).toUpperCase()}</span>
                </div>
                <div>
                  <p className="text-lg font-semibold text-slate-800">{selectedUser.name}</p>
                  <p className="text-sm text-slate-500">{userContactLabel(selectedUser)}</p>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4 pt-2">
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-1">Role</p>
                  <div className="flex flex-col items-start gap-1">
                    {getRoleBadge(selectedUser)}
                    {getAccountTypeChip(selectedUser)}
                  </div>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-1">Email Status</p>
                  <p className="text-sm font-medium text-slate-700">
                    {selectedUser.email ? selectedUser.email_verified_at ? 'Verified' : 'Pending' : 'No email'}
                  </p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-1">Joined</p>
                  <p className="text-sm font-medium text-slate-700">{new Date(selectedUser.created_at).toLocaleDateString()}</p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-1">Phone</p>
                  <p className="text-sm font-medium text-slate-700">{selectedUser.phone_number || 'Not provided'}</p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-1">Branch</p>
                  <p className="text-sm font-medium text-slate-700">
                    {selectedUser.branch?.name || 'Not assigned'}
                  </p>
                  {selectedUser.branch?.code && (
                    <p className="text-xs text-slate-500 mt-0.5">{selectedUser.branch.code}</p>
                  )}
                </div>
              </div>
              <div className="flex gap-2">
                <button onClick={() => setSelectedUser(null)} className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors">
                  Close
                </button>
                <Authorize permission={PERMISSIONS.UPDATE_USERS}>
                  <button
                    onClick={() => {
                      setPasswordResetUser(selectedUser)
                      setSelectedUser(null)
                    }}
                    className="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors"
                  >
                    <KeyRound className="h-4 w-4" />
                    Reset password
                  </button>
                </Authorize>
              </div>
            </div>
          </div>
        </>
      )}

      {/* Role Management Modal */}
      {roleModalUser && (
        <>
          <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40" onClick={() => setRoleModalUser(null)} />
          <div className="fixed inset-x-4 top-[10%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-md bg-white rounded-2xl shadow-xl z-50 overflow-hidden">
            <div className="p-5 border-b border-slate-200">
              <div className="flex items-center justify-between">
                <h3 className="text-lg font-semibold text-slate-800">Manage Role</h3>
                <button onClick={() => setRoleModalUser(null)} className="p-1 text-slate-400 hover:text-slate-600 rounded-lg">×</button>
              </div>
            </div>
            <div className="p-5 space-y-4">
              <p className="text-sm text-slate-600">
                Assign a role to <strong>{roleModalUser.name}</strong>
              </p>
              <p className="text-xs text-slate-500">
                Current role: {getRoleBadge(roleModalUser)}
              </p>
              <div className="space-y-2">
                {(rolesData || []).map((role) => (
                  <button
                    key={role.id}
                    onClick={() => assignRoleMutation.mutate({ userId: roleModalUser.id, role: role.name })}
                    disabled={assignRoleMutation.isPending}
                    className={`w-full p-3 text-left rounded-lg border transition-colors ${
                      getUserRole(roleModalUser) === role.name
                        ? 'border-blue-500 bg-blue-50 text-blue-700'
                        : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50'
                    }`}
                  >
                    <p className="text-sm font-medium capitalize">{role.name.replace('_', ' ')}</p>
                  </button>
                ))}
              </div>
              <button
                onClick={() => setRoleModalUser(null)}
                className="w-full px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors"
              >
                Cancel
              </button>
            </div>
          </div>
        </>
      )}

      {/* Confirm Action Modal */}
      {confirmAction && (
        <>
          <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40" onClick={() => setConfirmAction(null)} />
          <div className="fixed inset-x-4 top-[20%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-sm bg-white rounded-2xl shadow-xl z-50 overflow-hidden">
            <div className="p-5 space-y-4">
              <div className={`h-12 w-12 rounded-full mx-auto flex items-center justify-center ${
                confirmAction.type === 'delete' ? 'bg-red-100' :
                confirmAction.type === 'suspend' ? 'bg-amber-100' :
                'bg-emerald-100'
              }`}>
                {confirmAction.type === 'delete' ? <Trash2 className="h-6 w-6 text-red-600" /> :
                 confirmAction.type === 'suspend' ? <Ban className="h-6 w-6 text-amber-600" /> :
                 confirmAction.type === 'unsuspend' ? <RefreshCw className="h-6 w-6 text-emerald-600" /> :
                 <MailCheck className="h-6 w-6 text-emerald-600" />}
              </div>
              <div className="text-center">
                <h3 className="text-lg font-semibold text-slate-800">
                  {confirmAction.type === 'delete' ? 'Delete User' :
                   confirmAction.type === 'suspend' ? 'Suspend User' :
                   confirmAction.type === 'unsuspend' ? 'Unsuspend User' :
                   'Verify Email'}
                </h3>
                <p className="text-sm text-slate-500 mt-1">
                  {confirmAction.type === 'delete'
                    ? `Are you sure you want to delete ${confirmAction.user.name}? This action cannot be undone.`
                    : confirmAction.type === 'suspend'
                    ? `Are you sure you want to suspend ${confirmAction.user.name}?`
                    : confirmAction.type === 'unsuspend'
                    ? `Are you sure you want to unsuspend ${confirmAction.user.name}?`
                    : `Manually verify ${confirmAction.user.name}'s email address?`}
                </p>
              </div>
              <div className="flex gap-2">
                <button
                  onClick={() => setConfirmAction(null)}
                  disabled={isActionLoading}
                  className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors disabled:opacity-50"
                >
                  Cancel
                </button>
                <button
                  onClick={handleConfirmAction}
                  disabled={isActionLoading}
                  className={`flex-1 px-4 py-2.5 text-white text-sm font-medium rounded-lg transition-colors disabled:opacity-50 ${
                    confirmAction.type === 'delete' ? 'bg-red-600 hover:bg-red-700' :
                    confirmAction.type === 'suspend' ? 'bg-amber-600 hover:bg-amber-700' :
                    'bg-emerald-600 hover:bg-emerald-700'
                  }`}
                >
                  {isActionLoading ? <Loader2 className="h-4 w-4 animate-spin mx-auto" /> : 'Confirm'}
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* Create/Edit User Modal */}
      {(showCreateModal || editingUser) && (
        <>
          <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40" onClick={handleCloseModals} />
          <div className="fixed inset-x-4 top-[5%] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-lg bg-white rounded-2xl shadow-xl z-50 overflow-hidden max-h-[90vh] overflow-y-auto">
            <div className="p-5 border-b border-slate-200 sticky top-0 bg-white">
              <div className="flex items-center justify-between">
                <h3 className="text-lg font-semibold text-slate-800">
                  {editingUser ? 'Edit User' : 'Create New User'}
                </h3>
                <button
                  onClick={handleCloseModals}
                  className="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                >
                  <X className="h-5 w-5" />
                </button>
              </div>
            </div>
            <form onSubmit={editingUser ? handleUpdateSubmit : handleCreateSubmit} className="p-5 space-y-4">
              {/* Required fields note */}
              <p className="text-xs text-slate-500">
                <span className="text-red-500">*</span> indicates required fields
              </p>

              {editingUser && (
                <div className="flex items-start gap-2 p-3 rounded-lg bg-blue-50 border border-blue-100">
                  <KeyRound className="h-4 w-4 text-blue-600 flex-shrink-0 mt-0.5" />
                  <p className="text-xs text-blue-800">
                    Passwords are not changed here. Use <strong>Reset password</strong> in the actions column — it can
                    email a reset link or set a new password and notify the user.
                  </p>
                </div>
              )}

              {/* Name */}
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">
                  Name {!editingUser && <span className="text-red-500">*</span>}
                </label>
                <input
                  type="text"
                  value={formData.name}
                  onChange={(e) => handleFormChange('name', e.target.value)}
                  className={`w-full px-3.5 py-2.5 rounded-lg border text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors ${
                    formErrors.name ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-slate-50/50'
                  }`}
                  placeholder="Enter full name"
                  required={!editingUser}
                  maxLength={255}
                />
                {formErrors.name && <p className="mt-1 text-xs text-red-600">{formErrors.name}</p>}
              </div>

              {/* Email */}
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">
                  Email {!editingUser && formData.role !== 'customer' ? (
                    <span className="text-red-500">*</span>
                  ) : (
                    <span className="text-slate-400 font-normal"> (optional for customers)</span>
                  )}
                </label>
                <input
                  type="email"
                  value={formData.email}
                  onChange={(e) => handleFormChange('email', e.target.value)}
                  className={`w-full px-3.5 py-2.5 rounded-lg border text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors ${
                    formErrors.email ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-slate-50/50'
                  }`}
                  placeholder="Enter email address"
                  required={!editingUser && formData.role !== 'customer'}
                  maxLength={255}
                />
                {formErrors.email && <p className="mt-1 text-xs text-red-600">{formErrors.email}</p>}
              </div>

              {/* Phone */}
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">
                  Phone Number <span className="text-slate-400 font-normal">(optional, max 20 chars)</span>
                </label>
                <input
                  type="tel"
                  value={formData.phone_number}
                  onChange={(e) => handleFormChange('phone_number', e.target.value)}
                  className={`w-full px-3.5 py-2.5 rounded-lg border text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors ${
                    formErrors.phone_number ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-slate-50/50'
                  }`}
                  placeholder="e.g. +265999123456"
                  maxLength={20}
                />
                {formErrors.phone_number && <p className="mt-1 text-xs text-red-600">{formErrors.phone_number}</p>}
              </div>

              {/* National ID */}
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">
                  National ID <span className="text-slate-400 font-normal">(optional, max 50 chars)</span>
                </label>
                <input
                  type="text"
                  value={formData.national_id}
                  onChange={(e) => handleFormChange('national_id', e.target.value)}
                  className={`w-full px-3.5 py-2.5 rounded-lg border text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors ${
                    formErrors.national_id ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-slate-50/50'
                  }`}
                  placeholder="e.g. 1234567890"
                  maxLength={50}
                />
                {formErrors.national_id && <p className="mt-1 text-xs text-red-600">{formErrors.national_id}</p>}
              </div>

              {/* Role */}
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">
                  Role {!editingUser && <span className="text-red-500">*</span>}
                </label>
                <select
                  value={formData.role}
                  onChange={(e) => handleFormChange('role', e.target.value)}
                  className={`w-full px-3.5 py-2.5 rounded-lg border text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors ${
                    formErrors.role ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-slate-50/50'
                  }`}
                >
                  {(rolesData || []).map((role) => (
                    <option key={role.id} value={role.name}>
                      {role.name.replace('_', ' ')}
                    </option>
                  ))}
                </select>
                {formErrors.role && <p className="mt-1 text-xs text-red-600">{formErrors.role}</p>}
              </div>

              {/* Branch */}
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">
                  Branch <span className="text-red-500">*</span>
                </label>
                <select
                  value={formData.branch_id}
                  onChange={(e) => handleFormChange('branch_id', e.target.value)}
                  required
                  disabled={branchesLoading}
                  className={`w-full px-3.5 py-2.5 rounded-lg border text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors ${
                    formErrors.branch_id ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-slate-50/50'
                  }`}
                >
                  <option value="">
                    {branchesLoading ? 'Loading branches...' : 'Select branch'}
                  </option>
                  {(branchesData || []).map((branch) => (
                    <option key={branch.id} value={branch.id.toString()}>
                      {branch.name}
                    </option>
                  ))}
                </select>
                {formErrors.branch_id && <p className="mt-1 text-xs text-red-600">{formErrors.branch_id}</p>}
              </div>

              {/*
                Passwords are only set at creation time. Changing an existing
                password has side effects the form cannot carry - session
                revocation, stale reset-token invalidation and a confirmation
                email - so it goes through the Reset password action instead of
                a silent field on this form.
              */}
              {!editingUser && (
                <>
                  {/* Password */}
                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1.5">
                      Password {formData.role !== 'customer' ? (
                        <span className="text-red-500">*</span>
                      ) : (
                        <span className="text-slate-400 font-normal"> (optional for customers)</span>
                      )}
                    </label>
                    <input
                      type="password"
                      value={formData.password}
                      onChange={(e) => handleFormChange('password', e.target.value)}
                      className={`w-full px-3.5 py-2.5 rounded-lg border text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors ${
                        formErrors.password ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-slate-50/50'
                      }`}
                      placeholder="Minimum 8 characters"
                      required={formData.role !== 'customer'}
                      minLength={8}
                    />
                    {formErrors.password && <p className="mt-1 text-xs text-red-600">{formErrors.password}</p>}
                  </div>

                  {/* Confirm Password */}
                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1.5">
                      Confirm Password {formData.role !== 'customer' || formData.password ? <span className="text-red-500">*</span> : null}
                    </label>
                    <input
                      type="password"
                      value={formData.password_confirmation}
                      onChange={(e) => handleFormChange('password_confirmation', e.target.value)}
                      className={`w-full px-3.5 py-2.5 rounded-lg border text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors ${
                        formErrors.password_confirmation ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-slate-50/50'
                      }`}
                      placeholder="Re-enter password"
                      required={formData.role !== 'customer' || formData.password.length > 0}
                    />
                    {formErrors.password_confirmation && <p className="mt-1 text-xs text-red-600">{formErrors.password_confirmation}</p>}
                  </div>
                </>
              )}

              {/* Actions */}
              <div className="flex gap-3 pt-2">
                <button
                  type="button"
                  onClick={handleCloseModals}
                  className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={createUserMutation.isPending || updateUserMutation.isPending}
                  className="flex-1 px-4 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center justify-center gap-2"
                >
                  {(createUserMutation.isPending || updateUserMutation.isPending) && (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  )}
                  {editingUser ? 'Update User' : 'Create User'}
                </button>
              </div>
            </form>
          </div>
        </>
      )}
      {/* Password Reset Modal */}
      {passwordResetUser && (
        <PasswordResetModal
          user={passwordResetUser}
          onClose={() => setPasswordResetUser(null)}
          onDone={() => queryClient.invalidateQueries({ queryKey: ['admin-users'] })}
        />
      )}
    </div>
  )
}
