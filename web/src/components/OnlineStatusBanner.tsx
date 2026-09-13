import { useEffect, useState } from 'react'
import { WifiOff } from 'lucide-react'

export function OnlineStatusBanner() {
  const [isOnline, setIsOnline] = useState(() => navigator.onLine)

  useEffect(() => {
    const handleOnline = () => setIsOnline(true)
    const handleOffline = () => setIsOnline(false)

    window.addEventListener('online', handleOnline)
    window.addEventListener('offline', handleOffline)

    return () => {
      window.removeEventListener('online', handleOnline)
      window.removeEventListener('offline', handleOffline)
    }
  }, [])

  if (isOnline) return null

  return (
    <div className="fixed top-0 left-0 right-0 z-50 bg-amber-500 text-white px-4 py-2 text-sm font-medium flex items-center justify-center gap-2">
      <WifiOff className="h-4 w-4 shrink-0" />
      You are offline. Some features may be unavailable.
    </div>
  )
}
