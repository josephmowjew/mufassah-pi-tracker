import { useEffect, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Loader2, UserPlus, X } from 'lucide-react'
import { toast } from 'sonner'
import { customersApi, customerCreateMode, type CustomerSummary } from '@/api/customers'
import { useAuthStore } from '@/stores/auth-store'
import { cn } from '@/lib/utils'

export interface CreateCustomerDialogProps {
  /** Called with the created customer so the caller can select it immediately. */
  onCreated: (customer: CustomerSummary) => void
  onClose: () => void
  /** Pre-fill from what the operator already typed (a name, or a phone number). */
  initialName?: string
  initialPhone?: string
  /**
   * Require a phone number or an email. Turn this on wherever the customer has
   * to be contactable afterwards (layby reminders, receipts, handover checks).
   */
  requireContact?: boolean
  /** Optional affordance when the identity is already on file. */
  onConflict?: () => void
  /** Admin mode only: book the customer into the branch being worked in. */
  branchId?: number | null
  description?: string
}

type FieldErrors = Partial<Record<'name' | 'phone_number' | 'email' | 'national_id', string>>

const fieldClass = (invalid: boolean) =>
  cn(
    'w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20',
    invalid ? 'border-red-300' : 'border-slate-200'
  )

export default function CreateCustomerDialog({
  onCreated,
  onClose,
  initialName = '',
  initialPhone = '',
  requireContact = false,
  onConflict,
  branchId = null,
  description = 'The new customer will be selected for this record.',
}: CreateCustomerDialogProps) {
  // Select the stable action, not its result: a selector that returns a fresh
  // array on every call re-renders forever under zustand v5.
  const getUserRoles = useAuthStore((state) => state.getUserRoles)
  const mode = customerCreateMode(getUserRoles())
  const queryClient = useQueryClient()
  const [isPending, setIsPending] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [message, setMessage] = useState<string | null>(null)
  const [form, setForm] = useState({
    name: initialName,
    phone_number: initialPhone,
    email: '',
    national_id: '',
  })

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [onClose])

  const update = (field: keyof typeof form, value: string) => {
    setForm((current) => ({ ...current, [field]: value }))
    if (errors[field]) setErrors((current) => ({ ...current, [field]: undefined }))
  }

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setMessage(null)

    const name = form.name.trim()
    const phone = form.phone_number.trim()
    const email = form.email.trim()

    const nextErrors: FieldErrors = {}
    if (!name) nextErrors.name = 'Enter the customer name.'
    if (requireContact && !phone && !email) {
      nextErrors.phone_number = 'Enter a phone number or an email address.'
    }
    if (Object.keys(nextErrors).length > 0) {
      setErrors(nextErrors)
      return
    }

    setIsPending(true)
    try {
      const customer = await customersApi.create(
        { name, phone_number: phone, email, national_id: form.national_id, branch_id: branchId },
        mode
      )

      // Every page caches customer choices under its own key; refresh them all
      // so the new record shows up in searches as well as in the current form.
      await queryClient.invalidateQueries({ queryKey: ['customers'] })
      await queryClient.invalidateQueries({ queryKey: ['search-customers'] })
      await queryClient.invalidateQueries({ queryKey: ['invoice-customers'] })

      toast.success('Customer created successfully')
      onCreated(customer)
    } catch (error) {
      const failure = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }

      if (failure.response?.data?.errors) {
        const fieldErrors: FieldErrors = {}
        for (const [field, messages] of Object.entries(failure.response.data.errors)) {
          if (field === 'name' || field === 'phone_number' || field === 'email' || field === 'national_id') {
            fieldErrors[field] = messages[0]
          }
        }
        setErrors(fieldErrors)
        if (fieldErrors.phone_number || fieldErrors.email) {
          setMessage('That customer may already be on file. Search for them before creating a duplicate.')
        }
        return
      }

      setMessage(failure.response?.data?.message ?? 'The customer could not be created.')
    } finally {
      setIsPending(false)
    }
  }

  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="presentation">
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="create-customer-title"
        className="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl bg-white shadow-xl"
      >
        <header className="flex items-start justify-between border-b border-slate-200 px-5 py-4">
          <div>
            <h2 id="create-customer-title" className="font-semibold text-slate-900">Create customer</h2>
            <p className="text-xs text-slate-500">{description}</p>
          </div>
          <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <X className="h-5 w-5" />
          </button>
        </header>

        <form onSubmit={submit} className="space-y-4 p-5">
          <div>
            <label htmlFor="new-customer-name" className="mb-1 block text-sm font-medium text-slate-700">
              Full name <span className="text-red-500">*</span>
            </label>
            <input
              id="new-customer-name"
              required
              autoFocus
              value={form.name}
              onChange={(event) => update('name', event.target.value)}
              className={fieldClass(Boolean(errors.name))}
              placeholder="Customer name"
              maxLength={255}
            />
            {errors.name && <p className="mt-1 text-xs text-red-600">{errors.name}</p>}
          </div>

          <div>
            <label htmlFor="new-customer-phone" className="mb-1 block text-sm font-medium text-slate-700">
              Phone number {requireContact && <span className="text-red-500">*</span>}
            </label>
            <input
              id="new-customer-phone"
              value={form.phone_number}
              onChange={(event) => update('phone_number', event.target.value)}
              className={fieldClass(Boolean(errors.phone_number))}
              placeholder="e.g. 0991 234 567"
              inputMode="tel"
              maxLength={20}
            />
            {errors.phone_number && <p className="mt-1 text-xs text-red-600">{errors.phone_number}</p>}
          </div>

          <div>
            <label htmlFor="new-customer-email" className="mb-1 block text-sm font-medium text-slate-700">
              Email
            </label>
            <input
              id="new-customer-email"
              type="email"
              value={form.email}
              onChange={(event) => update('email', event.target.value)}
              className={fieldClass(Boolean(errors.email))}
              placeholder="Optional"
              maxLength={255}
            />
            {errors.email && <p className="mt-1 text-xs text-red-600">{errors.email}</p>}
          </div>

          <div>
            <label htmlFor="new-customer-national-id" className="mb-1 block text-sm font-medium text-slate-700">
              National ID
            </label>
            <input
              id="new-customer-national-id"
              value={form.national_id}
              onChange={(event) => update('national_id', event.target.value)}
              className={fieldClass(Boolean(errors.national_id))}
              placeholder="Optional"
              maxLength={50}
            />
            {errors.national_id && <p className="mt-1 text-xs text-red-600">{errors.national_id}</p>}
          </div>

          {message && (
            <div role="alert" className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
              <p>{message}</p>
              {onConflict && (
                <button
                  type="button"
                  onClick={onConflict}
                  className="mt-2 font-medium text-teal-700 underline hover:text-teal-900"
                >
                  Search existing customers
                </button>
              )}
            </div>
          )}

          <div className="flex justify-end gap-2 pt-2">
            <button
              type="button"
              onClick={onClose}
              className="rounded-lg border border-slate-200 px-4 py-2 text-sm"
              disabled={isPending}
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={isPending}
              className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700 disabled:opacity-50"
            >
              {isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <UserPlus className="h-4 w-4" />}
              Create and select
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
