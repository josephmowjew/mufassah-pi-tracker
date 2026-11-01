import { malawiDateInput } from '@/lib/datetime'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { DeviceCategory, InventoryReceiptData, InventoryReceiptLineData } from '@/types'
import { AlertCircle, History, Loader2, PackageCheck, Plus, Trash2, X } from 'lucide-react'
import apiClient from '@/api/client'
import { toast } from 'sonner'
import { useAuthStore } from '@/stores/auth-store'

interface BulkAddDeviceModalProps {
  onClose: () => void
}

interface BranchOption {
  id: number
  name: string
}

interface BrandOption {
  id: number
  name: string
  slug: string
  logo: string | null
  models_count: number
}

interface ModelOption {
  id: number
  name: string
  slug: string
  base_price: number | null
  available_colors: string[] | null
}

const emptyLine = (): InventoryReceiptLineData => ({
  category_id: 0,
  brand: '',
  model: '',
  color: '',
  quantity: 1,
  buying_price: undefined,
  target_selling_price: undefined,
  auto_generate_serials: true,
  serial_numbers: [],
  notes: '',
})

const money = (value: number) => `MWK ${value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

interface PriceSuggestion {
  buying_price: number | null
  target_selling_price: number | null
  source: 'last_batch' | 'catalog_base_price' | null
  batch_id: number | null
  batch_number: string | null
  received_at: string | null
}

interface ReceiptLinePriceFieldsProps {
  branchId?: number
  errors: Record<string, string>
  index: number
  line: InventoryReceiptLineData
  updateLine: (index: number, patch: Partial<InventoryReceiptLineData>) => void
}

/**
 * Price inputs for one receipt line.
 *
 * Stock received today should keep the price already in use for the product,
 * so an empty field is filled from the last batch that carried a selling price
 * (or the catalog base price). Anything the operator types is never
 * overwritten, and the server re-resolves the same fallback if the field is
 * submitted empty.
 */
function ReceiptLinePriceFields({ branchId, errors, index, line, updateLine }: ReceiptLinePriceFieldsProps) {
  const appliedKeyRef = useRef<string | null>(null)
  const hintKey = `${branchId ?? 0}|${line.category_id}|${line.brand.trim()}|${line.model.trim()}`
  const canLookUp = !!branchId && branchId > 0 && line.category_id > 0 && line.brand.trim() !== '' && line.model.trim() !== ''

  const { data: priceHint } = useQuery({
    queryKey: ['inventory-price-suggestion', hintKey],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: PriceSuggestion }>(
        '/admin/inventory/price-suggestion',
        {
          params: {
            branch_id: branchId,
            category_id: line.category_id,
            brand: line.brand.trim(),
            model: line.model.trim(),
          },
        }
      )
      return response.data.data
    },
    enabled: canLookUp,
    staleTime: 5 * 60 * 1000,
  })

  useEffect(() => {
    if (appliedKeyRef.current === hintKey) return

    // Product changed but the lookup has not resolved yet: forget the previous
    // application so this product's pricing can still fill the fields.
    appliedKeyRef.current = priceHint ? hintKey : null
    if (!priceHint) return

    const patch: Partial<InventoryReceiptLineData> = {}
    if (line.buying_price === undefined && priceHint.buying_price !== null) {
      patch.buying_price = priceHint.buying_price
    }
    if (line.target_selling_price === undefined && priceHint.target_selling_price !== null) {
      patch.target_selling_price = priceHint.target_selling_price
    }
    if (Object.keys(patch).length > 0) updateLine(index, patch)
  }, [priceHint, hintKey, index, line.buying_price, line.target_selling_price, updateLine])

  const buyingError = errors[`lines.${index}.buying_price`]
  const sellingError = errors[`lines.${index}.target_selling_price`]

  // A value counts as reused only while it still equals what the lookup
  // returned. The moment the operator types something else the badge goes away,
  // so the label never claims a number the operator chose themselves.
  const buyingIsReused = priceHint?.buying_price != null && line.buying_price === priceHint.buying_price
  const sellingIsReused = priceHint?.target_selling_price != null && line.target_selling_price === priceHint.target_selling_price

  const sourceLabel = priceHint?.source === 'last_batch' && priceHint.batch_number
    ? priceHint.batch_number
    : priceHint?.source === 'catalog_base_price'
    ? 'catalog price'
    : null

  const reusedBadge = (text: string) => (
    <span className="mt-1 inline-flex items-center gap-1 rounded border border-sky-200 bg-sky-50 px-1.5 py-0.5 text-[11px] font-medium text-sky-700">
      <History className="h-3 w-3" />
      {text}
    </span>
  )

  return (
    <>
      <div>
        <label className="block text-xs font-medium text-slate-600 mb-1">Buying Price / Cost</label>
        <input
          type="number"
          min="0"
          step="0.01"
          value={line.buying_price ?? ''}
          onChange={(e) => updateLine(index, { buying_price: e.target.value === '' ? undefined : parseFloat(e.target.value) })}
          placeholder="0.00"
          className={`w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${
            buyingError ? 'border-red-300' : 'border-slate-300'
          }`}
        />
        {buyingError && <p className="mt-1 text-xs text-red-500">{buyingError}</p>}
        {!buyingError && buyingIsReused && sourceLabel && (
          <div>{reusedBadge(`Same as ${sourceLabel}`)}</div>
        )}
      </div>
      <div>
        <label className="block text-xs font-medium text-slate-600 mb-1">Target Selling Price</label>
        <input
          type="number"
          min="0"
          step="0.01"
          value={line.target_selling_price ?? ''}
          onChange={(e) => updateLine(index, { target_selling_price: e.target.value === '' ? undefined : parseFloat(e.target.value) })}
          placeholder="0.00"
          className={`w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${
            sellingError ? 'border-red-300' : 'border-slate-300'
          }`}
        />
        {sellingError && <p className="mt-1 text-xs text-red-500">{sellingError}</p>}
        {!sellingError && sellingIsReused && sourceLabel && (
          <div>{reusedBadge(`Reusing ${sourceLabel}`)}</div>
        )}
        {!sellingError && !sellingIsReused && priceHint && canLookUp && sourceLabel === null && (
          <p className="mt-1 text-xs text-amber-600">
            No previous price for this product — enter one.
          </p>
        )}
      </div>
    </>
  )
}

interface ReceiptLineProductFieldsProps {
  categories?: DeviceCategory[]
  errors: Record<string, string>
  index: number
  line: InventoryReceiptLineData
  updateLine: (index: number, patch: Partial<InventoryReceiptLineData>) => void
}

function ReceiptLineProductFields({ categories, errors, index, line, updateLine }: ReceiptLineProductFieldsProps) {
  const [selectedBrandId, setSelectedBrandId] = useState<number | null>(null)
  const [selectedModelId, setSelectedModelId] = useState<number | null>(null)
  const [useCustomProduct, setUseCustomProduct] = useState(false)

  const { data: brands, isLoading: brandsLoading } = useQuery({
    queryKey: ['catalog-brands-for-receipt-line', line.category_id],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: BrandOption[] }>(
        `/admin/catalog/categories/${line.category_id}/brands`
      )
      return response.data.data
    },
    enabled: line.category_id > 0 && !useCustomProduct,
  })

  const { data: models, isLoading: modelsLoading } = useQuery({
    queryKey: ['catalog-models-for-receipt-line', selectedBrandId],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: ModelOption[] }>(
        `/admin/catalog/brands/${selectedBrandId}/models`
      )
      return response.data.data
    },
    enabled: !!selectedBrandId && !useCustomProduct,
  })

  const selectedModel = models?.find(model => model.id === selectedModelId)
  const availableColors = selectedModel?.available_colors || []
  const error = (field: string) => errors[`lines.${index}.${field}`]
  const fieldClass = (field: string) => error(field) ? 'border-red-300' : 'border-slate-300'

  const handleCategoryChange = (categoryId: number) => {
    setSelectedBrandId(null)
    setSelectedModelId(null)
    updateLine(index, {
      category_id: categoryId,
      brand: '',
      model: '',
      color: '',
      buying_price: undefined,
      target_selling_price: undefined,
    })
  }

  const handleBrandChange = (brandId: number | null) => {
    setSelectedBrandId(brandId)
    setSelectedModelId(null)
    const brand = brands?.find(option => option.id === brandId)
    updateLine(index, {
      brand: brand?.name || '',
      model: '',
      color: '',
      buying_price: undefined,
      target_selling_price: undefined,
    })
  }

  const handleModelChange = (modelId: number | null) => {
    setSelectedModelId(modelId)
    const model = models?.find(option => option.id === modelId)
    // Prices belong to the product, not the line: clear both so this model's
    // existing pricing fills in instead of the previous model's carrying over.
    updateLine(index, {
      model: model?.name || '',
      color: '',
      buying_price: undefined,
      target_selling_price: undefined,
    })
  }

  return (
    <>
      <div className="grid grid-cols-1 md:grid-cols-6 gap-3">
        <div className="md:col-span-2">
          <label className="block text-xs font-medium text-slate-600 mb-1">Category</label>
          <select
            value={line.category_id}
            onChange={(e) => handleCategoryChange(parseInt(e.target.value) || 0)}
            className={`w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${fieldClass('category_id')}`}
          >
            <option value="0">Select category</option>
            {categories?.map((category) => (
              <option key={category.id} value={category.id}>{category.name}</option>
            ))}
          </select>
          {error('category_id') && <p className="mt-1 text-xs text-red-500">{error('category_id')}</p>}
        </div>

        {useCustomProduct ? (
          <>
            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">Brand</label>
              <input
                value={line.brand}
                onChange={(e) => updateLine(index, { brand: e.target.value })}
                placeholder="Samsung"
                className={`w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${fieldClass('brand')}`}
              />
              {error('brand') && <p className="mt-1 text-xs text-red-500">{error('brand')}</p>}
            </div>
            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">Model</label>
              <input
                value={line.model}
                onChange={(e) => updateLine(index, { model: e.target.value })}
                placeholder="Galaxy S24"
                className={`w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${fieldClass('model')}`}
              />
              {error('model') && <p className="mt-1 text-xs text-red-500">{error('model')}</p>}
            </div>
          </>
        ) : (
          <>
            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">Brand</label>
              <div className="relative">
                <select
                  value={selectedBrandId || ''}
                  onChange={(e) => handleBrandChange(e.target.value ? parseInt(e.target.value) : null)}
                  disabled={line.category_id === 0 || brandsLoading}
                  className={`w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-100 disabled:cursor-not-allowed ${fieldClass('brand')}`}
                >
                  <option value="">
                    {line.category_id === 0
                      ? 'Select category first'
                      : brandsLoading
                      ? 'Loading brands...'
                      : brands?.length === 0
                      ? 'No brands'
                      : 'Select brand'}
                  </option>
                  {brands?.map((brand) => (
                    <option key={brand.id} value={brand.id}>
                      {brand.name}
                    </option>
                  ))}
                </select>
                {brandsLoading && <Loader2 className="absolute right-8 top-1/2 -translate-y-1/2 h-4 w-4 animate-spin text-slate-400" />}
              </div>
              {error('brand') && <p className="mt-1 text-xs text-red-500">{error('brand')}</p>}
            </div>
            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">Model</label>
              <div className="relative">
                <select
                  value={selectedModelId || ''}
                  onChange={(e) => handleModelChange(e.target.value ? parseInt(e.target.value) : null)}
                  disabled={!selectedBrandId || modelsLoading}
                  className={`w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-100 disabled:cursor-not-allowed ${fieldClass('model')}`}
                >
                  <option value="">
                    {!selectedBrandId
                      ? 'Select brand first'
                      : modelsLoading
                      ? 'Loading models...'
                      : models?.length === 0
                      ? 'No models'
                      : 'Select model'}
                  </option>
                  {models?.map((model) => (
                    <option key={model.id} value={model.id}>
                      {model.name} {model.base_price ? `(MWK ${Number(model.base_price).toLocaleString()})` : ''}
                    </option>
                  ))}
                </select>
                {modelsLoading && <Loader2 className="absolute right-8 top-1/2 -translate-y-1/2 h-4 w-4 animate-spin text-slate-400" />}
              </div>
              {error('model') && <p className="mt-1 text-xs text-red-500">{error('model')}</p>}
            </div>
          </>
        )}

        <div>
          <label className="block text-xs font-medium text-slate-600 mb-1">Color</label>
          {!useCustomProduct && availableColors.length > 0 ? (
            <select
              value={line.color || ''}
              onChange={(e) => updateLine(index, { color: e.target.value })}
              className={`w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${fieldClass('color')}`}
            >
              <option value="">Select color</option>
              {availableColors.map((color) => (
                <option key={color} value={color}>{color}</option>
              ))}
            </select>
          ) : (
            <input
              value={line.color || ''}
              onChange={(e) => updateLine(index, { color: e.target.value })}
              placeholder="Black"
              className={`w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${fieldClass('color')}`}
            />
          )}
          {error('color') && <p className="mt-1 text-xs text-red-500">{error('color')}</p>}
        </div>
        <div>
          <label className="block text-xs font-medium text-slate-600 mb-1">Qty</label>
          <input
            type="number"
            min="1"
            max="100"
            value={line.quantity}
            onChange={(e) => updateLine(index, { quantity: parseInt(e.target.value) || 1 })}
            className={`w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${fieldClass('quantity')}`}
          />
          {error('quantity') && <p className="mt-1 text-xs text-red-500">{error('quantity')}</p>}
        </div>
      </div>

      <label className="inline-flex items-center gap-2 text-xs text-slate-600">
        <input
          type="checkbox"
          checked={useCustomProduct}
          onChange={(e) => {
            const checked = e.target.checked
            setUseCustomProduct(checked)
            setSelectedBrandId(null)
            setSelectedModelId(null)
            updateLine(index, {
              brand: '',
              model: '',
              color: '',
              buying_price: undefined,
              target_selling_price: undefined,
            })
          }}
          className="h-4 w-4 text-blue-600 border-slate-300 rounded focus:ring-blue-500"
        />
        Custom product not in catalog
      </label>
    </>
  )
}

export default function BulkAddDeviceModal({ onClose }: BulkAddDeviceModalProps) {
  const queryClient = useQueryClient()
  const currentUser = useAuthStore((state) => state.user)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [receipt, setReceipt] = useState<InventoryReceiptData>({
    branch_id: currentUser?.branch_id || 0,
    supplier: '',
    reference_number: '',
    purchase_date: malawiDateInput(),
    purchase_location: '',
    notes: '',
    lines: [emptyLine()],
  })

  const { data: categories } = useQuery({
    queryKey: ['device-categories'],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: DeviceCategory[] }>('/device-categories')
      return response.data.data
    },
  })

  const { data: branches } = useQuery({
    queryKey: ['branches'],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: BranchOption[] }>('/admin/branches')
      return response.data.data
    },
  })

  const totals = useMemo(() => {
    return receipt.lines.reduce((summary, line) => {
      const quantity = Number(line.quantity) || 0
      const buyingPrice = Number(line.buying_price) || 0
      const sellingPrice = Number(line.target_selling_price) || 0

      summary.units += quantity
      summary.cost += quantity * buyingPrice
      summary.revenue += quantity * sellingPrice
      summary.profit += quantity * (sellingPrice - buyingPrice)

      return summary
    }, { units: 0, cost: 0, revenue: 0, profit: 0 })
  }, [receipt.lines])

  const receiveMutation = useMutation({
    mutationFn: async (data: InventoryReceiptData) => {
      const response = await apiClient.post('/admin/inventory/receipts', data)
      return response.data.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['inventory'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-grouped'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-stats'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-batches'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-receipts'] })
      queryClient.invalidateQueries({ queryKey: ['category-stock'] })
      queryClient.invalidateQueries({ queryKey: ['product-options'] })
      toast.success(`Received ${data.count} devices into inventory`)
      onClose()
    },
    onError: (error: any) => {
      const responseErrors = error.response?.data?.errors
      if (responseErrors) {
        const apiErrors: Record<string, string> = {}
        Object.entries(responseErrors).forEach(([key, messages]) => {
          apiErrors[key] = Array.isArray(messages) ? String(messages[0]) : String(messages)
        })
        setErrors(apiErrors)
        toast.error('Please fix the errors in the form')
      } else {
        toast.error(error.response?.data?.message || 'Failed to receive inventory')
      }
    },
  })

  const clearErrors = (...keys: string[]) => {
    setErrors(prev => {
      const next = { ...prev }
      keys.forEach(key => delete next[key])
      return next
    })
  }

  const updateReceipt = (patch: Partial<InventoryReceiptData>, ...errorKeys: string[]) => {
    setReceipt(prev => ({ ...prev, ...patch }))
    clearErrors(...errorKeys)
  }

  const updateLine = (index: number, patch: Partial<InventoryReceiptLineData>) => {
    setReceipt(prev => ({
      ...prev,
      lines: prev.lines.map((line, lineIndex) => lineIndex === index ? { ...line, ...patch } : line),
    }))
    clearErrors(...Object.keys(patch).map(key => `lines.${index}.${key}`), `lines.${index}.serial_numbers`)
  }

  const addLine = () => {
    setReceipt(prev => ({ ...prev, lines: [...prev.lines, emptyLine()] }))
  }

  const removeLine = (index: number) => {
    setReceipt(prev => ({
      ...prev,
      lines: prev.lines.length === 1 ? prev.lines : prev.lines.filter((_, lineIndex) => lineIndex !== index),
    }))
  }

  const serialsForLine = (line: InventoryReceiptLineData) => (
    typeof line.serial_numbers === 'string'
      ? line.serial_numbers.split('\n').map(s => s.trim()).filter(Boolean)
      : line.serial_numbers || []
  )

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    const newErrors: Record<string, string> = {}

    if (!receipt.branch_id) {
      newErrors.branch_id = 'Please select the receiving branch'
    }

    for (const [index, line] of receipt.lines.entries()) {
      if (!line.category_id || !line.brand.trim() || !line.model.trim()) {
        if (!line.category_id) newErrors[`lines.${index}.category_id`] = 'Please select a category'
        if (!line.brand.trim()) newErrors[`lines.${index}.brand`] = 'Please select or enter a brand'
        if (!line.model.trim()) newErrors[`lines.${index}.model`] = 'Please select or enter a model'
      }
      if (!line.quantity || line.quantity < 1) {
        newErrors[`lines.${index}.quantity`] = 'Please enter a valid quantity'
      }
      if (line.buying_price === undefined || line.buying_price < 0) {
        newErrors[`lines.${index}.buying_price`] = 'Please enter a valid buying price'
      }
      // A blank target price is valid: the server inherits the price already in
      // use for the product, and answers with a line-level error when there is
      // nothing to inherit. Blocking it here would contradict that contract.
      if (line.target_selling_price !== undefined && line.target_selling_price < 0) {
        newErrors[`lines.${index}.target_selling_price`] = 'Target selling price cannot be negative'
      }

      if (!line.auto_generate_serials) {
        const serials = serialsForLine(line)
        if (serials.length !== line.quantity) {
          newErrors[`lines.${index}.serial_numbers`] = `Enter exactly ${line.quantity} serial numbers`
        }
      }
    }

    setErrors(newErrors)
    if (Object.keys(newErrors).length > 0) {
      toast.error('Please fix the errors in the form')
      return
    }

    receiveMutation.mutate({
      ...receipt,
      lines: receipt.lines.map(line => ({
        ...line,
        serial_numbers: line.auto_generate_serials ? undefined : serialsForLine(line),
      })),
    })
  }

  return (
    <>
      <div
        className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40"
        onClick={onClose}
      />

      <div className="fixed inset-x-4 top-[3vh] md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-6xl max-h-[94vh] bg-white rounded-xl shadow-xl z-50 overflow-hidden flex flex-col">
        <div className="flex-shrink-0 p-5 border-b border-slate-200">
          <div className="flex items-start justify-between gap-4">
            <div>
              <h3 className="text-lg font-semibold text-slate-800">Receive Stock</h3>
              <p className="text-sm text-slate-500 mt-1">Record one incoming delivery with multiple product lines</p>
            </div>
            <button
              onClick={onClose}
              className="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
              aria-label="Close"
            >
              <X className="h-5 w-5" />
            </button>
          </div>
        </div>

        <form onSubmit={handleSubmit} className="flex-1 overflow-y-auto">
          <div className="p-5 space-y-5">
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">
                  Receiving Branch <span className="text-red-500">*</span>
                </label>
                <select
                  value={receipt.branch_id}
                  onChange={(e) => updateReceipt({ branch_id: parseInt(e.target.value) || 0 }, 'branch_id')}
                  className={`w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 ${
                    errors.branch_id ? 'border-red-300' : 'border-slate-300'
                  }`}
                  required
                >
                  <option value="0">Select branch</option>
                  {branches?.map((branch) => (
                    <option key={branch.id} value={branch.id}>{branch.name}</option>
                  ))}
                </select>
                {errors.branch_id && <p className="mt-1 text-xs text-red-500">{errors.branch_id}</p>}
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">Supplier</label>
                <input
                  value={receipt.supplier || ''}
                  onChange={(e) => updateReceipt({ supplier: e.target.value }, 'supplier')}
                  placeholder="e.g., Dubai Electronics Market"
                  className={`w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 ${
                    errors.supplier ? 'border-red-300' : 'border-slate-300'
                  }`}
                />
                {errors.supplier && <p className="mt-1 text-xs text-red-500">{errors.supplier}</p>}
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">Invoice / Reference</label>
                <input
                  value={receipt.reference_number || ''}
                  onChange={(e) => updateReceipt({ reference_number: e.target.value }, 'reference_number')}
                  placeholder="e.g., INV-1001"
                  className={`w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 ${
                    errors.reference_number ? 'border-red-300' : 'border-slate-300'
                  }`}
                />
                {errors.reference_number && <p className="mt-1 text-xs text-red-500">{errors.reference_number}</p>}
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">Purchase Date</label>
                <input
                  type="date"
                  value={receipt.purchase_date || ''}
                  onChange={(e) => updateReceipt({ purchase_date: e.target.value || undefined }, 'purchase_date')}
                  className={`w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 ${
                    errors.purchase_date ? 'border-red-300' : 'border-slate-300'
                  }`}
                />
                {errors.purchase_date && <p className="mt-1 text-xs text-red-500">{errors.purchase_date}</p>}
              </div>
              <div className="md:col-span-2">
                <label className="block text-sm font-medium text-slate-700 mb-1.5">Purchase Location</label>
                <input
                  value={receipt.purchase_location || ''}
                  onChange={(e) => updateReceipt({ purchase_location: e.target.value }, 'purchase_location')}
                  placeholder="e.g., Dubai, Lilongwe supplier, Blantyre warehouse"
                  className={`w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 ${
                    errors.purchase_location ? 'border-red-300' : 'border-slate-300'
                  }`}
                />
                {errors.purchase_location && <p className="mt-1 text-xs text-red-500">{errors.purchase_location}</p>}
              </div>
            </div>

            <div className="border border-slate-200 rounded-lg overflow-hidden">
              <div className="flex items-center justify-between gap-3 px-4 py-3 bg-slate-50 border-b border-slate-200">
                <div>
                  <h4 className="text-sm font-semibold text-slate-800">Product Lines</h4>
                  <p className="text-xs text-slate-500">Each line becomes a trackable inventory batch</p>
                  <p className="text-xs text-slate-500">
                    Prices default to the last delivery of the same product — change them if this one differs.
                  </p>
                </div>
                <button
                  type="button"
                  onClick={addLine}
                  className="inline-flex items-center gap-2 px-3 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700"
                >
                  <Plus className="h-4 w-4" />
                  Add Line
                </button>
              </div>

              <div className="divide-y divide-slate-200">
                {receipt.lines.map((line, index) => (
                  <div key={index} className="p-4 space-y-4">
                    <div className="flex items-center justify-between">
                      <span className="text-sm font-semibold text-slate-700">Line {index + 1}</span>
                      <button
                        type="button"
                        onClick={() => removeLine(index)}
                        disabled={receipt.lines.length === 1}
                        className="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg disabled:opacity-40 disabled:hover:text-slate-400 disabled:hover:bg-transparent"
                        aria-label={`Remove line ${index + 1}`}
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </div>

                    <ReceiptLineProductFields
                      categories={categories}
                      errors={errors}
                      index={index}
                      line={line}
                      updateLine={updateLine}
                    />

                    <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
                      <ReceiptLinePriceFields
                        branchId={receipt.branch_id}
                        errors={errors}
                        index={index}
                        line={line}
                        updateLine={updateLine}
                      />
                      <div className="md:col-span-2">
                        <label className="block text-xs font-medium text-slate-600 mb-1">Line Notes</label>
                        <input
                          value={line.notes || ''}
                          onChange={(e) => updateLine(index, { notes: e.target.value })}
                          placeholder="Optional notes for this product line"
                          className={`w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${
                            errors[`lines.${index}.notes`] ? 'border-red-300' : 'border-slate-300'
                          }`}
                        />
                        {errors[`lines.${index}.notes`] && (
                          <p className="mt-1 text-xs text-red-500">{errors[`lines.${index}.notes`]}</p>
                        )}
                      </div>
                    </div>

                    <div className="rounded-lg bg-slate-50 border border-slate-200 p-3">
                      <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input
                          type="checkbox"
                          checked={line.auto_generate_serials !== false}
                          onChange={(e) => updateLine(index, { auto_generate_serials: e.target.checked })}
                          className="h-4 w-4 text-blue-600 border-slate-300 rounded focus:ring-blue-500"
                        />
                        Auto-generate serial numbers
                      </label>

                      {line.auto_generate_serials === false ? (
                        <div className="mt-3">
                          <textarea
                            value={Array.isArray(line.serial_numbers) ? line.serial_numbers.join('\n') : line.serial_numbers || ''}
                            onChange={(e) => updateLine(index, { serial_numbers: e.target.value as any })}
                            rows={Math.min(line.quantity || 1, 6)}
                            placeholder="SN001&#10;SN002&#10;SN003"
                            className={`w-full px-3 py-2 border rounded-lg font-mono text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${
                              errors[`lines.${index}.serial_numbers`] ? 'border-red-300' : 'border-slate-300'
                            }`}
                          />
                          <p className="text-xs text-slate-500 mt-1">
                            {serialsForLine(line).length} of {line.quantity} serial numbers entered
                          </p>
                          {errors[`lines.${index}.serial_numbers`] && (
                            <p className="mt-1 text-xs text-red-500">{errors[`lines.${index}.serial_numbers`]}</p>
                          )}
                        </div>
                      ) : (
                        <div className="flex items-start gap-2 text-xs text-slate-600 mt-2">
                          <AlertCircle className="h-4 w-4 flex-shrink-0 mt-0.5" />
                          <p>Serial numbers will be generated for this line when the receipt is saved.</p>
                        </div>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1.5">Receipt Notes</label>
              <textarea
                value={receipt.notes || ''}
                onChange={(e) => updateReceipt({ notes: e.target.value }, 'notes')}
                rows={3}
                placeholder="General notes for this delivery"
                className={`w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 ${
                  errors.notes ? 'border-red-300' : 'border-slate-300'
                }`}
              />
              {errors.notes && <p className="mt-1 text-xs text-red-500">{errors.notes}</p>}
            </div>
          </div>
        </form>

        <div className="flex-shrink-0 p-5 border-t border-slate-200 bg-slate-50">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4 text-sm">
            <div>
              <p className="text-slate-500">Units</p>
              <p className="font-semibold text-slate-900">{totals.units}</p>
            </div>
            <div>
              <p className="text-slate-500">Total Cost</p>
              <p className="font-semibold text-slate-900">{money(totals.cost)}</p>
            </div>
            <div>
              <p className="text-slate-500">Expected Revenue</p>
              <p className="font-semibold text-slate-900">{money(totals.revenue)}</p>
            </div>
            <div>
              <p className="text-slate-500">Expected Profit</p>
              <p className={totals.profit >= 0 ? 'font-semibold text-emerald-700' : 'font-semibold text-red-700'}>
                {money(totals.profit)}
              </p>
            </div>
          </div>
          <div className="flex justify-end gap-3">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 bg-white border border-slate-300 text-slate-700 text-sm font-medium rounded-lg hover:bg-slate-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              onClick={handleSubmit}
              disabled={receiveMutation.isPending}
              className="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
            >
              {receiveMutation.isPending ? (
                <>
                  <Loader2 className="h-4 w-4 animate-spin" />
                  Receiving...
                </>
              ) : (
                <>
                  <PackageCheck className="h-4 w-4" />
                  Receive Stock
                </>
              )}
            </button>
          </div>
        </div>
      </div>
    </>
  )
}
