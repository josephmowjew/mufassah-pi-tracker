import { type ClassValue, clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

export function formatDate(date: string | Date, options?: Intl.DateTimeFormatOptions) {
  return new Intl.DateTimeFormat('en-US', {
    dateStyle: 'medium',
    ...options,
  }).format(new Date(date))
}

export function formatCurrency(amount: number, currency = 'MWK') {
  return new Intl.NumberFormat('en-MW', {
    style: 'currency',
    currency,
  }).format(amount)
}

export function formatPhone(phone: string) {
  // Format Malawi phone numbers
  if (phone.startsWith('+265')) {
    return phone.replace(/(\+265)(\d{3})(\d{3})(\d{3})/, '$1 $2 $3 $4')
  }
  return phone
}

export function truncate(str: string, length: number) {
  if (str.length <= length) return str
  return str.slice(0, length) + '...'
}

export function sleep(ms: number) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

export function getInitials(name: string) {
  return name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2)
}
