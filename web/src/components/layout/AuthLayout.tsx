import { Outlet } from 'react-router-dom'
import { Shield, Smartphone, Radio, CheckCircle } from 'lucide-react'

export default function AuthLayout() {
  return (
    <div className="flex min-h-screen">
      {/* Left side - Branding */}
      <div className="hidden lg:flex lg:w-[55%] auth-gradient items-center justify-center p-12 xl:p-16 relative">
        {/* Decorative elements */}
        <div className="absolute inset-0 overflow-hidden pointer-events-none">
          {/* Floating circles */}
          <div className="absolute -top-24 -right-24 w-96 h-96 rounded-full border border-white/[0.08] float-animation" />
          <div className="absolute -top-12 -right-12 w-72 h-72 rounded-full border border-white/[0.05]" />
          <div className="absolute -bottom-32 -left-32 w-[500px] h-[500px] rounded-full border border-white/[0.08] float-animation-reverse" />
          <div className="absolute -bottom-16 -left-16 w-80 h-80 rounded-full border border-white/[0.04]" />

          {/* Accent dots */}
          <div className="absolute top-[20%] left-[15%] w-2 h-2 rounded-full bg-white/20" />
          <div className="absolute top-[35%] right-[25%] w-3 h-3 rounded-full bg-white/15" />
          <div className="absolute bottom-[30%] left-[25%] w-2 h-2 rounded-full bg-white/25" />
          <div className="absolute top-[60%] right-[15%] w-2 h-2 rounded-full bg-white/20" />
          <div className="absolute bottom-[15%] right-[35%] w-1.5 h-1.5 rounded-full bg-white/30" />
        </div>

        <div className="relative z-10 max-w-xl">
          {/* Logo */}
          <div className="flex items-center gap-4 mb-12">
            <div className="relative">
              <div className="absolute inset-0 rounded-2xl bg-white/20 pulse-ring" />
              <div className="relative h-14 w-14 rounded-2xl bg-white/10 backdrop-blur-sm flex items-center justify-center border border-white/20 p-2">
                <img src="/logo_transparent.png" alt="Mufasah" className="h-full w-full object-contain" />
              </div>
            </div>
            <div>
              <h2 className="text-xl font-semibold text-white tracking-tight" style={{ fontFamily: 'var(--font-heading)' }}>
                Mufasah
              </h2>
              <p className="text-white/60 text-sm">Device Tracking</p>
            </div>
          </div>

          {/* Main headline */}
          <h1 className="text-4xl xl:text-5xl font-bold text-white mb-6 leading-[1.15] tracking-tight" style={{ fontFamily: 'var(--font-heading)' }}>
            Protect what matters most to you
          </h1>
          <p className="text-lg xl:text-xl text-white/75 mb-12 leading-relaxed max-w-md">
            Real-time GPS tracking, instant alerts, and comprehensive theft recovery — all in one platform.
          </p>

          {/* Feature list */}
          <div className="space-y-4 mb-12">
            {[
              'Real-time location tracking with 10-second updates',
              'Smart geofencing with instant notifications',
              'Theft recovery support & police coordination',
              'Multi-device management from one dashboard',
            ].map((feature, index) => (
              <div key={index} className="flex items-center gap-3">
                <div className="h-5 w-5 rounded-full bg-white/10 flex items-center justify-center flex-shrink-0">
                  <CheckCircle className="h-3.5 w-3.5 text-white/90" />
                </div>
                <span className="text-white/80 text-[15px]">{feature}</span>
              </div>
            ))}
          </div>

          {/* Feature cards */}
          <div className="grid grid-cols-3 gap-4">
            <div className="glass rounded-xl p-4 text-center">
              <div className="h-10 w-10 rounded-lg bg-white/10 flex items-center justify-center mx-auto mb-3">
                <Radio className="h-5 w-5 text-white" />
              </div>
              <span className="text-white/90 text-sm font-medium">Live Tracking</span>
            </div>
            <div className="glass rounded-xl p-4 text-center">
              <div className="h-10 w-10 rounded-lg bg-white/10 flex items-center justify-center mx-auto mb-3">
                <Shield className="h-5 w-5 text-white" />
              </div>
              <span className="text-white/90 text-sm font-medium">Geofencing</span>
            </div>
            <div className="glass rounded-xl p-4 text-center">
              <div className="h-10 w-10 rounded-lg bg-white/10 flex items-center justify-center mx-auto mb-3">
                <Smartphone className="h-5 w-5 text-white" />
              </div>
              <span className="text-white/90 text-sm font-medium">Multi-Device</span>
            </div>
          </div>

          {/* Trust badge */}
          <div className="mt-12 pt-8 border-t border-white/10 flex items-center gap-8">
            <div className="text-center">
              <p className="text-2xl font-bold text-white">10K+</p>
              <p className="text-white/50 text-xs">Active Users</p>
            </div>
            <div className="text-center">
              <p className="text-2xl font-bold text-white">50K+</p>
              <p className="text-white/50 text-xs">Devices Tracked</p>
            </div>
            <div className="text-center">
              <p className="text-2xl font-bold text-white">98%</p>
              <p className="text-white/50 text-xs">Recovery Rate</p>
            </div>
          </div>
        </div>
      </div>

      {/* Right side - Auth forms */}
      <div className="w-full lg:w-[45%] flex items-center justify-center p-6 sm:p-8 lg:p-12 bg-white">
        <div className="w-full max-w-[400px]">
          {/* Mobile logo */}
          <div className="lg:hidden flex items-center gap-3 mb-10">
            <div className="h-12 w-12 rounded-xl bg-primary flex items-center justify-center p-2">
              <img src="/logo_transparent.png" alt="Mufasah" className="h-full w-full object-contain" />
            </div>
            <div>
              <span className="text-lg font-semibold text-foreground" style={{ fontFamily: 'var(--font-heading)' }}>
                Mufasah
              </span>
              <p className="text-xs text-muted-foreground">Device Tracking</p>
            </div>
          </div>

          <Outlet />

          {/* Footer */}
          <div className="mt-12 pt-8 border-t border-border">
            <p className="text-xs text-muted-foreground text-center">
              By continuing, you agree to our{' '}
              <a href="/terms" className="text-primary hover:underline">Terms</a>
              {' '}and{' '}
              <a href="/privacy" className="text-primary hover:underline">Privacy Policy</a>
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
