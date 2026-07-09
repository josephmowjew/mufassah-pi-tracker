import { cn } from '@/lib/utils'
import { Loader2 } from 'lucide-react'

interface LoadingSpinnerProps {
  size?: 'sm' | 'md' | 'lg'
  fullScreen?: boolean
  className?: string
}

export default function LoadingSpinner({
  size = 'md',
  fullScreen = false,
  className,
}: LoadingSpinnerProps) {
  const sizeClasses = {
    sm: 'h-4 w-4',
    md: 'h-8 w-8',
    lg: 'h-12 w-12',
  }

  if (fullScreen) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <Loader2 className={cn('animate-spin text-primary', sizeClasses[size], className)} />
      </div>
    )
  }

  return <Loader2 className={cn('animate-spin text-primary', sizeClasses[size], className)} />
}
