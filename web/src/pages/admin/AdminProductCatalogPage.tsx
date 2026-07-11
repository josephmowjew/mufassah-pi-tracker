import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Search, Plus, Loader2, Edit2, Trash2, ChevronRight, Package, Layers } from 'lucide-react'
import { Authorize } from '@/components/auth/Authorize'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { PERMISSIONS } from '@/stores/auth-store'
import apiClient from '@/api/client'
import { toast } from 'sonner'
import type { Brand, ProductModel, DeviceCategory } from '@/types'

interface BrandFormData {
  category_id: number | ''
  name: string
  description: string
  is_active: boolean
}

interface ModelFormData {
  brand_id: number | ''
  name: string
  description: string
  base_price: string
  available_colors: string
  is_active: boolean
}

export default function AdminProductCatalogPage() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [selectedCategoryId, setSelectedCategoryId] = useState<number | ''>('')
  const [selectedBrandId, setSelectedBrandId] = useState<number | null>(null)

  // Brand modal state
  const [showBrandModal, setShowBrandModal] = useState(false)
  const [editingBrand, setEditingBrand] = useState<Brand | null>(null)
  const [brandForm, setBrandForm] = useState<BrandFormData>({
    category_id: '',
    name: '',
    description: '',
    is_active: true,
  })

  // Model modal state
  const [showModelModal, setShowModelModal] = useState(false)
  const [editingModel, setEditingModel] = useState<ProductModel | null>(null)
  const [modelForm, setModelForm] = useState<ModelFormData>({
    brand_id: '',
    name: '',
    description: '',
    base_price: '',
    available_colors: '',
    is_active: true,
  })
  const [showPriceCascadeConfirm, setShowPriceCascadeConfirm] = useState(false)

  // Fetch categories
  const { data: categories } = useQuery({
    queryKey: ['device-categories'],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: DeviceCategory[] }>('/device-categories')
      return response.data.data
    },
  })

  // Fetch brands
  const { data: brands, isLoading: brandsLoading } = useQuery({
    queryKey: ['catalog-brands', selectedCategoryId, search],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (selectedCategoryId) params.append('category_id', selectedCategoryId.toString())
      if (search) params.append('search', search)
      params.append('with_models_count', 'true')
      params.append('with_devices_count', 'true')

      const response = await apiClient.get<{ success: boolean; data: Brand[] }>(
        `/admin/catalog/brands?${params.toString()}`
      )
      return response.data.data
    },
  })

  // Fetch models for selected brand
  const { data: models, isLoading: modelsLoading } = useQuery({
    queryKey: ['catalog-models', selectedBrandId],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (selectedBrandId) params.append('brand_id', selectedBrandId.toString())
      params.append('with_devices_count', 'true')

      const response = await apiClient.get<{ success: boolean; data: ProductModel[] }>(
        `/admin/catalog/models?${params.toString()}`
      )
      return response.data.data
    },
    enabled: !!selectedBrandId,
  })

  // Brand mutations
  const createBrandMutation = useMutation({
    mutationFn: async (data: BrandFormData) => {
      await apiClient.post('/admin/catalog/brands', data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['catalog-brands'] })
      toast.success('Brand created successfully')
      setShowBrandModal(false)
      resetBrandForm()
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Failed to create brand')
    },
  })

  const updateBrandMutation = useMutation({
    mutationFn: async ({ id, data }: { id: number; data: Partial<BrandFormData> }) => {
      await apiClient.put(`/admin/catalog/brands/${id}`, data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['catalog-brands'] })
      toast.success('Brand updated successfully')
      setShowBrandModal(false)
      resetBrandForm()
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Failed to update brand')
    },
  })

  const deleteBrandMutation = useMutation({
    mutationFn: async (id: number) => {
      await apiClient.delete(`/admin/catalog/brands/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['catalog-brands'] })
      toast.success('Brand deleted successfully')
      if (selectedBrandId) setSelectedBrandId(null)
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Failed to delete brand')
    },
  })

  // Model mutations
  const createModelMutation = useMutation({
    mutationFn: async (data: ModelFormData) => {
      const payload = {
        ...data,
        base_price: data.base_price ? parseFloat(data.base_price) : null,
        available_colors: data.available_colors ? data.available_colors.split(',').map(c => c.trim()).filter(Boolean) : [],
      }
      await apiClient.post('/admin/catalog/models', payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['catalog-models'] })
      queryClient.invalidateQueries({ queryKey: ['catalog-brands'] })
      toast.success('Product model created successfully')
      setShowModelModal(false)
      resetModelForm()
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Failed to create product model')
    },
  })

  const updateModelMutation = useMutation({
    mutationFn: async ({ id, data }: { id: number; data: Partial<ModelFormData> }) => {
      const payload = {
        ...data,
        base_price: data.base_price ? parseFloat(data.base_price) : null,
        available_colors: data.available_colors ? data.available_colors.split(',').map(c => c.trim()).filter(Boolean) : [],
      }
      await apiClient.put(`/admin/catalog/models/${id}`, payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['catalog-models'] })
      queryClient.invalidateQueries({ queryKey: ['catalog-brands'] })
      toast.success('Product model updated successfully')
      setShowModelModal(false)
      resetModelForm()
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Failed to update product model')
    },
  })

  const deleteModelMutation = useMutation({
    mutationFn: async (id: number) => {
      await apiClient.delete(`/admin/catalog/models/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['catalog-models'] })
      queryClient.invalidateQueries({ queryKey: ['catalog-brands'] })
      toast.success('Product model deleted successfully')
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Failed to delete product model')
    },
  })

  const resetBrandForm = () => {
    setEditingBrand(null)
    setBrandForm({ category_id: '', name: '', description: '', is_active: true })
  }

  const resetModelForm = () => {
    setEditingModel(null)
    setModelForm({ brand_id: '', name: '', description: '', base_price: '', available_colors: '', is_active: true })
  }

  const openEditBrand = (brand: Brand) => {
    setEditingBrand(brand)
    setBrandForm({
      category_id: brand.category_id,
      name: brand.name,
      description: brand.description || '',
      is_active: brand.is_active,
    })
    setShowBrandModal(true)
  }

  const openEditModel = (model: ProductModel) => {
    setEditingModel(model)
    setModelForm({
      brand_id: model.brand_id,
      name: model.name,
      description: model.description || '',
      base_price: model.base_price?.toString() || '',
      available_colors: model.available_colors?.join(', ') || '',
      is_active: model.is_active,
    })
    setShowModelModal(true)
  }

  const openNewBrand = () => {
    resetBrandForm()
    if (selectedCategoryId) {
      setBrandForm(prev => ({ ...prev, category_id: selectedCategoryId }))
    }
    setShowBrandModal(true)
  }

  const openNewModel = () => {
    resetModelForm()
    if (selectedBrandId) {
      setModelForm(prev => ({ ...prev, brand_id: selectedBrandId }))
    }
    setShowModelModal(true)
  }

  const handleBrandSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (editingBrand) {
      updateBrandMutation.mutate({ id: editingBrand.id, data: brandForm })
    } else {
      createBrandMutation.mutate(brandForm)
    }
  }

  const handleModelSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (editingModel) {
      if (modelForm.base_price !== (editingModel.base_price?.toString() || '')) {
        setShowPriceCascadeConfirm(true)
        return
      }
      updateModelMutation.mutate({ id: editingModel.id, data: modelForm })
    } else {
      createModelMutation.mutate(modelForm)
    }
  }

  const confirmCatalogPriceUpdate = () => {
    if (!editingModel) return
    updateModelMutation.mutate({ id: editingModel.id, data: modelForm })
    setShowPriceCascadeConfirm(false)
  }

  const selectedBrand = brands?.find(b => b.id === selectedBrandId)

  return (
    <Authorize permission={PERMISSIONS.ACCESS_ADMIN_PANEL} fallback={<div className="p-6">Access denied</div>}>
      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold text-slate-900">Product Catalog</h1>
            <p className="text-slate-500 text-sm mt-1">Manage brands and product models</p>
          </div>
          <div className="flex gap-2">
            <button
              onClick={openNewBrand}
              className="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors"
            >
              <Plus className="h-4 w-4" />
              Add Brand
            </button>
            <button
              onClick={openNewModel}
              disabled={!selectedBrandId}
              className="inline-flex items-center gap-2 bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <Plus className="h-4 w-4" />
              Add Model
            </button>
          </div>
        </div>

        {/* Filters */}
        <div className="bg-white rounded-xl border border-slate-200 p-4">
          <div className="flex flex-col sm:flex-row gap-4">
            <div className="flex-1 relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
              <input
                type="text"
                placeholder="Search brands..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="w-full pl-10 pr-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
              />
            </div>
            <select
              value={selectedCategoryId}
              onChange={(e) => {
                setSelectedCategoryId(e.target.value ? parseInt(e.target.value) : '')
                setSelectedBrandId(null)
              }}
              className="px-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">All Categories</option>
              {categories?.map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </select>
          </div>
        </div>

        {/* Content Grid */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          {/* Brands List */}
          <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div className="p-4 border-b border-slate-200 bg-slate-50">
              <div className="flex items-center gap-2">
                <Package className="h-5 w-5 text-slate-600" />
                <h2 className="font-semibold text-slate-900">Brands</h2>
                <span className="text-sm text-slate-500">({brands?.length || 0})</span>
              </div>
            </div>
            <div className="divide-y divide-slate-100 max-h-[600px] overflow-y-auto">
              {brandsLoading ? (
                <div className="p-8 text-center">
                  <Loader2 className="h-8 w-8 animate-spin text-slate-400 mx-auto" />
                </div>
              ) : brands?.length === 0 ? (
                <div className="p-8 text-center text-slate-500">
                  No brands found. Create one to get started.
                </div>
              ) : (
                brands?.map((brand) => (
                  <div
                    key={brand.id}
                    className={`p-4 cursor-pointer transition-colors ${
                      selectedBrandId === brand.id ? 'bg-blue-50' : 'hover:bg-slate-50'
                    }`}
                    onClick={() => setSelectedBrandId(brand.id)}
                  >
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-3">
                        <div className="h-10 w-10 rounded-lg bg-slate-100 flex items-center justify-center">
                          <Package className="h-5 w-5 text-slate-600" />
                        </div>
                        <div>
                          <p className="font-medium text-slate-900">{brand.name}</p>
                          <p className="text-sm text-slate-500">{brand.category?.name}</p>
                        </div>
                      </div>
                      <div className="flex items-center gap-4">
                        <div className="text-right">
                          <p className="text-sm font-medium text-slate-900">{brand.product_models_count || 0} models</p>
                          <p className="text-xs text-slate-500">{brand.devices_count || 0} devices</p>
                        </div>
                        <div className="flex items-center gap-1">
                          <button
                            onClick={(e) => { e.stopPropagation(); openEditBrand(brand) }}
                            className="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                          >
                            <Edit2 className="h-4 w-4" />
                          </button>
                          <button
                            onClick={(e) => {
                              e.stopPropagation()
                              if (confirm('Are you sure you want to delete this brand?')) {
                                deleteBrandMutation.mutate(brand.id)
                              }
                            }}
                            className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                          >
                            <Trash2 className="h-4 w-4" />
                          </button>
                          <ChevronRight className={`h-4 w-4 text-slate-400 transition-transform ${
                            selectedBrandId === brand.id ? 'rotate-90' : ''
                          }`} />
                        </div>
                      </div>
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>

          {/* Models List */}
          <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div className="p-4 border-b border-slate-200 bg-slate-50">
              <div className="flex items-center gap-2">
                <Layers className="h-5 w-5 text-slate-600" />
                <h2 className="font-semibold text-slate-900">
                  {selectedBrand ? `${selectedBrand.name} Models` : 'Product Models'}
                </h2>
                {selectedBrandId && <span className="text-sm text-slate-500">({models?.length || 0})</span>}
              </div>
            </div>
            <div className="divide-y divide-slate-100 max-h-[600px] overflow-y-auto">
              {!selectedBrandId ? (
                <div className="p-8 text-center text-slate-500">
                  Select a brand to view its product models.
                </div>
              ) : modelsLoading ? (
                <div className="p-8 text-center">
                  <Loader2 className="h-8 w-8 animate-spin text-slate-400 mx-auto" />
                </div>
              ) : models?.length === 0 ? (
                <div className="p-8 text-center text-slate-500">
                  No models found for this brand.
                </div>
              ) : (
                models?.map((model) => (
                  <div key={model.id} className="p-4 hover:bg-slate-50 transition-colors">
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-3">
                        <div className="h-10 w-10 rounded-lg bg-emerald-100 flex items-center justify-center">
                          <Layers className="h-5 w-5 text-emerald-600" />
                        </div>
                        <div>
                          <p className="font-medium text-slate-900">{model.name}</p>
                          <div className="flex items-center gap-2 mt-0.5">
                            {model.base_price && (
                              <span className="text-sm text-emerald-600 font-medium">
                                MWK {Number(model.base_price).toLocaleString()}
                              </span>
                            )}
                            {model.available_colors && model.available_colors.length > 0 && (
                              <span className="text-xs text-slate-500">
                                {model.available_colors.length} colors
                              </span>
                            )}
                          </div>
                        </div>
                      </div>
                      <div className="flex items-center gap-4">
                        <div className="text-right">
                          <p className="text-sm font-medium text-slate-900">{model.devices_count || 0} devices</p>
                          <span className={`text-xs px-2 py-0.5 rounded-full ${
                            model.is_active
                              ? 'bg-emerald-100 text-emerald-700'
                              : 'bg-slate-100 text-slate-600'
                          }`}>
                            {model.is_active ? 'Active' : 'Inactive'}
                          </span>
                        </div>
                        <div className="flex items-center gap-1">
                          <button
                            onClick={() => openEditModel(model)}
                            className="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                          >
                            <Edit2 className="h-4 w-4" />
                          </button>
                          <button
                            onClick={() => {
                              if (confirm('Are you sure you want to delete this model?')) {
                                deleteModelMutation.mutate(model.id)
                              }
                            }}
                            className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                          >
                            <Trash2 className="h-4 w-4" />
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>
        </div>

        {/* Brand Modal */}
        {showBrandModal && (
          <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <div className="bg-white rounded-xl max-w-md w-full">
              <div className="p-6 border-b border-slate-200">
                <h2 className="text-lg font-semibold text-slate-900">
                  {editingBrand ? 'Edit Brand' : 'New Brand'}
                </h2>
              </div>
              <form onSubmit={handleBrandSubmit} className="p-6 space-y-4">
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Category *</label>
                  <select
                    value={brandForm.category_id}
                    onChange={(e) => setBrandForm({ ...brandForm, category_id: parseInt(e.target.value) || '' })}
                    required
                    className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="">Select Category</option>
                    {categories?.map((category) => (
                      <option key={category.id} value={category.id}>
                        {category.name}
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Brand Name *</label>
                  <input
                    type="text"
                    value={brandForm.name}
                    onChange={(e) => setBrandForm({ ...brandForm, name: e.target.value })}
                    required
                    placeholder="e.g., Apple, Samsung"
                    className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Description</label>
                  <textarea
                    value={brandForm.description}
                    onChange={(e) => setBrandForm({ ...brandForm, description: e.target.value })}
                    rows={3}
                    placeholder="Brand description..."
                    className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    id="brandActive"
                    checked={brandForm.is_active}
                    onChange={(e) => setBrandForm({ ...brandForm, is_active: e.target.checked })}
                    className="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                  />
                  <label htmlFor="brandActive" className="text-sm text-slate-700">Active</label>
                </div>
                <div className="flex justify-end gap-3 pt-4">
                  <button
                    type="button"
                    onClick={() => { setShowBrandModal(false); resetBrandForm() }}
                    className="px-4 py-2 text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={createBrandMutation.isPending || updateBrandMutation.isPending}
                    className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors disabled:opacity-50"
                  >
                    {(createBrandMutation.isPending || updateBrandMutation.isPending) ? (
                      <Loader2 className="h-4 w-4 animate-spin" />
                    ) : editingBrand ? 'Update' : 'Create'}
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* Model Modal */}
        {showModelModal && (
          <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <div className="bg-white rounded-xl max-w-md w-full">
              <div className="p-6 border-b border-slate-200">
                <h2 className="text-lg font-semibold text-slate-900">
                  {editingModel ? 'Edit Product Model' : 'New Product Model'}
                </h2>
              </div>
              <form onSubmit={handleModelSubmit} className="p-6 space-y-4">
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Brand *</label>
                  <select
                    value={modelForm.brand_id}
                    onChange={(e) => setModelForm({ ...modelForm, brand_id: parseInt(e.target.value) || '' })}
                    required
                    className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="">Select Brand</option>
                    {brands?.map((brand) => (
                      <option key={brand.id} value={brand.id}>
                        {brand.name} ({brand.category?.name})
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Model Name *</label>
                  <input
                    type="text"
                    value={modelForm.name}
                    onChange={(e) => setModelForm({ ...modelForm, name: e.target.value })}
                    required
                    placeholder="e.g., iPhone 15 Pro Max"
                    className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Default Selling Price (MWK)</label>
                  <input
                    type="number"
                    value={modelForm.base_price}
                    onChange={(e) => setModelForm({ ...modelForm, base_price: e.target.value })}
                    placeholder="e.g., 850000"
                    min="0"
                    step="0.01"
                    className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                  <p className="mt-1 text-xs text-amber-700">Changing this updates matching in-stock batches; completed and reserved sales stay unchanged.</p>
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Available Colors</label>
                  <input
                    type="text"
                    value={modelForm.available_colors}
                    onChange={(e) => setModelForm({ ...modelForm, available_colors: e.target.value })}
                    placeholder="Black, White, Silver (comma-separated)"
                    className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                  <p className="text-xs text-slate-500 mt-1">Enter colors separated by commas</p>
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Description</label>
                  <textarea
                    value={modelForm.description}
                    onChange={(e) => setModelForm({ ...modelForm, description: e.target.value })}
                    rows={2}
                    placeholder="Model description..."
                    className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    id="modelActive"
                    checked={modelForm.is_active}
                    onChange={(e) => setModelForm({ ...modelForm, is_active: e.target.checked })}
                    className="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                  />
                  <label htmlFor="modelActive" className="text-sm text-slate-700">Active</label>
                </div>
                <div className="flex justify-end gap-3 pt-4">
                  <button
                    type="button"
                    onClick={() => { setShowModelModal(false); resetModelForm() }}
                    className="px-4 py-2 text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={createModelMutation.isPending || updateModelMutation.isPending}
                    className="px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors disabled:opacity-50"
                  >
                    {(createModelMutation.isPending || updateModelMutation.isPending) ? (
                      <Loader2 className="h-4 w-4 animate-spin" />
                    ) : editingModel ? 'Update' : 'Create'}
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}
      </div>
      <ConfirmDialog
        isOpen={showPriceCascadeConfirm}
        onClose={() => setShowPriceCascadeConfirm(false)}
        onConfirm={confirmCatalogPriceUpdate}
        title="Update default selling price?"
        message={`This will set the default price to MWK ${Number(modelForm.base_price || 0).toLocaleString()} and update all matching in-stock batches. Completed and reserved sales will not change.`}
        confirmText="Update in-stock batches"
        cancelText="Keep current prices"
        variant="warning"
        isLoading={updateModelMutation.isPending}
      />
    </Authorize>
  )
}
