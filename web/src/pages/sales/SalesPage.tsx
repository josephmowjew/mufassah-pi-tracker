import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Search, ShoppingCart, Plus, Loader2, User, Eye, CheckCircle, XCircle, RotateCcw, Clock, Receipt, Smartphone, Trash2, Package, UserPlus, X, Minus, Printer } from 'lucide-react'
import apiClient from '@/api/client'
import { devicesApi } from '@/api/devices'
import { toast } from 'sonner'
import type { Sale, User as UserType, SubscriptionPlan, ProductOptions } from '@/types'
import { Authorize } from '@/components/auth/Authorize'
import { PERMISSIONS } from '@/stores/auth-store'
import ReceiptModal from '@/components/sales/ReceiptModal'

interface NewCustomerFormData {
  name: string
  email: string
  phone_number: string
  national_id: string
  password: string
}

interface PaginatedResponse {
  data: Sale[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

interface CartItem {
  category_id: number
  category_name: string
  brand: string
  model: string
  color: string | null
  quantity: number
  unit_price: string
  discount: string
  tax: string
  subscription_plan_id: string
}

const roundMoney = (value: number) => Math.round((value + Number.EPSILON) * 100) / 100
const formatMoney = (value: number | string | null | undefined) =>
  `MWK ${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const customerContactLabel = (customer: Pick<UserType, 'email' | 'phone_number'>) =>
  customer.email || customer.phone_number || 'No contact details'

export default function SalesPage() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [statusFilter, setStatusFilter] = useState('')
  const [selectedSale, setSelectedSale] = useState<Sale | null>(null)
  const [showNewSaleModal, setShowNewSaleModal] = useState(false)
  const [showDetailsModal, setShowDetailsModal] = useState(false)
  const [showRefundModal, setShowRefundModal] = useState(false)
  const [showReceiptModal, setShowReceiptModal] = useState(false)
  const [showCompletePaymentModal, setShowCompletePaymentModal] = useState(false)
  const [saleToComplete, setSaleToComplete] = useState<Sale | null>(null)
  const [completePaymentAmount, setCompletePaymentAmount] = useState('')
  const [completePaymentReference, setCompletePaymentReference] = useState('')
  const [refundReason, setRefundReason] = useState('')

  // New sale state
  const [cart, setCart] = useState<CartItem[]>([])
  const [selectedCustomer, setSelectedCustomer] = useState<UserType | null>(null)
  const [customerSearch, setCustomerSearch] = useState('')

  // Product selection state (cascading dropdowns)
  const [selectedCategoryId, setSelectedCategoryId] = useState<number | null>(null)
  const [selectedBrand, setSelectedBrand] = useState<string>('')
  const [selectedModel, setSelectedModel] = useState<string>('')
  const [selectedColor, setSelectedColor] = useState<string | null>(null)
  const [quantity, setQuantity] = useState(1)

  const [paymentMethod, setPaymentMethod] = useState('cash')
  const [paymentReference, setPaymentReference] = useState('')
  const [amountPaid, setAmountPaid] = useState('')
  const [notes, setNotes] = useState('')
  const [vatApplicable, setVatApplicable] = useState(true)

  // New customer modal state
  const [showNewCustomerModal, setShowNewCustomerModal] = useState(false)
  const [newCustomerForm, setNewCustomerForm] = useState<NewCustomerFormData>({
    name: '',
    email: '',
    phone_number: '',
    national_id: '',
    password: '',
  })
  const [customerFormErrors, setCustomerFormErrors] = useState<Partial<Record<keyof NewCustomerFormData, string>>>({})

  // Fetch sales
  const { data, isLoading, error } = useQuery({
    queryKey: ['admin-sales', search, page, statusFilter],
    queryFn: async () => {
      const params = new URLSearchParams()
      params.append('page', page.toString())
      params.append('per_page', '15')
      if (search) params.append('search', search)
      if (statusFilter) params.append('status', statusFilter)

      const response = await apiClient.get<{ success: boolean; data: Sale[]; meta: PaginatedResponse['meta'] }>(
        `/admin/sales?${params.toString()}`
      )
      return response.data
    },
  })

  // Search customers
  const { data: customersData } = useQuery({
    queryKey: ['search-customers', customerSearch],
    queryFn: async () => {
      if (!customerSearch || customerSearch.length < 2) return []
      const response = await apiClient.get<{ success: boolean; data: UserType[] }>(
        `/admin/users?search=${customerSearch}&per_page=10`
      )
      return response.data.data
    },
    enabled: customerSearch.length >= 2,
  })

  // Fetch categories (simple list for dropdown)
  const { data: categoriesData, isLoading: categoriesLoading } = useQuery({
    queryKey: ['device-categories-with-stock'],
    queryFn: () => devicesApi.getCategories({ withStock: true }),
    staleTime: 1000 * 60 * 5, // 5 minutes
  })

  // Fetch product options based on selected filters (cascading data)
  const { data: productOptions, isLoading: productOptionsLoading } = useQuery({
    queryKey: ['product-options', selectedCategoryId, selectedBrand, selectedModel],
    queryFn: async () => {
      if (!selectedCategoryId) return null

      const params = new URLSearchParams()
      params.append('category_id', selectedCategoryId.toString())
      if (selectedBrand) params.append('brand', selectedBrand)
      if (selectedModel) params.append('model', selectedModel)

      const response = await apiClient.get<{ success: boolean; data: ProductOptions }>(
        `/admin/inventory/product-options?${params.toString()}`
      )
      return response.data.data
    },
    enabled: !!selectedCategoryId,
  })

  // Fetch subscription plans
  const { data: plansData } = useQuery({
    queryKey: ['subscription-plans'],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: SubscriptionPlan[] }>('/subscription-plans')
      return response.data.data
    },
  })

  // Recalculate cart taxes when VAT toggle changes
  useEffect(() => {
    if (cart.length > 0) {
      const updatedCart = cart.map(item => {
        const basePrice = parseFloat(item.unit_price) || 0
        const tax = vatApplicable ? roundMoney(basePrice * 0.175) : 0
        return { ...item, tax: tax.toString() }
      })
      setCart(updatedCart)
    }
  }, [vatApplicable])

  // Create sale mutation (new format with product specs + quantity)
  const createSaleMutation = useMutation({
    mutationFn: async (status: 'draft' | 'completed') => {
      const items = cart.map(item => ({
        category_id: item.category_id,
        brand: item.brand,
        model: item.model,
        color: item.color,
        quantity: item.quantity,
        subscription_plan_id: item.subscription_plan_id ? parseInt(item.subscription_plan_id) : null,
        unit_price: parseFloat(item.unit_price) || 0,
        discount: parseFloat(item.discount) || 0,
        tax: roundMoney(parseFloat(item.tax) || 0),
      }))

      await apiClient.post('/admin/sales', {
        customer_id: selectedCustomer?.id,
        payment_method: paymentMethod,
        payment_reference: paymentReference || null,
        payment_amount: paymentMethod === 'cash' ? (parseFloat(amountPaid) || null) : null,
        notes: notes || null,
        vat_applicable: vatApplicable,
        status,
        items,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-sales'] })
      queryClient.invalidateQueries({ queryKey: ['admin-inventory'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-stats'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-batches'] })
      queryClient.invalidateQueries({ queryKey: ['device-categories-with-stock'] })
      queryClient.invalidateQueries({ queryKey: ['product-options'] })
      queryClient.invalidateQueries({ queryKey: ['admin-dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['admin-shifts'] })
      toast.success('Sale created successfully')
      resetNewSaleForm()
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Failed to create sale')
    },
  })

  // Complete sale mutation
  const completeSaleMutation = useMutation({
    mutationFn: async (data: { saleId: number; payment_amount?: number; payment_reference?: string }) => {
      await apiClient.post(`/admin/sales/${data.saleId}/complete`, {
        payment_amount: data.payment_amount,
        payment_reference: data.payment_reference || undefined,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-sales'] })
      queryClient.invalidateQueries({ queryKey: ['admin-inventory'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-batches'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-stats'] })
      queryClient.invalidateQueries({ queryKey: ['device-categories-with-stock'] })
      queryClient.invalidateQueries({ queryKey: ['product-options'] })
      queryClient.invalidateQueries({ queryKey: ['admin-dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['admin-shifts'] })
      toast.success('Sale completed successfully')
      setShowDetailsModal(false)
      setShowCompletePaymentModal(false)
      setSelectedSale(null)
      setSaleToComplete(null)
      setCompletePaymentAmount('')
      setCompletePaymentReference('')
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Failed to complete sale')
    },
  })

  // Cancel sale mutation
  const cancelSaleMutation = useMutation({
    mutationFn: async (saleId: number) => {
      await apiClient.post(`/admin/sales/${saleId}/cancel`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-sales'] })
      queryClient.invalidateQueries({ queryKey: ['admin-inventory'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-batches'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-stats'] })
      queryClient.invalidateQueries({ queryKey: ['device-categories-with-stock'] })
      queryClient.invalidateQueries({ queryKey: ['product-options'] })
      toast.success('Sale cancelled')
      setShowDetailsModal(false)
      setSelectedSale(null)
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Failed to cancel sale')
    },
  })

  // Refund sale mutation
  const refundSaleMutation = useMutation({
    mutationFn: async ({ saleId, reason }: { saleId: number; reason: string }) => {
      await apiClient.post(`/admin/sales/${saleId}/refund`, { reason })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-sales'] })
      queryClient.invalidateQueries({ queryKey: ['admin-inventory'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-stats'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-batches'] })
      queryClient.invalidateQueries({ queryKey: ['device-categories-with-stock'] })
      queryClient.invalidateQueries({ queryKey: ['product-options'] })
      toast.success('Sale refunded successfully')
      setShowRefundModal(false)
      setShowDetailsModal(false)
      setSelectedSale(null)
      setRefundReason('')
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Failed to refund sale')
    },
  })

  // Create new customer mutation
  const createCustomerMutation = useMutation({
    mutationFn: async (data: NewCustomerFormData) => {
      const response = await apiClient.post<{ success: boolean; data: UserType }>('/admin/users', {
        ...data,
        role: 'customer',
        password_confirmation: data.password || undefined,
      })
      return response.data.data
    },
    onSuccess: (newCustomer) => {
      setSelectedCustomer(newCustomer)
      setShowNewCustomerModal(false)
      setNewCustomerForm({ name: '', email: '', phone_number: '', national_id: '', password: '' })
      setCustomerFormErrors({})
      toast.success('Customer created successfully')
    },
    onError: (error: { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }) => {
      if (error.response?.data?.errors) {
        const errors: Partial<Record<keyof NewCustomerFormData, string>> = {}
        Object.entries(error.response.data.errors).forEach(([key, messages]) => {
          errors[key as keyof NewCustomerFormData] = messages[0]
        })
        setCustomerFormErrors(errors)
      } else {
        toast.error(error.response?.data?.message || 'Failed to create customer')
      }
    },
  })

  const handleNewCustomerFormChange = (field: keyof NewCustomerFormData, value: string) => {
    setNewCustomerForm(prev => ({ ...prev, [field]: value }))
    if (customerFormErrors[field]) {
      setCustomerFormErrors(prev => ({ ...prev, [field]: undefined }))
    }
  }

  const handleCreateCustomer = (e: React.FormEvent) => {
    e.preventDefault()
    createCustomerMutation.mutate(newCustomerForm)
  }

  const resetNewSaleForm = () => {
    setShowNewSaleModal(false)
    setCart([])
    setSelectedCustomer(null)
    setCustomerSearch('')
    setSelectedCategoryId(null)
    setSelectedBrand('')
    setSelectedModel('')
    setSelectedColor(null)
    setQuantity(1)
    setPaymentMethod('cash')
    setPaymentReference('')
    setAmountPaid('')
    setNotes('')
    setVatApplicable(true)
  }

  const addToCart = () => {
    if (!selectedCategoryId || !selectedBrand || !selectedModel) {
      toast.error('Please select category, brand, and model')
      return
    }

    const availableCount = productOptions?.available_count || 0
    const existingItemIndex = cart.findIndex(item =>
      item.category_id === selectedCategoryId &&
      item.brand === selectedBrand &&
      item.model === selectedModel &&
      item.color === selectedColor
    )
    const existingQuantity = existingItemIndex >= 0 ? cart[existingItemIndex].quantity : 0
    if (existingQuantity + quantity > availableCount) {
      toast.error(`Only ${availableCount} units available. ${existingQuantity} already in cart.`)
      return
    }

    const selectedCategory = categories.find(c => c.id === selectedCategoryId)
    const basePrice = productOptions?.selling_price?.toString() || '0'

    // Calculate tax if VAT is enabled
    const taxAmount = vatApplicable ? roundMoney(parseFloat(basePrice) * 0.175) : 0

    if (existingItemIndex >= 0) {
      // Update quantity of existing item
      const updatedCart = [...cart]
      updatedCart[existingItemIndex].quantity += quantity
      // Recalculate tax for the item
      const itemBasePrice = parseFloat(updatedCart[existingItemIndex].unit_price) || 0
      updatedCart[existingItemIndex].tax = vatApplicable ? roundMoney(itemBasePrice * 0.175).toString() : '0'
      setCart(updatedCart)
      toast.success(`Increased quantity to ${updatedCart[existingItemIndex].quantity}`)
    } else {
      // Add new item to cart
      setCart([...cart, {
        category_id: selectedCategoryId,
        category_name: selectedCategory?.name || '',
        brand: selectedBrand,
        model: selectedModel,
        color: selectedColor,
        quantity,
        unit_price: basePrice,
        discount: '0',
        tax: taxAmount.toString(),
        subscription_plan_id: '',
      }])
      toast.success('Added to cart')
    }

    // Reset product selection
    setSelectedCategoryId(null)
    setSelectedBrand('')
    setSelectedModel('')
    setSelectedColor(null)
    setQuantity(1)
  }

  const removeFromCart = (index: number) => {
    setCart(cart.filter((_, i) => i !== index))
  }

  const updateCartItem = (index: number, field: keyof Omit<CartItem, 'category_id' | 'category_name' | 'brand' | 'model' | 'color'>, value: string | number) => {
    setCart(cart.map((item, i) => {
      if (i !== index) return item

      if (field === 'discount') {
        const unitPrice = parseFloat(item.unit_price) || 0
        const discount = parseFloat(String(value)) || 0
        if (discount > unitPrice) {
          toast.error('Discount cannot exceed unit price')
          return item
        }
      }

      return { ...item, [field]: value }
    }))
  }

  const calculateCartTotal = () => {
    return cart.reduce((total, item) => {
      const price = (parseFloat(item.unit_price) || 0) * item.quantity
      const discount = (parseFloat(item.discount) || 0) * item.quantity
      const tax = (parseFloat(item.tax) || 0) * item.quantity
      return total + (price - discount + tax)
    }, 0)
  }

  const getStatusBadge = (sale: Sale) => {
    const statusValue = sale.status.value
    const statusConfig: Record<string, { bg: string; text: string; icon: typeof CheckCircle }> = {
      draft: { bg: 'bg-slate-100', text: 'text-slate-700', icon: Clock },
      completed: { bg: 'bg-emerald-50', text: 'text-emerald-700', icon: CheckCircle },
      cancelled: { bg: 'bg-red-50', text: 'text-red-700', icon: XCircle },
      refunded: { bg: 'bg-purple-50', text: 'text-purple-700', icon: RotateCcw },
    }
    const config = statusConfig[statusValue] || statusConfig.draft
    const Icon = config.icon

    return (
      <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${config.bg} ${config.text}`}>
        <Icon className="h-3 w-3" />
        {sale.status.label}
      </span>
    )
  }

  const getPaymentStatusBadge = (sale: Sale) => {
    const statusValue = sale.payment_status.value
    const statusConfig: Record<string, { bg: string; text: string }> = {
      pending: { bg: 'bg-amber-50', text: 'text-amber-700' },
      completed: { bg: 'bg-emerald-50', text: 'text-emerald-700' },
      partial: { bg: 'bg-blue-50', text: 'text-blue-700' },
      refunded: { bg: 'bg-purple-50', text: 'text-purple-700' },
    }
    const config = statusConfig[statusValue] || statusConfig.pending

    return (
      <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${config.bg} ${config.text}`}>
        {sale.payment_status.label}
      </span>
    )
  }

  const handleViewDetails = async (sale: Sale) => {
    // Fetch full sale details with items
    const response = await apiClient.get<{ success: boolean; data: Sale }>(`/admin/sales/${sale.id}`)
    setSelectedSale(response.data.data)
    setShowDetailsModal(true)
  }

  const handleCompleteSale = (sale: Sale) => {
    const paymentAmount = Number(sale.payment_amount || 0)
    const totalAmount = Number(sale.total_amount || 0)

    if (sale.payment_method.value === 'cash' && paymentAmount < totalAmount) {
      setSaleToComplete(sale)
      setCompletePaymentAmount(paymentAmount > 0 ? paymentAmount.toFixed(2) : totalAmount.toFixed(2))
      setCompletePaymentReference(sale.payment_reference || '')
      setShowCompletePaymentModal(true)
      return
    }

    completeSaleMutation.mutate({
      saleId: sale.id,
      payment_amount: sale.payment_amount ? Number(sale.payment_amount) : undefined,
      payment_reference: sale.payment_reference || undefined,
    })
  }

  const submitCompletePayment = () => {
    if (!saleToComplete) return

    const paymentAmount = roundMoney(Number(completePaymentAmount || 0))
    const totalAmount = Number(saleToComplete.total_amount || 0)

    if (paymentAmount < totalAmount) {
      toast.error(`Amount paid must be at least ${formatMoney(totalAmount)}`)
      return
    }

    completeSaleMutation.mutate({
      saleId: saleToComplete.id,
      payment_amount: paymentAmount,
      payment_reference: completePaymentReference.trim() || undefined,
    })
  }

  const handlePrintReceipt = async (sale: Sale) => {
    // Fetch full sale details with items if not already loaded
    if (!selectedSale || selectedSale.id !== sale.id || !selectedSale.items) {
      try {
        const response = await apiClient.get<{ success: boolean; data: Sale }>(`/admin/sales/${sale.id}`)
        setSelectedSale(response.data.data)
        setShowReceiptModal(true)
      } catch (error) {
        toast.error('Failed to load receipt')
      }
    } else {
      setShowReceiptModal(true)
    }
  }

  if (error) {
    return (
      <div className="flex flex-col items-center justify-center py-16">
        <div className="h-12 w-12 rounded-xl bg-red-100 flex items-center justify-center mb-3">
          <ShoppingCart className="h-6 w-6 text-red-500" />
        </div>
        <p className="text-red-500 text-sm">Error loading sales: {error.message}</p>
      </div>
    )
  }

  const sales = data?.data || []
  const meta = data?.meta
  const customers = customersData || []
  const categories = categoriesData || []
  const plans = plansData || []

  // Get available options from product options query
  const brands = productOptions?.brands || []
  const models = productOptions?.models || []
  const colors = productOptions?.colors || []
  const hasColors = productOptions?.has_colors || false
  const availableCount = productOptions?.available_count || 0

  // Check if we can add to cart (all required fields selected)
  const canAddToCart = selectedCategoryId && selectedBrand && selectedModel &&
    (!hasColors || selectedColor !== null) && quantity > 0 && availableCount >= quantity

  return (
    <Authorize permission={PERMISSIONS.VIEW_SALES} fallback={<div className="p-6">Access denied. You need permission to view sales.</div>}>
      <div className="space-y-6 p-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Sales Management</h1>
          <p className="text-slate-500 text-sm mt-1">Create and manage device sales</p>
        </div>
        <Authorize permission={PERMISSIONS.MANAGE_SALES}>
          <button
            onClick={() => setShowNewSaleModal(true)}
            className="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors"
          >
            <Plus className="h-4 w-4" />
            New Sale
          </button>
        </Authorize>
      </div>

        {/* Filters */}
        <div className="bg-white rounded-xl border border-slate-200 p-4">
          <div className="flex flex-col sm:flex-row gap-4">
            <div className="flex-1 relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
              <input
                type="text"
                placeholder="Search by sale number or customer..."
                value={search}
                onChange={(e) => { setSearch(e.target.value); setPage(1) }}
                className="w-full pl-10 pr-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
              />
            </div>
            <select
              value={statusFilter}
              onChange={(e) => { setStatusFilter(e.target.value); setPage(1) }}
              className="px-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">All Status</option>
              <option value="draft">Draft</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
              <option value="refunded">Refunded</option>
            </select>
          </div>
        </div>

        {/* Sales Table */}
        <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
          {isLoading ? (
            <div className="flex items-center justify-center py-16">
              <Loader2 className="h-8 w-8 animate-spin text-blue-500" />
            </div>
          ) : sales.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-16">
              <div className="h-12 w-12 rounded-xl bg-slate-100 flex items-center justify-center mb-3">
                <ShoppingCart className="h-6 w-6 text-slate-400" />
              </div>
              <p className="text-slate-500 text-sm">No sales found</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead className="bg-slate-50 border-b border-slate-200">
                  <tr>
                    <th className="text-left px-6 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">Sale #</th>
                    <th className="text-left px-6 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">Customer</th>
                    <th className="text-left px-6 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">Items</th>
                    <th className="text-left px-6 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">Total</th>
                    <th className="text-left px-6 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">Payment</th>
                    <th className="text-left px-6 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">Status</th>
                    <th className="text-left px-6 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">Date</th>
                    <th className="text-right px-6 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200">
                  {sales.map((sale) => (
                    <tr key={sale.id} className="hover:bg-slate-50 transition-colors">
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-2">
                          <Receipt className="h-4 w-4 text-slate-400" />
                          <span className="font-medium text-slate-900">{sale.sale_number}</span>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-2">
                          <User className="h-4 w-4 text-slate-400" />
                          <span className="text-sm text-slate-600">{sale.customer?.name || '-'}</span>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-sm text-slate-600">{sale.items_count || 0} item(s)</span>
                      </td>
                      <td className="px-6 py-4">
                        <span className="font-medium text-slate-900">
                          {formatMoney(sale.total_amount)}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        {getPaymentStatusBadge(sale)}
                      </td>
                      <td className="px-6 py-4">
                        {getStatusBadge(sale)}
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-sm text-slate-500">
                          {new Date(sale.sale_date).toLocaleDateString()}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-right">
                        <div className="flex items-center justify-end gap-2">
                          <button
                            onClick={() => handlePrintReceipt(sale)}
                            className="text-emerald-600 hover:text-emerald-800"
                            title="Print Receipt"
                          >
                            <Printer className="h-4 w-4" />
                          </button>
                          {sale.status.value === 'draft' && (
                            <Authorize permission={PERMISSIONS.MANAGE_SALES}>
                              <button
                                onClick={() => handleCompleteSale(sale)}
                                disabled={completeSaleMutation.isPending}
                                className="text-emerald-600 hover:text-emerald-800 disabled:opacity-50"
                                title="Complete Sale"
                              >
                                <CheckCircle className="h-4 w-4" />
                              </button>
                              <button
                                onClick={() => cancelSaleMutation.mutate(sale.id)}
                                disabled={cancelSaleMutation.isPending}
                                className="text-red-600 hover:text-red-800 disabled:opacity-50"
                                title="Cancel Sale"
                              >
                                <XCircle className="h-4 w-4" />
                              </button>
                            </Authorize>
                          )}
                          <button
                            onClick={() => handleViewDetails(sale)}
                            className="text-blue-600 hover:text-blue-800"
                            title="View Details"
                          >
                            <Eye className="h-4 w-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {/* Pagination */}
          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between px-6 py-4 border-t border-slate-200">
              <p className="text-sm text-slate-500">
                Showing {((meta.current_page - 1) * meta.per_page) + 1} to {Math.min(meta.current_page * meta.per_page, meta.total)} of {meta.total}
              </p>
              <div className="flex gap-2">
                <button
                  onClick={() => setPage(p => Math.max(1, p - 1))}
                  disabled={meta.current_page === 1}
                  className="px-3 py-1 text-sm border border-slate-200 rounded-lg hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  Previous
                </button>
                <button
                  onClick={() => setPage(p => Math.min(meta.last_page, p + 1))}
                  disabled={meta.current_page === meta.last_page}
                  className="px-3 py-1 text-sm border border-slate-200 rounded-lg hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  Next
                </button>
              </div>
            </div>
          )}
        </div>

        {/* New Sale Modal */}
        {showNewSaleModal && (
          <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <div className="bg-white rounded-xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
              <div className="p-6 border-b border-slate-200">
                <h2 className="text-lg font-semibold text-slate-900">Create New Sale</h2>
              </div>

              <div className="p-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Left Column - Customer & Items */}
                <div className="space-y-6">
                  {/* Customer Selection */}
                  <div>
                    <div className="flex items-center justify-between mb-2">
                      <label className="block text-sm font-medium text-slate-700">Customer <span className="text-red-500">*</span></label>
                      {!selectedCustomer && (
                        <button
                          type="button"
                          onClick={() => setShowNewCustomerModal(true)}
                          className="inline-flex items-center gap-1 text-xs text-blue-600 hover:text-blue-800 font-medium"
                        >
                          <UserPlus className="h-3.5 w-3.5" />
                          New Customer
                        </button>
                      )}
                    </div>
                    {selectedCustomer ? (
                      <div className="flex items-center justify-between p-3 bg-blue-50 rounded-lg">
                        <div className="flex items-center gap-3">
                          <div className="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                            <User className="h-5 w-5 text-blue-600" />
                          </div>
                          <div>
                            <p className="font-medium text-slate-900">{selectedCustomer.name}</p>
                            <p className="text-sm text-slate-500">{customerContactLabel(selectedCustomer)}</p>
                          </div>
                        </div>
                        <button
                          onClick={() => setSelectedCustomer(null)}
                          className="text-slate-400 hover:text-slate-600"
                        >
                          <XCircle className="h-5 w-5" />
                        </button>
                      </div>
                    ) : (
                      <div className="space-y-2">
                        <div className="relative">
                          <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
                          <input
                            type="text"
                            placeholder="Search existing customers..."
                            value={customerSearch}
                            onChange={(e) => setCustomerSearch(e.target.value)}
                            className="w-full pl-10 pr-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                          />
                          {customers.length > 0 && (
                            <div className="absolute z-10 mt-1 w-full bg-white border border-slate-200 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                              {customers.map((customer) => (
                                <button
                                  key={customer.id}
                                  onClick={() => {
                                    setSelectedCustomer(customer)
                                    setCustomerSearch('')
                                  }}
                                  className="w-full text-left px-4 py-2 hover:bg-slate-50 flex items-center gap-3"
                                >
                                  <User className="h-4 w-4 text-slate-400" />
                                  <div>
                                    <p className="text-sm font-medium text-slate-900">{customer.name}</p>
                                    <p className="text-xs text-slate-500">{customerContactLabel(customer)}</p>
                                  </div>
                                </button>
                              ))}
                            </div>
                          )}
                        </div>
                        <p className="text-xs text-slate-500">
                          Can't find the customer? <button type="button" onClick={() => setShowNewCustomerModal(true)} className="text-blue-600 hover:underline">Create a new one</button>
                        </p>
                      </div>
                    )}
                  </div>

                  {/* Add Product - Cascading Dropdowns */}
                  <div className="space-y-4">
                    <label className="block text-sm font-medium text-slate-700">
                      <Smartphone className="inline h-4 w-4 mr-1" />
                      Select Product
                    </label>

                    {/* Category Dropdown */}
                    <div>
                      <label className="block text-xs text-slate-500 mb-1">Category *</label>
                      <select
                        value={selectedCategoryId || ''}
                        onChange={(e) => {
                          const val = e.target.value ? parseInt(e.target.value) : null
                          setSelectedCategoryId(val)
                          setSelectedBrand('')
                          setSelectedModel('')
                          setSelectedColor(null)
                        }}
                        className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                        disabled={categoriesLoading}
                      >
                        <option value="">Select category...</option>
                        {categories.map((cat) => (
                          <option key={cat.id} value={cat.id} disabled={(cat.available_count ?? 0) === 0}>
                            {cat.name} ({cat.available_count ?? 0} available)
                          </option>
                        ))}
                      </select>
                    </div>

                    {/* Brand Dropdown */}
                    {selectedCategoryId && (
                      <div>
                        <label className="block text-xs text-slate-500 mb-1">Brand *</label>
                        <select
                          value={selectedBrand}
                          onChange={(e) => {
                            setSelectedBrand(e.target.value)
                            setSelectedModel('')
                            setSelectedColor(null)
                          }}
                          className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                          disabled={productOptionsLoading || brands.length === 0}
                        >
                          <option value="">Select brand...</option>
                          {brands.map((b) => (
                            <option key={b.name} value={b.name || ''}>
                              {b.name} ({b.count} available)
                            </option>
                          ))}
                        </select>
                      </div>
                    )}

                    {/* Model Dropdown */}
                    {selectedBrand && (
                      <div>
                        <label className="block text-xs text-slate-500 mb-1">Model *</label>
                        <select
                          value={selectedModel}
                          onChange={(e) => {
                            setSelectedModel(e.target.value)
                            setSelectedColor(null)
                          }}
                          className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                          disabled={productOptionsLoading || models.length === 0}
                        >
                          <option value="">Select model...</option>
                          {models.map((m) => (
                            <option key={m.name} value={m.name || ''}>
                              {m.name} ({m.count} available)
                            </option>
                          ))}
                        </select>
                      </div>
                    )}

                    {/* Color Dropdown (optional) */}
                    {selectedModel && hasColors && (
                      <div>
                        <label className="block text-xs text-slate-500 mb-1">
                          Color {!hasColors && <span className="text-slate-400">(optional)</span>}
                        </label>
                        <select
                          value={selectedColor === null ? 'null' : selectedColor}
                          onChange={(e) => setSelectedColor(e.target.value === 'null' ? null : e.target.value)}
                          className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                          disabled={productOptionsLoading}
                        >
                          <option value="null">Any color</option>
                          {colors.filter(c => c.name !== null).map((c) => (
                            <option key={c.name} value={c.name || ''}>
                              {c.name} ({c.count} available)
                            </option>
                          ))}
                        </select>
                      </div>
                    )}

                    {/* Stock Info & Quantity */}
                    {selectedModel && (
                      <div className="p-3 bg-blue-50 rounded-lg space-y-3">
                        <div className="flex items-center justify-between">
                          <span className="text-sm text-slate-600">Available:</span>
                          <span className="text-sm font-medium text-slate-900">{availableCount} units</span>
                        </div>
                        {productOptions?.selling_price && (
                          <div className="flex items-center justify-between">
                            <span className="text-sm text-slate-600">Price:</span>
                            <span className="text-sm font-medium text-slate-900">
                              {formatMoney(productOptions.selling_price)} each
                            </span>
                          </div>
                        )}

                        {/* Quantity Selector */}
                        <div>
                          <label className="block text-xs text-slate-500 mb-2">Quantity</label>
                          <div className="flex items-center gap-3">
                            <button
                              onClick={() => setQuantity(Math.max(1, quantity - 1))}
                              className="p-2 border border-slate-200 rounded-lg hover:bg-slate-50 disabled:opacity-50"
                              disabled={quantity <= 1}
                            >
                              <Minus className="h-4 w-4" />
                            </button>
                            <input
                              type="number"
                              value={quantity}
                              onChange={(e) => setQuantity(Math.max(1, Math.min(availableCount, parseInt(e.target.value) || 1)))}
                              className="flex-1 px-3 py-2 border border-slate-200 rounded-lg text-center focus:outline-none focus:ring-2 focus:ring-blue-500"
                              min="1"
                              max={availableCount}
                            />
                            <button
                              onClick={() => setQuantity(Math.min(availableCount, quantity + 1))}
                              className="p-2 border border-slate-200 rounded-lg hover:bg-slate-50 disabled:opacity-50"
                              disabled={quantity >= availableCount}
                            >
                              <Plus className="h-4 w-4" />
                            </button>
                          </div>
                        </div>

                        {/* Add to Cart Button */}
                        <button
                          onClick={addToCart}
                          disabled={!canAddToCart}
                          className="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                        >
                          <Plus className="h-4 w-4" />
                          Add to Cart
                        </button>
                      </div>
                    )}
                  </div>

                  {/* Cart Items */}
                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-2">Cart Items</label>
                    {cart.length === 0 ? (
                      <div className="flex flex-col items-center justify-center py-8 bg-slate-50 rounded-lg">
                        <Package className="h-8 w-8 text-slate-300 mb-2" />
                        <p className="text-sm text-slate-400">No items in cart</p>
                      </div>
                    ) : (
                      <div className="space-y-3">
                        {cart.map((item, index) => {
                          const basePrice = (parseFloat(item.unit_price) || 0) * item.quantity
                          const tax = (parseFloat(item.tax) || 0) * item.quantity
                          const itemTotal = basePrice + tax
                          const colorText = item.color ? ` - ${item.color}` : ''
                          return (
                            <div key={index} className="p-3 bg-slate-50 rounded-lg">
                              <div className="flex items-start justify-between mb-3">
                                <div className="flex-1">
                                  <p className="font-medium text-slate-900">
                                    {item.brand} {item.model}{colorText}
                                  </p>
                                  <p className="text-xs text-slate-500">{item.category_name} × {item.quantity}</p>
                                  <p className="text-xs text-slate-500">
                                    Base: {formatMoney(basePrice)}
                                    {tax > 0 && (
                                    <span className="text-blue-600"> + VAT: {formatMoney(tax)}</span>
                                    )}
                                  </p>
                                  <p className="text-xs text-emerald-600 font-medium mt-1">
                                    Total: {formatMoney(itemTotal)}
                                  </p>
                                </div>
                                <button
                                  onClick={() => removeFromCart(index)}
                                  className="text-red-500 hover:text-red-700"
                                >
                                  <Trash2 className="h-4 w-4" />
                                </button>
                              </div>
                              <div className="grid grid-cols-2 gap-2">
                                <div>
                                  <label className="text-xs text-slate-500">Quantity</label>
                                  <input
                                    type="number"
                                    value={item.quantity}
                                    onChange={(e) => updateCartItem(index, 'quantity', parseInt(e.target.value) || 1)}
                                    className="w-full px-2 py-1 text-sm border border-slate-200 rounded"
                                    min="1"
                                  />
                                </div>
                                <div>
                                  <label className="text-xs text-slate-500">Unit Price</label>
                                  <input
                                    type="number"
                                    value={item.unit_price}
                                    readOnly
                                    className="w-full px-2 py-1 text-sm border border-slate-200 rounded bg-slate-100 text-slate-600 cursor-not-allowed"
                                    title="Unit price cannot be modified"
                                  />
                                </div>
                                <div>
                                  <label className="text-xs text-slate-500">Discount (per unit)</label>
                                  <input
                                    type="number"
                                    value={item.discount}
                                    onChange={(e) => updateCartItem(index, 'discount', e.target.value)}
                                    className="w-full px-2 py-1 text-sm border border-slate-200 rounded"
                                  />
                                </div>
                                <div>
                                  <label className="text-xs text-slate-500">Tax (per unit)</label>
                                  <input
                                    type="number"
                                    value={item.tax}
                                    readOnly
                                    className="w-full px-2 py-1 text-sm border border-slate-200 rounded bg-slate-100 text-slate-600 cursor-not-allowed"
                                    title="Tax is automatically calculated based on VAT setting"
                                  />
                                </div>
                              </div>
                              <div className="mt-2">
                                <label className="text-xs text-slate-500">Subscription Plan (optional)</label>
                                <select
                                  value={item.subscription_plan_id}
                                  onChange={(e) => updateCartItem(index, 'subscription_plan_id', e.target.value)}
                                  className="w-full px-2 py-1 text-sm border border-slate-200 rounded"
                                >
                                  <option value="">No subscription</option>
                                  {plans.map((plan) => (
                                    <option key={plan.id} value={plan.id}>{plan.name}</option>
                                  ))}
                                </select>
                              </div>
                            </div>
                          )
                        })}
                      </div>
                    )}
                  </div>
                </div>

                {/* Right Column - Payment & Summary */}
                <div className="space-y-6">
                  {/* Payment Method */}
                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-2">Payment Method *</label>
                    <select
                      value={paymentMethod}
                      onChange={(e) => setPaymentMethod(e.target.value)}
                      className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                      <option value="cash">Cash</option>
                      <option value="mobile_money">Mobile Money</option>
                      <option value="card">Card</option>
                      <option value="bank_transfer">Bank Transfer</option>
                      <option value="credit">Credit</option>
                    </select>
                  </div>

                  {/* Payment Reference */}
                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-2">Payment Reference</label>
                    <input
                      type="text"
                      value={paymentReference}
                      onChange={(e) => setPaymentReference(e.target.value)}
                      placeholder="Transaction ID, receipt number, etc."
                      className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  </div>

                  {/* Notes */}
                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-2">Notes</label>
                    <textarea
                      value={notes}
                      onChange={(e) => setNotes(e.target.value)}
                      rows={3}
                      placeholder="Add any notes..."
                      className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  </div>

                  {/* VAT Toggle */}
                  <div className="flex items-center gap-3 p-4 bg-blue-50 rounded-lg">
                    <input
                      type="checkbox"
                      id="vat-applicable"
                      checked={vatApplicable}
                      onChange={(e) => setVatApplicable(e.target.checked)}
                      className="w-5 h-5 text-blue-600 rounded focus:ring-2 focus:ring-blue-500"
                    />
                    <div className="flex-1">
                      <label htmlFor="vat-applicable" className="block text-sm font-medium text-slate-900 cursor-pointer">
                        Apply VAT (17.5%)
                      </label>
                      <p className="text-xs text-slate-500 mt-1">
                        {vatApplicable ? 'VAT will be calculated and added to all item prices' : 'No VAT will be applied to this sale'}
                      </p>
                    </div>
                  </div>

                  {/* Cash Payment Section - Only shown for cash payments */}
                  {paymentMethod === 'cash' && (
                    <div className="bg-emerald-50 border-2 border-emerald-200 rounded-lg p-4 space-y-4">
                      <h3 className="font-semibold text-emerald-900 flex items-center gap-2">
                        <span className="text-lg">💵</span>
                        Cash Payment Details
                      </h3>

                      {/* Amount Paid Input */}
                      <div>
                        <label className="block text-sm font-medium text-slate-700 mb-2">
                          Amount Paid <span className="text-red-500">*</span>
                        </label>
                        <input
                          type="number"
                          value={amountPaid}
                          onChange={(e) => setAmountPaid(e.target.value)}
                          placeholder="Enter amount customer is paying..."
                          className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 text-lg font-semibold"
                          min="0"
                          step="0.01"
                        />
                      </div>

                      {/* Change Due Display - Only show when amount is entered */}
                      {amountPaid && (
                        <div className="bg-white rounded-lg p-4 border-2 border-emerald-300">
                          <div className="flex items-center justify-between">
                            <span className="text-slate-600 font-medium">Change Due:</span>
                            <div className="text-right">
                              {parseFloat(amountPaid) >= calculateCartTotal() ? (
                                <>
                                  <span className="text-3xl font-bold text-emerald-600">
                                    {formatMoney(roundMoney(parseFloat(amountPaid) - calculateCartTotal()))}
                                  </span>
                                  <div className="text-xs text-emerald-500 mt-1">✓ Amount sufficient</div>
                                </>
                              ) : (
                                <>
                                  <span className="text-2xl font-bold text-red-600">
                                    {formatMoney(roundMoney(parseFloat(amountPaid) - calculateCartTotal()))}
                                  </span>
                                  <div className="text-xs text-red-500 mt-1">
                                    ⚠️ Insufficient - Need {formatMoney(roundMoney(calculateCartTotal() - parseFloat(amountPaid)))} more
                                  </div>
                                </>
                              )}
                            </div>
                          </div>
                        </div>
                      )}
                    </div>
                  )}

                  {/* Order Summary */}
                  <div className="bg-slate-50 rounded-lg p-4">
                    <h3 className="font-medium text-slate-900 mb-3">Order Summary</h3>
                    <div className="space-y-2 text-sm">
                      <div className="flex justify-between">
                        <span className="text-slate-500">Items</span>
                        <span className="text-slate-900">{cart.length}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-slate-500">Subtotal</span>
                        <span className="text-slate-900">{formatMoney(cart.reduce((t, i) => t + ((parseFloat(i.unit_price) || 0) * i.quantity), 0))}</span>
                      </div>
                      {vatApplicable && (
                        <div className="flex justify-between">
                          <span className="text-blue-600">VAT (17.5%)</span>
                          <span className="text-blue-600">{formatMoney(cart.reduce((t, i) => t + ((parseFloat(i.tax) || 0) * i.quantity), 0))}</span>
                        </div>
                      )}
                      <div className="flex justify-between">
                        <span className="text-slate-500">Total Discount</span>
                        <span className="text-red-600">-{formatMoney(cart.reduce((t, i) => t + ((parseFloat(i.discount) || 0) * i.quantity), 0))}</span>
                      </div>
                      <div className="pt-2 border-t border-slate-200 flex justify-between font-semibold text-base">
                        <span className="text-slate-900">Total</span>
                        <span className="text-blue-600">{formatMoney(calculateCartTotal())}</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              {/* Footer */}
              <div className="p-6 border-t border-slate-200 flex justify-end gap-3">
                <button
                  onClick={resetNewSaleForm}
                  className="px-4 py-2 text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                >
                  Cancel
                </button>
                <button
                  onClick={() => createSaleMutation.mutate('draft')}
                  disabled={createSaleMutation.isPending || !selectedCustomer || cart.length === 0}
                  className="px-4 py-2 border border-slate-200 text-slate-700 rounded-lg hover:bg-slate-50 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  Save Draft
                </button>
                <button
                  onClick={() => createSaleMutation.mutate('completed')}
                  disabled={createSaleMutation.isPending || !selectedCustomer || cart.length === 0 ||
                    (paymentMethod === 'cash' && (!amountPaid || parseFloat(amountPaid) < calculateCartTotal() - 0.01))}
                  className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {createSaleMutation.isPending ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  ) : (
                    'Complete Sale'
                  )}
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Sale Details Modal */}
        {showDetailsModal && selectedSale && (
          <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <div className="bg-white rounded-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
              <div className="p-6 border-b border-slate-200">
                <div className="flex items-center justify-between">
                  <div>
                    <h2 className="text-lg font-semibold text-slate-900">Sale Details</h2>
                    <p className="text-sm text-slate-500">{selectedSale.sale_number}</p>
                  </div>
                  {getStatusBadge(selectedSale)}
                </div>
              </div>

              <div className="p-6 space-y-6">
                {/* Customer Info */}
                <div className="flex items-center gap-4 p-4 bg-slate-50 rounded-lg">
                  <div className="h-12 w-12 rounded-full bg-blue-100 flex items-center justify-center">
                    <User className="h-6 w-6 text-blue-600" />
                  </div>
                  <div>
                    <p className="font-medium text-slate-900">{selectedSale.customer?.name}</p>
                    <p className="text-sm text-slate-500">
                      {selectedSale.customer ? customerContactLabel(selectedSale.customer) : 'No contact details'}
                    </p>
                  </div>
                </div>

                {/* Items */}
                <div>
                  <h3 className="font-medium text-slate-900 mb-3">Items</h3>
                  <div className="space-y-2">
                    {selectedSale.items?.map((item) => (
                      <div key={item.id} className="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
                        <div className="flex items-center gap-3">
                          <Smartphone className="h-5 w-5 text-slate-400" />
                          <div>
                            <p className="font-medium text-slate-900">{item.item_description}</p>
                            {item.subscription_plan && (
                              <p className="text-xs text-blue-600">+ {item.subscription_plan.name}</p>
                            )}
                          </div>
                        </div>
                        <p className="font-medium text-slate-900">{formatMoney(item.total_price)}</p>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Payment Info */}
                <div className="grid grid-cols-2 gap-4">
                  <div className="p-4 bg-slate-50 rounded-lg">
                    <p className="text-sm text-slate-500 mb-1">Payment Method</p>
                    <p className="font-medium text-slate-900">{selectedSale.payment_method.label}</p>
                  </div>
                  <div className="p-4 bg-slate-50 rounded-lg">
                    <p className="text-sm text-slate-500 mb-1">Payment Status</p>
                    {getPaymentStatusBadge(selectedSale)}
                  </div>
                </div>

                {/* Cash Payment Details - Only for cash payments with payment_amount */}
                {selectedSale.payment_method.value === 'cash' && selectedSale.payment_amount && (
                  <div className="grid grid-cols-2 gap-4 p-4 bg-emerald-50 rounded-lg border border-emerald-200">
                    <div>
                      <p className="text-sm text-slate-600 mb-1">Amount Paid</p>
                      <p className="font-semibold text-slate-900">
                        {formatMoney(selectedSale.payment_amount)}
                      </p>
                    </div>
                    <div>
                      <p className="text-sm text-slate-600 mb-1">Change Due</p>
                      <p className="font-bold text-emerald-600">
                        {formatMoney(selectedSale.change_due || 0)}
                      </p>
                    </div>
                  </div>
                )}

                {/* Totals */}
                <div className="p-4 bg-blue-50 rounded-lg">
                  <div className="space-y-2 text-sm">
                    <div className="flex justify-between">
                      <span className="text-slate-600">Subtotal</span>
                      <span>{formatMoney(selectedSale.subtotal)}</span>
                    </div>
                    <div className="flex justify-between">
                      <span className="text-slate-600">Tax</span>
                      <span>{formatMoney(selectedSale.tax_amount)}</span>
                    </div>
                    <div className="flex justify-between">
                      <span className="text-slate-600">Discount</span>
                      <span className="text-red-600">-{formatMoney(selectedSale.discount_amount)}</span>
                    </div>
                    <div className="pt-2 border-t border-blue-200 flex justify-between font-semibold text-lg">
                      <span>Total</span>
                      <span className="text-blue-600">{formatMoney(selectedSale.total_amount)}</span>
                    </div>
                  </div>
                </div>

                {/* Notes */}
                {selectedSale.notes && (
                  <div>
                    <h3 className="font-medium text-slate-900 mb-2">Notes</h3>
                    <p className="text-sm text-slate-600 p-3 bg-slate-50 rounded-lg">{selectedSale.notes}</p>
                  </div>
                )}

                {/* Refund Reason */}
                {selectedSale.status.value === 'refunded' && selectedSale.refund_reason && (
                  <div>
                    <h3 className="font-medium text-slate-900 mb-2">Refund Reason</h3>
                    <p className="text-sm text-red-600 p-3 bg-red-50 rounded-lg border border-red-200">{selectedSale.refund_reason}</p>
                  </div>
                )}
              </div>

              {/* Actions */}
              <div className="p-6 border-t border-slate-200 flex justify-between">
                <div className="flex gap-2">
                  <button
                    onClick={() => handlePrintReceipt(selectedSale)}
                    className="inline-flex items-center gap-2 px-4 py-2 text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors"
                  >
                    <Printer className="h-4 w-4" />
                    Print Receipt
                  </button>
                  <button
                    onClick={() => {
                      setShowDetailsModal(false)
                      setSelectedSale(null)
                    }}
                    className="px-4 py-2 text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                  >
                    Close
                  </button>
                </div>
                <div className="flex gap-2">
                  {selectedSale.status.value === 'draft' && (
                    <Authorize permission={PERMISSIONS.MANAGE_SALES}>
                      <button
                        onClick={() => cancelSaleMutation.mutate(selectedSale.id)}
                        disabled={cancelSaleMutation.isPending}
                        className="px-4 py-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                      >
                        Cancel Sale
                      </button>
                      <button
                        onClick={() => handleCompleteSale(selectedSale)}
                        disabled={completeSaleMutation.isPending}
                        className="px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors"
                      >
                        {completeSaleMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Complete Sale'}
                      </button>
                    </Authorize>
                  )}
                  {selectedSale.status.value === 'completed' && (
                    <Authorize permission={PERMISSIONS.MANAGE_SALES}>
                      <button
                        onClick={() => {
                          setSelectedSale(selectedSale)
                          setShowRefundModal(true)
                        }}
                        className="px-4 py-2 text-purple-600 hover:bg-purple-50 rounded-lg transition-colors"
                      >
                        Refund Sale
                      </button>
                    </Authorize>
                  )}
                </div>
              </div>
            </div>
          </div>
        )}

        {/* New Customer Modal */}
        {showNewCustomerModal && (
          <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-[60] p-4">
            <div className="bg-white rounded-xl max-w-md w-full max-h-[90vh] overflow-y-auto">
              <div className="p-5 border-b border-slate-200 sticky top-0 bg-white">
                <div className="flex items-center justify-between">
                  <h3 className="text-lg font-semibold text-slate-800">Create New Customer</h3>
                  <button
                    onClick={() => {
                      setShowNewCustomerModal(false)
                      setNewCustomerForm({ name: '', email: '', phone_number: '', national_id: '', password: '' })
                      setCustomerFormErrors({})
                    }}
                    className="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                  >
                    <X className="h-5 w-5" />
                  </button>
                </div>
              </div>
              <form onSubmit={handleCreateCustomer} className="p-5 space-y-4">
                <p className="text-xs text-slate-500">
                  <span className="text-red-500">*</span> indicates required fields
                </p>

                {/* Name */}
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1.5">
                    Name <span className="text-red-500">*</span>
                  </label>
                  <input
                    type="text"
                    value={newCustomerForm.name}
                    onChange={(e) => handleNewCustomerFormChange('name', e.target.value)}
                    className={`w-full px-3.5 py-2.5 rounded-lg border text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors ${
                      customerFormErrors.name ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-slate-50/50'
                    }`}
                    placeholder="Enter full name"
                    required
                    maxLength={255}
                  />
                  {customerFormErrors.name && <p className="mt-1 text-xs text-red-600">{customerFormErrors.name}</p>}
                </div>

                {/* Email */}
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1.5">
                    Email <span className="text-slate-400 font-normal">(optional)</span>
                  </label>
                  <input
                    type="email"
                    value={newCustomerForm.email}
                    onChange={(e) => handleNewCustomerFormChange('email', e.target.value)}
                    className={`w-full px-3.5 py-2.5 rounded-lg border text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors ${
                      customerFormErrors.email ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-slate-50/50'
                    }`}
                    placeholder="Enter email address"
                    maxLength={255}
                  />
                  {customerFormErrors.email && <p className="mt-1 text-xs text-red-600">{customerFormErrors.email}</p>}
                </div>

                {/* Phone */}
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1.5">
                    Phone Number <span className="text-slate-400 font-normal">(optional, max 20 chars)</span>
                  </label>
                  <input
                    type="tel"
                    value={newCustomerForm.phone_number}
                    onChange={(e) => handleNewCustomerFormChange('phone_number', e.target.value)}
                    className={`w-full px-3.5 py-2.5 rounded-lg border text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors ${
                      customerFormErrors.phone_number ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-slate-50/50'
                    }`}
                    placeholder="e.g. +265999123456"
                    maxLength={20}
                  />
                  {customerFormErrors.phone_number && <p className="mt-1 text-xs text-red-600">{customerFormErrors.phone_number}</p>}
                </div>

                {/* National ID */}
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1.5">
                    National ID <span className="text-slate-400 font-normal">(optional, max 50 chars)</span>
                  </label>
                  <input
                    type="text"
                    value={newCustomerForm.national_id}
                    onChange={(e) => handleNewCustomerFormChange('national_id', e.target.value)}
                    className={`w-full px-3.5 py-2.5 rounded-lg border text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors ${
                      customerFormErrors.national_id ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-slate-50/50'
                    }`}
                    placeholder="e.g. 1234567890"
                    maxLength={50}
                  />
                  {customerFormErrors.national_id && <p className="mt-1 text-xs text-red-600">{customerFormErrors.national_id}</p>}
                </div>

                {/* Password */}
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1.5">
                    Password <span className="text-slate-400 font-normal">(optional)</span>
                  </label>
                  <input
                    type="password"
                    value={newCustomerForm.password}
                    onChange={(e) => handleNewCustomerFormChange('password', e.target.value)}
                    className={`w-full px-3.5 py-2.5 rounded-lg border text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors ${
                      customerFormErrors.password ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-slate-50/50'
                    }`}
                    placeholder="Minimum 8 characters"
                    minLength={8}
                  />
                  {customerFormErrors.password && <p className="mt-1 text-xs text-red-600">{customerFormErrors.password}</p>}
                  <p className="mt-1 text-xs text-slate-500">Only needed if the customer will log in</p>
                </div>

                {/* Actions */}
                <div className="flex gap-3 pt-2">
                  <button
                    type="button"
                    onClick={() => {
                      setShowNewCustomerModal(false)
                      setNewCustomerForm({ name: '', email: '', phone_number: '', national_id: '', password: '' })
                      setCustomerFormErrors({})
                    }}
                    className="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={createCustomerMutation.isPending}
                    className="flex-1 px-4 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center justify-center gap-2"
                  >
                    {createCustomerMutation.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
                    Create Customer
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* Complete Payment Modal */}
        {showCompletePaymentModal && saleToComplete && (
          <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-[60] p-4">
            <div className="bg-white rounded-xl max-w-md w-full">
              <div className="p-5 border-b border-slate-200">
                <div className="flex items-center justify-between">
                  <div>
                    <h3 className="text-lg font-semibold text-slate-800">Complete Sale</h3>
                    <p className="text-sm text-slate-500">{saleToComplete.sale_number}</p>
                  </div>
                  <button
                    onClick={() => {
                      setShowCompletePaymentModal(false)
                      setSaleToComplete(null)
                      setCompletePaymentAmount('')
                      setCompletePaymentReference('')
                    }}
                    className="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                  >
                    <X className="h-5 w-5" />
                  </button>
                </div>
              </div>
              <div className="p-5 space-y-4">
                <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
                  <div className="flex justify-between text-sm">
                    <span className="text-slate-600">Total due</span>
                    <span className="font-semibold text-slate-900">{formatMoney(saleToComplete.total_amount)}</span>
                  </div>
                  <div className="flex justify-between text-sm mt-2">
                    <span className="text-slate-600">Change due</span>
                    <span className="font-semibold text-emerald-700">
                      {formatMoney(Math.max(0, roundMoney(Number(completePaymentAmount || 0) - Number(saleToComplete.total_amount || 0))))}
                    </span>
                  </div>
                </div>

                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-2">
                    Amount Paid <span className="text-red-500">*</span>
                  </label>
                  <input
                    type="number"
                    min={Number(saleToComplete.total_amount || 0)}
                    step="0.01"
                    value={completePaymentAmount}
                    onChange={(e) => setCompletePaymentAmount(e.target.value)}
                    className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                    placeholder="Enter amount paid"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-2">Payment Reference</label>
                  <input
                    type="text"
                    value={completePaymentReference}
                    onChange={(e) => setCompletePaymentReference(e.target.value)}
                    className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 text-sm"
                    placeholder="Transaction ID, receipt number, etc."
                  />
                </div>

                <div className="flex gap-3 justify-end pt-2">
                  <button
                    onClick={() => {
                      setShowCompletePaymentModal(false)
                      setSaleToComplete(null)
                      setCompletePaymentAmount('')
                      setCompletePaymentReference('')
                    }}
                    disabled={completeSaleMutation.isPending}
                    className="px-4 py-2 text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    onClick={submitCompletePayment}
                    disabled={completeSaleMutation.isPending}
                    className="px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors disabled:opacity-50 inline-flex items-center gap-2"
                  >
                    {completeSaleMutation.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
                    Complete Sale
                  </button>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Refund Confirmation Modal */}
        {showRefundModal && selectedSale && (
          <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-[60] p-4">
            <div className="bg-white rounded-xl max-w-md w-full">
              <div className="p-5 border-b border-slate-200">
                <div className="flex items-center justify-between">
                  <h3 className="text-lg font-semibold text-slate-800">Refund Sale</h3>
                  <button
                    onClick={() => {
                      setShowRefundModal(false)
                      setRefundReason('')
                    }}
                    className="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                  >
                    <X className="h-5 w-5" />
                  </button>
                </div>
              </div>
              <div className="p-5">
                <div className="mb-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                  <p className="text-sm text-yellow-800 font-medium mb-1">
                    ⚠️ You are about to refund sale {selectedSale.sale_number}
                  </p>
                  <p className="text-xs text-yellow-700">
                    This will return {selectedSale.items_count || selectedSale.items?.length || 0} item(s) to inventory.
                  </p>
                </div>

                <div className="mb-4">
                  <label className="block text-sm font-medium text-slate-700 mb-2">
                    Reason for Refund <span className="text-red-500">*</span>
                  </label>
                  <textarea
                    value={refundReason}
                    onChange={(e) => setRefundReason(e.target.value)}
                    rows={4}
                    placeholder="Please explain why this sale is being refunded..."
                    className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 text-sm"
                    required
                  />
                  <p className="text-xs text-slate-500 mt-1">
                    This reason will be recorded for audit purposes.
                  </p>
                </div>

                <div className="flex gap-3 justify-end">
                  <button
                    onClick={() => {
                      setShowRefundModal(false)
                      setRefundReason('')
                    }}
                    disabled={refundSaleMutation.isPending}
                    className="px-4 py-2 text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    onClick={() => {
                      if (!refundReason.trim()) {
                        toast.error('Please provide a reason for the refund')
                        return
                      }
                      refundSaleMutation.mutate({ saleId: selectedSale.id, reason: refundReason })
                    }}
                    disabled={refundSaleMutation.isPending || !refundReason.trim()}
                    className="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
                  >
                    {refundSaleMutation.isPending ? (
                      <>
                        <Loader2 className="h-4 w-4 animate-spin" />
                        Processing...
                      </>
                    ) : (
                      'Confirm Refund'
                    )}
                  </button>
                </div>
              </div>
            </div>
          </div>
        )}
      </div>

      {/* Receipt Modal */}
      {showReceiptModal && selectedSale && (
        <ReceiptModal
          sale={selectedSale}
          onClose={() => {
            setShowReceiptModal(false)
          }}
        />
      )}
    </Authorize>
  )
}
