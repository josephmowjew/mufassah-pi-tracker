import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BriefcaseBusiness, CalendarDays, Edit2, Eye, EyeOff, Loader2, Plus, Search, Trash2, X } from 'lucide-react'
import { toast } from 'sonner'
import apiClient, { type PaginatedResponse } from '@/api/client'
import Authorize from '@/components/auth/Authorize'
import { PERMISSIONS } from '@/stores/auth-store'
import type { Vacancy } from '@/types'

interface VacancyForm {
  title: string
  location: string
  employment_type: string
  description: string
  application_email: string
  closing_date: string
  sort_order: string
  is_published: boolean
}

const emptyForm: VacancyForm = {
  title: '',
  location: '',
  employment_type: 'Full-time',
  description: '',
  application_email: '',
  closing_date: '',
  sort_order: '0',
  is_published: false,
}

const inputClass = 'w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100'

function errorMessage(error: unknown, fallback: string) {
  const response = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
  return response.response?.data?.message || Object.values(response.response?.data?.errors || {}).flat()[0] || fallback
}

function formatClosingDate(date: string | null) {
  if (!date) return 'No closing date'
  return new Intl.DateTimeFormat('en', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(`${date}T00:00:00`))
}

export default function AdminVacanciesPage() {
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [editingVacancy, setEditingVacancy] = useState<Vacancy | null>(null)
  const [form, setForm] = useState<VacancyForm>(emptyForm)
  const [modalOpen, setModalOpen] = useState(false)

  const query = useMemo(() => {
    const params = new URLSearchParams({ page: String(page), per_page: '15' })
    if (search) params.set('search', search)
    if (status) params.set('is_published', status)
    return params.toString()
  }, [page, search, status])

  const { data, isLoading } = useQuery({
    queryKey: ['admin-vacancies', query],
    queryFn: async () => {
      const response = await apiClient.get<{ data: Vacancy[]; meta: PaginatedResponse<Vacancy>['meta'] }>(`/admin/vacancies?${query}`)
      return response.data
    },
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['admin-vacancies'] })
    queryClient.invalidateQueries({ queryKey: ['public-vacancies'] })
  }

  const closeModal = () => {
    setModalOpen(false)
    setEditingVacancy(null)
    setForm(emptyForm)
  }

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = {
        ...form,
        location: form.location || null,
        application_email: form.application_email || null,
        closing_date: form.closing_date || null,
        sort_order: Number(form.sort_order || 0),
      }
      if (editingVacancy) return apiClient.put(`/admin/vacancies/${editingVacancy.id}`, payload)
      return apiClient.post('/admin/vacancies', payload)
    },
    onSuccess: () => {
      toast.success(editingVacancy ? 'Vacancy updated' : 'Vacancy created')
      closeModal()
      refresh()
    },
    onError: (error) => toast.error(errorMessage(error, 'Could not save vacancy')),
  })

  const publishMutation = useMutation({
    mutationFn: (vacancy: Vacancy) => apiClient.put(`/admin/vacancies/${vacancy.id}`, { is_published: !vacancy.is_published }),
    onSuccess: (_response, vacancy) => {
      toast.success(vacancy.is_published ? 'Vacancy unpublished' : 'Vacancy published')
      refresh()
    },
    onError: (error) => toast.error(errorMessage(error, 'Could not update vacancy status')),
  })

  const deleteMutation = useMutation({
    mutationFn: (vacancy: Vacancy) => apiClient.delete(`/admin/vacancies/${vacancy.id}`),
    onSuccess: () => {
      toast.success('Vacancy deleted')
      refresh()
    },
    onError: (error) => toast.error(errorMessage(error, 'Could not delete vacancy')),
  })

  const openCreate = () => {
    setEditingVacancy(null)
    setForm(emptyForm)
    setModalOpen(true)
  }

  const openEdit = (vacancy: Vacancy) => {
    setEditingVacancy(vacancy)
    setForm({
      title: vacancy.title,
      location: vacancy.location || '',
      employment_type: vacancy.employment_type,
      description: vacancy.description,
      application_email: vacancy.application_email || '',
      closing_date: vacancy.closing_date || '',
      sort_order: String(vacancy.sort_order),
      is_published: vacancy.is_published,
    })
    setModalOpen(true)
  }

  const vacancies = data?.data || []
  const meta = data?.meta

  return (
    <Authorize permission={PERMISSIONS.ACCESS_ADMIN_PANEL} fallback={<div className="p-6">Access denied</div>}>
      <div className="space-y-6 p-6">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="text-2xl font-bold text-slate-900">Vacancies</h1>
            <p className="mt-1 text-sm text-slate-500">Create and publish roles for the public careers page.</p>
          </div>
          <button onClick={openCreate} className="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
            <Plus className="h-4 w-4" /> New vacancy
          </button>
        </div>

        <div className="rounded-xl border border-slate-200 bg-white p-4">
          <div className="grid gap-3 md:grid-cols-[1fr_180px]">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1) }} placeholder="Search title or location" className="w-full rounded-lg border border-slate-200 py-2 pl-10 pr-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" />
            </div>
            <select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1) }} className={inputClass}>
              <option value="">All statuses</option>
              <option value="true">Published</option>
              <option value="false">Draft</option>
            </select>
          </div>
        </div>

        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
          {isLoading ? <div className="flex justify-center py-20"><Loader2 className="h-7 w-7 animate-spin text-blue-600" /></div> : vacancies.length === 0 ? (
            <div className="flex flex-col items-center px-6 py-20 text-center"><BriefcaseBusiness className="mb-3 h-11 w-11 text-slate-300" /><h2 className="font-semibold text-slate-800">No vacancies found</h2><p className="mt-1 text-sm text-slate-500">Create a role when you are ready to start hiring.</p></div>
          ) : (
            <div className="divide-y divide-slate-100">
              {vacancies.map((vacancy) => (
                <article key={vacancy.id} className="flex flex-col gap-4 p-5 lg:flex-row lg:items-center lg:justify-between">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2"><h2 className="font-semibold text-slate-900">{vacancy.title}</h2><span className={`rounded-full px-2 py-0.5 text-xs font-medium ${vacancy.is_published ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'}`}>{vacancy.is_published ? 'Published' : 'Draft'}</span></div>
                    <p className="mt-1 text-sm text-slate-500">{[vacancy.location, vacancy.employment_type].filter(Boolean).join(' · ')}</p>
                    <p className="mt-2 line-clamp-2 max-w-3xl text-sm text-slate-600">{vacancy.description}</p>
                    <p className="mt-2 inline-flex items-center gap-1.5 text-xs text-slate-400"><CalendarDays className="h-3.5 w-3.5" /> {formatClosingDate(vacancy.closing_date)}</p>
                  </div>
                  <div className="flex shrink-0 items-center gap-2">
                    <button onClick={() => publishMutation.mutate(vacancy)} disabled={publishMutation.isPending} title={vacancy.is_published ? 'Unpublish vacancy' : 'Publish vacancy'} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900 disabled:opacity-50">{vacancy.is_published ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}</button>
                    <button onClick={() => openEdit(vacancy)} title="Edit vacancy" className="rounded-lg p-2 text-slate-500 hover:bg-blue-50 hover:text-blue-700"><Edit2 className="h-4 w-4" /></button>
                    <button onClick={() => { if (window.confirm(`Delete “${vacancy.title}”? This cannot be undone.`)) deleteMutation.mutate(vacancy) }} title="Delete vacancy" className="rounded-lg p-2 text-slate-500 hover:bg-red-50 hover:text-red-700"><Trash2 className="h-4 w-4" /></button>
                  </div>
                </article>
              ))}
            </div>
          )}
        </div>

        {meta && meta.last_page > 1 && <div className="flex items-center justify-between text-sm text-slate-600"><span>Page {meta.current_page} of {meta.last_page}</span><div className="flex gap-2"><button disabled={meta.current_page <= 1} onClick={() => setPage((current) => current - 1)} className="rounded-lg border px-3 py-1.5 disabled:opacity-40">Previous</button><button disabled={meta.current_page >= meta.last_page} onClick={() => setPage((current) => current + 1)} className="rounded-lg border px-3 py-1.5 disabled:opacity-40">Next</button></div></div>}

        {modalOpen && <div className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/45 p-4"><form onSubmit={(event) => { event.preventDefault(); saveMutation.mutate() }} className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white shadow-2xl"><div className="sticky top-0 flex items-center justify-between border-b border-slate-100 bg-white px-6 py-4"><div><h2 className="text-lg font-semibold text-slate-900">{editingVacancy ? 'Edit vacancy' : 'New vacancy'}</h2><p className="text-sm text-slate-500">Publish only when the role is ready for applicants.</p></div><button type="button" onClick={closeModal} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100"><X className="h-5 w-5" /></button></div><div className="grid gap-4 p-6 sm:grid-cols-2"><label className="sm:col-span-2"><span className="mb-1 block text-sm font-medium text-slate-700">Role title</span><input required value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} className={inputClass} /></label><label><span className="mb-1 block text-sm font-medium text-slate-700">Location</span><input value={form.location} onChange={(event) => setForm({ ...form, location: event.target.value })} placeholder="e.g. Blantyre" className={inputClass} /></label><label><span className="mb-1 block text-sm font-medium text-slate-700">Employment type</span><input required value={form.employment_type} onChange={(event) => setForm({ ...form, employment_type: event.target.value })} placeholder="Full-time" className={inputClass} /></label><label><span className="mb-1 block text-sm font-medium text-slate-700">Application email</span><input type="email" value={form.application_email} onChange={(event) => setForm({ ...form, application_email: event.target.value })} placeholder="Optional; branch email is used otherwise" className={inputClass} /></label><label><span className="mb-1 block text-sm font-medium text-slate-700">Closing date</span><input type="date" value={form.closing_date} onChange={(event) => setForm({ ...form, closing_date: event.target.value })} className={inputClass} /></label><label><span className="mb-1 block text-sm font-medium text-slate-700">Display order</span><input type="number" min="0" value={form.sort_order} onChange={(event) => setForm({ ...form, sort_order: event.target.value })} className={inputClass} /></label><label className="flex items-end gap-3 pb-2"><input type="checkbox" checked={form.is_published} onChange={(event) => setForm({ ...form, is_published: event.target.checked })} className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" /><span className="text-sm font-medium text-slate-700">Publish on the careers page</span></label><label className="sm:col-span-2"><span className="mb-1 block text-sm font-medium text-slate-700">Role description</span><textarea required rows={7} value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} placeholder="Describe the opportunity, responsibilities, and ideal candidate." className={inputClass} /></label></div><div className="flex justify-end gap-3 border-t border-slate-100 px-6 py-4"><button type="button" onClick={closeModal} className="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100">Cancel</button><button disabled={saveMutation.isPending} className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">{saveMutation.isPending && <Loader2 className="h-4 w-4 animate-spin" />}{editingVacancy ? 'Save changes' : 'Create vacancy'}</button></div></form></div>}
      </div>
    </Authorize>
  )
}
