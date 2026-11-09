import type { Shift } from '@/types'

export function LaybyShiftTotals({ totals }: { totals: Shift['layby_tender_totals_minor'] }) {
  const rows = Object.entries(totals ?? {})
  if (rows.length === 0) return null
  const money = (minor = 0) => `MWK ${(minor / 100).toLocaleString('en-MW', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

  return <section className="space-y-2 rounded-lg border border-slate-200 bg-white p-4">
    <h3 className="font-semibold text-slate-900">Layby payments and payouts</h3>
    <ul className="space-y-1 text-sm text-slate-700">
      {rows.map(([tender, amounts]) => (
        <li key={tender}>
          <span className="capitalize">{tender.replaceAll('_', ' ')}</span>
          {' · Collected '}{money(amounts.collection)}{' · Paid out '}{money(amounts.refund)}{' · Corrected '}{money(amounts.correction)}
        </li>
      ))}
    </ul>
  </section>
}
