import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLocation } from 'react-router-dom'
import {
  CheckCircle2,
  FileText,
  Loader2,
  Mail,
  Plus,
  Printer,
  ReceiptText,
  Search,
  ShieldCheck,
  Trash2,
  X,
  XCircle,
} from 'lucide-react'
import apiClient from '@/api/client'
import { devicesApi } from '@/api/devices'
import { toast } from 'sonner'
import type { Branch, Invoice, ProductOptions, User as UserType } from '@/types'

interface InvoiceListResponse {
  data: Invoice[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

interface InvoiceLine {
  category_id: number
  category_name: string
  brand: string
  model: string
  color: string | null
  quantity: number
  unit_price: string
  discount: string
}

interface NewCustomerFormData {
  name: string
  email: string
  phone_number: string
  national_id: string
}

const initialNewCustomerForm: NewCustomerFormData = {
  name: '',
  email: '',
  phone_number: '',
  national_id: '',
}

const formatMoney = (value: number | string | null | undefined) =>
  `MWK ${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const formatDate = (value: string | null | undefined) =>
  value ? new Date(value).toLocaleString() : '—'

const roundMoney = (value: number) => Math.round((value + Number.EPSILON) * 100) / 100

export default function InvoicesPage() {
  const location = useLocation()
  const queryClient = useQueryClient()
  const isCustomerService = location.pathname.startsWith('/customer-service')
  const basePath = isCustomerService ? '/customer-service' : '/admin'
  const customersPath = isCustomerService ? '/customer-service/customers' : '/admin/users'
  const optionsPath = isCustomerService ? '/customer-service/inventory/product-options' : '/admin/inventory/product-options'

  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [showCreate, setShowCreate] = useState(false)
  const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null)
  const [invoiceToRedeem, setInvoiceToRedeem] = useState<Invoice | null>(null)
  const [paymentMethod, setPaymentMethod] = useState('cash')
  const [paymentAmount, setPaymentAmount] = useState('')
  const [paymentReference, setPaymentReference] = useState('')

  const [customerSearch, setCustomerSearch] = useState('')
  const [selectedCustomer, setSelectedCustomer] = useState<UserType | null>(null)
  const [recipientEmail, setRecipientEmail] = useState('')
  const [showNewCustomer, setShowNewCustomer] = useState(false)
  const [newCustomerForm, setNewCustomerForm] = useState<NewCustomerFormData>(initialNewCustomerForm)
  const [newCustomerErrors, setNewCustomerErrors] = useState<Partial<Record<keyof NewCustomerFormData, string>>>({})
  const [branchId, setBranchId] = useState('')
  const [vatApplicable, setVatApplicable] = useState(true)
  const [taxRate, setTaxRate] = useState('17.5')
  const [notes, setNotes] = useState('')

  const [categoryId, setCategoryId] = useState<number | null>(null)
  const [brand, setBrand] = useState('')
  const [model, setModel] = useState('')
  const [color, setColor] = useState('')
  const [quantity, setQuantity] = useState(1)
  const [discount, setDiscount] = useState('0')
  const [lines, setLines] = useState<InvoiceLine[]>([])

  const { data: invoiceData, isLoading: invoicesLoading } = useQuery({
    queryKey: ['invoices', basePath, search, status, page],
    queryFn: async () => {
      const params = new URLSearchParams({ page: String(page), per_page: '15' })
      if (search) params.set('search', search)
      if (status) params.set('status', status)
      const response = await apiClient.get<InvoiceListResponse>(`${basePath}/invoices?${params}`)
      return response.data
    },
  })

  const { data: customers = [] } = useQuery({
    queryKey: ['invoice-customers', customersPath, customerSearch],
    queryFn: async () => {
      if (customerSearch.length < 2) return []
      const response = await apiClient.get<{ data: UserType[] }>(`${customersPath}?search=${encodeURIComponent(customerSearch)}&per_page=10`)
      return response.data.data
    },
    enabled: customerSearch.length >= 2,
  })

  const createCustomerMutation = useMutation({
    mutationFn: async () => {
      const payload: Record<string, string | number | null> = {
        name: newCustomerForm.name.trim(),
        email: newCustomerForm.email.trim(),
        phone_number: newCustomerForm.phone_number.trim() || null,
        national_id: newCustomerForm.national_id.trim() || null,
      }

      if (!isCustomerService && branchId) {
        payload.branch_id = Number(branchId)
      }

      const endpoint = isCustomerService ? '/customer-service/customers' : '/admin/users'
      const response = await apiClient.post<{ data: UserType }>(endpoint, {
        ...payload,
        ...(!isCustomerService ? { role: 'customer' } : {}),
      })
      return response.data.data
    },
    onSuccess: (customer) => {
      queryClient.invalidateQueries({ queryKey: ['invoice-customers'] })
      setSelectedCustomer(customer)
      setRecipientEmail(customer.email || newCustomerForm.email.trim())
      setCustomerSearch('')
      setShowNewCustomer(false)
      setNewCustomerForm(initialNewCustomerForm)
      setNewCustomerErrors({})
      toast.success('Customer created and selected')
    },
    onError: (error: { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }) => {
      const errors = error.response?.data?.errors
      if (errors) {
        setNewCustomerErrors(Object.fromEntries(
          Object.entries(errors).map(([field, messages]) => [field, messages[0]]),
        ) as Partial<Record<keyof NewCustomerFormData, string>>)
      }
      toast.error(error.response?.data?.message || 'Failed to create customer')
    },
  })

  const { data: categories = [] } = useQuery({
    queryKey: ['invoice-categories', isCustomerService],
    queryFn: () => devicesApi.getCategories({ withStock: true }),
  })

  const { data: branches = [] } = useQuery({
    queryKey: ['invoice-branches'],
    queryFn: async () => {
      if (isCustomerService) return []
      const response = await apiClient.get<{ data: Branch[] }>('/admin/branches?per_page=100')
      return response.data.data
    },
    enabled: !isCustomerService,
  })

  const { data: productOptions, isLoading: optionsLoading } = useQuery({
    queryKey: ['invoice-product-options', isCustomerService, categoryId, brand, model],
    queryFn: async () => {
      if (!categoryId) return null
      const params = new URLSearchParams({ category_id: String(categoryId) })
      if (brand) params.set('brand', brand)
      if (model) params.set('model', model)
      const response = await apiClient.get<{ data: ProductOptions }>(`${optionsPath}?${params}`)
      return response.data.data
    },
    enabled: categoryId !== null,
  })

  const createInvoiceMutation = useMutation({
    mutationFn: async () => {
      if (!selectedCustomer) throw new Error('Select a customer first.')
      if (!recipientEmail.trim()) throw new Error('An email address is required to send the invoice.')
      if (lines.length === 0) throw new Error('Add at least one product to the invoice.')

      const response = await apiClient.post<{ data: Invoice }>(`${basePath}/invoices`, {
        customer_id: selectedCustomer.id,
        branch_id: branchId ? Number(branchId) : null,
        recipient_email: recipientEmail.trim(),
        vat_applicable: vatApplicable,
        tax_rate: Number(taxRate || 0),
        notes: notes || null,
        items: lines.map((line) => ({
          category_id: line.category_id,
          brand: line.brand,
          model: line.model,
          color: line.color || null,
          quantity: line.quantity,
          discount: Number(line.discount || 0),
        })),
      })
      return response.data.data
    },
    onSuccess: (invoice) => {
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      queryClient.invalidateQueries({ queryKey: ['invoice-product-options'] })
      queryClient.invalidateQueries({ queryKey: ['invoice-categories'] })
      toast.success(`Invoice ${invoice.invoice_number} sent successfully`)
      resetCreateForm()
      setSelectedInvoice(invoice)
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || error.message || 'Failed to issue invoice')
    },
  })

  const resendMutation = useMutation({
    mutationFn: async (invoiceId: number) => {
      await apiClient.post(`${basePath}/invoices/${invoiceId}/resend`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      toast.success('Invoice email queued again')
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Failed to resend invoice email')
    },
  })

  const redeemMutation = useMutation({
    mutationFn: async () => {
      if (!invoiceToRedeem) throw new Error('Select an invoice to redeem.')
      const response = await apiClient.post<{ data: Invoice }>(`${basePath}/invoices/${invoiceToRedeem.id}/redeem`, {
        payment_method: paymentMethod,
        payment_amount: paymentAmount ? Number(paymentAmount) : null,
        payment_reference: paymentReference || null,
      })
      return response.data.data
    },
    onSuccess: (invoice) => {
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      queryClient.invalidateQueries({ queryKey: ['invoice-product-options'] })
      toast.success(`Invoice ${invoice.invoice_number} redeemed`)
      setInvoiceToRedeem(null)
      setSelectedInvoice(invoice)
      setPaymentAmount('')
      setPaymentReference('')
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || error.message || 'Failed to redeem invoice')
    },
  })

  const loadInvoice = async (invoice: Invoice) => {
    const response = await apiClient.get<{ data: Invoice }>(`${basePath}/invoices/${invoice.id}`)
    setSelectedInvoice(response.data.data)
  }

  const downloadInvoice = async (invoice: Invoice) => {
    try {
      const response = await apiClient.get(`${basePath}/invoices/${invoice.id}/document?format=pdf`, { responseType: 'blob' })
      const url = URL.createObjectURL(response.data as Blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `invoice_${invoice.invoice_number}.pdf`
      link.click()
      URL.revokeObjectURL(url)
    } catch {
      toast.error('Could not download the invoice')
    }
  }

  const resetCreateForm = () => {
    setShowCreate(false)
    setShowNewCustomer(false)
    setNewCustomerForm(initialNewCustomerForm)
    setNewCustomerErrors({})
    setSelectedCustomer(null)
    setCustomerSearch('')
    setRecipientEmail('')
    setBranchId('')
    setVatApplicable(true)
    setTaxRate('17.5')
    setNotes('')
    setCategoryId(null)
    setBrand('')
    setModel('')
    setColor('')
    setQuantity(1)
    setDiscount('0')
    setLines([])
  }

  const addLine = () => {
    if (!categoryId || !brand || !model) {
      toast.error('Select a category, brand, and model first')
      return
    }
    const available = productOptions?.available_count || 0
    if (quantity < 1 || quantity > available) {
      toast.error(`Choose a quantity between 1 and ${available}`)
      return
    }
    const unitPrice = Number(productOptions?.selling_price || 0)
    if (unitPrice <= 0) {
      toast.error('This product has no valid selling price')
      return
    }
    const category = categories.find((item) => item.id === categoryId)
    const line: InvoiceLine = {
      category_id: categoryId,
      category_name: category?.name || '',
      brand,
      model,
      color: color || null,
      quantity,
      unit_price: String(unitPrice),
      discount: discount || '0',
    }
    setLines((current) => [...current, line])
    setCategoryId(null)
    setBrand('')
    setModel('')
    setColor('')
    setQuantity(1)
    setDiscount('0')
  }

  const updateLine = (index: number, field: 'quantity' | 'discount', value: string) => {
    setLines((current) => current.map((line, lineIndex) => {
      if (lineIndex !== index) return line
      if (field === 'quantity') return { ...line, quantity: Math.max(1, Number(value) || 1) }
      const nextDiscount = Number(value) || 0
      if (nextDiscount > Number(line.unit_price)) {
        toast.error('Discount cannot exceed the unit price')
        return line
      }
      return { ...line, discount: value }
    }))
  }

  const totals = useMemo(() => {
    const subtotal = lines.reduce((sum, line) => sum + Number(line.unit_price) * line.quantity, 0)
    const discountAmount = lines.reduce((sum, line) => sum + Number(line.discount || 0) * line.quantity, 0)
    const tax = vatApplicable ? roundMoney((subtotal - discountAmount) * (Number(taxRate) / 100)) : 0
    return { subtotal, discountAmount, tax, total: roundMoney(subtotal - discountAmount + tax) }
  }, [lines, vatApplicable, taxRate])

  const invoices = invoiceData?.data || []
  const meta = invoiceData?.meta
  const options = productOptions
  const selectedCategory = categories.find((item) => item.id === categoryId)

  const statusBadge = (invoice: Invoice) => {
    const styles: Record<string, string> = {
      sent: 'bg-emerald-50 text-emerald-700',
      expired: 'bg-amber-50 text-amber-700',
      cancelled: 'bg-red-50 text-red-700',
      converted: 'bg-blue-50 text-blue-700',
    }
    return (
      <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ${styles[invoice.status.value] || 'bg-slate-100 text-slate-700'}`}>
        {invoice.status.label}
      </span>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Invoices</h1>
          <p className="mt-1 text-sm text-slate-500">Issue and redeem 24-hour price-locked device offers</p>
        </div>
        <button onClick={() => setShowCreate(true)} className="inline-flex items-center justify-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700">
          <Plus className="h-4 w-4" /> New Invoice
        </button>
      </div>

      <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div className="flex flex-col gap-3 lg:flex-row">
          <div className="relative flex-1">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1) }} placeholder="Search invoice number, customer, or email" className="w-full rounded-lg border border-slate-200 py-2.5 pl-10 pr-3 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-500/20" />
          </div>
          <select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1) }} className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 focus:border-teal-500 focus:outline-none">
            <option value="">All statuses</option>
            <option value="sent">Active</option>
            <option value="converted">Redeemed</option>
            <option value="expired">Expired</option>
            <option value="cancelled">Cancelled</option>
          </select>
        </div>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {invoicesLoading ? (
          <div className="flex justify-center py-16"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div>
        ) : invoices.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-16 text-center">
            <ReceiptText className="mb-3 h-10 w-10 text-slate-300" />
            <p className="text-sm text-slate-500">No invoices found</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="border-b border-slate-200 bg-slate-50 text-left text-xs font-medium uppercase tracking-wider text-slate-500">
                <tr>
                  <th className="px-6 py-3">Invoice</th><th className="px-6 py-3">Customer</th><th className="px-6 py-3">Total</th><th className="px-6 py-3">VAT</th><th className="px-6 py-3">Status</th><th className="px-6 py-3">Expires</th><th className="px-6 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {invoices.map((invoice) => (
                  <tr key={invoice.id} className="hover:bg-slate-50">
                    <td className="px-6 py-4"><div className="flex items-center gap-2 font-medium text-slate-900"><FileText className="h-4 w-4 text-slate-400" />{invoice.invoice_number}</div><p className="mt-1 text-xs text-slate-500">{invoice.items_count || 0} item(s)</p></td>
                    <td className="px-6 py-4"><p className="text-sm text-slate-700">{invoice.customer?.name || 'Customer'}</p><p className="text-xs text-slate-500">{invoice.recipient_email}</p></td>
                    <td className="px-6 py-4 font-medium text-slate-900">{formatMoney(invoice.total_amount)}</td>
                    <td className="px-6 py-4 text-sm text-slate-600">{invoice.vat_applicable ? `${invoice.tax_rate}%` : 'Not applicable'}</td>
                    <td className="px-6 py-4">{statusBadge(invoice)}</td>
                    <td className="px-6 py-4 text-sm text-slate-500">{formatDate(invoice.expires_at)}</td>
                    <td className="px-6 py-4"><div className="flex justify-end gap-2">
                      <button onClick={() => loadInvoice(invoice)} className="text-teal-600 hover:text-teal-800" title="View invoice"><FileText className="h-4 w-4" /></button>
                      <button onClick={() => resendMutation.mutate(invoice.id)} disabled={resendMutation.isPending} className="text-slate-600 hover:text-slate-900 disabled:opacity-50" title="Resend email"><Mail className="h-4 w-4" /></button>
                      <button onClick={() => downloadInvoice(invoice)} className="text-slate-600 hover:text-slate-900" title="Download PDF"><Printer className="h-4 w-4" /></button>
                      {invoice.status.value === 'sent' && <button onClick={() => { setInvoiceToRedeem(invoice); setPaymentAmount(String(invoice.total_amount)); setPaymentMethod('cash') }} className="text-emerald-600 hover:text-emerald-800" title="Redeem invoice"><CheckCircle2 className="h-4 w-4" /></button>}
                    </div></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {meta && meta.last_page > 1 && <div className="flex items-center justify-between border-t border-slate-200 px-6 py-4 text-sm text-slate-500"><span>Page {meta.current_page} of {meta.last_page}</span><div className="flex gap-2"><button disabled={page <= 1} onClick={() => setPage((value) => value - 1)} className="rounded-lg border border-slate-200 px-3 py-1 disabled:opacity-50">Previous</button><button disabled={page >= meta.last_page} onClick={() => setPage((value) => value + 1)} className="rounded-lg border border-slate-200 px-3 py-1 disabled:opacity-50">Next</button></div></div>}
      </div>

      {showNewCustomer && <NewCustomerModal form={newCustomerForm} errors={newCustomerErrors} isPending={createCustomerMutation.isPending} onChange={(field, value) => { setNewCustomerForm((current) => ({ ...current, [field]: value })); if (newCustomerErrors[field]) setNewCustomerErrors((current) => ({ ...current, [field]: undefined })) }} onClose={() => setShowNewCustomer(false)} onSubmit={() => createCustomerMutation.mutate()} />}

      {showCreate && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="max-h-[92vh] w-full max-w-5xl overflow-y-auto rounded-xl bg-white shadow-xl">
            <div className="sticky top-0 z-10 flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4"><div><h2 className="text-lg font-semibold text-slate-900">Issue price-locked invoice</h2><p className="text-xs text-slate-500">The exact devices are reserved for 24 hours after issue.</p></div><button onClick={resetCreateForm} className="rounded-lg p-2 text-slate-400 hover:bg-slate-100"><X className="h-5 w-5" /></button></div>
            <div className="grid gap-6 p-6 lg:grid-cols-2">
              <div className="space-y-5">
                <div><div className="mb-2 flex items-center justify-between"><label className="block text-sm font-medium text-slate-700">Customer and recipient</label><button type="button" onClick={() => { setShowNewCustomer(true); setNewCustomerErrors({}) }} className="inline-flex items-center gap-1 text-xs font-medium text-teal-700 hover:text-teal-900"><Plus className="h-3.5 w-3.5" /> New customer</button></div>{selectedCustomer ? <div className="flex items-center justify-between rounded-lg border border-teal-200 bg-teal-50 p-3"><div><p className="font-medium text-slate-900">{selectedCustomer.name}</p><p className="text-xs text-slate-500">{selectedCustomer.email || 'No email on customer record'}</p></div><button type="button" onClick={() => { setSelectedCustomer(null); setRecipientEmail('') }} className="text-slate-400 hover:text-slate-600"><XCircle className="h-5 w-5" /></button></div> : <div className="relative"><Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" /><input value={customerSearch} onChange={(event) => setCustomerSearch(event.target.value)} placeholder="Search customers by name or email" className="w-full rounded-lg border border-slate-200 py-2.5 pl-10 pr-3 text-sm focus:border-teal-500 focus:outline-none" />{customers.length > 0 && <div className="absolute z-20 mt-1 max-h-48 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">{customers.map((customer) => <button type="button" key={customer.id} onClick={() => { setSelectedCustomer(customer); setRecipientEmail(customer.email || ''); setCustomerSearch('') }} className="block w-full px-4 py-2 text-left hover:bg-slate-50"><p className="text-sm text-slate-900">{customer.name}</p><p className="text-xs text-slate-500">{customer.email || customer.phone_number || 'No contact details'}</p></button>)}</div>}</div>}
                  <label className="mt-3 block text-xs font-medium text-slate-600">Send invoice to <span className="text-red-500">*</span><input type="email" value={recipientEmail} onChange={(event) => setRecipientEmail(event.target.value)} className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none" placeholder="customer@example.com" /></label>
                </div>

                <div><label className="mb-2 block text-sm font-medium text-slate-700">Add products</label><div className="grid gap-3 rounded-lg border border-slate-200 p-4 sm:grid-cols-2">
                  <select value={categoryId || ''} onChange={(event) => { setCategoryId(event.target.value ? Number(event.target.value) : null); setBrand(''); setModel(''); setColor('') }} className="rounded-lg border border-slate-200 px-3 py-2 text-sm"><option value="">Category</option>{categories.map((category) => <option key={category.id} value={category.id}>{category.name} ({category.available_count || 0})</option>)}</select>
                  <select value={brand} onChange={(event) => { setBrand(event.target.value); setModel(''); setColor('') }} disabled={!categoryId || optionsLoading} className="rounded-lg border border-slate-200 px-3 py-2 text-sm disabled:bg-slate-50"><option value="">Brand</option>{(options?.brands || []).map((item) => <option key={item.name} value={item.name || ''}>{item.name} ({item.count})</option>)}</select>
                  <select value={model} onChange={(event) => { setModel(event.target.value); setColor('') }} disabled={!brand || optionsLoading} className="rounded-lg border border-slate-200 px-3 py-2 text-sm disabled:bg-slate-50"><option value="">Model</option>{(options?.models || []).map((item) => <option key={item.name} value={item.name || ''}>{item.name} ({item.count})</option>)}</select>
                  {options?.has_colors && <select value={color} onChange={(event) => setColor(event.target.value)} className="rounded-lg border border-slate-200 px-3 py-2 text-sm"><option value="">Any color</option>{(options.colors || []).filter((item) => item.name).map((item) => <option key={item.name} value={item.name || ''}>{item.name} ({item.count})</option>)}</select>}
                  <label className="text-xs text-slate-500">Quantity<input type="number" min={1} max={options?.available_count || 1} value={quantity} onChange={(event) => setQuantity(Math.max(1, Number(event.target.value) || 1))} className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" /></label>
                  <label className="text-xs text-slate-500">Discount per unit<input type="number" min={0} step="0.01" value={discount} onChange={(event) => setDiscount(event.target.value)} className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" /></label>
                  <div className="sm:col-span-2"><div className="mb-2 flex justify-between text-xs text-slate-500"><span>{selectedCategory?.name || 'Selected product'}</span><span>{options ? `${options.available_count} available · ${formatMoney(options.selling_price)}` : ''}</span></div><button type="button" onClick={addLine} className="w-full rounded-lg bg-slate-800 px-3 py-2 text-sm font-medium text-white hover:bg-slate-900">Add to invoice</button></div>
                </div></div>

                {lines.length > 0 && <div className="space-y-2">{lines.map((line, index) => <div key={`${line.category_id}-${line.brand}-${line.model}-${line.color}-${index}`} className="flex items-center justify-between rounded-lg border border-slate-200 p-3"><div className="min-w-0"><p className="truncate text-sm font-medium text-slate-900">{line.brand} {line.model}{line.color ? ` - ${line.color}` : ''}</p><p className="text-xs text-slate-500">{line.quantity} × {formatMoney(line.unit_price)} · discount {formatMoney(line.discount)} each</p></div><div className="flex items-center gap-2"><input aria-label="Quantity" type="number" min={1} value={line.quantity} onChange={(event) => updateLine(index, 'quantity', event.target.value)} className="w-16 rounded border border-slate-200 px-2 py-1 text-sm" /><button type="button" onClick={() => setLines((current) => current.filter((_, lineIndex) => lineIndex !== index))} className="text-red-500 hover:text-red-700"><Trash2 className="h-4 w-4" /></button></div></div>)}</div>}
              </div>

              <div className="space-y-5">
                {!isCustomerService && <div><label className="mb-2 block text-sm font-medium text-slate-700">Branch</label><select value={branchId} onChange={(event) => setBranchId(event.target.value)} className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm"><option value="">Your assigned/default branch</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></div>}
                <div className="rounded-lg border border-teal-200 bg-teal-50 p-4"><div className="flex items-start gap-3"><input id="invoice-vat" type="checkbox" checked={vatApplicable} onChange={(event) => setVatApplicable(event.target.checked)} className="mt-1 h-5 w-5 rounded border-teal-300 text-teal-600 focus:ring-teal-500" /><div className="flex-1"><label htmlFor="invoice-vat" className="block text-sm font-medium text-slate-900">Apply VAT to this invoice</label><p className="mt-1 text-xs text-slate-600">The selected rate and VAT amount are frozen when the invoice is issued.</p>{vatApplicable && <div className="mt-3 flex items-center gap-2"><span className="text-sm text-slate-700">Rate</span><input type="number" min="0" max="100" step="0.01" value={taxRate} onChange={(event) => setTaxRate(event.target.value)} className="w-24 rounded-lg border border-teal-200 px-3 py-2 text-sm" /><span className="text-sm text-slate-700">%</span></div>}</div></div></div>
                <div><label className="mb-2 block text-sm font-medium text-slate-700">Notes (optional)</label><textarea value={notes} onChange={(event) => setNotes(event.target.value)} rows={4} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" placeholder="Delivery instructions or customer-facing notes" /></div>
                <div className="rounded-lg bg-slate-50 p-4"><h3 className="mb-3 font-medium text-slate-900">Invoice summary</h3><div className="space-y-2 text-sm"><div className="flex justify-between"><span className="text-slate-500">Subtotal</span><span>{formatMoney(totals.subtotal)}</span></div><div className="flex justify-between"><span className="text-slate-500">Discount</span><span className="text-red-600">-{formatMoney(totals.discountAmount)}</span></div><div className="flex justify-between"><span className="text-slate-500">VAT</span><span>{vatApplicable ? `${taxRate}%: ${formatMoney(totals.tax)}` : 'Not applicable'}</span></div><div className="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold"><span>Total</span><span className="text-teal-700">{formatMoney(totals.total)}</span></div></div></div>
                <div className="flex items-start gap-2 rounded-lg bg-amber-50 p-3 text-xs text-amber-800"><ShieldCheck className="mt-0.5 h-4 w-4 shrink-0" />Issuing this invoice reserves the exact devices and queues an email to the recipient. The reservation is released automatically at expiry.</div>
                <button type="button" onClick={() => createInvoiceMutation.mutate()} disabled={createInvoiceMutation.isPending || !selectedCustomer || !recipientEmail.trim() || lines.length === 0} className="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-50">{createInvoiceMutation.isPending && <Loader2 className="h-4 w-4 animate-spin" />}Send invoice</button>
              </div>
            </div>
          </div>
        </div>
      )}

      {selectedInvoice && <InvoiceDetails invoice={selectedInvoice} onClose={() => setSelectedInvoice(null)} onDownload={() => downloadInvoice(selectedInvoice)} onRedeem={() => { setInvoiceToRedeem(selectedInvoice); setPaymentAmount(String(selectedInvoice.total_amount)); setPaymentMethod('cash') }} />}
      {invoiceToRedeem && <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4"><div className="w-full max-w-md rounded-xl bg-white shadow-xl"><div className="flex items-center justify-between border-b border-slate-200 px-5 py-4"><div><h2 className="font-semibold text-slate-900">Redeem {invoiceToRedeem.invoice_number}</h2><p className="text-xs text-slate-500">Frozen total: {formatMoney(invoiceToRedeem.total_amount)}</p></div><button onClick={() => setInvoiceToRedeem(null)} className="text-slate-400"><X className="h-5 w-5" /></button></div><div className="space-y-4 p-5"><div><label className="mb-1 block text-sm font-medium text-slate-700">Payment method</label><select value={paymentMethod} onChange={(event) => setPaymentMethod(event.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm"><option value="cash">Cash</option><option value="mobile_money">Mobile Money</option><option value="card">Card</option><option value="bank_transfer">Bank Transfer</option><option value="credit">Credit / On Account</option></select></div>{paymentMethod === 'cash' && <div><label className="mb-1 block text-sm font-medium text-slate-700">Amount paid</label><input type="number" min="0" step="0.01" value={paymentAmount} onChange={(event) => setPaymentAmount(event.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" /></div>}<div><label className="mb-1 block text-sm font-medium text-slate-700">Payment reference (optional)</label><input value={paymentReference} onChange={(event) => setPaymentReference(event.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" /></div><div className="flex justify-end gap-2"><button onClick={() => setInvoiceToRedeem(null)} className="rounded-lg border border-slate-200 px-4 py-2 text-sm">Cancel</button><button onClick={() => redeemMutation.mutate()} disabled={redeemMutation.isPending || (paymentMethod === 'cash' && Number(paymentAmount) < Number(invoiceToRedeem.total_amount))} className="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50">{redeemMutation.isPending && <Loader2 className="h-4 w-4 animate-spin" />}Redeem invoice</button></div></div></div></div>}
    </div>
  )
}

function NewCustomerModal({ form, errors, isPending, onChange, onClose, onSubmit }: { form: NewCustomerFormData; errors: Partial<Record<keyof NewCustomerFormData, string>>; isPending: boolean; onChange: (field: keyof NewCustomerFormData, value: string) => void; onClose: () => void; onSubmit: () => void }) {
  return <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4"><div className="w-full max-w-md rounded-xl bg-white shadow-xl"><div className="flex items-center justify-between border-b border-slate-200 px-5 py-4"><div><h2 className="font-semibold text-slate-900">Create customer</h2><p className="text-xs text-slate-500">The new customer will be selected for this invoice.</p></div><button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-600"><X className="h-5 w-5" /></button></div><form onSubmit={(event) => { event.preventDefault(); onSubmit() }} className="space-y-4 p-5"><div><label htmlFor="new-invoice-customer-name" className="mb-1 block text-sm font-medium text-slate-700">Full name <span className="text-red-500">*</span></label><input id="new-invoice-customer-name" required value={form.name} onChange={(event) => onChange('name', event.target.value)} className={`w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 ${errors.name ? 'border-red-300' : 'border-slate-200'}`} placeholder="Customer name" />{errors.name && <p className="mt-1 text-xs text-red-600">{errors.name}</p>}</div><div><label htmlFor="new-invoice-customer-email" className="mb-1 block text-sm font-medium text-slate-700">Email <span className="text-red-500">*</span></label><input id="new-invoice-customer-email" required type="email" value={form.email} onChange={(event) => onChange('email', event.target.value)} className={`w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 ${errors.email ? 'border-red-300' : 'border-slate-200'}`} placeholder="customer@example.com" />{errors.email && <p className="mt-1 text-xs text-red-600">{errors.email}</p>}</div><div><label htmlFor="new-invoice-customer-phone" className="mb-1 block text-sm font-medium text-slate-700">Phone number</label><input id="new-invoice-customer-phone" value={form.phone_number} onChange={(event) => onChange('phone_number', event.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20" placeholder="Optional" /></div><div><label htmlFor="new-invoice-customer-national-id" className="mb-1 block text-sm font-medium text-slate-700">National ID</label><input id="new-invoice-customer-national-id" value={form.national_id} onChange={(event) => onChange('national_id', event.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20" placeholder="Optional" /></div><div className="flex justify-end gap-2 pt-2"><button type="button" onClick={onClose} className="rounded-lg border border-slate-200 px-4 py-2 text-sm">Cancel</button><button type="submit" disabled={isPending} className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700 disabled:opacity-50">{isPending && <Loader2 className="h-4 w-4 animate-spin" />}Create and select</button></div></form></div></div>
}

function InvoiceDetails({ invoice, onClose, onDownload, onRedeem }: { invoice: Invoice; onClose: () => void; onDownload: () => void; onRedeem: () => void }) {
  return <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"><div className="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-xl bg-white shadow-xl"><div className="sticky top-0 flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4"><div><h2 className="font-semibold text-slate-900">{invoice.invoice_number}</h2><p className="text-xs text-slate-500">Issued {formatDate(invoice.issued_at)} · {invoice.is_redeemable ? `Expires ${formatDate(invoice.expires_at)}` : invoice.status.label}</p></div><div className="flex items-center gap-2"><button onClick={onDownload} className="rounded-lg border border-slate-200 p-2 text-slate-600 hover:bg-slate-50" title="Download PDF"><Printer className="h-4 w-4" /></button><button onClick={onClose} className="rounded-lg p-2 text-slate-400 hover:bg-slate-100"><X className="h-5 w-5" /></button></div></div><div className="space-y-5 p-6"><div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-50 p-4"><div><p className="text-sm font-medium text-slate-900">{invoice.customer?.name || 'Customer'}</p><p className="text-xs text-slate-500">{invoice.recipient_email}</p></div><div className="text-right"><p className="text-xs text-slate-500">Total</p><p className="text-xl font-semibold text-slate-900">{formatMoney(invoice.total_amount)}</p></div></div><div className="overflow-x-auto"><table className="w-full text-sm"><thead className="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th className="px-3 py-2">Item</th><th className="px-3 py-2 text-right">Unit</th><th className="px-3 py-2 text-right">Discount</th><th className="px-3 py-2 text-right">VAT</th><th className="px-3 py-2 text-right">Total</th></tr></thead><tbody className="divide-y divide-slate-200">{(invoice.items || []).map((item) => <tr key={item.id}><td className="px-3 py-3 text-slate-800">{item.item_description}</td><td className="px-3 py-3 text-right">{formatMoney(item.unit_price)}</td><td className="px-3 py-3 text-right">{formatMoney(item.discount)}</td><td className="px-3 py-3 text-right">{invoice.vat_applicable ? `${item.tax_rate}%: ${formatMoney(item.tax)}` : 'Not applicable'}</td><td className="px-3 py-3 text-right font-medium">{formatMoney(item.total_price)}</td></tr>)}</tbody></table></div><div className="ml-auto max-w-xs space-y-2 text-sm"><div className="flex justify-between"><span className="text-slate-500">Subtotal</span><span>{formatMoney(invoice.subtotal)}</span></div><div className="flex justify-between"><span className="text-slate-500">Discount</span><span>-{formatMoney(invoice.discount_amount)}</span></div><div className="flex justify-between"><span className="text-slate-500">VAT</span><span>{invoice.vat_applicable ? `${invoice.tax_rate}%: ${formatMoney(invoice.tax_amount)}` : 'Not applicable'}</span></div><div className="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold"><span>Total</span><span>{formatMoney(invoice.total_amount)}</span></div></div>{invoice.notes && <div className="rounded-lg bg-amber-50 p-3 text-sm text-amber-800"><strong>Notes:</strong> {invoice.notes}</div>}<div className="flex justify-end gap-2">{invoice.status.value === 'sent' && <button onClick={onRedeem} className="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700"><CheckCircle2 className="h-4 w-4" />Redeem now</button>}<button onClick={onClose} className="rounded-lg border border-slate-200 px-4 py-2 text-sm">Close</button></div></div></div></div>
}
