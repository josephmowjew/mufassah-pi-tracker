import { useState, useEffect } from 'react'
import { X, Loader2, Smartphone } from 'lucide-react'
import { toast } from 'sonner'
import { useUpdateDevice, useDeviceCategories } from '@/hooks/useDevices'
import type { Device } from '@/types'

interface EditDeviceModalProps {
  device: Device
  isOpen: boolean
  onClose: () => void
}

export default function EditDeviceModal({ device, isOpen, onClose }: EditDeviceModalProps) {
  const [formData, setFormData] = useState({
    category_id: device.category_id.toString(),
    serial_number: device.serial_number || '',
    brand: device.brand,
    model: device.model,
    color: device.color || '',
    purchase_date: device.purchase_date || '',
    purchase_price: device.purchase_price?.toString() || '',
    purchase_location: (device as { purchase_location?: string }).purchase_location || '',
    description: device.description || '',
    notes: device.notes || '',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})

  const { data: categories, isLoading: categoriesLoading } = useDeviceCategories()
  const updateDevice = useUpdateDevice()

  useEffect(() => {
    setFormData({
      category_id: device.category_id.toString(),
      serial_number: device.serial_number || '',
      brand: device.brand,
      model: device.model,
      color: device.color || '',
      purchase_date: device.purchase_date || '',
      purchase_price: device.purchase_price?.toString() || '',
      purchase_location: (device as { purchase_location?: string }).purchase_location || '',
      description: device.description || '',
      notes: device.notes || '',
    })
  }, [device])

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
    const { name, value } = e.target
    setFormData((prev) => ({ ...prev, [name]: value }))
    if (errors[name]) {
      setErrors((prev) => ({ ...prev, [name]: '' }))
    }
  }

  const validate = () => {
    const newErrors: Record<string, string> = {}
    if (!formData.category_id) newErrors.category_id = 'Please select a device category'
    if (!formData.serial_number.trim()) newErrors.serial_number = 'Serial number is required'
    if (!formData.brand.trim()) newErrors.brand = 'Brand is required'
    if (!formData.model.trim()) newErrors.model = 'Model is required'
    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!validate()) return

    updateDevice.mutate(
      {
        id: device.id,
        data: {
          category_id: parseInt(formData.category_id),
          serial_number: formData.serial_number,
          brand: formData.brand,
          model: formData.model,
          color: formData.color || undefined,
          purchase_date: formData.purchase_date || undefined,
          purchase_price: formData.purchase_price ? parseFloat(formData.purchase_price) : undefined,
          purchase_location: formData.purchase_location || undefined,
          description: formData.description || undefined,
          notes: formData.notes || undefined,
        },
      },
      {
        onSuccess: () => {
          toast.success('Device updated successfully')
          onClose()
        },
        onError: (error) => {
          const err = error as { response?: { data?: { errors?: Record<string, string[]> } } }
          if (err.response?.data?.errors) {
            const apiErrors: Record<string, string> = {}
            Object.entries(err.response.data.errors).forEach(([key, messages]) => {
              apiErrors[key] = messages[0]
            })
            setErrors(apiErrors)
            toast.error('Please fix the errors in the form')
          } else {
            toast.error('Failed to update device. Please try again.')
          }
        },
      }
    )
  }

  if (!isOpen) return null

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto">
      {/* Backdrop */}
      <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" onClick={onClose} />

      {/* Modal */}
      <div className="relative min-h-screen flex items-center justify-center p-4">
        <div className="relative bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-hidden">
          {/* Header */}
          <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <div className="flex items-center gap-3">
              <div className="p-2 bg-teal-50 rounded-lg">
                <Smartphone className="h-5 w-5 text-teal-600" />
              </div>
              <h2 className="text-lg font-semibold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
                Edit Device
              </h2>
            </div>
            <button
              onClick={onClose}
              className="p-2 hover:bg-slate-100 rounded-lg transition-colors"
            >
              <X className="h-5 w-5 text-slate-500" />
            </button>
          </div>

          {/* Form */}
          <form onSubmit={handleSubmit} className="overflow-y-auto max-h-[calc(90vh-140px)]">
            <div className="p-6 space-y-5">
              {/* Category */}
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">
                  Category <span className="text-red-500">*</span>
                </label>
                <select
                  name="category_id"
                  value={formData.category_id}
                  onChange={handleChange}
                  disabled={categoriesLoading}
                  className={`w-full px-4 py-2.5 bg-slate-50 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 ${
                    errors.category_id ? 'border-red-300' : 'border-slate-200'
                  }`}
                >
                  <option value="">Select category</option>
                  {categories?.map((cat) => (
                    <option key={cat.id} value={cat.id}>
                      {cat.name}
                    </option>
                  ))}
                </select>
                {errors.category_id && (
                  <p className="mt-1 text-xs text-red-500">{errors.category_id}</p>
                )}
              </div>

              {/* Serial Number */}
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">
                  Serial Number <span className="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  name="serial_number"
                  value={formData.serial_number}
                  onChange={handleChange}
                  placeholder="e.g., SN123456789"
                  className={`w-full px-4 py-2.5 bg-slate-50 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 ${
                    errors.serial_number ? 'border-red-300' : 'border-slate-200'
                  }`}
                />
                {errors.serial_number && <p className="mt-1 text-xs text-red-500">{errors.serial_number}</p>}
              </div>

              {/* Brand & Model */}
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1.5">
                    Brand <span className="text-red-500">*</span>
                  </label>
                  <input
                    type="text"
                    name="brand"
                    value={formData.brand}
                    onChange={handleChange}
                    placeholder="e.g., Apple"
                    className={`w-full px-4 py-2.5 bg-slate-50 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 ${
                      errors.brand ? 'border-red-300' : 'border-slate-200'
                    }`}
                  />
                  {errors.brand && <p className="mt-1 text-xs text-red-500">{errors.brand}</p>}
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1.5">
                    Model <span className="text-red-500">*</span>
                  </label>
                  <input
                    type="text"
                    name="model"
                    value={formData.model}
                    onChange={handleChange}
                    placeholder="e.g., iPhone 15 Pro"
                    className={`w-full px-4 py-2.5 bg-slate-50 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 ${
                      errors.model ? 'border-red-300' : 'border-slate-200'
                    }`}
                  />
                  {errors.model && <p className="mt-1 text-xs text-red-500">{errors.model}</p>}
                </div>
              </div>

              {/* Color & Purchase Date */}
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1.5">
                    Color
                  </label>
                  <input
                    type="text"
                    name="color"
                    value={formData.color}
                    onChange={handleChange}
                    placeholder="e.g., Space Black"
                    className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1.5">
                    Purchase Date
                  </label>
                  <input
                    type="date"
                    name="purchase_date"
                    value={formData.purchase_date}
                    onChange={handleChange}
                    max={new Date().toISOString().split('T')[0]}
                    className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500"
                  />
                </div>
              </div>

              {/* Purchase Price & Location */}
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1.5">
                    Purchase Price (MWK)
                  </label>
                  <input
                    type="number"
                    name="purchase_price"
                    value={formData.purchase_price}
                    onChange={handleChange}
                    placeholder="Optional"
                    min="0"
                    className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1.5">
                    Purchase Location
                  </label>
                  <input
                    type="text"
                    name="purchase_location"
                    value={formData.purchase_location}
                    onChange={handleChange}
                    placeholder="e.g., Game Stores"
                    className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500"
                  />
                </div>
              </div>

              {/* Description */}
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">
                  Description
                </label>
                <textarea
                  name="description"
                  value={formData.description}
                  onChange={handleChange}
                  rows={2}
                  placeholder="Brief description of your device..."
                  className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 resize-none"
                />
              </div>

              {/* Notes */}
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">
                  Notes
                </label>
                <textarea
                  name="notes"
                  value={formData.notes}
                  onChange={handleChange}
                  rows={2}
                  placeholder="Any additional notes..."
                  className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 resize-none"
                />
              </div>
            </div>

            {/* Footer */}
            <div className="flex items-center justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50/50">
              <button
                type="button"
                onClick={onClose}
                className="px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-slate-800 hover:bg-slate-100 rounded-lg transition-colors"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={updateDevice.isPending}
                className="inline-flex items-center gap-2 px-5 py-2.5 bg-teal-500 text-white rounded-lg text-sm font-medium hover:bg-teal-600 transition-colors disabled:opacity-50"
              >
                {updateDevice.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
                {updateDevice.isPending ? 'Saving...' : 'Save Changes'}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  )
}
