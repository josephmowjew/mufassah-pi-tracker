import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Download, Loader2, DollarSign, Smartphone, AlertTriangle, Users, TrendingUp, Wifi, CheckCircle, Package, Building2 } from 'lucide-react'
import apiClient from '@/api/client'

interface RevenueData {
  summary: {
    total_revenue: number
    total_transactions: number
    average_transaction: number
  }
  by_period: Array<{ period: string; transactions: number; revenue: number }>
  by_method: Record<string, { count: number; total: number }>
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
  filters: {
    from: string | null
    to: string | null
    branch_id: number | null
  }
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

export default function AdminReportsPage() {
  const [period, setPeriod] = useState<'month' | 'year'>('month')
  const [stockFrom, setStockFrom] = useState('')
  const [stockTo, setStockTo] = useState('')
  const [stockBranchId, setStockBranchId] = useState('')

  const stockQuery = new URLSearchParams()
  if (stockFrom) stockQuery.append('from', stockFrom)
  if (stockTo) stockQuery.append('to', stockTo)
  if (stockBranchId) stockQuery.append('branch_id', stockBranchId)
  const stockQueryString = stockQuery.toString()

  const { data: revenueData, isLoading: revenueLoading } = useQuery({
    queryKey: ['admin-reports-revenue', period],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: RevenueData }>(
        `/admin/reports/revenue?group_by=${period}`
      )
      return response.data.data
    },
  })

  const { data: devicesData, isLoading: devicesLoading } = useQuery({
    queryKey: ['admin-reports-devices'],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: DevicesData }>(
        '/admin/reports/devices'
      )
      return response.data.data
    },
  })

  const { data: theftData, isLoading: theftLoading } = useQuery({
    queryKey: ['admin-reports-theft'],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: TheftData }>(
        '/admin/reports/theft'
      )
      return response.data.data
    },
  })

  const { data: usersData, isLoading: usersLoading } = useQuery({
    queryKey: ['admin-reports-users'],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: UsersData }>(
        '/admin/reports/users'
      )
      return response.data.data
    },
  })

  const { data: branches } = useQuery({
    queryKey: ['branches'],
    queryFn: async () => {
      const response = await apiClient.get<{ data: BranchOption[] }>('/admin/branches')
      return response.data.data
    },
  })

  const { data: stockData, isLoading: stockLoading } = useQuery({
    queryKey: ['admin-reports-stock', stockFrom, stockTo, stockBranchId],
    queryFn: async () => {
      const response = await apiClient.get<{ success: boolean; data: StockData }>(
        `/admin/reports/stock${stockQueryString ? `?${stockQueryString}` : ''}`
      )
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

  const isLoading = revenueLoading || devicesLoading || theftLoading || usersLoading || stockLoading

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
            Reports & Analytics
          </h1>
          <p className="text-slate-500 text-sm mt-1">Comprehensive overview of system metrics</p>
        </div>
        <div className="flex items-center gap-3">
          <select
            className="px-4 py-2.5 rounded-lg border border-slate-200 bg-white text-sm text-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-colors"
            value={period}
            onChange={(e) => setPeriod(e.target.value as 'month' | 'year')}
          >
            <option value="month">Monthly</option>
            <option value="year">Yearly</option>
          </select>
          <button className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-100 text-slate-600 text-sm font-medium hover:bg-slate-200 transition-colors">
            <Download className="h-4 w-4" />
            Export
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
                  <p className="text-sm text-slate-500">Total Revenue</p>
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
                <div className="h-12 w-12 rounded-xl bg-gradient-to-br from-red-400 to-red-600 flex items-center justify-center">
                  <AlertTriangle className="h-6 w-6 text-white" />
                </div>
                <div>
                  <p className="text-sm text-slate-500">Theft Reports</p>
                  <p className="text-xl font-bold text-slate-800">{theftData?.summary.total || 0}</p>
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

          {/* Stock Report */}
          <div className="bg-white rounded-xl border border-slate-200/60 shadow-sm">
            <div className="p-5 border-b border-slate-200/60">
              <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                  <h2 className="text-base font-semibold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
                    Stock Report
                  </h2>
                  <p className="text-sm text-slate-500 mt-1">Current inventory, received stock, and sold stock by branch and date range</p>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 lg:min-w-[620px]">
                  <div>
                    <label className="block text-xs font-medium text-slate-500 mb-1">From</label>
                    <input
                      type="date"
                      value={stockFrom}
                      onChange={(event) => setStockFrom(event.target.value)}
                      className="w-full px-3 py-2 rounded-lg border border-slate-200 bg-white text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-slate-500 mb-1">To</label>
                    <input
                      type="date"
                      value={stockTo}
                      onChange={(event) => setStockTo(event.target.value)}
                      className="w-full px-3 py-2 rounded-lg border border-slate-200 bg-white text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-slate-500 mb-1">Branch</label>
                    <select
                      value={stockBranchId}
                      onChange={(event) => setStockBranchId(event.target.value)}
                      className="w-full px-3 py-2 rounded-lg border border-slate-200 bg-white text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                    >
                      <option value="">All Branches</option>
                      {branches?.map((branch) => (
                        <option key={branch.id} value={branch.id}>
                          {branch.name}{branch.code ? ` (${branch.code})` : ''}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>
              </div>
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
            {/* Revenue Overview */}
            <div className="bg-white rounded-xl border border-slate-200/60 shadow-sm">
              <div className="p-5 border-b border-slate-200/60">
                <h2 className="text-base font-semibold text-slate-800" style={{ fontFamily: 'var(--font-heading)' }}>
                  Revenue Overview
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
          </div>
        </>
      )}
    </div>
  )
}
