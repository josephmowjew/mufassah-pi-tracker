import { malawiCalendarDate } from '@/lib/datetime'
import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Loader2, DollarSign, Smartphone, Users, TrendingUp,
  Wifi, CheckCircle, Package, Building2, FileSpreadsheet, Filter,
} from 'lucide-react'
import apiClient from '@/api/client'
import { adminApi, reportQuery, type ReportFilters, type ReportType } from '@/api/admin'
import { StaffTimeReportSection } from '@/components/reports/StaffTimeReportSection'
import { useAuthStore } from '@/stores/auth-store'

interface ReportMeta {
  from: string | null
  to: string | null
  branch_id: number | null
  all_branches: boolean
  scope_label: string
  period_label: string
}

interface SalesData {
  filters: ReportMeta
  summary: {
    pos_sales_count: number
    pos_revenue: number
    pos_gross_profit: number
    administrative_sales_count: number
    administrative_sale_value: number
    administrative_sale_profit: number
    combined_revenue: number
    combined_gross_profit: number
    average_sale_value: number
    units_sold: number
  }
  by_branch: Array<{
    branch_id: number | null
    branch_name: string
    branch_code: string | null
    pos_sales_count: number
    pos_revenue: number
    pos_gross_profit: number
    administrative_sale_value: number
    combined_revenue: number
    combined_gross_profit: number
  }>
  by_product: Array<{
    product: string
    category: string
    units_sold: number
    revenue: number
    gross_profit: number
  }>
  by_day: Array<{
    date: string
    pos_sales_count: number
    pos_revenue: number
    administrative_sale_value: number
    combined_revenue: number
  }>
  recent_sales: Array<{
    sale_number: string
    sale_date: string | null
    branch_name: string
    customer_name: string
    total_amount: number
    gross_profit: number
    payment_method: string
  }>
}

interface RevenueData {
  summary: {
    total_revenue: number
    total_transactions: number
    average_transaction: number
  }
  by_period: Array<{ period: string; transactions: number; revenue: number }>
  by_method: Record<string, { count: number; total: number; label?: string }>
  by_plan: Record<string, { count: number; total: number }>
}

interface DevicesData {
  summary: {
    total: number
    active: number
    stolen: number
    recovered: number
  }
  by_status: Record<string, number>
  by_category: Record<string, number>
  registration_trend: Record<string, number>
  iot_coverage: {
    with_module: number
    without_module: number
    percentage: number
  }
}

interface TheftData {
  summary: {
    total: number
    by_status: Record<string, number>
    recovered: number
    recovery_rate: number
    average_resolution_days: number | null
  }
  by_month: Array<{ month: string; total: number; recovered: number }>
}

interface UsersData {
  summary: {
    total: number
    verified: number
    with_active_subscription: number
    with_devices: number
  }
  by_role: Record<string, number>
  registration_trend: Record<string, number>
}

interface BranchOption {
  id: number
  name: string
  code?: string | null
}

interface StockData {
  filters: ReportMeta
  summary: {
    current_total: number
    current_in_stock: number
    current_reserved: number
    current_sold: number
    current_returned: number
    current_faulty: number
    received_in_range: number
    sold_in_range: number
    stock_value: number
    pos_revenue?: number
    pos_gross_profit?: number
    administrative_sale_value?: number
    administrative_sale_profit?: number
    combined_inventory_realized_value?: number
    combined_gross_profit_loss?: number
  }
  by_branch: Array<{
    branch_id: number
    branch_name: string
    branch_code?: string | null
    current_total: number
    current_in_stock: number
    current_reserved: number
    current_sold: number
    current_faulty: number
    received_in_range: number
    sold_in_range: number
  }>
  by_product: Array<{
    category: string
    brand: string | null
    model: string | null
    color: string | null
    current_total: number
    current_in_stock: number
    current_reserved: number
    current_sold: number
    current_faulty: number
    total_cost: number
  }>
  by_batch: Array<{
    id: number
    batch_number: string
    branch_name: string | null
    category: string | null
    product: string
    purchase_date: string | null
    quantity_received: number
    in_stock: number
    reserved: number
    sold: number
    sold_in_range: number
    buying_price: number
    target_selling_price: number
    total_cost: number
    realized_revenue: number
    realized_gross_profit: number
    pos_revenue?: number
    pos_gross_profit?: number
    administrative_sale_value?: number
    administrative_sale_profit?: number
    combined_inventory_realized_value?: number
    combined_gross_profit_loss?: number
  }>
}

const EXPORT_OPTIONS: Array<{ value: ReportType; label: string }> = [
  { value: 'sales', label: 'Sales report' },
  { value: 'revenue', label: 'Revenue report' },
  { value: 'stock', label: 'Stock report' },
  { value: 'devices', label: 'Device report' },
  { value: 'theft', label: 'Theft report' },
  { value: 'subscriptions', label: 'Subscription report' },
  { value: 'users', label: 'User report' },
  { value: 'staff_time', label: 'Staff time report' },
]

/** Local-time `YYYY-MM-DD`; `toISOString()` would shift the day across UTC. */
function toIsoDate(date: Date): string {
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${date.getFullYear()}-${month}-${day}`
}

function monthRange(offset: number): { from: string; to: string } {
  const now = malawiCalendarDate()
  return {
    from: toIsoDate(new Date(now.getFullYear(), now.getMonth() + offset, 1)),
    to: toIsoDate(new Date(now.getFullYear(), now.getMonth() + offset + 1, 0)),
  }
}

function yearRange(): { from: string; to: string } {
  const year = malawiCalendarDate().getFullYear()
  return { from: `${year}-01-01`, to: `${year}-12-31` }
}

function quarterRange(): { from: string; to: string } {
  const now = malawiCalendarDate()
  const startMonth = Math.floor(now.getMonth() / 3) * 3
  return {
    from: toIsoDate(new Date(now.getFullYear(), startMonth, 1)),
    to: toIsoDate(new Date(now.getFullYear(), startMonth + 3, 0)),
  }
}

/** A failed download still arrives as a Blob, so unwrap the JSON error envelope. */
async function describeExportError(error: unknown): Promise<string> {
  const data = (error as { response?: { data?: unknown } })?.response?.data

  if (data instanceof Blob) {
    try {
      const payload = JSON.parse(await data.text()) as {
        message?: string
        errors?: Record<string, string[]>
      }
      const first = payload?.errors ? Object.values(payload.errors).flat()[0] : undefined
      if (first) return first
      if (payload?.message) return payload.message
    } catch {
      // Not a JSON body — fall through to the generic message.
    }
  }

  if (error instanceof Error && error.message) return error.message

  return 'Export failed. Please try again.'
}

export default function AdminReportsPage() {
  const isSuperAdmin = useAuthStore((state) => state.isSuperAdmin())

  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [branchId, setBranchId] = useState('')
  const [groupBy, setGroupBy] = useState<'day' | 'week' | 'month' | 'year'>('month')

  const [exportType, setExportType] = useState<ReportType>('sales')
  const [exporting, setExporting] = useState(false)
  const [exportError, setExportError] = useState<string | null>(null)

  const filters = useMemo<ReportFilters>(
    () => ({
      from: from || undefined,
      to: to || undefined,
      branch_id: branchId || undefined,
      // An explicit global request; the server ignores it for branch-scoped roles.
      all_branches: isSuperAdmin && !branchId ? true : undefined,
      group_by: groupBy,
    }),
    [from, to, branchId, groupBy, isSuperAdmin]
  )

  const query = reportQuery(filters)

  const { data: salesData, isLoading: salesLoading } = useQuery({
    queryKey: ['admin-reports-sales', query],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: SalesData }>(`/admin/reports/sales?${query}`)
      return response.data.data
    },
  })

  const { data: revenueData, isLoading: revenueLoading } = useQuery({
    queryKey: ['admin-reports-revenue', query],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: RevenueData }>(`/admin/reports/revenue?${query}`)
      return response.data.data
    },
  })

  const { data: devicesData, isLoading: devicesLoading } = useQuery({
    queryKey: ['admin-reports-devices', query],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: DevicesData }>(`/admin/reports/devices?${query}`)
      return response.data.data
    },
  })

  const { data: theftData, isLoading: theftLoading } = useQuery({
    queryKey: ['admin-reports-theft', query],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: TheftData }>(`/admin/reports/theft?${query}`)
      return response.data.data
    },
  })

  const { data: usersData, isLoading: usersLoading } = useQuery({
    queryKey: ['admin-reports-users', query],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: UsersData }>(`/admin/reports/users?${query}`)
      return response.data.data
    },
  })

  const { data: branches } = useQuery({
    queryKey: ['branches'],
    queryFn: async () => {
      const response = await apiClient.get<{ data: BranchOption[] }>('/admin/branches')
      return response.data.data
    },
    enabled: isSuperAdmin,
  })

  const { data: stockData, isLoading: stockLoading } = useQuery({
    queryKey: ['admin-reports-stock', query],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: StockData }>(`/admin/reports/stock?${query}`)
      return response.data.data
    },
  })

  const formatCurrency = (amount: number | null | undefined) => {
    return `MWK ${Number(amount || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}`
  }

  const formatResolutionDays = (days: number | null | undefined) => {
    if (typeof days !== 'number' || Number.isNaN(days)) return 'N/A'
    return `${days} ${days === 1 ? 'day' : 'days'}`
  }

  const isLoading = salesLoading || revenueLoading || devicesLoading || theftLoading || usersLoading || stockLoading

  const applyPreset = (range: { from: string; to: string } | null) => {
    setFrom(range?.from ?? '')
    setTo(range?.to ?? '')
  }

  const handleExport = async () => {
    setExporting(true)
    setExportError(null)

    try {
      const { blob, filename } = await adminApi.exportReport(exportType, filters)

      // A failed request can still resolve as a Blob; detect the JSON envelope.
      if (blob.type.includes('json')) {
        throw new Error(await describeExportError({ response: { data: blob } }))
      }

      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = filename
      document.body.appendChild(link)
      link.click()
      link.remove()
      URL.revokeObjectURL(url)
    } catch (error) {
      setExportError(await describeExportError(error))
    } finally {
      setExporting(false)
    }
  }

  const scopeLabel = salesData?.filters.scope_label ?? stockData?.filters.scope_label
  const periodLabel = salesData?.filters.period_label ?? stockData?.filters.period_label

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            Reports &amp; Analytics
          </h1>
          <p className="text-slate-500 text-sm mt-1">
            {scopeLabel && periodLabel
              ? `${scopeLabel} · ${periodLabel}`
              : 'Comprehensive overview of system metrics'}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <select
            aria-label="Report to export"
            className="px-4 py-2.5 rounded-lg border border-slate-200 bg-white text-sm text-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors"
            value={exportType}
            onChange={(event) => setExportType(event.target.value as ReportType)}
          >
            {EXPORT_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>{option.label}</option>
            ))}
          </select>
          <button
            type="button"
            onClick={handleExport}
            disabled={exporting}
            className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 disabled:opacity-60 disabled:cursor-not-allowed transition-colors"
          >
            {exporting ? <Loader2 className="h-4 w-4 animate-spin" /> : <FileSpreadsheet className="h-4 w-4" />}
            {exporting ? 'Preparing…' : 'Export to Excel'}
          </button>
        </div>
      </div>

      {exportError && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {exportError}
        </div>
      )}

      {/* Filters */}
      <div className="bg-white rounded-xl border border-slate-200/60 shadow-sm p-5">
        <div className="flex items-center gap-2 mb-4">
          <Filter className="h-4 w-4 text-slate-500" />
          <h2 className="text-sm font-semibold text-slate-700">Report filters</h2>
          <span className="text-xs text-slate-400">Applies to every section and to the export</span>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <div>
            <label className="block text-xs font-medium text-slate-500 mb-1">From</label>
            <input
              type="date"
              value={from}
              onChange={(event) => setFrom(event.target.value)}
              className="w-full px-3 py-2 rounded-lg border border-slate-200 bg-white text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
            />
          </div>
          <div>
            <label className="block text-xs font-medium text-slate-500 mb-1">To</label>
            <input
              type="date"
              value={to}
              onChange={(event) => setTo(event.target.value)}
              className="w-full px-3 py-2 rounded-lg border border-slate-200 bg-white text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
            />
          </div>
          {isSuperAdmin ? (
            <div>
              <label className="block text-xs font-medium text-slate-500 mb-1">Branch</label>
              <select
                value={branchId}
                onChange={(event) => setBranchId(event.target.value)}
                className="w-full px-3 py-2 rounded-lg border border-slate-200 bg-white text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
              >
                <option value="">All branches</option>
                {branches?.map((branch) => (
                  <option key={branch.id} value={branch.id}>
                    {branch.name}{branch.code ? ` (${branch.code})` : ''}
                  </option>
                ))}
              </select>
            </div>
          ) : (
            <div>
              <label className="block text-xs font-medium text-slate-500 mb-1">Branch</label>
              <div className="w-full px-3 py-2 rounded-lg border border-slate-200 bg-slate-50 text-sm text-slate-500">
                Your assigned branch
              </div>
            </div>
          )}
          <div>
            <label className="block text-xs font-medium text-slate-500 mb-1">Group by</label>
            <select
              value={groupBy}
              onChange={(event) => setGroupBy(event.target.value as 'day' | 'week' | 'month' | 'year')}
              className="w-full px-3 py-2 rounded-lg border border-slate-200 bg-white text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
            >
              <option value="day">Day</option>
              <option value="week">Week</option>
              <option value="month">Month</option>
              <option value="year">Year</option>
            </select>
          </div>
        </div>

        <div className="flex flex-wrap gap-2 mt-4">
          {[
            { label: 'This month', range: () => monthRange(0) },
            { label: 'Last month', range: () => monthRange(-1) },
            { label: 'This quarter', range: quarterRange },
            { label: 'This year', range: yearRange },
          ].map((preset) => (
            <button
              key={preset.label}
              type="button"
              onClick={() => applyPreset(preset.range())}
              className="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-600 text-xs font-medium hover:bg-slate-200 transition-colors"
            >
              {preset.label}
            </button>
          ))}
          <button
            type="button"
            onClick={() => applyPreset(null)}
            className="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-600 text-xs font-medium hover:bg-slate-200 transition-colors"
          >
            All time
          </button>
        </div>
      </div>

      {isLoading ? (
        <div className="flex items-center justify-center py-24">
          <Loader2 className="h-8 w-8 animate-spin text-blue-500" />
        </div>
      ) : (
        <>
          {/* Summary Cards */}
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div className="bg-white rounded-xl border border-slate-200/60 p-5 shadow-sm">
              <div className="flex items-center gap-4">
                <div className="h-12 w-12 rounded-xl bg-gradient-to-br from-emerald-400 to-emerald-600 flex items-center justify-center">
                  <DollarSign className="h-6 w-6 text-white" />
                </div>
                <div>
                  <p className="text-sm text-slate-500">Device sales</p>
                  <p className="text-xl font-bold text-slate-800">{formatCurrency(salesData?.summary.combined_revenue || 0)}</p>
                </div>
              </div>
            </div>
            <div className="bg-white rounded-xl border border-slate-200/60 p-5 shadow-sm">
              <div className="flex items-center gap-4">
                <div className="h-12 w-12 rounded-xl bg-gradient-to-br from-violet-400 to-violet-600 flex items-center justify-center">
                  <TrendingUp className="h-6 w-6 text-white" />
                </div>
                <div>
                  <p className="text-sm text-slate-500">Subscription revenue</p>
                  <p className="text-xl font-bold text-slate-800">{formatCurrency(revenueData?.summary.total_revenue || 0)}</p>
                </div>
              </div>
            </div>
            <div className="bg-white rounded-xl border border-slate-200/60 p-5 shadow-sm">
              <div className="flex items-center gap-4">
                <div className="h-12 w-12 rounded-xl bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center">
                  <Smartphone className="h-6 w-6 text-white" />
                </div>
                <div>
                  <p className="text-sm text-slate-500">Total Devices</p>
                  <p className="text-xl font-bold text-slate-800">{devicesData?.summary.total || 0}</p>
                </div>
              </div>
            </div>
            <div className="bg-white rounded-xl border border-slate-200/60 p-5 shadow-sm">
              <div className="flex items-center gap-4">
                <div className="h-12 w-12 rounded-xl bg-gradient-to-br from-slate-400 to-slate-600 flex items-center justify-center">
                  <Users className="h-6 w-6 text-white" />
                </div>
                <div>
                  <p className="text-sm text-slate-500">Total Users</p>
                  <p className="text-xl font-bold text-slate-800">{usersData?.summary.total || 0}</p>
                </div>
              </div>
            </div>
          </div>

          {/* Sales Report */}
          <div className="bg-white rounded-xl border border-slate-200/60 shadow-sm">
            <div className="p-5 border-b border-slate-200/60">
              <h2 className="text-base font-semibold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
                Sales Report
              </h2>
              <p className="text-sm text-slate-500 mt-1">
                Completed POS device sales plus administrative sales for the selected scope and period
              </p>
            </div>

            <div className="p-5 space-y-5">
              <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
                <div className="p-4 bg-slate-50 rounded-xl">
                  <p className="text-xs text-slate-500 font-medium">POS Sales</p>
                  <p className="text-2xl font-bold text-slate-800">{salesData?.summary.pos_sales_count || 0}</p>
                </div>
                <div className="p-4 bg-emerald-50 rounded-xl">
                  <p className="text-xs text-emerald-600 font-medium">POS Revenue</p>
                  <p className="text-lg font-bold text-emerald-700">{formatCurrency(salesData?.summary.pos_revenue || 0)}</p>
                </div>
                <div className="p-4 bg-teal-50 rounded-xl">
                  <p className="text-xs text-teal-600 font-medium">Gross Profit</p>
                  <p className="text-lg font-bold text-teal-700">{formatCurrency(salesData?.summary.pos_gross_profit || 0)}</p>
                </div>
                <div className="p-4 bg-violet-50 rounded-xl">
                  <p className="text-xs text-violet-600 font-medium">Administrative</p>
                  <p className="text-lg font-bold text-violet-700">{formatCurrency(salesData?.summary.administrative_sale_value || 0)}</p>
                </div>
                <div className="p-4 bg-blue-50 rounded-xl">
                  <p className="text-xs text-blue-600 font-medium">Combined Revenue</p>
                  <p className="text-lg font-bold text-blue-700">{formatCurrency(salesData?.summary.combined_revenue || 0)}</p>
                </div>
              </div>

              <div className="grid grid-cols-1 xl:grid-cols-2 gap-5">
                <div>
                  <div className="flex items-center gap-2 mb-3">
                    <Building2 className="h-4 w-4 text-slate-500" />
                    <h3 className="text-sm font-semibold text-slate-700">Sales by branch</h3>
                  </div>
                  <div className="overflow-x-auto rounded-lg border border-slate-200">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                      <thead className="bg-slate-50">
                        <tr>
                          <th className="px-3 py-2 text-left font-medium text-slate-500">Branch</th>
                          <th className="px-3 py-2 text-right font-medium text-slate-500">Sales</th>
                          <th className="px-3 py-2 text-right font-medium text-slate-500">Revenue</th>
                          <th className="px-3 py-2 text-right font-medium text-slate-500">Profit</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {salesData?.by_branch.length ? salesData.by_branch.map((branch) => (
                          <tr key={`${branch.branch_id ?? 'none'}-${branch.branch_name}`}>
                            <td className="px-3 py-2 text-slate-700">{branch.branch_name}</td>
                            <td className="px-3 py-2 text-right text-slate-700">{branch.pos_sales_count}</td>
                            <td className="px-3 py-2 text-right text-slate-700">{formatCurrency(branch.combined_revenue)}</td>
                            <td className="px-3 py-2 text-right text-slate-700">{formatCurrency(branch.combined_gross_profit)}</td>
                          </tr>
                        )) : (
                          <tr>
                            <td colSpan={4} className="px-3 py-8 text-center text-slate-400">No sales in this period</td>
                          </tr>
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>

                <div>
                  <div className="flex items-center gap-2 mb-3">
                    <Package className="h-4 w-4 text-slate-500" />
                    <h3 className="text-sm font-semibold text-slate-700">Sales by product</h3>
                  </div>
                  <div className="overflow-x-auto rounded-lg border border-slate-200">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                      <thead className="bg-slate-50">
                        <tr>
                          <th className="px-3 py-2 text-left font-medium text-slate-500">Product</th>
                          <th className="px-3 py-2 text-right font-medium text-slate-500">Units</th>
                          <th className="px-3 py-2 text-right font-medium text-slate-500">Revenue</th>
                          <th className="px-3 py-2 text-right font-medium text-slate-500">Profit</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {salesData?.by_product.length ? salesData.by_product.slice(0, 10).map((product, index) => (
                          <tr key={`${product.product}-${index}`}>
                            <td className="px-3 py-2 text-slate-700">
                              <div className="font-medium">{product.product}</div>
                              <div className="text-xs text-slate-400">{product.category}</div>
                            </td>
                            <td className="px-3 py-2 text-right text-slate-700">{product.units_sold}</td>
                            <td className="px-3 py-2 text-right text-slate-700">{formatCurrency(product.revenue)}</td>
                            <td className="px-3 py-2 text-right text-slate-700">{formatCurrency(product.gross_profit)}</td>
                          </tr>
                        )) : (
                          <tr>
                            <td colSpan={4} className="px-3 py-8 text-center text-slate-400">No product sales in this period</td>
                          </tr>
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>

              <div>
                <h3 className="text-sm font-semibold text-slate-700 mb-3">Recent sales</h3>
                <div className="overflow-x-auto rounded-lg border border-slate-200">
                  <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50">
                      <tr>
                        <th className="px-3 py-2 text-left font-medium text-slate-500">Sale</th>
                        <th className="px-3 py-2 text-left font-medium text-slate-500">Date</th>
                        <th className="px-3 py-2 text-left font-medium text-slate-500">Branch</th>
                        <th className="px-3 py-2 text-left font-medium text-slate-500">Customer</th>
                        <th className="px-3 py-2 text-right font-medium text-slate-500">Total</th>
                        <th className="px-3 py-2 text-right font-medium text-slate-500">Profit</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {salesData?.recent_sales.length ? salesData.recent_sales.slice(0, 15).map((sale) => (
                        <tr key={sale.sale_number}>
                          <td className="px-3 py-2 font-medium text-slate-700">{sale.sale_number}</td>
                          <td className="px-3 py-2 text-slate-600">{sale.sale_date || '—'}</td>
                          <td className="px-3 py-2 text-slate-600">{sale.branch_name}</td>
                          <td className="px-3 py-2 text-slate-600">{sale.customer_name}</td>
                          <td className="px-3 py-2 text-right text-slate-700">{formatCurrency(sale.total_amount)}</td>
                          <td className="px-3 py-2 text-right text-slate-700">{formatCurrency(sale.gross_profit)}</td>
                        </tr>
                      )) : (
                        <tr>
                          <td colSpan={6} className="px-3 py-8 text-center text-slate-400">No sales in this period</td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
                <p className="text-xs text-slate-400 mt-2">
                  Showing the 15 most recent. Export to Excel for the full list.
                </p>
              </div>
            </div>
          </div>

          {/* Stock Report */}
          <div className="bg-white rounded-xl border border-slate-200/60 shadow-sm">
            <div className="p-5 border-b border-slate-200/60">
              <h2 className="text-base font-semibold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
                Stock Report
              </h2>
              <p className="text-sm text-slate-500 mt-1">Current inventory, received stock, and sold stock by branch and date range</p>
            </div>

            <div className="p-5 space-y-5">
              <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
                <div className="p-4 bg-slate-50 rounded-xl">
                  <p className="text-xs text-slate-500 font-medium">Current Stock</p>
                  <p className="text-2xl font-bold text-slate-800">{stockData?.summary.current_total || 0}</p>
                </div>
                <div className="p-4 bg-emerald-50 rounded-xl">
                  <p className="text-xs text-emerald-600 font-medium">In Stock</p>
                  <p className="text-2xl font-bold text-emerald-700">{stockData?.summary.current_in_stock || 0}</p>
                </div>
                <div className="p-4 bg-amber-50 rounded-xl">
                  <p className="text-xs text-amber-600 font-medium">Reserved</p>
                  <p className="text-2xl font-bold text-amber-700">{stockData?.summary.current_reserved || 0}</p>
                </div>
                <div className="p-4 bg-blue-50 rounded-xl">
                  <p className="text-xs text-blue-600 font-medium">Received in Range</p>
                  <p className="text-2xl font-bold text-blue-700">{stockData?.summary.received_in_range || 0}</p>
                </div>
                <div className="p-4 bg-teal-50 rounded-xl">
                  <p className="text-xs text-teal-600 font-medium">Stock Value</p>
                  <p className="text-xl font-bold text-teal-700">{formatCurrency(stockData?.summary.stock_value || 0)}</p>
                </div>
              </div>

              <div>
                <div className="flex items-center gap-2 mb-3">
                  <TrendingUp className="h-4 w-4 text-slate-500" />
                  <h3 className="text-sm font-semibold text-slate-700">Inventory Realization</h3>
                  <span className="text-xs text-slate-400">POS and administrative sales</span>
                </div>
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                  <div className="p-4 bg-emerald-50 rounded-xl">
                    <p className="text-xs text-emerald-600 font-medium">POS Revenue</p>
                    <p className="text-lg font-bold text-emerald-700">{formatCurrency(stockData?.summary.pos_revenue || 0)}</p>
                  </div>
                  <div className="p-4 bg-violet-50 rounded-xl">
                    <p className="text-xs text-violet-600 font-medium">Administrative Sale Value</p>
                    <p className="text-lg font-bold text-violet-700">{formatCurrency(stockData?.summary.administrative_sale_value || 0)}</p>
                  </div>
                  <div className="p-4 bg-blue-50 rounded-xl">
                    <p className="text-xs text-blue-600 font-medium">Combined Realized Value</p>
                    <p className="text-lg font-bold text-blue-700">{formatCurrency(stockData?.summary.combined_inventory_realized_value || 0)}</p>
                  </div>
                  <div className={`p-4 rounded-xl ${(stockData?.summary.combined_gross_profit_loss || 0) < 0 ? 'bg-red-50' : 'bg-teal-50'}`}>
                    <p className={`text-xs font-medium ${(stockData?.summary.combined_gross_profit_loss || 0) < 0 ? 'text-red-600' : 'text-teal-600'}`}>Combined Gross Profit/Loss</p>
                    <p className={`text-lg font-bold ${(stockData?.summary.combined_gross_profit_loss || 0) < 0 ? 'text-red-700' : 'text-teal-700'}`}>
                      {formatCurrency(stockData?.summary.combined_gross_profit_loss || 0)}
                    </p>
                  </div>
                </div>
              </div>

              <div className="grid grid-cols-1 xl:grid-cols-2 gap-5">
                <div>
                  <div className="flex items-center gap-2 mb-3">
                    <Building2 className="h-4 w-4 text-slate-500" />
                    <h3 className="text-sm font-semibold text-slate-700">By Branch</h3>
                  </div>
                  <div className="overflow-x-auto rounded-lg border border-slate-200">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                      <thead className="bg-slate-50">
                        <tr>
                          <th className="px-3 py-2 text-left font-medium text-slate-500">Branch</th>
                          <th className="px-3 py-2 text-right font-medium text-slate-500">Current</th>
                          <th className="px-3 py-2 text-right font-medium text-slate-500">In Stock</th>
                          <th className="px-3 py-2 text-right font-medium text-slate-500">Received</th>
                          <th className="px-3 py-2 text-right font-medium text-slate-500">Sold</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {stockData?.by_branch.length ? stockData.by_branch.map((branch) => (
                          <tr key={branch.branch_id}>
                            <td className="px-3 py-2 text-slate-700">{branch.branch_name}</td>
                            <td className="px-3 py-2 text-right text-slate-700">{branch.current_total}</td>
                            <td className="px-3 py-2 text-right text-slate-700">{branch.current_in_stock}</td>
                            <td className="px-3 py-2 text-right text-slate-700">{branch.received_in_range}</td>
                            <td className="px-3 py-2 text-right text-slate-700">{branch.sold_in_range}</td>
                          </tr>
                        )) : (
                          <tr>
                            <td colSpan={5} className="px-3 py-8 text-center text-slate-400">No branch stock data</td>
                          </tr>
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>

                <div>
                  <div className="flex items-center gap-2 mb-3">
                    <Package className="h-4 w-4 text-slate-500" />
                    <h3 className="text-sm font-semibold text-slate-700">By Product</h3>
                  </div>
                  <div className="overflow-x-auto rounded-lg border border-slate-200">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                      <thead className="bg-slate-50">
                        <tr>
                          <th className="px-3 py-2 text-left font-medium text-slate-500">Product</th>
                          <th className="px-3 py-2 text-right font-medium text-slate-500">Current</th>
                          <th className="px-3 py-2 text-right font-medium text-slate-500">In Stock</th>
                          <th className="px-3 py-2 text-right font-medium text-slate-500">Reserved</th>
                          <th className="px-3 py-2 text-right font-medium text-slate-500">Cost</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {stockData?.by_product.length ? stockData.by_product.map((product, index) => (
                          <tr key={`${product.category}-${product.brand}-${product.model}-${product.color}-${index}`}>
                            <td className="px-3 py-2 text-slate-700">
                              <div className="font-medium">{[product.brand, product.model, product.color].filter(Boolean).join(' ') || 'Unspecified product'}</div>
                              <div className="text-xs text-slate-400">{product.category}</div>
                            </td>
                            <td className="px-3 py-2 text-right text-slate-700">{product.current_total}</td>
                            <td className="px-3 py-2 text-right text-slate-700">{product.current_in_stock}</td>
                            <td className="px-3 py-2 text-right text-slate-700">{product.current_reserved}</td>
                            <td className="px-3 py-2 text-right text-slate-700">{formatCurrency(product.total_cost)}</td>
                          </tr>
                        )) : (
                          <tr>
                            <td colSpan={5} className="px-3 py-8 text-center text-slate-400">No product stock data</td>
                          </tr>
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>

              <div>
                <h3 className="text-sm font-semibold text-slate-700 mb-3">Recent Batches in Range</h3>
                <div className="overflow-x-auto rounded-lg border border-slate-200">
                  <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50">
                      <tr>
                        <th className="px-3 py-2 text-left font-medium text-slate-500">Batch</th>
                        <th className="px-3 py-2 text-left font-medium text-slate-500">Branch</th>
                        <th className="px-3 py-2 text-left font-medium text-slate-500">Product</th>
                        <th className="px-3 py-2 text-right font-medium text-slate-500">Received</th>
                        <th className="px-3 py-2 text-right font-medium text-slate-500">In Stock</th>
                        <th className="px-3 py-2 text-right font-medium text-slate-500">Sold</th>
                        <th className="px-3 py-2 text-right font-medium text-slate-500">POS Revenue</th>
                        <th className="px-3 py-2 text-right font-medium text-slate-500">Admin Sale</th>
                        <th className="px-3 py-2 text-right font-medium text-slate-500">Combined P/L</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {stockData?.by_batch.length ? stockData.by_batch.map((batch) => (
                        <tr key={batch.id}>
                          <td className="px-3 py-2 text-slate-700">
                            <div className="font-medium">{batch.batch_number}</div>
                            <div className="text-xs text-slate-400">{batch.purchase_date || 'No date'}</div>
                          </td>
                          <td className="px-3 py-2 text-slate-700">{batch.branch_name || 'Unassigned'}</td>
                          <td className="px-3 py-2 text-slate-700">{batch.product || batch.category || 'Unspecified product'}</td>
                          <td className="px-3 py-2 text-right text-slate-700">{batch.quantity_received}</td>
                          <td className="px-3 py-2 text-right text-slate-700">{batch.in_stock}</td>
                          <td className="px-3 py-2 text-right text-slate-700">{batch.sold}</td>
                          <td className="px-3 py-2 text-right text-slate-700">{formatCurrency(batch.pos_revenue || 0)}</td>
                          <td className="px-3 py-2 text-right text-violet-700">{formatCurrency(batch.administrative_sale_value || 0)}</td>
                          <td className={`px-3 py-2 text-right font-medium ${(batch.combined_gross_profit_loss || 0) < 0 ? 'text-red-700' : 'text-teal-700'}`}>
                            {formatCurrency(batch.combined_gross_profit_loss || 0)}
                          </td>
                        </tr>
                      )) : (
                        <tr>
                          <td colSpan={9} className="px-3 py-8 text-center text-slate-400">No batches received in this range</td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {/* Subscription Revenue */}
            <div className="bg-white rounded-xl border border-slate-200/60 shadow-sm">
              <div className="p-5 border-b border-slate-200/60">
                <h2 className="text-base font-semibold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
                  Subscription Revenue
                </h2>
              </div>
              <div className="p-5 space-y-4">
                <div className="flex items-center justify-between py-2 border-b border-slate-100">
                  <span className="text-sm text-slate-500">Total Transactions</span>
                  <span className="text-sm font-semibold text-slate-800">{revenueData?.summary.total_transactions || 0}</span>
                </div>
                <div className="flex items-center justify-between py-2 border-b border-slate-100">
                  <span className="text-sm text-slate-500">Average Transaction</span>
                  <span className="text-sm font-semibold text-slate-800">{formatCurrency(revenueData?.summary.average_transaction || 0)}</span>
                </div>
                <div className="pt-2">
                  <h3 className="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">Revenue by Plan</h3>
                  {revenueData?.by_plan && Object.keys(revenueData.by_plan).length > 0 ? (
                    <div className="space-y-2">
                      {Object.entries(revenueData.by_plan).map(([plan, data]) => (
                        <div key={plan} className="flex items-center justify-between text-sm">
                          <span className="text-slate-600">{plan}</span>
                          <span className="font-medium text-slate-800">{formatCurrency(data.total)}</span>
                        </div>
                      ))}
                    </div>
                  ) : (
                    <p className="text-sm text-slate-400">No revenue data yet</p>
                  )}
                </div>
              </div>
            </div>

            {/* Device Statistics */}
            <div className="bg-white rounded-xl border border-slate-200/60 shadow-sm">
              <div className="p-5 border-b border-slate-200/60">
                <h2 className="text-base font-semibold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
                  Device Statistics
                </h2>
              </div>
              <div className="p-5 space-y-4">
                <div className="grid grid-cols-2 gap-3">
                  <div className="p-3 bg-emerald-50 rounded-xl">
                    <p className="text-xs text-emerald-600 font-medium">Active</p>
                    <p className="text-xl font-bold text-emerald-700">{devicesData?.summary.active || 0}</p>
                  </div>
                  <div className="p-3 bg-red-50 rounded-xl">
                    <p className="text-xs text-red-600 font-medium">Stolen</p>
                    <p className="text-xl font-bold text-red-700">{devicesData?.summary.stolen || 0}</p>
                  </div>
                  <div className="p-3 bg-blue-50 rounded-xl">
                    <p className="text-xs text-blue-600 font-medium">Recovered</p>
                    <p className="text-xl font-bold text-blue-700">{devicesData?.summary.recovered || 0}</p>
                  </div>
                  <div className="p-3 bg-slate-100 rounded-xl">
                    <div className="flex items-center gap-1.5">
                      <Wifi className="h-3.5 w-3.5 text-slate-600" />
                      <p className="text-xs text-slate-600 font-medium">IoT Coverage</p>
                    </div>
                    <p className="text-xl font-bold text-slate-700">{devicesData?.iot_coverage?.percentage || 0}%</p>
                  </div>
                </div>
                <div className="pt-2">
                  <h3 className="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">By Category</h3>
                  {devicesData?.by_category && Object.keys(devicesData.by_category).length > 0 ? (
                    <div className="space-y-2">
                      {Object.entries(devicesData.by_category).map(([category, count]) => (
                        <div key={category} className="flex items-center justify-between text-sm">
                          <span className="text-slate-600">{category}</span>
                          <span className="font-medium text-slate-800">{count}</span>
                        </div>
                      ))}
                    </div>
                  ) : (
                    <p className="text-sm text-slate-400">No category data</p>
                  )}
                </div>
              </div>
            </div>

            {/* Theft Report Statistics */}
            <div className="bg-white rounded-xl border border-slate-200/60 shadow-sm">
              <div className="p-5 border-b border-slate-200/60">
                <h2 className="text-base font-semibold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
                  Theft Report Statistics
                </h2>
              </div>
              <div className="p-5 space-y-4">
                <div className="grid grid-cols-2 gap-3">
                  <div className="p-3 bg-emerald-50 rounded-xl flex items-center gap-3">
                    <TrendingUp className="h-5 w-5 text-emerald-600" />
                    <div>
                      <p className="text-xs text-emerald-600 font-medium">Recovery Rate</p>
                      <p className="text-xl font-bold text-emerald-700">{theftData?.summary.recovery_rate || 0}%</p>
                    </div>
                  </div>
                  <div className="p-3 bg-blue-50 rounded-xl flex items-center gap-3">
                    <CheckCircle className="h-5 w-5 text-blue-600" />
                    <div>
                      <p className="text-xs text-blue-600 font-medium">Recovered</p>
                      <p className="text-xl font-bold text-blue-700">{theftData?.summary.recovered || 0}</p>
                    </div>
                  </div>
                </div>
                <div>
                  <p className="text-xs text-slate-500 mb-1">Average Resolution Time</p>
                  <p className="text-lg font-semibold text-slate-800">
                    {formatResolutionDays(theftData?.summary.average_resolution_days)}
                  </p>
                </div>
                <div className="pt-2">
                  <h3 className="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">By Status</h3>
                  {theftData?.summary.by_status && Object.keys(theftData.summary.by_status).length > 0 ? (
                    <div className="space-y-2">
                      {Object.entries(theftData.summary.by_status).map(([status, count]) => (
                        <div key={status} className="flex items-center justify-between text-sm">
                          <span className="text-slate-600 capitalize">{status.replace('_', ' ')}</span>
                          <span className="font-medium text-slate-800">{count}</span>
                        </div>
                      ))}
                    </div>
                  ) : (
                    <p className="text-sm text-slate-400">No theft reports yet</p>
                  )}
                </div>
              </div>
            </div>

            {/* User Statistics */}
            <div className="bg-white rounded-xl border border-slate-200/60 shadow-sm">
              <div className="p-5 border-b border-slate-200/60">
                <h2 className="text-base font-semibold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
                  User Statistics
                </h2>
              </div>
              <div className="p-5 space-y-4">
                <div className="grid grid-cols-2 gap-3">
                  <div className="p-3 bg-emerald-50 rounded-xl">
                    <p className="text-xs text-emerald-600 font-medium">Verified</p>
                    <p className="text-xl font-bold text-emerald-700">{usersData?.summary.verified || 0}</p>
                  </div>
                  <div className="p-3 bg-blue-50 rounded-xl">
                    <p className="text-xs text-blue-600 font-medium">With Subscription</p>
                    <p className="text-xl font-bold text-blue-700">{usersData?.summary.with_active_subscription || 0}</p>
                  </div>
                  <div className="p-3 bg-teal-50 rounded-xl">
                    <p className="text-xs text-teal-600 font-medium">With Devices</p>
                    <p className="text-xl font-bold text-teal-700">{usersData?.summary.with_devices || 0}</p>
                  </div>
                  <div className="p-3 bg-slate-100 rounded-xl">
                    <p className="text-xs text-slate-600 font-medium">Conversion Rate</p>
                    <p className="text-xl font-bold text-slate-700">
                      {usersData?.summary.total
                        ? Math.round((usersData.summary.with_active_subscription / usersData.summary.total) * 100)
                        : 0}%
                    </p>
                  </div>
                </div>
                <div className="pt-2">
                  <h3 className="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">By Role</h3>
                  {usersData?.by_role && Object.keys(usersData.by_role).length > 0 ? (
                    <div className="space-y-2">
                      {Object.entries(usersData.by_role).map(([role, count]) => (
                        <div key={role} className="flex items-center justify-between text-sm">
                          <span className="text-slate-600 capitalize">{role.replace('_', ' ')}</span>
                          <span className="font-medium text-slate-800">{count}</span>
                        </div>
                      ))}
                    </div>
                  ) : (
                    <p className="text-sm text-slate-400">No role data</p>
                  )}
                </div>
              </div>
            </div>

            {/* Staff Time */}
            <StaffTimeReportSection query={query} />
          </div>
        </>
      )}
    </div>
  )
}
