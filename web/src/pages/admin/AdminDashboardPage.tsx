import {
  Users,
  Smartphone,
  AlertTriangle,
  DollarSign,
  TrendingUp,
  TrendingDown,
  ShieldCheck,
  Activity,
  ArrowUpRight,
  ArrowDownRight,
  Package,
  CheckCircle2,
  RotateCcw,
  Zap,
  Target,
  Award,
  Eye,
  Receipt,
} from 'lucide-react'
import {
  AreaChart,
  Area,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  PieChart,
  Pie,
  Cell,
  BarChart,
  Bar,
} from 'recharts'
import { useAdminDashboard, useAdminUserStats, useAdminDeviceStats, useAdminRevenueStats } from '@/hooks/useAdmin'
import { cn } from '@/lib/utils'

const COLORS = {
  primary: '#0ea5e9',
  success: '#10b981',
  warning: '#f59e0b',
  danger: '#ef4444',
  info: '#3b82f6',
  teal: '#14b8a6',
  slate: '#64748b',
  cyan: '#06b6d4',
}

const PIE_COLORS = ['#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#14b8a6', '#3b82f6']

export default function AdminDashboardPage() {
  const { data: dashboard, isLoading: dashboardLoading } = useAdminDashboard()
  const { data: userStats, isLoading: userStatsLoading } = useAdminUserStats()
  const { data: deviceStats, isLoading: deviceStatsLoading } = useAdminDeviceStats()
  const { data: revenueStats, isLoading: revenueStatsLoading } = useAdminRevenueStats()

  const isLoading = dashboardLoading || userStatsLoading || deviceStatsLoading || revenueStatsLoading

  // Prepare chart data
  const deviceStatusData = deviceStats?.by_status
    ? Object.entries(deviceStats.by_status).map(([name, value]) => ({ name, value }))
    : []

  const userGrowthData = userStats?.growth
    ? Object.entries(userStats.growth).map(([date, count]) => ({ date, count }))
    : []

  const revenueData = revenueStats?.trend
    ? Object.entries(revenueStats.trend).map(([month, amount]) => ({ month, amount }))
    : []

  const deviceCategoryData = deviceStats?.by_category
    ? Object.entries(deviceStats.by_category).map(([name, value]) => ({ name, value }))
    : []

  if (isLoading) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[600px] gap-4">
        <div className="relative">
          <div className="h-16 w-16 rounded-full border-4 border-sky-100 border-t-sky-600 animate-spin" />
          <Activity className="absolute inset-0 m-auto h-6 w-6 text-sky-600" />
        </div>
        <p className="text-slate-500 animate-pulse">Loading dashboard data...</p>
      </div>
    )
  }

  const totalDevices = dashboard?.devices?.total || 0
  const activeDevices = dashboard?.devices?.active || 0
  const recoveryRate = dashboard?.theft_reports?.recovery_rate || 0
  const monthlyRevenue = dashboard?.revenue?.this_month || 0
  const lastMonthRevenue = dashboard?.revenue?.last_month || 0
  const monthlyRevenueChange = dashboard?.revenue?.month_over_month_change_percentage ?? null
  const administrativeSalesThisMonth = dashboard?.administrative_sales?.this_month || 0
  const administrativeSalesLastMonth = dashboard?.administrative_sales?.last_month || 0
  const administrativeSalesChange = dashboard?.administrative_sales?.month_over_month_change_percentage ?? null
  const revenueTrend = getTrend(monthlyRevenue, lastMonthRevenue)
  const administrativeSalesTrend = getTrend(administrativeSalesThisMonth, administrativeSalesLastMonth)

  return (
    <div className="space-y-6 pb-8">
      {/* Header Section */}
      <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-slate-800 via-slate-900 to-slate-800 p-8 text-white">
        <div className="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjAiIGhlaWdodD0iNjAiIHZpZXdCb3g9IjAgMCA2MCA2MCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48ZyBmaWxsPSJub25lIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiPjxwYXRoIGQ9Ik0zNiAxOGMtOS45NDEgMC0xOCA4LjA1OS0xOCAxOHM4LjA1OSAxOCAxOCAxOGM5Ljk0MSAwIDE4LTguMDU5IDE4LTE4cy04LjA1OS0xOC0xOC0xOHptMCAzMmMtNy43MzIgMC0xNC02LjI2OC0xNC0xNHM2LjI2OC0xNCAxNC0xNHMxNCA2LjI2OCAxNCAxNC02LjI2OCAxNC0xNCAxNHoiIGZpbGw9IiNmZmYiIGZpbGwtb3BhY2l0eT0iLjA1Ii8+PC9nPjwvc3ZnPg==')] opacity-30" />
        {/* Accent gradient overlay */}
        <div className="absolute top-0 right-0 w-96 h-96 bg-gradient-to-bl from-sky-500/20 via-teal-500/10 to-transparent rounded-full blur-3xl" />
        <div className="absolute bottom-0 left-0 w-64 h-64 bg-gradient-to-tr from-emerald-500/20 via-cyan-500/10 to-transparent rounded-full blur-3xl" />
        <div className="relative z-10">
          <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
              <h1 className="text-3xl font-bold tracking-tight">
                Welcome back, Admin! 👋
              </h1>
              <p className="text-slate-300 mt-2 text-lg">
                Here's what's happening with your platform today
              </p>
            </div>
            <div className="flex items-center gap-3">
              <div className="px-4 py-2 bg-white/10 backdrop-blur-sm rounded-xl border border-white/20">
                <p className="text-xs text-slate-400">Last updated</p>
                <p className="text-sm font-medium">{new Date().toLocaleTimeString()}</p>
              </div>
            </div>
          </div>

          {/* Mini Stats in Header */}
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mt-8">
            <div className="bg-white/10 backdrop-blur-sm rounded-xl p-4 border border-white/20 hover:bg-white/15 transition-colors">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-lg bg-sky-500/30 flex items-center justify-center">
                  <Zap className="h-5 w-5 text-sky-300" />
                </div>
                <div>
                  <p className="text-2xl font-bold">{activeDevices}</p>
                  <p className="text-xs text-slate-400">Active Devices</p>
                </div>
              </div>
            </div>
            <div className="bg-white/10 backdrop-blur-sm rounded-xl p-4 border border-white/20 hover:bg-white/15 transition-colors">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-lg bg-emerald-500/30 flex items-center justify-center">
                  <Target className="h-5 w-5 text-emerald-300" />
                </div>
                <div>
                  <p className="text-2xl font-bold">{recoveryRate}%</p>
                  <p className="text-xs text-slate-400">Recovery Rate</p>
                </div>
              </div>
            </div>
            <div className="bg-white/10 backdrop-blur-sm rounded-xl p-4 border border-white/20 hover:bg-white/15 transition-colors">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-lg bg-amber-500/30 flex items-center justify-center">
                  <Award className="h-5 w-5 text-amber-300" />
                </div>
                <div>
                  <p className="text-2xl font-bold">{dashboard?.subscriptions?.active || 0}</p>
                  <p className="text-xs text-slate-400">Active Plans</p>
                </div>
              </div>
            </div>
            <div className="bg-white/10 backdrop-blur-sm rounded-xl p-4 border border-white/20 hover:bg-white/15 transition-colors">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-lg bg-rose-500/30 flex items-center justify-center">
                  <Eye className="h-5 w-5 text-rose-300" />
                </div>
                <div>
                  <p className="text-2xl font-bold">{dashboard?.theft_reports?.active || 0}</p>
                  <p className="text-xs text-slate-400">Active Reports</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Main Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-5">
        <StatCard
          icon={Users}
          label="Total Users"
          value={dashboard?.users?.total || 0}
          change={dashboard?.users?.new_this_month || 0}
          changeLabel="this month"
          trend="up"
          gradient="from-blue-500 to-cyan-400"
          bgGradient="from-blue-50 to-cyan-50"
        />
        <StatCard
          icon={Smartphone}
          label="Total Devices"
          value={totalDevices}
          change={activeDevices}
          changeLabel="active"
          trend="up"
          gradient="from-emerald-500 to-teal-400"
          bgGradient="from-emerald-50 to-teal-50"
        />
        <StatCard
          icon={AlertTriangle}
          label="Theft Reports"
          value={dashboard?.theft_reports?.active || 0}
          change={dashboard?.theft_reports?.recovered || 0}
          changeLabel="recovered"
          trend="neutral"
          gradient="from-rose-500 to-orange-400"
          bgGradient="from-rose-50 to-orange-50"
        />
        <StatCard
          icon={DollarSign}
          label="Monthly Cash Revenue"
          value={formatMwk(monthlyRevenue)}
          change={monthlyRevenueChange}
          changeLabel="vs last month"
          trend={revenueTrend}
          gradient="from-amber-500 to-yellow-400"
          bgGradient="from-amber-50 to-yellow-50"
          isPercentage
        />
        <StatCard
          icon={Receipt}
          label="Administrative Sales"
          value={formatMwk(administrativeSalesThisMonth)}
          change={administrativeSalesChange}
          changeLabel="vs last month"
          trend={administrativeSalesTrend}
          gradient="from-violet-500 to-fuchsia-400"
          bgGradient="from-violet-50 to-fuchsia-50"
          isPercentage
        />
      </div>

      {/* Charts Row */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Revenue Chart - Takes 2 columns */}
        <div className="lg:col-span-2 bg-white rounded-2xl border border-slate-200/60 shadow-sm shadow-slate-200/50 overflow-hidden">
          <div className="p-6 border-b border-slate-100">
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-lg font-semibold text-slate-800">Cash Revenue Overview</h2>
                <p className="text-sm text-slate-500 mt-0.5">Monthly payment revenue performance</p>
              </div>
              <div className={cn(
                'flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-medium',
                revenueTrend === 'up' && 'bg-emerald-50 text-emerald-600',
                revenueTrend === 'down' && 'bg-red-50 text-red-600',
                revenueTrend === 'neutral' && 'bg-slate-100 text-slate-600',
              )}>
                {revenueTrend === 'down'
                  ? <TrendingDown className="h-4 w-4" />
                  : <TrendingUp className="h-4 w-4" />}
                {formatPercentageChange(monthlyRevenueChange)}
              </div>
            </div>
          </div>
          <div className="p-6">
            {revenueData.length > 0 ? (
              <ResponsiveContainer width="100%" height={300}>
                <AreaChart data={revenueData}>
                  <defs>
                    <linearGradient id="revenueGradient" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="5%" stopColor={COLORS.primary} stopOpacity={0.3} />
                      <stop offset="95%" stopColor={COLORS.primary} stopOpacity={0} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" vertical={false} />
                  <XAxis
                    dataKey="month"
                    tick={{ fontSize: 12, fill: '#64748b' }}
                    axisLine={false}
                    tickLine={false}
                  />
                  <YAxis
                    tick={{ fontSize: 12, fill: '#64748b' }}
                    axisLine={false}
                    tickLine={false}
                    tickFormatter={(v) => `${(v / 1000000).toFixed(1)}M`}
                  />
                  <Tooltip
                    formatter={(value: number) => [`MWK ${value.toLocaleString()}`, 'Cash Revenue']}
                    contentStyle={{
                      borderRadius: '12px',
                      border: 'none',
                      boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)',
                      padding: '12px 16px',
                    }}
                  />
                  <Area
                    type="monotone"
                    dataKey="amount"
                    stroke={COLORS.primary}
                    strokeWidth={3}
                    fill="url(#revenueGradient)"
                  />
                </AreaChart>
              </ResponsiveContainer>
            ) : (
              <EmptyState message="No revenue data available" />
            )}
          </div>
        </div>

        {/* Device Status */}
        <div className="bg-white rounded-2xl border border-slate-200/60 shadow-sm shadow-slate-200/50 overflow-hidden">
          <div className="p-6 border-b border-slate-100">
            <h2 className="text-lg font-semibold text-slate-800">Device Status</h2>
            <p className="text-sm text-slate-500 mt-0.5">Distribution by status</p>
          </div>
          <div className="p-6">
            {deviceStatusData.length > 0 ? (
              <>
                <ResponsiveContainer width="100%" height={200}>
                  <PieChart>
                    <Pie
                      data={deviceStatusData}
                      cx="50%"
                      cy="50%"
                      innerRadius={60}
                      outerRadius={85}
                      paddingAngle={4}
                      dataKey="value"
                      strokeWidth={0}
                    >
                      {deviceStatusData.map((_, index) => (
                        <Cell
                          key={`cell-${index}`}
                          fill={PIE_COLORS[index % PIE_COLORS.length]}
                        />
                      ))}
                    </Pie>
                    <Tooltip
                      contentStyle={{
                        borderRadius: '12px',
                        border: 'none',
                        boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)',
                      }}
                    />
                  </PieChart>
                </ResponsiveContainer>
                <div className="grid grid-cols-2 gap-2 mt-4">
                  {deviceStatusData.map((entry, index) => (
                    <div
                      key={entry.name}
                      className="flex items-center gap-2 p-2 rounded-lg bg-slate-50"
                    >
                      <div
                        className="w-3 h-3 rounded-full"
                        style={{ backgroundColor: PIE_COLORS[index % PIE_COLORS.length] }}
                      />
                      <div className="flex-1 min-w-0">
                        <p className="text-xs text-slate-500 capitalize truncate">{entry.name}</p>
                        <p className="text-sm font-semibold text-slate-800">{entry.value}</p>
                      </div>
                    </div>
                  ))}
                </div>
              </>
            ) : (
              <EmptyState message="No device data" />
            )}
          </div>
        </div>
      </div>

      {/* Second Charts Row */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* User Growth */}
        <div className="bg-white rounded-2xl border border-slate-200/60 shadow-sm shadow-slate-200/50 overflow-hidden">
          <div className="p-6 border-b border-slate-100">
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-lg font-semibold text-slate-800">User Growth</h2>
                <p className="text-sm text-slate-500 mt-0.5">New user registrations</p>
              </div>
              <div className="text-2xl font-bold text-slate-800">
                {dashboard?.users?.total || 0}
                <span className="text-sm font-normal text-slate-500 ml-1">total</span>
              </div>
            </div>
          </div>
          <div className="p-6">
            {userGrowthData.length > 0 ? (
              <ResponsiveContainer width="100%" height={250}>
                <BarChart data={userGrowthData} barSize={32}>
                  <defs>
                    <linearGradient id="userGradient" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor={COLORS.info} stopOpacity={1} />
                      <stop offset="100%" stopColor={COLORS.cyan} stopOpacity={1} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" vertical={false} />
                  <XAxis
                    dataKey="date"
                    tick={{ fontSize: 12, fill: '#64748b' }}
                    axisLine={false}
                    tickLine={false}
                  />
                  <YAxis
                    tick={{ fontSize: 12, fill: '#64748b' }}
                    axisLine={false}
                    tickLine={false}
                  />
                  <Tooltip
                    contentStyle={{
                      borderRadius: '12px',
                      border: 'none',
                      boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)',
                    }}
                  />
                  <Bar dataKey="count" fill="url(#userGradient)" radius={[8, 8, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            ) : (
              <EmptyState message="No user growth data available" />
            )}
          </div>
        </div>

        {/* Devices by Category */}
        <div className="bg-white rounded-2xl border border-slate-200/60 shadow-sm shadow-slate-200/50 overflow-hidden">
          <div className="p-6 border-b border-slate-100">
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-lg font-semibold text-slate-800">Devices by Category</h2>
                <p className="text-sm text-slate-500 mt-0.5">Distribution across categories</p>
              </div>
              <Package className="h-5 w-5 text-slate-400" />
            </div>
          </div>
          <div className="p-6">
            {deviceCategoryData.length > 0 ? (
              <div className="space-y-4">
                {deviceCategoryData.slice(0, 6).map((category, index) => {
                  const maxValue = Math.max(...deviceCategoryData.map((c) => c.value))
                  const percentage = (category.value / maxValue) * 100

                  return (
                    <div key={category.name} className="group">
                      <div className="flex items-center justify-between mb-2">
                        <div className="flex items-center gap-2">
                          <div
                            className="w-2 h-2 rounded-full"
                            style={{ backgroundColor: PIE_COLORS[index % PIE_COLORS.length] }}
                          />
                          <span className="text-sm font-medium text-slate-700">{category.name}</span>
                        </div>
                        <span className="text-sm font-bold text-slate-800">{category.value}</span>
                      </div>
                      <div className="h-2 bg-slate-100 rounded-full overflow-hidden">
                        <div
                          className="h-full rounded-full transition-all duration-500 ease-out"
                          style={{
                            width: `${percentage}%`,
                            background: `linear-gradient(90deg, ${PIE_COLORS[index % PIE_COLORS.length]}, ${PIE_COLORS[(index + 1) % PIE_COLORS.length]})`,
                          }}
                        />
                      </div>
                    </div>
                  )
                })}
              </div>
            ) : (
              <EmptyState message="No category data" />
            )}
          </div>
        </div>
      </div>

      {/* Quick Stats Grid */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <QuickStatCard
          icon={ShieldCheck}
          label="Recovery Rate"
          value={`${recoveryRate}%`}
          iconBg="bg-emerald-100"
          iconColor="text-emerald-600"
        />
        <QuickStatCard
          icon={AlertTriangle}
          label="Stolen Devices"
          value={dashboard?.devices?.stolen || 0}
          iconBg="bg-red-100"
          iconColor="text-red-600"
        />
        <QuickStatCard
          icon={RotateCcw}
          label="Recovered"
          value={dashboard?.devices?.recovered || 0}
          iconBg="bg-blue-100"
          iconColor="text-blue-600"
        />
        <QuickStatCard
          icon={CheckCircle2}
          label="Active Subs"
          value={dashboard?.subscriptions?.active || 0}
          iconBg="bg-amber-100"
          iconColor="text-amber-600"
        />
      </div>
    </div>
  )
}

function StatCard({
  icon: Icon,
  label,
  value,
  change,
  changeLabel,
  trend,
  gradient,
  bgGradient,
  isPercentage = false,
}: {
  icon: typeof Users
  label: string
  value: string | number
  change: number | null
  changeLabel: string
  trend: 'up' | 'down' | 'neutral'
  gradient: string
  bgGradient: string
  isPercentage?: boolean
}) {
  return (
    <div className={cn(
      'relative overflow-hidden rounded-2xl p-6 transition-all duration-300 hover:shadow-lg hover:-translate-y-1',
      `bg-gradient-to-br ${bgGradient}`
    )}>
      <div className="flex items-start justify-between">
        <div>
          <p className="text-sm font-medium text-slate-600">{label}</p>
          <p className="text-3xl font-bold text-slate-800 mt-2">{value}</p>
          <div className="flex items-center gap-1 mt-2">
            {trend === 'up' && <ArrowUpRight className="h-4 w-4 text-emerald-500" />}
            {trend === 'down' && <ArrowDownRight className="h-4 w-4 text-red-500" />}
            <span
              className={cn(
                'text-sm font-medium',
                trend === 'up' && 'text-emerald-600',
                trend === 'down' && 'text-red-600',
                trend === 'neutral' && 'text-slate-600'
              )}
            >
              {isPercentage ? formatPercentageChange(change) : change ?? 0}
            </span>
            <span className="text-sm text-slate-500">{changeLabel}</span>
          </div>
        </div>
        <div className={cn('p-3 rounded-xl bg-gradient-to-br', gradient)}>
          <Icon className="h-6 w-6 text-white" />
        </div>
      </div>
      {/* Decorative element */}
      <div className={cn(
        'absolute -bottom-4 -right-4 h-24 w-24 rounded-full opacity-10 bg-gradient-to-br',
        gradient
      )} />
    </div>
  )
}

function formatMwk(amount: number): string {
  return `MWK ${amount.toLocaleString(undefined, { maximumFractionDigits: 0 })}`
}

function formatPercentageChange(change: number | null): string {
  if (change === null) return 'New'

  const normalizedChange = Object.is(change, -0) ? 0 : change
  const value = Number.isInteger(normalizedChange)
    ? normalizedChange.toString()
    : normalizedChange.toFixed(1)

  return `${normalizedChange > 0 ? '+' : ''}${value}%`
}

function getTrend(current: number, previous: number): 'up' | 'down' | 'neutral' {
  if (current === previous) return 'neutral'

  return current > previous ? 'up' : 'down'
}

function QuickStatCard({
  icon: Icon,
  label,
  value,
  iconBg,
  iconColor,
}: {
  icon: typeof Users
  label: string
  value: string | number
  iconBg: string
  iconColor: string
}) {
  return (
    <div className="bg-white rounded-xl border border-slate-200/60 p-4 shadow-sm hover:shadow-md transition-shadow">
      <div className="flex items-center gap-3">
        <div className={cn('p-2.5 rounded-lg', iconBg)}>
          <Icon className={cn('h-5 w-5', iconColor)} />
        </div>
        <div>
          <p className="text-xl font-bold text-slate-800">{value}</p>
          <p className="text-xs text-slate-500">{label}</p>
        </div>
      </div>
    </div>
  )
}

function EmptyState({ message }: { message: string }) {
  return (
    <div className="flex flex-col items-center justify-center h-[200px] text-slate-400">
      <Package className="h-12 w-12 mb-2 opacity-50" />
      <p className="text-sm">{message}</p>
    </div>
  )
}
