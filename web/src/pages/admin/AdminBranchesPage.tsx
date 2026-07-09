import { useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, Edit2, Loader2, Plus, Power, Search, X } from 'lucide-react'
import { toast } from 'sonner'
import apiClient, { type PaginatedResponse } from '@/api/client'
import Authorize from '@/components/auth/Authorize'
import { PERMISSIONS } from '@/stores/auth-store'
import type { Branch, User } from '@/types'

interface BranchFormData {
  name: string
  code: string
  address: string
  city: string
  phone: string
  email: string
  manager_id: string
  is_active: boolean
}

const initialFormData: BranchFormData = {
  name: '',
  code: '',
  address: '',
  city: '',
  phone: '',
  email: '',
  manager_id: '',
  is_active: true,
}

const inputClass = 'w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100'

const extractErrors = (error: unknown) => {
  const response = error as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }
  return response.response?.data?.errors || {}
}

const firstError = (errors: Record<string, string[]>, key: keyof BranchFormData) => errors[key]?.[0]

export default function AdminBranchesPage() {
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [showModal, setShowModal] = useState(false)
  const [editingBranch, setEditingBranch] = useState<Branch | null>(null)
  const [formData, setFormData] = useState<BranchFormData>(initialFormData)
  const [formErrors, setFormErrors] = useState<Record<string, string[]>>({})

  const branchQuery = useMemo(() => {
    const params = new URLSearchParams({ page: page.toString(), per_page: '15' })
    if (search) params.append('search', search)
    if (status) params.append('is_active', status)
    return params.toString()
  }, [page, search, status])

  const { data: branchesData, isLoading } = useQuery({
    queryKey: ['branches-admin', branchQuery],
    queryFn: async () => {
      const response = await apiClient.get<{ data: Branch[]; meta: PaginatedResponse<Branch>['meta'] }>(
        `/admin/branches?${branchQuery}`
      )
      return { data: response.data.data, meta: response.data.meta }
    },
  })

  const { data: managers } = useQuery({
    queryKey: ['branch-managers'],
    queryFn: async () => {
      const response = await apiClient.get<{ data: User[] }>('/admin/users?per_page=100')
      return response.data.data.filter((user) => {
        const roles = user.roles || []
        return roles.some((role) => typeof role === 'string'
          ? ['super_admin', 'store_admin'].includes(role)
          : ['super_admin', 'store_admin'].includes(role.name))
      })
    },
  })

  const invalidateBranches = () => {
    queryClient.invalidateQueries({ queryKey: ['branches'] })
    queryClient.invalidateQueries({ queryKey: ['branches-admin'] })
    queryClient.invalidateQueries({ queryKey: ['branch-managers'] })
    queryClient.invalidateQueries({ queryKey: ['admin-reports-stock'] })
    queryClient.invalidateQueries({ queryKey: ['stock-movements'] })
  }

  const createMutation = useMutation({
    mutationFn: async (data: BranchFormData) => {
      await apiClient.post('/admin/branches', normalizePayload(data))
    },
    onSuccess: () => {
      toast.success('Branch created successfully')
      closeModal()
      invalidateBranches()
    },
    onError: (error) => {
      setFormErrors(extractErrors(error))
      const message = (error as { response?: { data?: { message?: string } } }).response?.data?.message
      if (!Object.keys(extractErrors(error)).length) toast.error(message || 'Failed to create branch')
    },
  })

  const updateMutation = useMutation({
    mutationFn: async ({ branch, data }: { branch: Branch; data: BranchFormData }) => {
      await apiClient.put(`/admin/branches/${branch.id}`, normalizePayload(data))
    },
    onSuccess: () => {
      toast.success('Branch updated successfully')
      closeModal()
      invalidateBranches()
    },
    onError: (error) => {
      setFormErrors(extractErrors(error))
      const message = (error as { response?: { data?: { message?: string } } }).response?.data?.message
      if (!Object.keys(extractErrors(error)).length) toast.error(message || 'Failed to update branch')
    },
  })

  const toggleMutation = useMutation({
    mutationFn: async (branch: Branch) => {
      await apiClient.put(`/admin/branches/${branch.id}`, { is_active: !branch.is_active })
    },
    onSuccess: (_data, branch) => {
      toast.success(branch.is_active ? 'Branch deactivated' : 'Branch activated')
      invalidateBranches()
    },
    onError: (error: { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Failed to update branch status')
    },
  })

  const openCreateModal = () => {
    setEditingBranch(null)
    setFormData(initialFormData)
    setFormErrors({})
    setShowModal(true)
  }

  const openEditModal = (branch: Branch) => {
    setEditingBranch(branch)
    setFormData({
      name: branch.name,
      code: branch.code,
      address: branch.address || '',
      city: branch.city || '',
      phone: branch.phone || '',
      email: branch.email || '',
      manager_id: branch.manager_id ? branch.manager_id.toString() : '',
      is_active: branch.is_active,
    })
    setFormErrors({})
    setShowModal(true)
  }

  const closeModal = () => {
    setShowModal(false)
    setEditingBranch(null)
    setFormData(initialFormData)
    setFormErrors({})
  }

  const updateField = <K extends keyof BranchFormData>(field: K, value: BranchFormData[K]) => {
    setFormData((current) => ({ ...current, [field]: value }))
    setFormErrors((current) => ({ ...current, [field]: [] }))
  }

  const submitForm = (event: React.FormEvent) => {
    event.preventDefault()
    setFormErrors({})

    if (editingBranch) {
      updateMutation.mutate({ branch: editingBranch, data: formData })
    } else {
      createMutation.mutate(formData)
    }
  }

  const branches = branchesData?.data || []
  const meta = branchesData?.meta
  const isSubmitting = createMutation.isPending || updateMutation.isPending
  const activeCount = branches.filter((branch) => branch.is_active).length
  const totalDevices = branches.reduce((sum, branch) => sum + (branch.devices_count || 0), 0)

  return (
    <Authorize permission={PERMISSIONS.ACCESS_ADMIN_PANEL} fallback={<div className="p-6">Access denied</div>}>
      <div className="space-y-6 p-6">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="text-2xl font-bold text-slate-900">Branch Management</h1>
            <p className="mt-1 text-sm text-slate-500">Define branches used by inventory, sales, users, reports, and stock movements</p>
          </div>
          <button
            onClick={openCreateModal}
            className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
          >
            <Plus className="h-4 w-4" />
            New Branch
          </button>
        </div>

        <div className="grid gap-4 sm:grid-cols-3">
          <SummaryTile label="Branches" value={meta?.total ?? branches.length} />
          <SummaryTile label="Active In View" value={activeCount} />
          <SummaryTile label="Devices In View" value={totalDevices} />
        </div>

        <div className="rounded-lg border border-slate-200 bg-white p-4">
          <div className="grid gap-3 md:grid-cols-[1fr_180px]">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <input
                value={search}
                onChange={(event) => { setSearch(event.target.value); setPage(1) }}
                placeholder="Search by name, code, or city"
                className="w-full rounded-lg border border-slate-200 py-2 pl-10 pr-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
              />
            </div>
            <select
              value={status}
              onChange={(event) => { setStatus(event.target.value); setPage(1) }}
              className="rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
            >
              <option value="">All Statuses</option>
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </div>
        </div>

        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
          {isLoading ? (
            <div className="flex items-center justify-center py-16">
              <Loader2 className="h-7 w-7 animate-spin text-blue-600" />
            </div>
          ) : branches.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-16">
              <Building2 className="mb-3 h-10 w-10 text-slate-300" />
              <p className="text-sm font-medium text-slate-700">No branches found</p>
              <p className="mt-1 text-sm text-slate-500">Create a branch to start assigning stock and staff.</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-200">
                <thead className="bg-slate-50">
                  <tr>
                    <TableHead>Branch</TableHead>
                    <TableHead>Code</TableHead>
                    <TableHead>Contact</TableHead>
                    <TableHead>Manager</TableHead>
                    <TableHead>Devices</TableHead>
                    <TableHead>Users</TableHead>
                    <TableHead>Sales</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Actions</TableHead>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200 bg-white">
                  {branches.map((branch) => (
                    <tr key={branch.id} className="hover:bg-slate-50">
                      <TableCell>
                        <div>
                          <p className="font-medium text-slate-900">{branch.name}</p>
                          <p className="text-xs text-slate-500">{branch.city || 'No city'}{branch.address ? ` · ${branch.address}` : ''}</p>
                        </div>
                      </TableCell>
                      <TableCell>{branch.code}</TableCell>
                      <TableCell>
                        <div className="space-y-0.5">
                          <p>{branch.phone || '-'}</p>
                          <p className="text-xs text-slate-500">{branch.email || '-'}</p>
                        </div>
                      </TableCell>
                      <TableCell>{branch.manager?.name || '-'}</TableCell>
                      <TableCell>{branch.devices_count ?? 0}</TableCell>
                      <TableCell>{branch.users_count ?? 0}</TableCell>
                      <TableCell>{branch.sales_count ?? 0}</TableCell>
                      <TableCell>
                        <span className={`inline-flex rounded-full px-2 py-1 text-xs font-medium ${branch.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>
                          {branch.is_active ? 'Active' : 'Inactive'}
                        </span>
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center gap-1">
                          <button onClick={() => openEditModal(branch)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900" title="Edit branch">
                            <Edit2 className="h-4 w-4" />
                          </button>
                          <button
                            onClick={() => toggleMutation.mutate(branch)}
                            disabled={toggleMutation.isPending}
                            className={`rounded-lg p-2 hover:bg-slate-100 ${branch.is_active ? 'text-amber-600' : 'text-emerald-600'}`}
                            title={branch.is_active ? 'Deactivate branch' : 'Activate branch'}
                          >
                            <Power className="h-4 w-4" />
                          </button>
                        </div>
                      </TableCell>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>

        {meta && meta.last_page > 1 && (
          <div className="flex items-center justify-between rounded-lg border border-slate-200 bg-white px-4 py-3">
            <p className="text-sm text-slate-500">Page {meta.current_page} of {meta.last_page}</p>
            <div className="flex gap-2">
              <button disabled={page <= 1} onClick={() => setPage((current) => current - 1)} className="rounded-lg border border-slate-200 px-3 py-1.5 text-sm disabled:opacity-50">Previous</button>
              <button disabled={page >= meta.last_page} onClick={() => setPage((current) => current + 1)} className="rounded-lg border border-slate-200 px-3 py-1.5 text-sm disabled:opacity-50">Next</button>
            </div>
          </div>
        )}

        {showModal && (
          <BranchModal
            title={editingBranch ? 'Edit Branch' : 'New Branch'}
            formData={formData}
            errors={formErrors}
            managers={managers || []}
            isSubmitting={isSubmitting}
            onChange={updateField}
            onClose={closeModal}
            onSubmit={submitForm}
          />
        )}
      </div>
    </Authorize>
  )
}

function normalizePayload(data: BranchFormData) {
  return {
    name: data.name,
    code: data.code,
    address: data.address || null,
    city: data.city || null,
    phone: data.phone || null,
    email: data.email || null,
    manager_id: data.manager_id ? Number(data.manager_id) : null,
    is_active: data.is_active,
  }
}

function SummaryTile({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white p-4">
      <p className="text-xs font-medium uppercase text-slate-500">{label}</p>
      <p className="mt-2 text-2xl font-semibold text-slate-900">{value}</p>
    </div>
  )
}

function BranchModal({
  title,
  formData,
  errors,
  managers,
  isSubmitting,
  onChange,
  onClose,
  onSubmit,
}: {
  title: string
  formData: BranchFormData
  errors: Record<string, string[]>
  managers: User[]
  isSubmitting: boolean
  onChange: <K extends keyof BranchFormData>(field: K, value: BranchFormData[K]) => void
  onClose: () => void
  onSubmit: (event: React.FormEvent) => void
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <form onSubmit={onSubmit} className="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-lg bg-white shadow-xl">
        <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
          <h2 className="text-lg font-semibold text-slate-900">{title}</h2>
          <button type="button" onClick={onClose} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100">
            <X className="h-5 w-5" />
          </button>
        </div>
        <div className="grid gap-4 p-6 sm:grid-cols-2">
          <Field label="Branch Name" error={firstError(errors, 'name')}>
            <input value={formData.name} onChange={(event) => onChange('name', event.target.value)} className={inputClass} />
          </Field>
          <Field label="Branch Code" error={firstError(errors, 'code')}>
            <input value={formData.code} onChange={(event) => onChange('code', event.target.value.toUpperCase())} className={inputClass} />
          </Field>
          <Field label="City" error={firstError(errors, 'city')}>
            <input value={formData.city} onChange={(event) => onChange('city', event.target.value)} className={inputClass} />
          </Field>
          <Field label="Phone" error={firstError(errors, 'phone')}>
            <input value={formData.phone} onChange={(event) => onChange('phone', event.target.value)} className={inputClass} />
          </Field>
          <Field label="Email" error={firstError(errors, 'email')}>
            <input type="email" value={formData.email} onChange={(event) => onChange('email', event.target.value)} className={inputClass} />
          </Field>
          <Field label="Manager" error={firstError(errors, 'manager_id')}>
            <select value={formData.manager_id} onChange={(event) => onChange('manager_id', event.target.value)} className={inputClass}>
              <option value="">No manager assigned</option>
              {managers.map((manager) => (
                <option key={manager.id} value={manager.id}>{manager.name}</option>
              ))}
            </select>
          </Field>
          <Field label="Address" error={firstError(errors, 'address')} className="sm:col-span-2">
            <textarea value={formData.address} onChange={(event) => onChange('address', event.target.value)} rows={3} className={inputClass} />
          </Field>
          <label className="flex items-center gap-2 sm:col-span-2">
            <input type="checkbox" checked={formData.is_active} onChange={(event) => onChange('is_active', event.target.checked)} className="h-4 w-4 rounded border-slate-300" />
            <span className="text-sm font-medium text-slate-700">Branch is active</span>
          </label>
        </div>
        <div className="flex justify-end gap-2 border-t border-slate-200 px-6 py-4">
          <button type="button" onClick={onClose} className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
          <button disabled={isSubmitting} className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
            {isSubmitting && <Loader2 className="h-4 w-4 animate-spin" />}
            Save Branch
          </button>
        </div>
      </form>
    </div>
  )
}

function Field({ label, error, className, children }: { label: string; error?: string; className?: string; children: ReactNode }) {
  return (
    <label className={className}>
      <span className="mb-1 block text-sm font-medium text-slate-700">{label}</span>
      {children}
      {error ? <span className="mt-1 block text-xs text-red-600">{error}</span> : null}
    </label>
  )
}

function TableHead({ children }: { children: ReactNode }) {
  return <th className="px-4 py-3 text-left text-xs font-medium uppercase text-slate-500">{children}</th>
}

function TableCell({ children }: { children: ReactNode }) {
  return <td className="whitespace-nowrap px-4 py-3 text-sm text-slate-700">{children}</td>
}
