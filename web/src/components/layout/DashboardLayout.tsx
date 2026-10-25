import { useState } from 'react'
import { Outlet, NavLink } from 'react-router-dom'
import { useAuthStore } from '@/stores/auth-store'
import { useUser, useLogout } from '@/hooks/useAuth'
import { cn } from '@/lib/utils'
import {
  LayoutDashboard,
  Smartphone,
  MapPin,
  Shield,
  AlertTriangle,
  CreditCard,
  FileCheck,
  Bell,
  User,
  Users,
  LogOut,
  Menu,
  X,
  ChevronDown,
  Settings,
  Radio,
  Globe,
} from 'lucide-react'

const navigation = [
  { name: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
  { name: 'Devices', href: '/devices', icon: Smartphone },
  { name: 'Families', href: '/families', icon: Users },
  { name: 'Community', href: '/community', icon: Radio },
  { name: 'Live Tracking', href: '/tracking', icon: MapPin },
  { name: 'Geofences', href: '/geofences', icon: Shield },
  { name: 'Theft Reports', href: '/theft-reports', icon: AlertTriangle },
  { name: 'Subscriptions', href: '/subscriptions', icon: CreditCard },
  { name: 'Warranties', href: '/warranties', icon: FileCheck },
  { name: 'Visit website', href: '/', icon: Globe },
]

export default function DashboardLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const [userMenuOpen, setUserMenuOpen] = useState(false)
  const { user, isAdmin } = useAuthStore()
  const logoutMutation = useLogout()

  // Fetch fresh user data when authenticated
  useUser()

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
          'fixed inset-y-0 left-0 z-50 w-[260px] bg-slate-900 transform transition-transform lg:translate-x-0',
          sidebarOpen ? 'translate-x-0' : '-translate-x-full'
        )}
      >
        {/* Logo */}
        <div className="flex h-16 items-center justify-between px-5">
          <NavLink to="/dashboard" className="flex items-center gap-3">
            <div className="h-9 w-9 rounded-xl bg-teal-500 flex items-center justify-center p-1.5">
              <img src="/logo_transparent.png" alt="Mufasah" className="h-full w-full object-contain" />
            </div>
            <span className="text-lg font-semibold text-white" style={{ fontFamily: 'var(--font-heading)' }}>
              Mufasah
            </span>
          </NavLink>
          <button className="lg:hidden text-slate-400 hover:text-white" onClick={() => setSidebarOpen(false)}>
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Navigation */}
        <nav className="px-3 py-4">
          <p className="px-3 mb-2 text-xs font-medium text-slate-500 uppercase tracking-wider">
            Main Menu
          </p>
          <div className="space-y-1">
            {navigation.map((item) => (
              <NavLink
                key={item.name}
                to={item.href}
                className={({ isActive }) =>
                  cn(
                    'flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-200',
                    isActive
                      ? 'bg-teal-500/10 text-teal-400 border-l-2 border-teal-400 -ml-px pl-[11px]'
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

          {/* Admin Section */}
          {isAdmin() && (
            <>
              <p className="px-3 mt-8 mb-2 text-xs font-medium text-slate-500 uppercase tracking-wider">
                Administration
              </p>
              <NavLink
                to="/admin"
                className={({ isActive }) =>
                  cn(
                    'flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-200',
                    isActive
                      ? 'bg-teal-500/10 text-teal-400 border-l-2 border-teal-400 -ml-px pl-[11px]'
                      : 'text-slate-400 hover:text-white hover:bg-white/5'
                  )
                }
                onClick={() => setSidebarOpen(false)}
              >
                <Settings className="h-5 w-5" />
                <span>Admin Panel</span>
              </NavLink>
            </>
          )}
        </nav>

        {/* User card at bottom */}
        <div className="absolute bottom-0 left-0 right-0 p-4">
          <div className="rounded-xl bg-slate-800/50 p-3">
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-full bg-gradient-to-br from-teal-400 to-emerald-500 flex items-center justify-center text-white font-semibold">
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

          <div className="flex-1" />

          {/* Right side */}
          <div className="flex items-center gap-2">
            {/* Notifications */}
            <NavLink
              to="/notifications"
              className="relative p-2.5 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors"
            >
              <Bell className="h-5 w-5" />
              <span className="absolute top-2 right-2 h-2 w-2 bg-red-500 rounded-full ring-2 ring-white" />
            </NavLink>

            {/* Divider */}
            <div className="hidden sm:block w-px h-6 bg-slate-200 mx-2" />

            {/* User menu */}
            <div className="relative">
              <button
                className="flex items-center gap-2 p-1.5 pr-3 rounded-lg hover:bg-slate-100 transition-colors"
                onClick={() => setUserMenuOpen(!userMenuOpen)}
              >
                <div className="h-8 w-8 rounded-full bg-gradient-to-br from-teal-400 to-emerald-500 flex items-center justify-center text-white text-sm font-medium">
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
                      to="/subscriptions"
                      className="flex items-center gap-3 px-4 py-2.5 text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900"
                      onClick={() => setUserMenuOpen(false)}
                    >
                      <CreditCard className="h-4 w-4" />
                      <span>Subscription</span>
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
