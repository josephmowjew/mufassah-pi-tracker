import apiClient from '@/api/client'

export type Tender = 'cash' | 'mobile_money' | 'bank_transfer' | 'card'
export type LaybyStatus = 'active' | 'ready' | 'fulfilled' | 'cancelled' | 'returned'
export type ActionData = Record<string, string | number | boolean>

export interface LedgerEntry {
  id: number
  layby_id: number
  branch_id: number
  actor_id: number
  shift_id: number | null
  source_id: number | null
  kind: string
  tender: Tender | null
  amount_minor: number
  reference: string | null
  merchant_channel: string | null
  evidence: string | null
  details: { balance_minor?: number; due_date?: string; document_type?: string } | null
  occurred_at?: string | null
  created_at: string
}

export interface Layby {
  id: number
  reference: string
  branch_id: number
  customer_name: string
  serial_number: string
  description: string
  status: LaybyStatus
  sale_id: number | null
  total_minor: number
  received_minor: number
  balance_minor: number
  refund_due_minor: number
  accepted_terms: string
  original_due_date: string
  due_date: string
  overdue: boolean
  created_at: string
  entries: LedgerEntry[]
}

export interface LaybyQuote {
  quote: string
  customer_name: string
  serial_number: string
  description: string
  total_minor: number
  minimum_deposit_minor: number
  due_date: string
  accepted_terms: string
}

export interface LaybyPolicy {
  enabled: boolean
  deposit_percent: number
  term_days: number
  vat_basis_points: number
  review_reference: string | null
  merchant_channels: Partial<Record<Exclude<Tender, 'cash'>, string>>
}

export interface Page<T> {
  data: T[]
  current_page: number
  last_page: number
}

export const tenderLabels: Record<Tender, string> = {
  cash: 'Cash',
  mobile_money: 'Mobile money',
  bank_transfer: 'Bank transfer',
  card: 'Card',
}

export function money(minor: number) {
  return `MWK ${(minor / 100).toLocaleString('en-MW', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

export function minorUnits(value: FormDataEntryValue | null) {
  if (typeof value !== 'string' || !/^\d{1,10}(\.\d{1,2})?$/.test(value)) {
    throw new Error('Enter an MWK amount with up to two decimal places.')
  }
  const [major, fractional = ''] = value.split('.')
  return Number(major) * 100 + Number(fractional.padEnd(2, '0'))
}

export function errorMessage(error: unknown) {
  const failure = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } }; message?: string }
  const messages = Object.values(failure.response?.data?.errors ?? {}).flat()
  return messages[0] ?? failure.response?.data?.message ?? failure.message ?? 'The operation could not be completed.'
}

export async function postOnce(path: string, body: ActionData, actorId: number) {
  // Keep the key when a network error makes the outcome ambiguous.
  const bytes = new TextEncoder().encode(JSON.stringify([actorId, path, body]))
  const hash = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', bytes)))
    .map((byte) => byte.toString(16).padStart(2, '0')).join('')
  const storageKey = `layby-request-${hash}`
  const key = sessionStorage.getItem(storageKey) ?? crypto.randomUUID()
  sessionStorage.setItem(storageKey, key)
  const result = await apiClient.post<Layby>(path, body, { headers: { 'Idempotency-Key': key } })
  sessionStorage.removeItem(storageKey)
  return result.data
}

export function printLayby(title: string, lines: [string, string][]) {
  const page = window.open('', '_blank', 'width=680,height=800')
  if (!page) throw new Error('Allow popups to print this document.')
  page.opener = null
  page.document.title = title
  const style = page.document.createElement('style')
  style.textContent = 'body{font:15px/1.5 system-ui,sans-serif;margin:3rem;color:#162332}h1{font-size:1.5rem}p{white-space:pre-wrap}strong{display:block} @media print{button{display:none}}'
  page.document.head.append(style)
  const heading = page.document.createElement('h1')
  heading.textContent = title
  page.document.body.append(heading)
  for (const [label, value] of lines) {
    const row = page.document.createElement('p')
    const name = page.document.createElement('strong')
    name.textContent = label
    row.append(name, value)
    page.document.body.append(row)
  }
  const button = page.document.createElement('button')
  button.textContent = 'Print'
  button.addEventListener('click', () => page.print())
  page.document.body.append(button)
  page.focus()
}
