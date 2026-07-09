import { useState, useMemo } from 'react'
import type { GroupedInventoryItem } from '@/types'
import {
  ChevronRight,
  ChevronDown,
  Package,
  Eye,
  AlertTriangle,
  Smartphone,
  Monitor,
  Headphones,
  Gamepad2,
  Laptop,
  Tv,
  Watch,
  Camera,
  Speaker,
  Box,
  TrendingUp,
  TrendingDown
} from 'lucide-react'
import { cn } from '@/lib/utils'

interface StockViewTableProps {
  data: GroupedInventoryItem[]
  isLoading?: boolean
  onViewDetails: (item: GroupedInventoryItem) => void
}

interface CategoryGroup {
  category_id: number
  category_name: string
  brands: BrandGroup[]
  totalInStock: number
  totalItems: number
}

interface BrandGroup {
  brand: string
  models: ModelGroup[]
  totalInStock: number
  totalItems: number
}

interface ModelGroup {
  model: string
  variants: GroupedInventoryItem[]
  totalInStock: number
  totalItems: number
}

// Get category icon and color based on name
const getCategoryStyle = (name: string) => {
  const lower = name.toLowerCase()
  if (lower.includes('phone') || lower.includes('mobile')) {
    return { icon: Smartphone, gradient: 'from-blue-500 to-indigo-600', bg: 'bg-blue-50', text: 'text-blue-700' }
  }
  if (lower.includes('laptop') || lower.includes('notebook')) {
    return { icon: Laptop, gradient: 'from-purple-500 to-pink-600', bg: 'bg-purple-50', text: 'text-purple-700' }
  }
  if (lower.includes('desktop') || lower.includes('computer') || lower.includes('pc')) {
    return { icon: Monitor, gradient: 'from-slate-500 to-slate-700', bg: 'bg-slate-50', text: 'text-slate-700' }
  }
  if (lower.includes('audio') || lower.includes('headphone') || lower.includes('earphone')) {
    return { icon: Headphones, gradient: 'from-orange-500 to-red-600', bg: 'bg-orange-50', text: 'text-orange-700' }
  }
  if (lower.includes('gaming') || lower.includes('console') || lower.includes('game')) {
    return { icon: Gamepad2, gradient: 'from-green-500 to-emerald-600', bg: 'bg-green-50', text: 'text-green-700' }
  }
  if (lower.includes('tv') || lower.includes('television') || lower.includes('display')) {
    return { icon: Tv, gradient: 'from-cyan-500 to-blue-600', bg: 'bg-cyan-50', text: 'text-cyan-700' }
  }
  if (lower.includes('watch') || lower.includes('wearable')) {
    return { icon: Watch, gradient: 'from-pink-500 to-rose-600', bg: 'bg-pink-50', text: 'text-pink-700' }
  }
  if (lower.includes('camera') || lower.includes('photo')) {
    return { icon: Camera, gradient: 'from-amber-500 to-orange-600', bg: 'bg-amber-50', text: 'text-amber-700' }
  }
  if (lower.includes('speaker') || lower.includes('sound')) {
    return { icon: Speaker, gradient: 'from-teal-500 to-cyan-600', bg: 'bg-teal-50', text: 'text-teal-700' }
  }
  return { icon: Box, gradient: 'from-gray-500 to-gray-700', bg: 'bg-gray-50', text: 'text-gray-700' }
}

// Get brand logo/color
const getBrandColor = (brand: string) => {
  const lower = brand.toLowerCase()
  if (lower === 'apple') return 'bg-gradient-to-r from-gray-800 to-gray-900 text-white'
  if (lower === 'samsung') return 'bg-gradient-to-r from-blue-600 to-blue-800 text-white'
  if (lower === 'google') return 'bg-gradient-to-r from-red-500 via-yellow-500 to-green-500 text-white'
  if (lower === 'sony') return 'bg-gradient-to-r from-black to-gray-800 text-white'
  if (lower === 'microsoft') return 'bg-gradient-to-r from-orange-500 to-red-600 text-white'
  if (lower === 'dell') return 'bg-gradient-to-r from-blue-700 to-blue-900 text-white'
  if (lower === 'hp') return 'bg-gradient-to-r from-blue-500 to-cyan-600 text-white'
  if (lower === 'lenovo') return 'bg-gradient-to-r from-red-600 to-red-800 text-white'
  if (lower === 'lg') return 'bg-gradient-to-r from-red-600 to-pink-600 text-white'
  if (lower === 'asus') return 'bg-gradient-to-r from-blue-600 to-indigo-700 text-white'
  if (lower === 'acer') return 'bg-gradient-to-r from-green-600 to-emerald-700 text-white'
  if (lower === 'xiaomi') return 'bg-gradient-to-r from-orange-500 to-orange-700 text-white'
  if (lower === 'huawei') return 'bg-gradient-to-r from-red-500 to-red-700 text-white'
  if (lower === 'oneplus') return 'bg-gradient-to-r from-red-600 to-rose-700 text-white'
  return 'bg-gradient-to-r from-slate-600 to-slate-800 text-white'
}

// Stock level indicator
const StockIndicator = ({ inStock, total }: { inStock: number; total: number }) => {
  const percentage = total > 0 ? (inStock / total) * 100 : 0
  const getColor = () => {
    if (inStock === 0) return 'bg-red-500'
    if (percentage < 30) return 'bg-amber-500'
    return 'bg-emerald-500'
  }

  return (
    <div className="flex items-center gap-2">
      <div className="w-20 h-2 bg-slate-200 rounded-full overflow-hidden">
        <div
          className={cn('h-full rounded-full transition-all duration-500', getColor())}
          style={{ width: `${Math.min(percentage, 100)}%` }}
        />
      </div>
      <span className={cn(
        'text-xs font-bold min-w-[3rem]',
        inStock === 0 ? 'text-red-600' : inStock < 5 ? 'text-amber-600' : 'text-emerald-600'
      )}>
        {inStock}/{total}
      </span>
    </div>
  )
}

export default function StockViewTable({ data, isLoading, onViewDetails }: StockViewTableProps) {
  const [expandedCategories, setExpandedCategories] = useState<Set<number>>(new Set())
  const [expandedBrands, setExpandedBrands] = useState<Set<string>>(new Set())
  const [expandedModels, setExpandedModels] = useState<Set<string>>(new Set())

  // Build hierarchical structure from flat data
  const hierarchy = useMemo(() => {
    const categoryMap = new Map<number, CategoryGroup>()

    data.forEach(item => {
      const catId = item.category_id
      const catName = item.category_name

      if (!categoryMap.has(catId)) {
        categoryMap.set(catId, {
          category_id: catId,
          category_name: catName,
          brands: [],
          totalInStock: 0,
          totalItems: 0,
        })
      }

      const category = categoryMap.get(catId)!
      category.totalInStock += item.in_stock
      category.totalItems += item.total

      let brandGroup = category.brands.find(b => b.brand === item.brand)
      if (!brandGroup) {
        brandGroup = {
          brand: item.brand,
          models: [],
          totalInStock: 0,
          totalItems: 0,
        }
        category.brands.push(brandGroup)
      }
      brandGroup.totalInStock += item.in_stock
      brandGroup.totalItems += item.total

      let modelGroup = brandGroup.models.find(m => m.model === item.model)
      if (!modelGroup) {
        modelGroup = {
          model: item.model,
          variants: [],
          totalInStock: 0,
          totalItems: 0,
        }
        brandGroup.models.push(modelGroup)
      }
      modelGroup.totalInStock += item.in_stock
      modelGroup.totalItems += item.total
      modelGroup.variants.push(item)
    })

    const categories = Array.from(categoryMap.values()).sort((a, b) =>
      a.category_name.localeCompare(b.category_name)
    )
    categories.forEach(cat => {
      cat.brands.sort((a, b) => a.brand.localeCompare(b.brand))
      cat.brands.forEach(brand => {
        brand.models.sort((a, b) => a.model.localeCompare(b.model))
        brand.models.forEach(model => {
          model.variants.sort((a, b) => (a.color || '').localeCompare(b.color || ''))
        })
      })
    })

    return categories
  }, [data])

  const toggleCategory = (catId: number) => {
    setExpandedCategories(prev => {
      const next = new Set(prev)
      if (next.has(catId)) next.delete(catId)
      else next.add(catId)
      return next
    })
  }

  const toggleBrand = (key: string) => {
    setExpandedBrands(prev => {
      const next = new Set(prev)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }

  const toggleModel = (key: string) => {
    setExpandedModels(prev => {
      const next = new Set(prev)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }

  if (isLoading) {
    return (
      <div className="flex flex-col items-center justify-center py-16">
        <div className="relative">
          <div className="animate-spin rounded-full h-12 w-12 border-4 border-blue-200 border-t-blue-600"></div>
          <Package className="absolute inset-0 m-auto h-5 w-5 text-blue-600" />
        </div>
        <p className="text-slate-500 mt-4 animate-pulse">Loading inventory...</p>
      </div>
    )
  }

  if (hierarchy.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center py-16 text-center">
        <div className="h-20 w-20 rounded-full bg-gradient-to-br from-slate-100 to-slate-200 flex items-center justify-center mb-4">
          <Package className="h-10 w-10 text-slate-400" />
        </div>
        <p className="text-slate-600 font-semibold text-lg">No inventory found</p>
        <p className="text-sm text-slate-400 mt-1 max-w-xs">
          Add devices to your inventory to see them organized by category, brand, and model
        </p>
      </div>
    )
  }

  return (
    <div className="p-4 space-y-3">
      {hierarchy.map(category => {
        const isCatExpanded = expandedCategories.has(category.category_id)
        const catStyle = getCategoryStyle(category.category_name)
        const CategoryIcon = catStyle.icon

        return (
          <div
            key={category.category_id}
            className={cn(
              'rounded-xl border-2 overflow-hidden transition-all duration-300',
              isCatExpanded ? 'border-slate-300 shadow-lg' : 'border-slate-200 hover:border-slate-300 hover:shadow-md'
            )}
          >
            {/* Category Header */}
            <button
              onClick={() => toggleCategory(category.category_id)}
              className={cn(
                'w-full flex items-center gap-4 p-4 transition-all duration-300 text-left',
                isCatExpanded ? 'bg-slate-50' : 'bg-white hover:bg-slate-50'
              )}
            >
              <div className={cn(
                'h-12 w-12 rounded-xl bg-gradient-to-br flex items-center justify-center shadow-lg transform transition-transform duration-300',
                catStyle.gradient,
                isCatExpanded && 'scale-110'
              )}>
                <CategoryIcon className="h-6 w-6 text-white" />
              </div>

              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                  <h3 className="font-bold text-slate-900 text-lg">{category.category_name}</h3>
                  <span className={cn(
                    'px-2 py-0.5 rounded-full text-xs font-medium',
                    catStyle.bg, catStyle.text
                  )}>
                    {category.brands.length} brand{category.brands.length !== 1 ? 's' : ''}
                  </span>
                </div>
                <div className="flex items-center gap-4 mt-1">
                  <StockIndicator inStock={category.totalInStock} total={category.totalItems} />
                  {category.totalInStock < 10 && category.totalInStock > 0 && (
                    <span className="flex items-center gap-1 text-xs text-amber-600 font-medium">
                      <TrendingDown className="h-3 w-3" />
                      Low stock
                    </span>
                  )}
                </div>
              </div>

              <div className={cn(
                'h-8 w-8 rounded-full flex items-center justify-center transition-all duration-300',
                isCatExpanded ? 'bg-slate-200 rotate-0' : 'bg-slate-100'
              )}>
                {isCatExpanded ? (
                  <ChevronDown className="h-5 w-5 text-slate-600" />
                ) : (
                  <ChevronRight className="h-5 w-5 text-slate-400" />
                )}
              </div>
            </button>

            {/* Brands within Category */}
            {isCatExpanded && (
              <div className="bg-slate-50 p-3 space-y-2 animate-in slide-in-from-top-2 duration-300">
                {category.brands.map(brand => {
                  const brandKey = `${category.category_id}-${brand.brand}`
                  const isBrandExpanded = expandedBrands.has(brandKey)

                  return (
                    <div
                      key={brandKey}
                      className={cn(
                        'rounded-lg overflow-hidden transition-all duration-300',
                        isBrandExpanded ? 'bg-white shadow-md' : 'bg-white/70 hover:bg-white hover:shadow-sm'
                      )}
                    >
                      {/* Brand Header */}
                      <button
                        onClick={() => toggleBrand(brandKey)}
                        className="w-full flex items-center gap-3 p-3 text-left"
                      >
                        <div className={cn(
                          'px-3 py-1.5 rounded-lg text-xs font-bold shadow-sm',
                          getBrandColor(brand.brand)
                        )}>
                          {brand.brand}
                        </div>

                        <div className="flex-1 flex items-center gap-3">
                          <span className="text-sm text-slate-500">
                            {brand.models.length} model{brand.models.length !== 1 ? 's' : ''}
                          </span>
                          <StockIndicator inStock={brand.totalInStock} total={brand.totalItems} />
                        </div>

                        <span className={cn(
                          'transition-transform duration-300',
                          isBrandExpanded && 'rotate-90'
                        )}>
                          <ChevronRight className="h-4 w-4 text-slate-400" />
                        </span>
                      </button>

                      {/* Models within Brand */}
                      {isBrandExpanded && (
                        <div className="px-3 pb-3 space-y-2 animate-in slide-in-from-top-1 duration-200">
                          {brand.models.map(model => {
                            const modelKey = `${brandKey}-${model.model}`
                            const isModelExpanded = expandedModels.has(modelKey)
                            const hasMultipleVariants = model.variants.length > 1

                            return (
                              <div key={modelKey} className="rounded-lg border border-slate-200 overflow-hidden">
                                {/* Model Header */}
                                <div
                                  onClick={() => hasMultipleVariants && toggleModel(modelKey)}
                                  className={cn(
                                    'flex items-center gap-3 px-3 py-2.5 bg-slate-50',
                                    hasMultipleVariants && 'cursor-pointer hover:bg-slate-100'
                                  )}
                                >
                                  {hasMultipleVariants && (
                                    <span className={cn(
                                      'transition-transform duration-200',
                                      isModelExpanded && 'rotate-90'
                                    )}>
                                      <ChevronRight className="h-4 w-4 text-slate-400" />
                                    </span>
                                  )}

                                  <div className="flex-1">
                                    <p className="font-medium text-slate-800">{model.model}</p>
                                    {hasMultipleVariants && (
                                      <p className="text-xs text-slate-500">
                                        {model.variants.length} color variant{model.variants.length !== 1 ? 's' : ''}
                                      </p>
                                    )}
                                  </div>

                                  <div className="flex items-center gap-3">
                                    {model.totalInStock < 5 && model.totalInStock > 0 && (
                                      <span className="flex items-center gap-1 px-2 py-1 bg-amber-100 text-amber-700 rounded-full text-xs font-medium">
                                        <AlertTriangle className="h-3 w-3" />
                                        Low
                                      </span>
                                    )}
                                    {model.totalInStock === 0 && (
                                      <span className="px-2 py-1 bg-red-100 text-red-700 rounded-full text-xs font-medium">
                                        Out of Stock
                                      </span>
                                    )}
                                    <div className={cn(
                                      'px-3 py-1 rounded-full text-sm font-bold',
                                      model.totalInStock > 4 ? 'bg-emerald-100 text-emerald-700' :
                                      model.totalInStock > 0 ? 'bg-amber-100 text-amber-700' :
                                      'bg-red-100 text-red-700'
                                    )}>
                                      {model.totalInStock} in stock
                                    </div>
                                    {!hasMultipleVariants && (
                                      <button
                                        onClick={(e) => {
                                          e.stopPropagation()
                                          onViewDetails(model.variants[0])
                                        }}
                                        className="flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded-lg hover:bg-blue-700 transition-colors shadow-sm"
                                      >
                                        <Eye className="h-3.5 w-3.5" />
                                        View
                                      </button>
                                    )}
                                  </div>
                                </div>

                                {/* Variants (Colors) */}
                                {isModelExpanded && hasMultipleVariants && (
                                  <div className="divide-y divide-slate-100 animate-in slide-in-from-top-1 duration-200">
                                    {model.variants.map((variant, idx) => (
                                      <div
                                        key={idx}
                                        className="flex items-center gap-3 px-4 py-3 bg-white hover:bg-blue-50/50 transition-colors"
                                      >
                                        {/* Color indicator */}
                                        <div className="flex items-center gap-2 min-w-[120px]">
                                          <div
                                            className="h-4 w-4 rounded-full border-2 border-white shadow-sm"
                                            style={{
                                              backgroundColor: variant.color?.toLowerCase() === 'white' ? '#f1f5f9' :
                                                variant.color?.toLowerCase() === 'black' ? '#1e293b' :
                                                variant.color?.toLowerCase() === 'gold' ? '#f59e0b' :
                                                variant.color?.toLowerCase() === 'silver' ? '#94a3b8' :
                                                variant.color?.toLowerCase() === 'blue' ? '#3b82f6' :
                                                variant.color?.toLowerCase() === 'red' ? '#ef4444' :
                                                variant.color?.toLowerCase() === 'green' ? '#22c55e' :
                                                variant.color?.toLowerCase() === 'space gray' ? '#4b5563' :
                                                '#9ca3af'
                                            }}
                                          />
                                          <span className="text-sm font-medium text-slate-700">
                                            {variant.color || 'Default'}
                                          </span>
                                        </div>

                                        {/* Stock badges */}
                                        <div className="flex-1 flex flex-wrap gap-1.5">
                                          {variant.in_stock > 0 && (
                                            <span className="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs font-semibold bg-emerald-100 text-emerald-700">
                                              <TrendingUp className="h-3 w-3" />
                                              {variant.in_stock} In Stock
                                            </span>
                                          )}
                                          {variant.reserved > 0 && (
                                            <span className="px-2 py-1 rounded-md text-xs font-semibold bg-amber-100 text-amber-700">
                                              {variant.reserved} Reserved
                                            </span>
                                          )}
                                          {variant.sold > 0 && (
                                            <span className="px-2 py-1 rounded-md text-xs font-semibold bg-blue-100 text-blue-700">
                                              {variant.sold} Sold
                                            </span>
                                          )}
                                          {variant.returned > 0 && (
                                            <span className="px-2 py-1 rounded-md text-xs font-semibold bg-purple-100 text-purple-700">
                                              {variant.returned} Returned
                                            </span>
                                          )}
                                          {variant.faulty > 0 && (
                                            <span className="px-2 py-1 rounded-md text-xs font-semibold bg-red-100 text-red-700">
                                              {variant.faulty} Faulty
                                            </span>
                                          )}
                                        </div>

                                        {/* Price & Action */}
                                        <div className="flex items-center gap-3">
                                          {variant.avg_price > 0 && (
                                            <span className="text-sm font-bold text-slate-700">
                                              ${variant.avg_price.toLocaleString('en-US', { minimumFractionDigits: 2 })}
                                            </span>
                                          )}
                                          <button
                                            onClick={() => onViewDetails(variant)}
                                            className="flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded-lg hover:bg-blue-700 transition-colors shadow-sm"
                                          >
                                            <Eye className="h-3.5 w-3.5" />
                                            View
                                          </button>
                                        </div>
                                      </div>
                                    ))}
                                  </div>
                                )}
                              </div>
                            )
                          })}
                        </div>
                      )}
                    </div>
                  )
                })}
              </div>
            )}
          </div>
        )
      })}
    </div>
  )
}
