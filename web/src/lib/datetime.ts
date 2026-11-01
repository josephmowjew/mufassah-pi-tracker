/** Display business instants in Malawi, independent of the browser timezone. */
export const MALAWI_TIMEZONE = 'Africa/Blantyre'
type DateValue = string | number | Date

function asDate(value: DateValue): Date {
  if (typeof value !== 'string') return new Date(value)
  // Calendar dates have no instant/timezone. Anchor them to Malawi midnight.
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return new Date(`${value}T00:00:00+02:00`)
  // Legacy API datetime strings without an offset represent UTC storage.
  if (/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/.test(value)) {
    return new Date(`${value.replace(' ', 'T')}Z`)
  }
  return new Date(value)
}

export function formatMalawiDate(value: DateValue, locales?: Intl.LocalesArgument, options?: Intl.DateTimeFormatOptions): string {
  return asDate(value).toLocaleDateString(locales, { ...options, timeZone: MALAWI_TIMEZONE })
}

export function formatMalawiTime(value: DateValue, locales?: Intl.LocalesArgument, options?: Intl.DateTimeFormatOptions): string {
  return asDate(value).toLocaleTimeString(locales, { ...options, timeZone: MALAWI_TIMEZONE })
}

export function formatMalawiDateTime(value: DateValue, locales?: Intl.LocalesArgument, options?: Intl.DateTimeFormatOptions): string {
  return asDate(value).toLocaleString(locales, { ...options, timeZone: MALAWI_TIMEZONE })
}

const inputFormatter = new Intl.DateTimeFormat('en-GB', {
  timeZone: MALAWI_TIMEZONE, year: 'numeric', month: '2-digit', day: '2-digit',
})

/** YYYY-MM-DD for date inputs and API calendar filters. */
export function malawiDateInput(value: DateValue = new Date()): string {
  const parts = inputFormatter.formatToParts(asDate(value))
  const part = (type: Intl.DateTimeFormatPartTypes) => parts.find((item) => item.type === type)!.value
  return `${part('year')}-${part('month')}-${part('day')}`
}

/** Calendar-only Date for preset arithmetic with local JS getters/setters.
 * This is a calendar carrier, not an instant to serialize or save.
 */
export function malawiCalendarDate(value: DateValue = new Date()): Date {
  const [year, month, day] = malawiDateInput(value).split('-').map(Number)
  return new Date(year, month - 1, day)
}

export function malawiHour(value: DateValue = new Date()): number {
  return Number(formatMalawiTime(value, 'en-GB', { hour: '2-digit', hourCycle: 'h23' }))
}
