import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { Suspense, lazy } from 'react'
import { Toaster, toast } from 'sonner'
import { getApiErrorMessage, requiresApiConfirmation } from '@/lib/utils'

// Layouts
import AuthLayout from '@/components/layout/AuthLayout'
import DashboardLayout from '@/components/layout/DashboardLayout'
import AdminLayout from '@/components/layout/AdminLayout'
import CustomerServiceLayout from '@/components/layout/CustomerServiceLayout'
import PublicLayout from '@/components/layout/PublicLayout'

// Auth guards
import ProtectedRoute from '@/components/auth/ProtectedRoute'
import GuestRoute from '@/components/auth/GuestRoute'

// Error boundary
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { OnlineStatusBanner } from '@/components/OnlineStatusBanner'

// Loading component
import LoadingSpinner from '@/components/ui/LoadingSpinner'

// Auth hook
import { getDefaultRedirectPath } from '@/hooks/useAuth'
import { useAuthStore } from '@/stores/auth-store'
import StockTransferAlerts from '@/components/notifications/StockTransferAlerts'
import InventoryPinAlerts from '@/components/notifications/InventoryPinAlerts'

// Lazy load pages
const LoginPage = lazy(() => import('@/pages/auth/LoginPage'))
const RegisterPage = lazy(() => import('@/pages/auth/RegisterPage'))
const ForgotPasswordPage = lazy(() => import('@/pages/auth/ForgotPasswordPage'))
const ResetPasswordPage = lazy(() => import('@/pages/auth/ResetPasswordPage'))
const VerifyEmailPage = lazy(() => import('@/pages/auth/VerifyEmailPage'))
const HomePage = lazy(() => import('@/pages/public/HomePage'))
const AboutPage = lazy(() => import('@/pages/public/AboutPage'))
const VacanciesPage = lazy(() => import('@/pages/public/VacanciesPage'))

const DashboardPage = lazy(() => import('@/pages/dashboard/DashboardPage'))
const DevicesPage = lazy(() => import('@/pages/devices/DevicesPage'))
const DeviceDetailPage = lazy(() => import('@/pages/devices/DeviceDetailPage'))
const TrackingPage = lazy(() => import('@/pages/tracking/TrackingPage'))
const GeofencesPage = lazy(() => import('@/pages/geofences/GeofencesPage'))
const TheftReportsPage = lazy(() => import('@/pages/theft-reports/TheftReportsPage'))
const SubscriptionsPage = lazy(() => import('@/pages/subscriptions/SubscriptionsPage'))
const WarrantiesPage = lazy(() => import('@/pages/warranties/WarrantiesPage'))
const ProfilePage = lazy(() => import('@/pages/profile/ProfilePage'))
const NotificationsPage = lazy(() => import('@/pages/notifications/NotificationsPage'))
const FamiliesPage = lazy(() => import('@/pages/families/FamiliesPage'))
const FamilyDetailPage = lazy(() => import('@/pages/families/FamilyDetailPage'))

// Community pages
const CommunityAlertsPage = lazy(() => import('@/pages/community/CommunityAlertsPage'))
const CommunityAlertDetailPage = lazy(() => import('@/pages/community/CommunityAlertDetailPage'))
const MyAlertsPage = lazy(() => import('@/pages/community/MyAlertsPage'))
const LeaderboardPage = lazy(() => import('@/pages/community/LeaderboardPage'))
const SuccessStoriesPage = lazy(() => import('@/pages/community/SuccessStoriesPage'))

// Admin pages
const AdminDashboardPage = lazy(() => import('@/pages/admin/AdminDashboardPage'))
const AdminUsersPage = lazy(() => import('@/pages/admin/AdminUsersPage'))
const AdminFamiliesPage = lazy(() => import('@/pages/admin/AdminFamiliesPage'))
const AdminTheftReportsPage = lazy(() => import('@/pages/admin/AdminTheftReportsPage'))
const AdminSubscriptionsPage = lazy(() => import('@/pages/admin/AdminSubscriptionsPage'))
const AdminReportsPage = lazy(() => import('@/pages/admin/AdminReportsPage'))
const AdminInventoryPage = lazy(() => import('@/pages/admin/AdminInventoryPage'))
const AdminStockMovementsPage = lazy(() => import('@/pages/admin/AdminStockMovementsPage'))
const AdminBranchesPage = lazy(() => import('@/pages/admin/AdminBranchesPage'))
const AdminProductCatalogPage = lazy(() => import('@/pages/admin/AdminProductCatalogPage'))
const AdminCategoriesPage = lazy(() => import('@/pages/admin/AdminCategoriesPage'))
const AdminShiftsPage = lazy(() => import('@/pages/admin/AdminShiftsPage'))
const AdminCommunicationsPage = lazy(() => import('@/pages/admin/AdminCommunicationsPage'))
const AdminCommunityPage = lazy(() => import('@/pages/admin/AdminCommunityPage'))
const AdminVacanciesPage = lazy(() => import('@/pages/admin/AdminVacanciesPage'))

// Management pages (reusable, permission-based)
const DeviceManagementPage = lazy(() => import('@/pages/management/DeviceManagementPage'))
const IoTModulesPage = lazy(() => import('@/pages/management/IoTModulesPage'))
const SalesPage = lazy(() => import('@/pages/sales/SalesPage'))
const AdminTrackingPage = lazy(() => import('@/pages/admin/AdminTrackingPage'))

// Customer Service pages
const CustomerServiceDashboard = lazy(() => import('@/pages/customer-service/CustomerServiceDashboard'))
const CustomerServiceSalesPage = lazy(() => import('@/pages/customer-service/CustomerServiceSalesPage'))
const CustomerServiceNewSalePage = lazy(() => import('@/pages/customer-service/CustomerServiceNewSalePage'))
const CustomerServiceSaleDetailPage = lazy(() => import('@/pages/customer-service/SaleDetailPage'))
const CustomerServiceDevicesPage = lazy(() => import('@/pages/customer-service/CustomerServiceDevicesPage'))
const CustomerServiceCustomersPage = lazy(() => import('@/pages/customer-service/CustomerServiceCustomersPage'))
const CustomerServiceWarrantiesPage = lazy(() => import('@/pages/customer-service/CustomerServiceWarrantiesPage'))
const ShiftHistoryPage = lazy(() => import('@/pages/customer-service/ShiftHistoryPage'))
const ShiftDetailPage = lazy(() => import('@/pages/customer-service/ShiftDetailPage'))

// Create query client
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 1000 * 60 * 5, // 5 minutes
      retry: 1,
      refetchOnWindowFocus: false,
    },
    mutations: {
      onError: (error) => {
        if (requiresApiConfirmation(error)) return
        const message = getApiErrorMessage(error, 'Something went wrong. Please try again.')
        toast.error(message)
      },
    },
  },
})

// Component for role-based 404 redirect
function NotFoundRedirect() {
  const { isAuthenticated, user, isLoading } = useAuthStore()

  if (isLoading) {
    return <LoadingSpinner fullScreen />
  }

  if (!isAuthenticated || !user) {
    return <Navigate to="/login" replace />
  }

  return <Navigate to={getDefaultRedirectPath(user)} replace />
}

function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <OnlineStatusBanner />
      <BrowserRouter>
        <StockTransferAlerts />
        <InventoryPinAlerts />
        <ErrorBoundary>
          <Suspense fallback={<LoadingSpinner fullScreen />}>
            <Routes>
            {/* Public marketing routes */}
            <Route element={<PublicLayout />}>
              <Route path="/" element={<HomePage />} />
              <Route path="/about" element={<AboutPage />} />
              <Route path="/vacancies" element={<VacanciesPage />} />
            </Route>

            {/* Auth routes */}
            <Route element={<GuestRoute />}>
              <Route element={<AuthLayout />}>
                <Route path="/login" element={<LoginPage />} />
                <Route path="/register" element={<RegisterPage />} />
                <Route path="/forgot-password" element={<ForgotPasswordPage />} />
                <Route path="/reset-password" element={<ResetPasswordPage />} />
              </Route>
            </Route>

            {/* Email verification (can be accessed when logged in) */}
            <Route path="/verify-email" element={<VerifyEmailPage />} />
            <Route path="/verify-email/:id/:hash" element={<VerifyEmailPage />} />

            {/* Protected customer routes */}
            <Route element={<ProtectedRoute />}>
              <Route element={<DashboardLayout />}>
                <Route path="/dashboard" element={<DashboardPage />} />
                <Route path="/devices" element={<DevicesPage />} />
                <Route path="/devices/:id" element={<DeviceDetailPage />} />
                <Route path="/tracking" element={<TrackingPage />} />
                <Route path="/geofences" element={<GeofencesPage />} />
                <Route path="/theft-reports" element={<TheftReportsPage />} />
                <Route path="/subscriptions" element={<SubscriptionsPage />} />
                <Route path="/warranties" element={<WarrantiesPage />} />
                <Route path="/profile" element={<ProfilePage />} />
                <Route path="/notifications" element={<NotificationsPage />} />
                <Route path="/families" element={<FamiliesPage />} />
                <Route path="/families/:id" element={<FamilyDetailPage />} />

                {/* Community routes */}
                <Route path="/community" element={<CommunityAlertsPage />} />
                <Route path="/community/alerts/:id" element={<CommunityAlertDetailPage />} />
                <Route path="/community/my-alerts" element={<MyAlertsPage />} />
                <Route path="/community/leaderboard" element={<LeaderboardPage />} />
                <Route path="/community/stories" element={<SuccessStoriesPage />} />
              </Route>
            </Route>

            {/* Protected admin routes */}
            <Route element={<ProtectedRoute requiredRoles={['super_admin', 'store_admin']} />}>
              <Route element={<AdminLayout />}>
                <Route path="/admin" element={<AdminDashboardPage />} />
                <Route path="/admin/users" element={<AdminUsersPage />} />
                <Route path="/admin/devices" element={<DeviceManagementPage />} />
                <Route path="/admin/tracking" element={<AdminTrackingPage />} />
                <Route path="/admin/iot-modules" element={<IoTModulesPage />} />
                <Route path="/admin/inventory" element={<AdminInventoryPage />} />
                <Route path="/admin/stock-movements" element={<AdminStockMovementsPage />} />
                <Route path="/admin/sales" element={<SalesPage />} />
                <Route path="/admin/shifts" element={<AdminShiftsPage />} />
                <Route path="/admin/notifications" element={<NotificationsPage />} />
                <Route path="/admin/theft-reports" element={<AdminTheftReportsPage />} />
                <Route path="/admin/subscriptions" element={<AdminSubscriptionsPage />} />
                <Route path="/admin/reports" element={<AdminReportsPage />} />
                <Route element={<ProtectedRoute requiredRoles={['super_admin']} />}>
                  <Route path="/admin/families" element={<AdminFamiliesPage />} />
                  <Route path="/admin/branches" element={<AdminBranchesPage />} />
                  <Route path="/admin/catalog" element={<AdminProductCatalogPage />} />
                  <Route path="/admin/categories" element={<AdminCategoriesPage />} />
                  <Route path="/admin/communications" element={<AdminCommunicationsPage />} />
                  <Route path="/admin/vacancies" element={<AdminVacanciesPage />} />
                  <Route path="/admin/community" element={<AdminCommunityPage />} />
                </Route>
              </Route>
            </Route>

            {/* Protected customer service routes */}
            <Route element={<ProtectedRoute requiredRoles={['customer_service']} />}>
              <Route element={<CustomerServiceLayout />}>
                <Route path="/customer-service" element={<CustomerServiceDashboard />} />
                <Route path="/customer-service/sales" element={<CustomerServiceSalesPage />} />
                <Route path="/customer-service/sales/new" element={<CustomerServiceNewSalePage />} />
                <Route path="/customer-service/sales/:id" element={<CustomerServiceSaleDetailPage />} />
                <Route path="/customer-service/devices" element={<CustomerServiceDevicesPage />} />
                <Route path="/customer-service/customers" element={<CustomerServiceCustomersPage />} />
                <Route path="/customer-service/warranties" element={<CustomerServiceWarrantiesPage />} />
                <Route path="/customer-service/profile" element={<ProfilePage />} />
                <Route path="/customer-service/shifts" element={<ShiftHistoryPage />} />
                <Route path="/customer-service/shifts/:id" element={<ShiftDetailPage />} />
              </Route>
            </Route>

            {/* 404 - role-based redirect */}
            <Route path="*" element={<NotFoundRedirect />} />
            </Routes>
          </Suspense>
        </ErrorBoundary>
      </BrowserRouter>
      <Toaster
        position="top-right"
        toastOptions={{
          duration: 4000,
          classNames: {
            toast: 'font-sans',
            success: 'bg-emerald-50 border-emerald-200 text-emerald-800',
            error: 'bg-red-50 border-red-200 text-red-800',
            warning: 'bg-amber-50 border-amber-200 text-amber-800',
            info: 'bg-blue-50 border-blue-200 text-blue-800',
          },
        }}
        richColors
      />
    </QueryClientProvider>
  )
}

export default App
