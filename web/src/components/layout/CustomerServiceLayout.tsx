import { NavLink, Outlet } from 'react-router-dom'
import {
  LayoutDashboard,
  ShoppingCart,
  Users,
  Smartphone,
  FileText,
  LogOut,
  Menu,
  User,
  ReceiptText,
  Globe,
} from 'lucide-react'
import { useAuthStore } from '@/stores/auth-store'
import { useLogout } from '@/hooks/useAuth'
import { useState } from 'react'
import { ShiftClosingNotice } from '@/components/shifts/ShiftClosingNotice'

export default function CustomerServiceLayout() {
  const { user } = useAuthStore()
  const logoutMutation = useLogout()
  const [sidebarOpen, setSidebarOpen] = useState(true)

  const handleLogout = () => {
    logoutMutation.mutate()
  }

  const navItems = [
    {
      name: 'Dashboard',
      path: '/customer-service',
      icon: LayoutDashboard,
    },
    {
      name: 'My Sales',
      path: '/customer-service/sales',
      icon: ShoppingCart,
    },
    {
      name: 'Invoices',
      path: '/customer-service/invoices',
      icon: ReceiptText,
    },
    {
      name: 'Layby',
      path: '/customer-service/laybys',
      icon: ReceiptText,
    },
    {
      name: 'Devices',
      path: '/customer-service/devices',
      icon: Smartphone,
    },
    {
      name: 'Customers',
      path: '/customer-service/customers',
      icon: Users,
    },
    {
      name: 'Warranties',
      path: '/customer-service/warranties',
      icon: FileText,
    },
    {
      name: 'Profile Settings',
      path: '/customer-service/profile',
      icon: User,
    },
    {
      name: 'Visit website',
      path: '/',
      icon: Globe,
    },
  ]

  return (
    <div className="min-h-screen bg-slate-50">
      {/* Sidebar */}
      <aside
        className={`fixed top-0 left-0 z-40 h-full bg-gradient-to-b from-teal-600 to-teal-800 transition-transform duration-300 ease-in-out ${
          sidebarOpen ? 'w-64' : 'w-20'
        }`}
      >
        {/* Logo/Header */}
        <div className="flex items-center justify-between h-16 px-4 border-b border-teal-500">
          <div className="flex items-center gap-2 overflow-hidden">
            <button
              onClick={() => setSidebarOpen(!sidebarOpen)}
              className="text-white hover:bg-teal-700 p-1.5 rounded-lg transition-colors flex-shrink-0"
            >
              <Menu className="h-5 w-5" />
            </button>
            {sidebarOpen && (
              <div className="min-w-0">
              
                <p className="text-teal-200 text-[10px] truncate leading-tight mt-0.5">{user?.name}</p>
                {user?.branch && (
                  <p className="text-teal-300 text-[10px] truncate leading-tight">{user.branch.name}</p>
                )}
              </div>
            )}
          </div>
        </div>

        {/* Navigation */}
        <nav className="p-4 space-y-1 overflow-y-auto h-[calc(100vh-8rem)]">
          {navItems.map((item) => (
            <NavLink
              key={item.path}
              to={item.path}
              aria-label={item.name}
              title={!sidebarOpen ? item.name : undefined}
              end={item.path === '/customer-service'}
              className={({ isActive }) =>
                `flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all duration-200 ${
                  isActive
                    ? 'bg-white text-teal-800 shadow-md'
                    : 'text-teal-100 hover:bg-teal-700/50'
                }`
              }
            >
              <item.icon className="h-5 w-5 flex-shrink-0" />
              {sidebarOpen && <span className="font-medium">{item.name}</span>}
            </NavLink>
          ))}
        </nav>

        {/* Logout */}
        <div className="absolute bottom-0 left-0 right-0 p-4 border-t border-teal-500">
          <button
            onClick={handleLogout}
            className="flex items-center gap-3 w-full px-3 py-2.5 text-teal-100 hover:bg-teal-700 rounded-lg transition-colors"
          >
            <LogOut className="h-5 w-5" />
            {sidebarOpen && <span className="font-medium">Logout</span>}
          </button>
        </div>
      </aside>

      {/* Main Content */}
      <main
        className={`transition-all duration-300 ${
          sidebarOpen ? 'ml-64' : 'ml-20'
        }`}
      >
        {/* Top Bar */}
        <header className="bg-white shadow-sm h-16 flex items-center justify-between px-6 sticky top-0 z-30">
          <div className="flex items-center gap-4">
            <button
              onClick={() => setSidebarOpen(!sidebarOpen)}
              className="lg:hidden text-slate-600 hover:text-slate-900"
            >
              <Menu className="h-6 w-6" />
            </button>
            <h2 className="text-xl font-semibold text-slate-800">
              Customer Service Portal
            </h2>
          </div>
          <div className="flex items-center gap-4">
            <div className="text-right">
              <p className="text-sm font-medium text-slate-900">{user?.name}</p>
              <p className="text-xs text-slate-500">Customer Service</p>
            </div>
            <div className="h-10 w-10 rounded-full bg-gradient-to-br from-teal-400 to-teal-600 flex items-center justify-center text-white font-semibold">
              {user?.name?.charAt(0).toUpperCase()}
            </div>
          </div>
        </header>

        {/* Page Content */}
        <div className="p-6">
          <ShiftClosingNotice />
          <Outlet />
        </div>
      </main>
    </div>
  )
}
