import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  ArrowRight, Boxes, ChevronLeft, ChevronRight, CircleCheck, Layers3, Loader2,
  MapPin, Package, PackageSearch, Search, Tag, X,
} from 'lucide-react'
import apiClient from '@/api/client'
import Button from '@/components/ui/Button'

interface Page<T> {
  data: T[]
  current_page: number
  last_page: number
  total: number
}

interface PriceTier {
  price: number | null
  quantity: number
}

interface Product {
  id: number
  brand: string
  model: string
  category: string | null
  available_units: number
  held_units: number
  total_units: number
  price_tiers: PriceTier[]
}

interface StockOverview {
  branch: { name: string; code: string }
  summary: { products: number; available_units: number; held_units: number }
  categories: { id: number; name: string }[]
  products: Page<Product>
}

interface Unit {
  id: number
  serial_number: string
  device_code: string | null
  color: string | null
  description: string | null
  owner: string | null
  batch: string | null
  status: string | null
  device_status: string | null
  hold: string | null
  available: boolean
  selling_price: number | null
}

const formatAmount = new Intl.NumberFormat('en-MW', { maximumFractionDigits: 2 })
const formatPrice = (price: number) => `MWK ${formatAmount.format(price)}`
const fieldStyle = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100'

function StockBadge({ available }: { available: number }) {
  return <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${available > 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600'}`}>
    <span className={`h-1.5 w-1.5 rounded-full ${available > 0 ? 'bg-emerald-600' : 'bg-slate-400'}`} aria-hidden="true" />
    {available > 0 ? `${available} available` : 'Out of stock'}
  </span>
}

function PriceSummary({ product }: { product: Product }) {
  const prices = product.price_tiers.flatMap(({ price }) => price === null ? [] : [price])
  const unpriced = product.price_tiers.find(({ price }) => price === null)?.quantity ?? 0
  const first = prices[0]
  const last = prices.at(-1)
  return (
    <div>
      <p className="flex items-center gap-1.5 text-xs font-medium text-slate-600">
        <Tag className="h-3.5 w-3.5" aria-hidden="true" /> Selling price before VAT
      </p>
      <p className="mt-1 font-semibold text-slate-900">
        {first === undefined || last === undefined
          ? product.available_units ? 'Price not set' : '—'
          : first === last ? formatPrice(first) : `${formatPrice(first)}–${formatAmount.format(last)}`}
      </p>
      {first !== undefined && unpriced > 0 && <p className="mt-1 text-xs text-amber-800">{unpriced} without a price</p>}
    </div>
  )
}

function ProductCard({ product, onOpen }: { product: Product; onOpen: () => void }) {
  return (
    <article className="flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition-shadow hover:shadow-md">
      <div className="flex flex-1 flex-col gap-4 p-5">
        <div className="flex items-start justify-between gap-2">
          <span className="grid h-11 w-11 place-items-center rounded-xl bg-teal-50 text-teal-700">
            <Package className="h-5 w-5" aria-hidden="true" />
          </span>
          <StockBadge available={product.available_units} />
        </div>
        <div>
          <p className="text-xs font-semibold text-teal-800">{product.category ?? 'Uncategorized'}</p>
          <h2 className="mt-1 break-words text-lg font-bold leading-snug text-slate-900">{product.brand} {product.model}</h2>
        </div>
        <div className="mt-auto rounded-xl bg-slate-50 p-3">
          <PriceSummary product={product} />
        </div>
      </div>
      <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-5 py-3">
        <span className="text-xs font-medium text-slate-600">{product.held_units ? `${product.held_units} held · ` : ''}{product.total_units} units tracked</span>
        <Button type="button" variant="secondary" size="sm" onClick={onOpen} className="gap-1.5" aria-label={`View stock for ${product.brand} ${product.model}`}>
          View stock <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
        </Button>
      </div>
    </article>
  )
}

function Pagination({ page, onChange }: { page: Page<unknown>; onChange: (page: number) => void }) {
  if (page.last_page <= 1) return null

  return (
    <nav className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3" aria-label="Pagination">
      <span className="text-sm text-slate-600">Page {page.current_page} of {page.last_page}</span>
      <div className="flex gap-2">
        <Button type="button" variant="secondary" size="sm" className="gap-1" disabled={page.current_page <= 1} onClick={() => onChange(page.current_page - 1)}>
          <ChevronLeft className="h-4 w-4" aria-hidden="true" /> Previous
        </Button>
        <Button type="button" variant="secondary" size="sm" className="gap-1" disabled={page.current_page >= page.last_page} onClick={() => onChange(page.current_page + 1)}>
          Next <ChevronRight className="h-4 w-4" aria-hidden="true" />
        </Button>
      </div>
    </nav>
  )
}

function ProductDetails({ product, onClose }: { product: Product; onClose: () => void }) {
  const dialog = useRef<HTMLDialogElement>(null)
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const units = useQuery({
    queryKey: ['customer-service-product-units', product.id, page, search],
    queryFn: async () => (await apiClient.get<{ data: Page<Unit> }>(`/customer-service/products/${product.id}/units`, {
      params: { page, search: search.trim() || undefined },
    })).data.data,
  })

  useEffect(() => {
    if (dialog.current && !dialog.current.open) dialog.current.showModal()
  }, [])

  return (
    <dialog ref={dialog} onClose={onClose} aria-labelledby="product-stock-title"
      className="fixed inset-y-0 right-0 left-auto m-0 h-dvh max-h-dvh w-full max-w-2xl overflow-y-auto bg-white text-slate-900 shadow-2xl backdrop:bg-slate-950/45">
      <div className="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-200 bg-white px-5 py-4 sm:px-7">
        <div>
          <p className="text-xs font-semibold text-teal-800">{product.category ?? 'Uncategorized'}</p>
          <h2 id="product-stock-title" className="mt-1 text-xl font-bold">{product.brand} {product.model}</h2>
        </div>
        <Button type="button" variant="secondary" size="sm" className="gap-1.5" onClick={() => dialog.current?.close()}>
          <X className="h-4 w-4" aria-hidden="true" /> Close
        </Button>
      </div>
      <div className="space-y-5 p-5 sm:p-7">
        <section className="space-y-3 rounded-2xl border border-teal-100 bg-teal-50/60 p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h3 className="font-semibold">Available stock and prices</h3>
            <StockBadge available={product.available_units} />
          </div>
          {product.price_tiers.length ? (
            <ul className="divide-y divide-teal-100 text-sm">
              {product.price_tiers.map((tier) => (
                <li key={tier.price ?? 'unpriced'} className="flex justify-between gap-3 py-2">
                  <span className="font-medium">{tier.price === null ? 'Price not set' : formatPrice(tier.price)}</span>
                  <span className="text-slate-600">{tier.quantity} {tier.quantity === 1 ? 'unit' : 'units'}</span>
                </li>
              ))}
            </ul>
          ) : <p className="text-sm text-slate-600">There are no units available for sale at this branch.</p>}
        </section>

        <section className="space-y-3">
          <h3 className="font-semibold">Individual units</h3>
          <label className="relative block">
            <Search className="pointer-events-none absolute left-3 top-3.5 h-4 w-4 text-slate-500" aria-hidden="true" />
            <input aria-label="Find a unit" className={`${fieldStyle} pl-9`} value={search} maxLength={100}
              placeholder="Find by serial, device code, or colour"
              onChange={(event) => { setSearch(event.target.value); setPage(1) }} />
          </label>
          {units.isPending && <p role="status" className="text-sm text-slate-600">Loading units…</p>}
          {units.isError && <div role="alert" className="flex items-center justify-between gap-2 rounded-xl bg-red-50 p-3 text-sm text-red-800">
            Couldn’t load these units. <Button type="button" variant="secondary" size="sm" onClick={() => void units.refetch()}>Retry</Button>
          </div>}
          {units.data?.data.length === 0 && <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-600">No units match your search.</p>}
          <ul className="space-y-2">
            {units.data?.data.map((unit) => (
              <li key={unit.id} className="rounded-xl border border-slate-200">
                <details className="group">
                  <summary className="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 rounded-xl p-3 hover:bg-slate-50 focus-visible:outline-teal-600 [&::-webkit-details-marker]:hidden">
                    <span className="min-w-0">
                      <span className="block break-all font-mono text-sm font-semibold">{unit.serial_number}</span>
                      <span className="text-xs text-slate-600">{unit.color ?? 'Colour unspecified'}{unit.device_code && ` · ${unit.device_code}`}</span>
                    </span>
                    <span className="flex items-center gap-2 text-xs">
                      <span className={`rounded-full px-2 py-1 font-semibold ${unit.available ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-700'}`}>
                        {unit.hold ?? unit.status ?? 'Status unknown'}
                      </span>
                      {unit.available && <span className="font-semibold">{unit.selling_price === null ? 'Price not set' : formatPrice(unit.selling_price)}</span>}
                      <ChevronRight className="h-4 w-4 shrink-0 text-slate-500 group-open:rotate-90" aria-hidden="true" />
                    </span>
                  </summary>
                  <dl className="grid gap-3 border-t border-slate-100 px-3 py-3 text-sm sm:grid-cols-2">
                    <div><dt className="text-xs text-slate-600">Device status</dt><dd>{unit.device_status ?? '—'}</dd></div>
                    <div><dt className="text-xs text-slate-600">Stock status</dt><dd>{unit.status ?? '—'}</dd></div>
                    <div><dt className="text-xs text-slate-600">Batch</dt><dd>{unit.batch ?? '—'}</dd></div>
                    <div><dt className="text-xs text-slate-600">Owner</dt><dd>{unit.owner ?? '—'}</dd></div>
                    {unit.description && <div className="sm:col-span-2"><dt className="text-xs text-slate-600">Description</dt><dd className="whitespace-pre-wrap">{unit.description}</dd></div>}
                  </dl>
                </details>
              </li>
            ))}
          </ul>
          {units.data && <Pagination page={units.data} onChange={setPage} />}
        </section>
      </div>
    </dialog>
  )
}

export default function CustomerServiceProductsPage() {
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState('')
  const [availability, setAvailability] = useState('')
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<Product | null>(null)
  const stock = useQuery({
    queryKey: ['customer-service-products', page, search, category, availability],
    queryFn: async () => (await apiClient.get<{ data: StockOverview }>('/customer-service/products', {
      params: { page, search: search.trim() || undefined, category_id: category || undefined, availability: availability || undefined },
    })).data.data,
    placeholderData: (previous) => previous,
  })
  const overview = stock.data
  const hasFilters = Boolean(search || category || availability)
  const clear = () => { setSearch(''); setCategory(''); setAvailability(''); setPage(1) }

  return (
    <div className="mx-auto max-w-7xl space-y-5">
      <header className="relative isolate overflow-hidden rounded-3xl bg-gradient-to-br from-slate-950 via-teal-950 to-teal-800 p-6 text-white sm:p-8">
        <Boxes className="pointer-events-none absolute -right-10 -top-14 -z-10 h-80 w-80 rotate-12 text-teal-200/10" strokeWidth={0.7} aria-hidden="true" />
        <p className="mb-3 inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1.5 text-xs font-semibold text-teal-50">
          <MapPin className="h-3.5 w-3.5" aria-hidden="true" /> {overview?.branch.name ?? 'Your branch'}{overview?.branch.code && ` · ${overview.branch.code}`}
        </p>
        <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">Products</h1>
        <p className="mt-2 max-w-lg text-sm text-teal-50/90">Browse your branch stock, check selling prices, and look up individual devices.</p>
        <div className="mt-6 grid grid-cols-3 gap-2 sm:max-w-2xl sm:gap-3">
          {[
            { label: 'Products', value: overview?.summary.products, icon: Layers3 },
            { label: 'Available units', value: overview?.summary.available_units, icon: CircleCheck },
            { label: 'Held units', value: overview?.summary.held_units, icon: Boxes },
          ].map(({ label, value, icon: Icon }) => (
            <div key={label} className="rounded-xl border border-white/15 bg-white/10 p-2.5 sm:p-3.5">
              <div className="flex items-center gap-2 text-teal-50">
                <Icon className="hidden h-4 w-4 shrink-0 sm:block" aria-hidden="true" />
                <span className="text-xs font-medium sm:text-sm">{label}</span>
              </div>
              <p className="mt-1 text-xl font-bold tabular-nums sm:text-2xl">{value === undefined ? '—' : formatAmount.format(value)}</p>
            </div>
          ))}
        </div>
      </header>

      <section aria-label="Product filters" className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]">
        <label className="block space-y-1.5 text-sm font-semibold text-slate-800">
          <span>Find a product</span>
          <span className="relative block">
            <Search className="pointer-events-none absolute left-3 top-3.5 h-4 w-4 text-slate-500" aria-hidden="true" />
            <input className={`${fieldStyle} pl-9`} type="search" placeholder="Brand, model, serial number, or code" value={search} maxLength={100}
              onChange={(event) => { setSearch(event.target.value); setPage(1) }} />
          </span>
        </label>
        <label className="block space-y-1.5 text-sm font-semibold text-slate-800">
          <span>Category</span>
          <select className={fieldStyle} value={category} onChange={(event) => { setCategory(event.target.value); setPage(1) }}>
            <option value="">All categories</option>
            {overview?.categories.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}
          </select>
        </label>
        <label className="block space-y-1.5 text-sm font-semibold text-slate-800">
          <span>Availability</span>
          <select className={fieldStyle} value={availability} onChange={(event) => { setAvailability(event.target.value); setPage(1) }}>
            <option value="">All products</option>
            <option value="available">Available for sale</option>
            <option value="unavailable">Out of stock</option>
          </select>
        </label>
      </section>

      <div className="flex min-h-8 flex-wrap items-center justify-between gap-2">
        <p role="status" className="flex items-center gap-2 text-sm font-medium text-slate-600">
          {stock.isFetching && <Loader2 className="h-4 w-4 animate-spin text-teal-700" aria-hidden="true" />}
          {stock.isPlaceholderData || stock.isPending ? 'Loading products…' : overview ? `${overview.products.total} ${overview.products.total === 1 ? 'product' : 'products'}` : ''}
        </p>
        {hasFilters && <Button type="button" variant="secondary" size="sm" className="gap-1.5" onClick={clear}>
          <X className="h-4 w-4" aria-hidden="true" /> Clear filters
        </Button>}
      </div>

      {stock.isError ? (
        <div role="alert" className="flex items-center justify-between gap-3 rounded-xl bg-red-50 p-4 text-sm text-red-800">
          Couldn’t load branch products. <Button type="button" variant="secondary" size="sm" onClick={() => void stock.refetch()}>Retry</Button>
        </div>
      ) : overview?.products.data.length === 0 && !stock.isFetching ? (
        <div className="rounded-2xl border border-dashed border-slate-300 bg-white px-5 py-12 text-center">
          <PackageSearch className="mx-auto mb-3 h-8 w-8 text-teal-700" aria-hidden="true" />
          <p className="font-semibold text-slate-900">{hasFilters ? 'No matching products' : 'No products at this branch yet'}</p>
          {hasFilters && <p className="mt-1 text-sm text-slate-600">Try another search or clear the filters.</p>}
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-busy={stock.isFetching}>
          {overview?.products.data.map((product) => <ProductCard key={product.id} product={product} onOpen={() => setSelected(product)} />)}
        </div>
      )}
      {overview && !stock.isPlaceholderData && !stock.isError && <Pagination page={overview.products} onChange={setPage} />}
      {selected && <ProductDetails key={selected.id} product={selected} onClose={() => setSelected(null)} />}
    </div>
  )
}
