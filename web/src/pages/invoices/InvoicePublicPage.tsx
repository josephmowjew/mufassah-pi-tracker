import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { FileText, Loader2, Mail, ShieldCheck } from 'lucide-react'
import apiClient from '@/api/client'
import type { Invoice } from '@/types'

const money = (value: number | string | null | undefined) => `MWK ${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const date = (value: string) => new Date(value).toLocaleString()

export default function InvoicePublicPage() {
  const { token } = useParams<{ token: string }>()
  const { data, isLoading, error } = useQuery({
    queryKey: ['public-invoice', token],
    queryFn: async () => {
      const response = await apiClient.get<{ data: Invoice }>(`/invoices/public/${token}`)
      return response.data.data
    },
    enabled: !!token,
  })

  if (isLoading) return <div className="flex min-h-[60vh] items-center justify-center"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div>
  if (error || !data) return <div className="mx-auto max-w-2xl px-4 py-16 text-center"><FileText className="mx-auto mb-4 h-12 w-12 text-slate-300" /><h1 className="text-xl font-semibold text-slate-900">Invoice not found</h1><p className="mt-2 text-sm text-slate-500">This invoice link may be invalid or no longer available.</p></div>

  return <div className="min-h-screen bg-slate-50 py-8"><div className="mx-auto max-w-4xl px-4"><div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8"><div className="flex flex-col justify-between gap-4 border-b border-slate-200 pb-6 sm:flex-row"><div><div className="flex items-center gap-2 text-teal-700"><ShieldCheck className="h-5 w-5" /><span className="font-semibold">Mufasah Electronics</span></div><h1 className="mt-4 text-3xl font-bold text-slate-900">Invoice</h1><p className="mt-1 text-sm text-slate-500">{data.invoice_number}</p></div><div className="sm:text-right"><p className="text-xs uppercase tracking-wide text-slate-500">Issued</p><p className="mt-1 text-sm text-slate-800">{date(data.issued_at)}</p><p className="mt-3 text-xs uppercase tracking-wide text-slate-500">Valid until</p><p className="mt-1 text-sm font-medium text-slate-800">{date(data.expires_at)}</p></div></div><div className="grid gap-6 border-b border-slate-200 py-6 sm:grid-cols-2"><div><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Bill to</p><p className="mt-2 font-medium text-slate-900">{data.customer?.name || 'Customer'}</p><p className="mt-1 text-sm text-slate-500">{data.recipient_email}</p></div><div className="sm:text-right"><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</p><p className="mt-2 inline-flex rounded-full bg-emerald-50 px-3 py-1 text-sm font-medium text-emerald-700">{data.status.label}</p></div></div><div className="overflow-x-auto py-6"><table className="w-full text-sm"><thead className="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th className="px-3 py-3">Item</th><th className="px-3 py-3 text-right">Unit</th><th className="px-3 py-3 text-right">Discount</th><th className="px-3 py-3 text-right">VAT</th><th className="px-3 py-3 text-right">Total</th></tr></thead><tbody className="divide-y divide-slate-200">{(data.items || []).map((item) => <tr key={item.id}><td className="px-3 py-3 text-slate-800">{item.item_description}</td><td className="px-3 py-3 text-right">{money(item.unit_price)}</td><td className="px-3 py-3 text-right">{money(item.discount)}</td><td className="px-3 py-3 text-right">{data.vat_applicable ? `${item.tax_rate}%: ${money(item.tax)}` : 'Not applicable'}</td><td className="px-3 py-3 text-right font-medium">{money(item.total_price)}</td></tr>)}</tbody></table></div><div className="ml-auto max-w-sm space-y-2 text-sm"><div className="flex justify-between"><span className="text-slate-500">Subtotal</span><span>{money(data.subtotal)}</span></div><div className="flex justify-between"><span className="text-slate-500">Discount</span><span>-{money(data.discount_amount)}</span></div><div className="flex justify-between"><span className="text-slate-500">{data.vat_applicable ? `VAT (${data.tax_rate}%)` : 'VAT'}</span><span>{data.vat_applicable ? money(data.tax_amount) : 'Not applicable'}</span></div><div className="flex justify-between border-t border-slate-200 pt-3 text-lg font-semibold"><span>Total</span><span className="text-teal-700">{money(data.total_amount)}</span></div></div><div className="mt-6 rounded-lg bg-teal-50 p-4 text-sm text-teal-900"><div className="flex gap-2"><Mail className="mt-0.5 h-4 w-4 shrink-0" /><p>Your prices and tax treatment are fixed for 24 hours. Please present invoice number <strong>{data.invoice_number}</strong> when you are ready to redeem it.</p></div></div></div></div></div>
}
