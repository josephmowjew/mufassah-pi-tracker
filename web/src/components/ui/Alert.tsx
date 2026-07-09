import type { ReactNode } from 'react'
import { AlertCircle, CheckCircle, Info, XCircle } from 'lucide-react'
import { cn } from '@/lib/utils'

export interface AlertProps {
  variant?: 'default' | 'success' | 'warning' | 'error'
  title?: string
  children: ReactNode
  className?: string
}

const iconMap = {
  default: Info,
  success: CheckCircle,
  warning: AlertCircle,
  error: XCircle,
}

const variantStyles = {
  default: 'bg-blue-50 text-blue-800 dark:bg-blue-900/20 dark:text-blue-400',
  success: 'bg-green-50 text-green-800 dark:bg-green-900/20 dark:text-green-400',
  warning: 'bg-yellow-50 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400',
  error: 'bg-red-50 text-red-800 dark:bg-red-900/20 dark:text-red-400',
}

export default function Alert({ variant = 'default', title, children, className }: AlertProps) {
  const Icon = iconMap[variant]

  return (
    <div
      className={cn(
        'flex gap-3 rounded-lg p-4',
        variantStyles[variant],
        className
      )}
      role="alert"
    >
      <Icon className="h-5 w-5 flex-shrink-0" />
      <div className="flex-1">
        {title && <h5 className="font-medium mb-1">{title}</h5>}
        <div className="text-sm">{children}</div>
      </div>
    </div>
  )
}
