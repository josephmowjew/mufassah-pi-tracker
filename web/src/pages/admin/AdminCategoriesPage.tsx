import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Search, Plus, Loader2, Edit2, Trash2, Grid3x3 } from 'lucide-react'
import { Authorize } from '@/components/auth/Authorize'
import { PERMISSIONS } from '@/stores/auth-store'
import apiClient from '@/api/client'
import { toast } from 'sonner'
import type { DeviceCategory } from '@/types'

interface CategoryFormData {
  name: string
  description: string
  icon: string
  is_active: boolean
}

const COMMON_ICONS = ['📱', '💻', '⌚', '📷', '🎧', '💾', '🖥️', '📟', '🎮', '📺', '🔌', '📡']

// Map legacy text icons to emoji
const ICON_MAP: Record<string, string> = {
  'tv': '📺',
  'television': '📺',
  'smartphone': '📱',
  'phone': '📱',
  'mobile': '📱',
  'laptop': '💻',
  'notebook': '💻',
  'desktop': '🖥️',
  'computer': '🖥️',
  'watch': '⌚',
  'smartwatch': '⌚',
  'camera': '📷',
  'headphone': '🎧',
  'headphones': '🎧',
  'storage': '💾',
  'hard drive': '💾',
  'tablet': '📟',
  'ipad': '📟',
  'gaming': '🎮',
  'game': '🎮',
  'iot': '📡',
  'accessories': '🔌',
  'accessory': '🔌',
}

const getCategoryIcon = (iconValue: string | null): string => {
  if (!iconValue) return ''
  // If it's already an emoji (single unicode character or multi-byte), return as-is
  if (/[\u{1F300}-\u{1F9FF}]/u.test(iconValue)) {
    return iconValue
  }
  // Otherwise, map from text to emoji
  return ICON_MAP[iconValue.toLowerCase()] || ''
}

export default function AdminCategoriesPage() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [showCreateModal, setShowCreateModal] = useState(false)
  const [editingCategory, setEditingCategory] = useState<DeviceCategory | null>(null)
  const [categoryForm, setCategoryForm] = useState<CategoryFormData>({
    name: '',
    description: '',
    icon: '',
    is_active: true,
  })
  const [formErrors, setFormErrors] = useState<Record<string, string>>({})

  // Fetch categories
  const { data: categories, isLoading } = useQuery({
    queryKey: ['admin-categories', search, page],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (search) params.append('search', search)
      params.append('page', page.toString())
      params.append('per_page', '12')
      params.append('with_count', 'true')

      const response = await apiClient.get<{
        success: boolean
        data: DeviceCategory[]
        meta: {
          current_page: number
          last_page: number
          per_page: number
          total: number
        }
      }>(`/admin/device-categories?${params.toString()}`)
      return {
        data: response.data.data,
        meta: response.data.meta,
      }
    },
  })

  // Create category mutation
  const createCategoryMutation = useMutation({
    mutationFn: async (data: CategoryFormData) => {
      await apiClient.post('/admin/device-categories', data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-categories'] })
      queryClient.invalidateQueries({ queryKey: ['device-categories'] })
      queryClient.invalidateQueries({ queryKey: ['catalog-brands'] })
      toast.success('Category created successfully')
      setShowCreateModal(false)
      resetForm()
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Failed to create category')
    },
  })

  // Update category mutation
  const updateCategoryMutation = useMutation({
    mutationFn: async ({ id, data }: { id: number; data: Partial<CategoryFormData> }) => {
      await apiClient.put(`/admin/device-categories/${id}`, data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-categories'] })
      queryClient.invalidateQueries({ queryKey: ['device-categories'] })
      queryClient.invalidateQueries({ queryKey: ['catalog-brands'] })
      toast.success('Category updated successfully')
      setShowCreateModal(false)
      resetForm()
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Failed to update category')
    },
  })

  // Delete category mutation
  const deleteCategoryMutation = useMutation({
    mutationFn: async (id: number) => {
      await apiClient.delete(`/admin/device-categories/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-categories'] })
      queryClient.invalidateQueries({ queryKey: ['device-categories'] })
      queryClient.invalidateQueries({ queryKey: ['catalog-brands'] })
      toast.success('Category deleted successfully')
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Failed to delete category')
    },
  })

  const resetForm = () => {
    setEditingCategory(null)
    setCategoryForm({ name: '', description: '', icon: '', is_active: true })
    setFormErrors({})
  }

  const openEditCategory = (category: DeviceCategory) => {
    setEditingCategory(category)
    setCategoryForm({
      name: category.name,
      description: category.description || '',
      icon: category.icon || '',
      is_active: category.is_active,
    })
    setShowCreateModal(true)
  }

  const openNewCategory = () => {
    resetForm()
    setShowCreateModal(true)
  }

  const validateForm = (): boolean => {
    const errors: Record<string, string> = {}

    if (!categoryForm.name.trim()) {
      errors.name = 'Category name is required'
    }

    setFormErrors(errors)
    return Object.keys(errors).length === 0
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    if (!validateForm()) {
      return
    }

    if (editingCategory) {
      updateCategoryMutation.mutate({ id: editingCategory.id, data: categoryForm })
    } else {
      createCategoryMutation.mutate(categoryForm)
    }
  }

  const handleDelete = (category: DeviceCategory) => {
    const deviceCount = (category as any).devices_count || 0

    if (deviceCount > 0) {
      toast.error(`Cannot delete category with ${deviceCount} devices. Please reassign or delete the devices first.`)
      return
    }

    if (confirm(`Are you sure you want to delete "${category.name}"?`)) {
      deleteCategoryMutation.mutate(category.id)
    }
  }

  const meta = categories?.meta

  return (
    <Authorize permission={PERMISSIONS.ACCESS_ADMIN_PANEL} fallback={<div className="p-6">Access denied</div>}>
      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold text-slate-900">Device Categories</h1>
            <p className="text-slate-500 text-sm mt-1">Manage device categories and their properties</p>
          </div>
          <button
            onClick={openNewCategory}
            className="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors"
          >
            <Plus className="h-4 w-4" />
            Add Category
          </button>
        </div>

        {/* Search */}
        <div className="bg-white rounded-xl border border-slate-200 p-4">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
            <input
              type="text"
              placeholder="Search categories..."
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(1)
              }}
              className="w-full pl-10 pr-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            />
          </div>
        </div>

        {/* Categories Grid */}
        <div className="bg-white rounded-xl border border-slate-200 p-6">
          {isLoading ? (
            <div className="flex items-center justify-center py-16">
              <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
            </div>
          ) : !categories?.data || categories.data.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-16">
              <Grid3x3 className="h-12 w-12 text-slate-300 mb-4" />
              <p className="text-slate-500">No categories found</p>
              <p className="text-sm text-slate-400 mt-1">Create a category to get started</p>
            </div>
          ) : (
            <>
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                {categories.data.map((category) => {
                  const deviceCount = (category as any).devices_count || 0
                  return (
                    <div
                      key={category.id}
                      className="border border-slate-200 rounded-lg p-4 hover:border-blue-300 hover:shadow-md transition-all"
                    >
                      <div className="flex items-start justify-between mb-3">
                        <div className="flex items-center gap-3 flex-1">
                          <div className="h-10 w-10 rounded-lg bg-slate-100 flex items-center justify-center flex-shrink-0">
                            {(() => {
                              const iconEmoji = getCategoryIcon(category.icon)
                              return iconEmoji ? (
                                <span className="text-xl">{iconEmoji}</span>
                              ) : (
                                <Grid3x3 className="h-5 w-5 text-slate-400" />
                              )
                            })()}
                          </div>
                          <div className="min-w-0 flex-1">
                            <h3 className="font-semibold text-slate-900 truncate">{category.name}</h3>
                            <span className={`text-xs px-2 py-0.5 rounded-full ${
                              category.is_active
                                ? 'bg-emerald-100 text-emerald-700'
                                : 'bg-slate-100 text-slate-600'
                            }`}>
                              {category.is_active ? 'Active' : 'Inactive'}
                            </span>
                          </div>
                        </div>
                      </div>

                      {category.description && (
                        <p className="text-sm text-slate-600 mb-3 line-clamp-2">{category.description}</p>
                      )}

                      <div className="flex items-center justify-between pt-3 border-t border-slate-100">
                        <span className="text-sm text-slate-500">{deviceCount} devices</span>
                        <div className="flex items-center gap-1">
                          <button
                            onClick={() => openEditCategory(category)}
                            className="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                            title="Edit"
                          >
                            <Edit2 className="h-4 w-4" />
                          </button>
                          <button
                            onClick={() => handleDelete(category)}
                            disabled={deviceCount > 0}
                            className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            title={deviceCount > 0 ? 'Cannot delete category with devices' : 'Delete'}
                          >
                            <Trash2 className="h-4 w-4" />
                          </button>
                        </div>
                      </div>
                    </div>
                  )
                })}
              </div>

              {/* Pagination */}
              {meta && meta.last_page > 1 && (
                <div className="flex items-center justify-between mt-6 pt-6 border-t border-slate-200">
                  <p className="text-sm text-slate-500">
                    Showing {((meta.current_page - 1) * meta.per_page + 1)} to{' '}
                    {Math.min(meta.current_page * meta.per_page, meta.total)} of {meta.total} categories
                  </p>
                  <div className="flex gap-2">
                    <button
                      onClick={() => setPage((p) => Math.max(1, p - 1))}
                      disabled={page === 1}
                      className="px-3 py-1.5 text-sm border border-slate-300 rounded-lg hover:bg-white disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    >
                      Previous
                    </button>
                    <span className="px-3 py-1.5 text-sm text-slate-600">
                      Page {meta.current_page} of {meta.last_page}
                    </span>
                    <button
                      onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                      disabled={page === meta.last_page}
                      className="px-3 py-1.5 text-sm border border-slate-300 rounded-lg hover:bg-white disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    >
                      Next
                    </button>
                  </div>
                </div>
              )}
            </>
          )}
        </div>

        {/* Create/Edit Modal */}
        {showCreateModal && (
          <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <div className="bg-white rounded-xl max-w-md w-full">
              <div className="p-6 border-b border-slate-200">
                <h2 className="text-lg font-semibold text-slate-900">
                  {editingCategory ? 'Edit Category' : 'New Category'}
                </h2>
              </div>
              <form onSubmit={handleSubmit} className="p-6 space-y-4">
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Category Name *</label>
                  <input
                    type="text"
                    value={categoryForm.name}
                    onChange={(e) => setCategoryForm({ ...categoryForm, name: e.target.value })}
                    className={`w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 ${
                      formErrors.name ? 'border-red-300' : 'border-slate-200'
                    }`}
                    placeholder="e.g., Smartphones"
                  />
                  {formErrors.name && <p className="text-red-600 text-xs mt-1">{formErrors.name}</p>}
                </div>

                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Description</label>
                  <textarea
                    value={categoryForm.description}
                    onChange={(e) => setCategoryForm({ ...categoryForm, description: e.target.value })}
                    rows={3}
                    placeholder="Category description..."
                    className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-2">Icon</label>
                  <div className="flex items-center gap-3 mb-3 p-3 bg-slate-50 rounded-lg border border-slate-200">
                    <span className="text-sm text-slate-600">Preview:</span>
                    <div className="h-10 w-10 rounded-lg bg-white border border-slate-200 flex items-center justify-center">
                      {getCategoryIcon(categoryForm.icon) ? (
                        <span className="text-xl">{getCategoryIcon(categoryForm.icon)}</span>
                      ) : (
                        <Grid3x3 className="h-5 w-5 text-slate-400" />
                      )}
                    </div>
                    {categoryForm.icon && (
                      <span className="text-xs text-slate-500">(stored as: {categoryForm.icon})</span>
                    )}
                  </div>
                  <div className="flex flex-wrap gap-2 mb-2">
                    {COMMON_ICONS.map((icon) => {
                      const isSelected = categoryForm.icon === icon || getCategoryIcon(categoryForm.icon) === icon
                      return (
                        <button
                          key={icon}
                          type="button"
                          onClick={() => setCategoryForm({ ...categoryForm, icon })}
                          className={`h-10 w-10 rounded-lg border-2 flex items-center justify-center text-xl transition-all ${
                            isSelected
                              ? 'border-blue-500 bg-blue-50'
                              : 'border-slate-200 hover:border-blue-300'
                          }`}
                        >
                          {icon}
                        </button>
                      )
                    })}
                  </div>
                  <input
                    type="text"
                    value={categoryForm.icon}
                    onChange={(e) => setCategoryForm({ ...categoryForm, icon: e.target.value })}
                    placeholder="Or enter custom emoji..."
                    className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-center text-xl"
                  />
                </div>

                <div className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    id="categoryActive"
                    checked={categoryForm.is_active}
                    onChange={(e) => setCategoryForm({ ...categoryForm, is_active: e.target.checked })}
                    className="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                  />
                  <label htmlFor="categoryActive" className="text-sm text-slate-700">Active</label>
                </div>

                <div className="flex justify-end gap-3 pt-4">
                  <button
                    type="button"
                    onClick={() => { setShowCreateModal(false); resetForm() }}
                    className="px-4 py-2 text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={createCategoryMutation.isPending || updateCategoryMutation.isPending}
                    className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors disabled:opacity-50"
                  >
                    {(createCategoryMutation.isPending || updateCategoryMutation.isPending) ? (
                      <Loader2 className="h-4 w-4 animate-spin" />
                    ) : editingCategory ? 'Update' : 'Create'}
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}
      </div>
    </Authorize>
  )
}
