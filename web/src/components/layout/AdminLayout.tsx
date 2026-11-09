import { useState, useMemo } from 'react'
import { Outlet, NavLink } from 'react-router-dom'
import { useAuthStore, ROLES } from '@/stores/auth-store'
import { useLogout } from '@/hooks/useAuth'
import { useUnreadCount } from '@/hooks/useNotifications'
import { cn } from '@/lib/utils'
import {
  LayoutDashboard,
  Users,
  UsersRound,
  Smartphone,
  Wifi,
  AlertTriangle,
  CreditCard,
  BarChart3,
  Menu,
  X,
  LogOut,
  ChevronDown,
  User,
  MapPin,
  Bell,
  Package,
  ShoppingCart,
  Radio,
  Layers,
  Grid3x3,
  Clock,
  ArrowLeftRight,
  Building2,
  BriefcaseBusiness,
  ReceiptText,
  Globe,
  type LucideIcon,
} from 'lucide-react'

interface NavItem {
  name: string
  href: string
  icon: LucideIcon
  // Roles that can see this item. If empty/undefined, all admin roles can see it.
  allowedRoles?: string[]
}

// Define which roles can see which menu items
const adminNavigation: NavItem[] = [
  { name: 'Dashboard', href: '/admin', icon: LayoutDashboard },
  { name: 'Users', href: '/admin/users', icon: Users, allowedRoles: [ROLES.SUPER_ADMIN, ROLES.STORE_ADMIN] },
  { name: 'Families', href: '/admin/families', icon: UsersRound, allowedRoles: [ROLES.SUPER_ADMIN] },
  { name: 'Devices', href: '/admin/devices', icon: Smartphone },
  { name: 'Live Tracking', href: '/admin/tracking', icon: MapPin },
  { name: 'IoT Modules', href: '/admin/iot-modules', icon: Wifi, allowedRoles: [ROLES.SUPER_ADMIN, ROLES.STORE_ADMIN] },
  { name: 'Inventory', href: '/admin/inventory', icon: Package, allowedRoles: [ROLES.SUPER_ADMIN, ROLES.STORE_ADMIN] },
  { name: 'Stock Movements', href: '/admin/stock-movements', icon: ArrowLeftRight, allowedRoles: [ROLES.SUPER_ADMIN, ROLES.STORE_ADMIN] },
  { name: 'Branches', href: '/admin/branches', icon: Building2, allowedRoles: [ROLES.SUPER_ADMIN] },
  { name: 'Product Catalog', href: '/admin/catalog', icon: Layers, allowedRoles: [ROLES.SUPER_ADMIN] },
  { name: 'Categories', href: '/admin/categories', icon: Grid3x3, allowedRoles: [ROLES.SUPER_ADMIN] },
  { name: 'Sales', href: '/admin/sales', icon: ShoppingCart },
  { name: 'Invoices', href: '/admin/invoices', icon: ReceiptText },
  { name: 'Layby', href: '/admin/laybys', icon: CreditCard },
  { name: 'Shifts', href: '/admin/shifts', icon: Clock, allowedRoles: [ROLES.SUPER_ADMIN, ROLES.STORE_ADMIN] },
  { name: 'Communications', href: '/admin/communications', icon: Bell, allowedRoles: [ROLES.SUPER_ADMIN] },
  { name: 'Vacancies', href: '/admin/vacancies', icon: BriefcaseBusiness, allowedRoles: [ROLES.SUPER_ADMIN] },
  { name: 'Theft Reports', href: '/admin/theft-reports', icon: AlertTriangle },
  { name: 'Community', href: '/admin/community', icon: Radio, allowedRoles: [ROLES.SUPER_ADMIN] },
  { name: 'Subscriptions', href: '/admin/subscriptions', icon: CreditCard },
  { name: 'Reports', href: '/admin/reports', icon: BarChart3, allowedRoles: [ROLES.SUPER_ADMIN, ROLES.STORE_ADMIN] },
  { name: 'Visit website', href: '/', icon: Globe },
]

export default function AdminLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const [userMenuOpen, setUserMenuOpen] = useState(false)
  const { user, getUserRoles } = useAuthStore()
  const logoutMutation = useLogout()
  const { data: unreadCount = 0 } = useUnreadCount()

  // Filter navigation items based on user's role
  const userRoles = getUserRoles()
  const filteredNavigation = useMemo(() => {
    return adminNavigation.filter(item => {
      // If no allowedRoles specified, everyone can see it
      if (!item.allowedRoles || item.allowedRoles.length === 0) {
        return true
      }
      // Check if user has any of the allowed roles
      return item.allowedRoles.some(role => userRoles.includes(role))
    })
  }, [userRoles])

  // Get role display name for the badge
  const getRoleDisplayName = () => {
    if (userRoles.includes(ROLES.SUPER_ADMIN)) return 'Super Admin'
    if (userRoles.includes(ROLES.STORE_ADMIN)) return 'Store Admin'
    if (userRoles.includes(ROLES.CUSTOMER_SERVICE)) return 'Customer Service'
    return 'Admin'
  }

  const handleLogout = () => {
    logoutMutation.mutate()
  }

  return (
    <div className="min-h-screen bg-slate-50">
      {/* Mobile sidebar backdrop */}
      {sidebarOpen && (
        <div
          className="fixed inset-0 z-40 bg-slate-900/60 backdrop-blur-sm lg:hidden"
          onClick={() => setSidebarOpen(false)}
        />
      )}

      {/* Sidebar */}
      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-50 flex w-[260px] flex-col bg-slate-900 transform transition-transform lg:translate-x-0',
          sidebarOpen ? 'translate-x-0' : '-translate-x-full'
        )}
      >
        {/* Logo */}
        <div className="flex h-16 items-center justify-between px-5">
          <NavLink to="/admin" className="flex items-center gap-3">
            <div className="h-9 w-9 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center p-1.5">
              <img src="/logo_transparent.png" alt="Mufasah" className="h-full w-full object-contain" />
            </div>
            <div>
              <span className="text-lg font-semibold text-white" style={{ fontFamily: 'var(--font-heading)' }}>
                Mufasah
              </span>
              <span className="block text-[10px] text-blue-400 font-medium tracking-wider uppercase">
                Admin Panel
              </span>
            </div>
          </NavLink>
          <button className="lg:hidden text-slate-400 hover:text-white" onClick={() => setSidebarOpen(false)}>
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Navigation */}
        <nav className="min-h-0 flex-1 overflow-y-auto px-3 py-4">
          <p className="px-3 mb-2 text-xs font-medium text-slate-500 uppercase tracking-wider">
            Management
          </p>
          <div className="space-y-1">
            {filteredNavigation.map((item) => (
              <NavLink
                key={item.name}
                to={item.href}
                end={item.href === '/admin'}
                className={({ isActive }) =>
                  cn(
                    'flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-200',
                    isActive
                      ? 'bg-blue-500/10 text-blue-400 border-l-2 border-blue-400 -ml-px pl-[11px]'
                      : 'text-slate-400 hover:text-white hover:bg-white/5'
                  )
                }
                onClick={() => setSidebarOpen(false)}
              >
                <item.icon className="h-5 w-5" />
                <span>{item.name}</span>
              </NavLink>
            ))}
          </div>

        </nav>

        {/* User card at bottom */}
        <div className="border-t border-slate-800/80 p-4">
          <div className="rounded-xl bg-slate-800/50 p-3">
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-semibold">
                {user?.name?.charAt(0).toUpperCase()}
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-white truncate">{user?.name}</p>
                <p className="text-xs text-slate-400 truncate">{user?.email}</p>
              </div>
            </div>
          </div>
        </div>
      </aside>

      {/* Main content */}
      <div className="lg:pl-[260px]">
        {/* Top header */}
        <header className="sticky top-0 z-30 h-16 bg-white/80 backdrop-blur-md border-b border-slate-200/60 flex items-center justify-between px-4 lg:px-6">
          <button
            className="lg:hidden p-2 -ml-2 text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded-lg"
            onClick={() => setSidebarOpen(true)}
          >
            <Menu className="h-5 w-5" />
          </button>

          {/* Role badge */}
          <div className="hidden lg:flex items-center gap-2">
            <div className={cn(
              'px-2.5 py-1 rounded-full text-xs font-medium',
              userRoles.includes(ROLES.CUSTOMER_SERVICE)
                ? 'bg-teal-100 text-teal-700'
                : 'bg-blue-100 text-blue-700'
            )}>
              {getRoleDisplayName()}
            </div>
          </div>

          <div className="flex-1" />

          {/* Right side */}
          <div className="flex items-center gap-2">
            {/* Notifications */}
            <NavLink
              to="/admin/notifications"
              aria-label="Open notifications"
              className="relative p-2.5 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors"
            >
              <Bell className="h-5 w-5" />
              {unreadCount > 0 && <span className="absolute -right-1 -top-1 min-w-5 rounded-full bg-red-500 px-1 text-center text-[10px] font-semibold leading-5 text-white">{unreadCount > 99 ? '99+' : unreadCount}</span>}
            </NavLink>

            {/* Divider */}
            <div className="hidden sm:block w-px h-6 bg-slate-200 mx-2" />

            {/* User menu */}
            <div className="relative">
              <button
                className="flex items-center gap-2 p-1.5 pr-3 rounded-lg hover:bg-slate-100 transition-colors"
                onClick={() => setUserMenuOpen(!userMenuOpen)}
              >
                <div className="h-8 w-8 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white text-sm font-medium">
                  {user?.name?.charAt(0).toUpperCase()}
                </div>
                <span className="hidden sm:block text-sm font-medium text-slate-700">{user?.name}</span>
                <ChevronDown className="h-4 w-4 text-slate-400" />
              </button>

              {userMenuOpen && (
                <>
                  <div
                    className="fixed inset-0 z-40"
                    onClick={() => setUserMenuOpen(false)}
                  />
                  <div className="absolute right-0 mt-2 w-56 bg-white border border-slate-200 rounded-xl shadow-lg shadow-slate-200/50 z-50 py-1 overflow-hidden">
                    <div className="px-4 py-3 border-b border-slate-100">
                      <p className="text-sm font-medium text-slate-900">{user?.name}</p>
                      <p className="text-xs text-slate-500 truncate">{user?.email}</p>
                    </div>
                    <NavLink
                      to="/profile"
                      className="flex items-center gap-3 px-4 py-2.5 text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900"
                      onClick={() => setUserMenuOpen(false)}
                    >
                      <User className="h-4 w-4" />
                      <span>Profile Settings</span>
                    </NavLink>
                    <NavLink
                      to="/dashboard"
                      className="flex items-center gap-3 px-4 py-2.5 text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900"
                      onClick={() => setUserMenuOpen(false)}
                    >
                      <MapPin className="h-4 w-4" />
                      <span>Customer Portal</span>
                    </NavLink>
                    <div className="border-t border-slate-100 mt-1 pt-1">
                      <button
                        className="flex items-center gap-3 px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 w-full text-left"
                        onClick={handleLogout}
                      >
                        <LogOut className="h-4 w-4" />
                        <span>Log out</span>
                      </button>
                    </div>
                  </div>
                </>
              )}
            </div>
          </div>
        </header>

        {/* Page content */}
        <main className="p-4 lg:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
