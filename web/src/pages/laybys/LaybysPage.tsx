import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import {
  ArrowDownToLine, ArrowUpRight, Banknote, Ban, CalendarClock, ChartNoAxesCombined, Check, ChevronLeft, ChevronRight,
  ClipboardCheck, Copy, FileCheck2, FilePlus2, FileText, Filter, ListChecks, PackageCheck,
  Printer, ReceiptText, RotateCcw, Save, Search, Settings2, Undo2, UserPlus, Wallet, X, type LucideIcon,
} from 'lucide-react'
import Button, { type ButtonProps } from '@/components/ui/Button'
import CreateCustomerDialog from '@/components/customers/CreateCustomerDialog'
import type { CustomerSummary } from '@/api/customers'
import apiClient from '@/api/client'
import { formatMalawiDateTime, malawiDateInput } from '@/lib/datetime'
import { useAuthStore } from '@/stores/auth-store'
import {
  errorMessage, minorUnits, money, postOnce, printLayby, tenderLabels,
  type ActionData, type Layby, type LaybyPolicy, type LaybyQuote, type LedgerEntry, type Page, type Tender,
} from './layby-api'

const input = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm'
const card = 'space-y-4 rounded-xl border border-slate-200 bg-white p-5'
const statuses = { active: 'Collecting', ready: 'Ready for pickup', fulfilled: 'Picked up', cancelled: 'Cancelled', returned: 'Returned' }
const pretty = (value: string) => value.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase())
const text = (form: FormData, name: string) => String(form.get(name) ?? '').trim()
const date = (value: string) => formatMalawiDateTime(value, 'en-MW')

function ActionButton({ icon: Icon, children, ...props }: ButtonProps & { icon: LucideIcon }) {
  return <Button className="gap-2" {...props}>
    <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />
    {children}
  </Button>
}

function useBranchOptions(enabled = true) {
  return useQuery({
    queryKey: ['branch-options'],
    enabled,
    queryFn: async () =>
      (await apiClient.get<{ data: { id: number; name: string; code: string }[] }>('/admin/branch-options')).data.data,
  })
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return <label className="block space-y-1 text-sm font-medium text-slate-700"><span>{label}</span>{children}</label>
}

function ErrorMessage({ error }: { error: unknown }) {
  return error ? <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-800">{errorMessage(error)}</p> : null
}

function PrintButton({ title, lines, children }: { title: string; lines: [string, string][]; children: ReactNode }) {
  const [error, setError] = useState<unknown>(null)
  return <div><ActionButton type="button" icon={Printer} variant="secondary" onClick={() => {
    try { printLayby(title, lines); setError(null) } catch (failure) { setError(failure) }
  }}>{children}</ActionButton><ErrorMessage error={error} /></div>
}

type LaybyCustomer = { id: number; name: string; phone_number?: string }
type LookupResult = { device_id: number; customers: LaybyCustomer[] }

/** The lookup treats a digits-only term as a phone number, so it prefills that way. */
const looksLikePhone = (term: string) => /^[+\d][\d\s-]{5,}$/.test(term.trim())

function OpenLayby({ cashier, actorId, onOpen }: { cashier: boolean; actorId: number; onOpen: (layby: Layby) => void }) {
  const [selection, setSelection] = useState<LookupResult>()
  const [customerId, setCustomerId] = useState('')
  const [searchTerm, setSearchTerm] = useState('')
  const [serialTerm, setSerialTerm] = useState('')
  const [addingCustomer, setAddingCustomer] = useState(false)
  const [quote, setQuote] = useState<LaybyQuote>()
  const [copied, setCopied] = useState(false)
  const lookup = useMutation({
    mutationFn: async (params: { serial_number: string; customer: string }) =>
      (await apiClient.get<LookupResult>('/laybys/lookup', { params })).data,
    onSuccess: (result) => { setSelection(result); setCustomerId('') },
  })
  const quoteRequest = useMutation({
    mutationFn: async ({ path, payload }: { path: string; payload: ActionData }) =>
      (await apiClient.post<LaybyQuote>(path, payload)).data,
    onSuccess: (offer) => { setQuote(offer); setCopied(false) },
  })
  const open = useMutation({ mutationFn: (body: ActionData) => postOnce('/laybys', body, actorId), onSuccess: onOpen })
  const [error, setError] = useState<unknown>(null)

  function prepareQuote(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!selection) return
    const form = new FormData(event.currentTarget)
    const payload: ActionData = { device_id: selection.device_id, customer_id: Number(form.get('customer_id')) }
    try {
      if (text(form, 'price')) payload.price_minor = minorUnits(form.get('price'))
      if (text(form, 'discount')) payload.discount_minor = minorUnits(form.get('discount'))
      if (text(form, 'reason')) payload.reason = text(form, 'reason')
      setError(null)
      quoteRequest.mutate({ path: '/laybys/quote', payload })
    } catch (failure) { setError(failure) }
  }

  return <section className={card}>
    <h2 className="text-lg font-semibold">{cashier ? 'Open an agreement' : 'Prepare a pricing approval'}</h2>
    <form className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" onSubmit={(event) => {
      event.preventDefault()
      const form = new FormData(event.currentTarget)
      setSelection(undefined)
      setQuote(undefined)
      setSearchTerm(text(form, 'customer'))
      setSerialTerm(text(form, 'serial_number'))
      lookup.mutate({ serial_number: text(form, 'serial_number'), customer: text(form, 'customer') })
    }}>
      <Field label="Device serial"><input className={input} name="serial_number" required maxLength={100} /></Field>
      <Field label="Customer name, email, or phone"><input className={input} name="customer" required maxLength={100} /></Field>
      <ActionButton icon={Search} disabled={lookup.isPending}>Find device and customer</ActionButton>
    </form>
    {selection && <form onSubmit={prepareQuote} className="grid gap-3 sm:grid-cols-2">
      <div className="space-y-1">
        <div className="flex items-center justify-between gap-2">
          <span className="text-sm font-medium text-slate-700">Customer</span>
          <button type="button" onClick={() => setAddingCustomer(true)}
            className="inline-flex items-center gap-1 text-xs font-medium text-teal-700 hover:text-teal-900">
            <UserPlus className="h-3.5 w-3.5" aria-hidden="true" /> New customer
          </button>
        </div>
        <select name="customer_id" required className={input} value={customerId} onChange={(event) => setCustomerId(event.target.value)}>
          <option value="">{selection.customers.length ? 'Select customer' : 'No matching customer'}</option>
          {selection.customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name} {customer.phone_number ?? ''}</option>)}
        </select>
        {selection.customers.length === 0 && <p className="text-xs text-amber-800">
          Nobody is on file for “{searchTerm}”. Create the customer to continue.
        </p>}
      </div>
      {!cashier && <>
        <Field label="Approved unit price (MWK)"><input name="price" inputMode="decimal" className={input} placeholder="Use batch price" /></Field>
        <Field label="Approved discount (MWK)"><input name="discount" inputMode="decimal" className={input} /></Field>
        <Field label="Approval reason"><input name="reason" maxLength={500} className={input} /></Field>
      </>}
      <div className="self-end"><ActionButton icon={FileText} disabled={quoteRequest.isPending}>Prepare quote</ActionButton></div>
    </form>}
    {cashier && <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => {
      event.preventDefault()
      quoteRequest.mutate({ path: '/laybys/quote/preview', payload: { quote: text(new FormData(event.currentTarget), 'approved_quote') } })
    }}>
      <Field label="Supervisor quote code"><input name="approved_quote" className={input} required /></Field>
      <ActionButton icon={FileCheck2} variant="secondary" disabled={quoteRequest.isPending}>Load approved quote</ActionButton>
    </form>}
    {quote && <div className="space-y-3 rounded-lg bg-slate-50 p-4">
      <p className="font-semibold">{quote.customer_name} · {quote.description} · {quote.serial_number}</p>
      <p>Total {money(quote.total_minor)} · Minimum deposit {money(quote.minimum_deposit_minor)} · Due {quote.due_date}</p>
      <p className="text-sm">{quote.accepted_terms}</p>
      {cashier ? <form key={quote.quote} className="space-y-3" onSubmit={(event) => {
        event.preventDefault()
        const form = new FormData(event.currentTarget)
        try {
          const amount = minorUnits(form.get('amount'))
          if (amount < quote.minimum_deposit_minor || amount >= quote.total_minor) throw new Error('Enter a deposit that meets the minimum and leaves a balance.')
          setError(null)
          open.mutate({ quote: quote.quote, amount_minor: amount, terms_accepted: form.has('accepted'), cash_received: form.has('received') })
        } catch (failure) { setError(failure) }
      }}>
        <Field label="Cash deposit (MWK)"><input name="amount" className={input} inputMode="decimal" required /></Field>
        <label className="block text-sm"><input name="accepted" type="checkbox" required /> Customer reviewed and accepted the written terms.</label>
        <label className="block text-sm"><input name="received" type="checkbox" required /> Cash received and counted.</label>
        <ActionButton icon={Banknote} disabled={open.isPending}>Record deposit and reserve device</ActionButton>
        <p className="text-xs text-slate-600">If the request times out, retry the same submission before taking any additional cash.</p>
      </form> : <ActionButton icon={copied ? Check : Copy} variant="secondary" onClick={async () => {
        try { await navigator.clipboard.writeText(quote.quote); setCopied(true); setError(null) } catch (failure) { setError(failure) }
      }}>{copied ? 'Code copied' : 'Copy quote code for cashier'}</ActionButton>}
    </div>}
    <ErrorMessage error={error ?? open.error ?? quoteRequest.error ?? lookup.error} />
    {addingCustomer && <CreateCustomerDialog
      requireContact
      description="The new customer is selected for this agreement and receives the deposit reminders."
      initialName={looksLikePhone(searchTerm) ? '' : searchTerm}
      initialPhone={looksLikePhone(searchTerm) ? searchTerm : ''}
      onClose={() => setAddingCustomer(false)}
      onConflict={() => { setAddingCustomer(false); lookup.mutate({ serial_number: serialTerm, customer: searchTerm }) }}
      onCreated={(customer: CustomerSummary) => {
        setSelection((current) => current
          ? { ...current, customers: [
              { id: customer.id, name: customer.name, phone_number: customer.phone_number ?? undefined },
              ...current.customers.filter((row) => row.id !== customer.id),
            ] }
          : current)
        setCustomerId(String(customer.id))
        setAddingCustomer(false)
      }}
    />}
  </section>
}

const commandStyles = {
  collect: { icon: Banknote, variant: 'primary' },
  handover: { icon: PackageCheck, variant: 'primary' },
  extend: { icon: CalendarClock, variant: 'secondary' },
  cancel: { icon: Ban, variant: 'destructive' },
  return: { icon: Undo2, variant: 'secondary' },
  verify: { icon: ClipboardCheck, variant: 'primary' },
  reject: { icon: Ban, variant: 'destructive' },
  correct: { icon: RotateCcw, variant: 'secondary' },
  'refund-approve': { icon: ClipboardCheck, variant: 'primary' },
  'refund-claim': { icon: Wallet, variant: 'primary' },
  'refund-result': { icon: ArrowDownToLine, variant: 'primary' },
  'fiscal-reference': { icon: ReceiptText, variant: 'secondary' },
} satisfies Record<string, { icon: LucideIcon; variant: ButtonProps['variant'] }>

type CommandName = keyof typeof commandStyles
type Command = { action: CommandName; label: string; source?: LedgerEntry; claim?: LedgerEntry }

function Agreement({ agreement, cashier, actorId, onChange }: { agreement: Layby; cashier: boolean; actorId: number; onChange: () => void }) {
  const [command, setCommand] = useState<Command>()
  const [tender, setTender] = useState<Tender>('cash')
  const [localError, setLocalError] = useState<unknown>(null)
  const action = useMutation({
    mutationFn: ({ name, body }: { name: string; body: ActionData }) => postOnce(`/laybys/${agreement.id}/${name}`, body, actorId),
    onSuccess: () => { setCommand(undefined); onChange() },
  })
  const open = ['active', 'ready'].includes(agreement.status)
  const closing = ['cancelled', 'returned'].includes(agreement.status)
  const summary: [string, string][] = [
    ['Agreement', agreement.reference], ['Branch', String(agreement.branch_id)], ['Customer', agreement.customer_name],
    ['Device', `${agreement.description} · ${agreement.serial_number}`],
  ]
  const select = (selected: Command) => { setCommand(selected); action.reset(); setLocalError(null); setTender('cash') }
  const offer = (name: CommandName, label: string, source?: LedgerEntry, claim?: LedgerEntry) =>
    <ActionButton key={name} type="button" {...commandStyles[name]} onClick={() => select({ action: name, label, source, claim })}>
      {label}
    </ActionButton>

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!command) return
    const form = new FormData(event.currentTarget)
    const body: ActionData = {}
    for (const field of ['evidence', 'reference', 'serial_number', 'due_date', 'reason', 'outcome', 'document_type', 'occurred_at']) {
      const value = text(form, field)
      if (value) body[field] = value
    }
    for (const field of ['verified_pickup', 'physically_received', 'cash_received']) {
      if (form.has(field)) body[field] = true
    }
    if (command.source) body.entry_id = command.source.id
    if (command.claim) body.claim_id = command.claim.id
    if (['cancel', 'return'].includes(command.action)) body.faulty = form.has('faulty')
    try {
      if (command.action === 'collect') {
        body.amount_minor = minorUnits(form.get('amount'))
        body.tender = tender
      }
      setLocalError(null)
      action.mutate({ name: command.action, body })
    } catch (failure) { setLocalError(failure) }
  }

  return <section className={card}>
    <header className="flex flex-wrap items-start justify-between gap-3">
      <div><p className="text-xs font-semibold uppercase tracking-widest text-teal-700">{agreement.reference}</p>
        <h2 className="text-xl font-semibold">{agreement.customer_name}</h2>
        <p className="text-sm text-slate-600">{agreement.description} · {agreement.serial_number} · Branch {agreement.branch_id}</p>
      </div>
      <div className="rounded-full bg-teal-50 px-3 py-1 text-sm font-medium text-teal-900">{agreement.overdue ? 'Overdue' : statuses[agreement.status]}</div>
    </header>
    <dl className="grid gap-3 rounded-lg bg-slate-50 p-4 sm:grid-cols-4">
      {([['Total', money(agreement.total_minor)], ['Net received', money(agreement.received_minor)],
        [closing ? 'Refund due' : 'Balance', money(closing ? agreement.refund_due_minor : agreement.balance_minor)],
        ['Payment deadline', agreement.due_date]]).map(([label, value]) =>
        <div key={label}><dt className="text-xs text-slate-600">{label}</dt><dd className="font-semibold">{value}</dd></div>)}
    </dl>
    <div className="flex flex-wrap gap-2">
      <PrintButton title="Mufassah layby agreement" lines={[...summary,
        ['Accepted', date(agreement.created_at)], ['Total', money(agreement.total_minor)],
        ['Original payment deadline', agreement.original_due_date], ['Current payment deadline', agreement.due_date],
        ['Terms accepted', agreement.accepted_terms]]}>Print agreement</PrintButton>
      {cashier && agreement.status === 'active' && !agreement.overdue && offer('collect', 'Record instalment')}
      {cashier && agreement.status === 'ready' && offer('handover', 'Confirm physical pickup')}
      {!cashier && agreement.status === 'active' && offer('extend', 'Extend deadline')}
      {!cashier && open && offer('cancel', 'Cancel agreement')}
      {!cashier && agreement.status === 'fulfilled' && offer('return', 'Record physical return')}
      {cashier && agreement.sale_id && <Link className="btn btn-secondary h-10 gap-2 px-4 text-sm" to={`/customer-service/sales/${agreement.sale_id}`}>
        <ArrowUpRight className="h-4 w-4 shrink-0" aria-hidden="true" /> Open completed sale
      </Link>}
    </div>
    {command && <form onSubmit={submit} className="space-y-3 rounded-lg border border-teal-200 bg-teal-50 p-4">
      <h3 className="font-semibold">{command.label}{command.source ? ` · #${command.source.id}` : ''}</h3>
      {command.action === 'collect' ? <>
        <Field label="Payment (MWK)"><input name="amount" inputMode="decimal" required className={input} /></Field>
        <Field label="Tender"><select value={tender} onChange={(event) => setTender(event.target.value as Tender)} className={input}>
          {Object.entries(tenderLabels).map(([value, label]) => <option value={value} key={value}>{label}</option>)}
        </select></Field>
        {tender === 'cash'
          ? <label className="block text-sm"><input name="cash_received" type="checkbox" required /> Cash received and counted.</label>
          : <Field label="Official merchant reference"><input name="reference" required maxLength={150} className={input} /></Field>}
      </> : <Field label="Reason or verification evidence"><textarea name="evidence" required maxLength={1000} className={input} /></Field>}
      {command.action === 'extend' && <Field label="Customer-agreed deadline"><input name="due_date" type="date" required className={input} /></Field>}
      {['handover', 'return'].includes(command.action) && <Field label="Scan or enter the physical serial"><input name="serial_number" required className={input} /></Field>}
      {command.action === 'handover' && <label className="block text-sm"><input name="verified_pickup" type="checkbox" required /> Verified the customer or authorized collector and matched the physical serial.</label>}
      {command.action === 'handover' && <p className="text-sm">Release the device after the agreement shows Picked up.</p>}
      {command.action === 'return' && <label className="block text-sm"><input name="physically_received" type="checkbox" required /> Received and inspected the device.</label>}
      {['cancel', 'return'].includes(command.action) && <label className="block text-sm"><input name="faulty" type="checkbox" /> Device must be quarantined as faulty.</label>}
      {command.action === 'correct' && <Field label="Correction type"><select name="reason" className={input}><option value="erroneous_posting">Money never received</option><option value="external_reversal">Payment reversed externally</option></select></Field>}
      {command.action === 'refund-result' && <>
        <Field label="Payout result"><select name="outcome" className={input}><option value="paid">Paid and verified</option><option value="uncertain">Uncertain · lock payout for review</option>{!cashier && <option value="failed">Confirmed unpaid</option>}</select></Field>
        {!cashier && <Field label="Actual payout time in Malawi, if earlier"><input name="occurred_at" type="datetime-local" className={input} /></Field>}
        <Field label="Receipt or merchant reference for a confirmed payout"><input name="reference" maxLength={150} className={input} /></Field>
      </>}
      {command.action === 'refund-claim' && <p className="text-sm">Record this claim before paying. Pay {money(command.source?.amount_minor ?? 0)} through the original tender, then record the result.</p>}
      {command.action === 'fiscal-reference' && <>
        <Field label="Fiscal document"><select name="document_type" className={input}><option value="tax_invoice">Tax invoice</option><option value="credit_note">Credit note</option></select></Field>
        <Field label="MRA document reference"><input name="reference" required maxLength={150} className={input} /></Field>
      </>}
      <div className="flex gap-2">
        <ActionButton icon={Check} variant={commandStyles[command.action].variant === 'destructive' ? 'destructive' : 'primary'} disabled={action.isPending}>Confirm</ActionButton>
        <ActionButton type="button" icon={X} variant="secondary" disabled={action.isPending} onClick={() => setCommand(undefined)}>Close</ActionButton>
      </div>
      <ErrorMessage error={localError ?? action.error} />
    </form>}
    <h3 className="font-semibold">Agreement history</h3>
    <ul className="divide-y divide-slate-200">
      {agreement.entries.map((entry) => {
        const children = agreement.entries.filter((row) => row.source_id === entry.id)
        const approval = children.find((row) => row.kind === 'refund_approved')
        const claim = children.filter((row) => row.kind === 'refund_claimed').at(-1)
        const refundState = children.filter((row) => row.kind.startsWith('refund') || row.kind === 'correction').at(-1)
        const canConfirm = refundState?.kind === 'refund_claimed' && claim?.actor_id === actorId
        const canReconcile = !cashier && claim?.actor_id !== actorId && approval?.actor_id !== actorId
          && ['refund_claimed', 'refund_uncertain'].includes(refundState?.kind ?? '')
        return <li key={entry.id} className="space-y-2 py-3 text-sm">
          <div className="flex flex-wrap justify-between gap-2"><span className="font-medium">#{entry.id} · {pretty(entry.kind)}</span><span>{date(entry.occurred_at ?? entry.created_at)}</span></div>
          {entry.amount_minor > 0 && <p>{money(entry.amount_minor)} {entry.tender && `· ${tenderLabels[entry.tender]}`}</p>}
          {entry.reference && <p>Reference {entry.reference}{entry.merchant_channel && ` · ${entry.merchant_channel}`}</p>}
          {entry.evidence && <p className="text-slate-600">{entry.evidence}</p>}
          <div className="flex flex-wrap gap-2">
            {!cashier && entry.kind === 'submitted' && !children.some((row) => ['collection', 'rejected'].includes(row.kind)) &&
              <>{offer('verify', 'Verify settlement', entry)}{offer('reject', 'Reject submission', entry)}</>}
            {!cashier && entry.kind === 'collection' && (!refundState || ['refund_approved', 'refund_failed'].includes(refundState.kind))
              && offer('correct', 'Post correction', entry)}
            {!cashier && closing && entry.kind === 'collection' && !refundState && offer('refund-approve', 'Approve original-tender refund', entry)}
            {entry.kind === 'collection' && approval?.actor_id !== actorId && ['refund_approved', 'refund_failed'].includes(refundState?.kind ?? '')
              && ((cashier && entry.tender === 'cash') || (!cashier && entry.tender !== 'cash')) && offer('refund-claim', 'Claim payout', entry)}
            {entry.kind === 'collection' && claim && (canConfirm || canReconcile) && offer('refund-result', 'Record or reconcile payout result', entry, claim)}
            {!cashier && ['collection', 'refund', 'correction', 'handover', 'return'].includes(entry.kind) && offer('fiscal-reference', 'Link fiscal document', entry)}
            {['collection', 'refund'].includes(entry.kind) && <PrintButton
              title={entry.kind === 'collection' ? 'Mufassah layby payment receipt' : 'Mufassah layby refund receipt'}
              lines={[...summary, ['Receipt number', `LB-R-${entry.id}`], ['Recorded', date(entry.occurred_at ?? entry.created_at)], ['Amount', money(entry.amount_minor)],
                ['Tender', entry.tender ? tenderLabels[entry.tender] : ''],
                ...(entry.kind === 'collection' ? [['Balance after payment', money(entry.details?.balance_minor ?? 0)], ['Deadline at payment', entry.details?.due_date ?? agreement.due_date]] as [string, string][] : []),
                ['Document', 'Layby payment record. Fiscal documents are issued through the approved fiscal workflow.']]}>Print receipt</PrintButton>}
          </div>
        </li>
      })}
    </ul>
  </section>
}

function Policy() {
  const [branch, setBranch] = useState('')
  const branches = useBranchOptions()

  const policy = useQuery({ queryKey: ['layby-policy', branch], enabled: !!branch, queryFn: async () =>
    (await apiClient.get<LaybyPolicy>(`/laybys/policy/${branch}`)).data })
  const save = useMutation({ mutationFn: (payload: LaybyPolicy) => apiClient.put(`/laybys/policy/${branch}`, payload), onSuccess: () => policy.refetch() })
  return <section className={card}>
    <h2 className="text-lg font-semibold">Branch rollout policy</h2>
    <Field label="Branch"><select className={input} value={branch} onChange={(event) => { setBranch(event.target.value); save.reset() }}>
      <option value="">Select branch</option>
      {branches.data?.map((row) => <option key={row.id} value={row.id}>{row.name} · {row.code}</option>)}
    </select></Field>
    <ErrorMessage error={branches.error ?? policy.error ?? save.error} />
    {policy.data && <form key={branch} className="grid gap-3 sm:grid-cols-2" onSubmit={(event) => {
      event.preventDefault()
      const form = new FormData(event.currentTarget)
      const channels: LaybyPolicy['merchant_channels'] = {}
      for (const channel of ['mobile_money', 'bank_transfer', 'card'] as const) {
        if (text(form, channel)) channels[channel] = text(form, channel)
      }
      save.mutate({ enabled: form.has('enabled'), deposit_percent: Number(form.get('deposit_percent')), term_days: Number(form.get('term_days')),
        vat_basis_points: Number(form.get('vat_basis_points')), review_reference: text(form, 'review_reference') || null, merchant_channels: channels })
    }}>
      <Field label="Minimum deposit (%)"><input type="number" min={1} max={99} name="deposit_percent" required defaultValue={policy.data.deposit_percent} className={input} /></Field>
      <Field label="Payment term (days)"><input type="number" min={1} max={365} name="term_days" required defaultValue={policy.data.term_days} className={input} /></Field>
      <Field label="VAT rate (basis points, 1750 = 17.50%)"><input type="number" min={0} max={10000} name="vat_basis_points" required defaultValue={policy.data.vat_basis_points} className={input} /></Field>
      <Field label="Finance and business approval reference"><input name="review_reference" maxLength={255} defaultValue={policy.data.review_reference ?? ''} className={input} /></Field>
      {(['mobile_money', 'bank_transfer', 'card'] as const).map((channel) =>
        <Field key={channel} label={`Dedicated ${tenderLabels[channel].toLowerCase()} channel`}><input name={channel} maxLength={100} defaultValue={policy.data?.merchant_channels[channel]} className={input} /></Field>)}
      <label className="self-end text-sm"><input name="enabled" type="checkbox" defaultChecked={policy.data.enabled} /> Enable new agreements at this branch</label>
      <div><ActionButton icon={Save} disabled={save.isPending}>Save policy</ActionButton>{save.isSuccess && <p className="text-sm text-teal-800">Policy saved.</p>}</div>
    </form>}
  </section>
}

type Report = {
  entries: Page<LedgerEntry>
  by_shift_and_tender: { branch_id: number; shift_id: number | null; tender: Tender; kind: string; amount_minor: number }[]
  open_deposits_minor: number
  refunds_due_minor: number
  returns: Page<{ id: number; reference: string; total_minor: number; returned_at: string }>
}

function Reconciliation({ global }: { global: boolean }) {
  const branches = useBranchOptions(global)
  const today = malawiDateInput()
  const [filters, setFilters] = useState({ from: today, to: today, branch_id: '' })
  const [page, setPage] = useState(1)
  const [returnsPage, setReturnsPage] = useState(1)
  const report = useQuery({ queryKey: ['layby-report', filters, page, returnsPage], queryFn: async () =>
    (await apiClient.get<Report>('/laybys/report', { params: { ...filters, branch_id: filters.branch_id || undefined, page, returns_page: returnsPage } })).data })
  return <section className={card}>
    <h2 className="text-lg font-semibold">Reconciliation</h2>
    <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => {
      event.preventDefault()
      const form = new FormData(event.currentTarget)
      setFilters({ from: text(form, 'from'), to: text(form, 'to'), branch_id: text(form, 'branch_id') })
      setPage(1)
      setReturnsPage(1)
    }}>
      <Field label="From"><input type="date" name="from" required defaultValue={filters.from} className={input} /></Field>
      <Field label="To"><input type="date" name="to" required defaultValue={filters.to} className={input} /></Field>
      {global && <Field label="Branch">
        <select name="branch_id" defaultValue={filters.branch_id} disabled={branches.isPending} className={input}>
          <option value="">All branches</option>
          {branches.data?.map((branch) => <option key={branch.id} value={branch.id}>{branch.name} · {branch.code}</option>)}
        </select>
      </Field>}
      <ActionButton icon={Filter}>Apply filters</ActionButton>
    </form>
    <ErrorMessage error={branches.error ?? report.error} />
    {report.data && <>
      <p className="rounded-lg bg-slate-50 p-3">Current open deposits <strong>{money(report.data.open_deposits_minor)}</strong> · Current refunds due <strong>{money(report.data.refunds_due_minor)}</strong></p>
      <h3 className="font-semibold">Money by branch, shift, and tender</h3>
      <ul className="text-sm">{report.data.by_shift_and_tender.map((row) =>
        <li key={`${row.branch_id}-${row.shift_id}-${row.tender}-${row.kind}`}>Branch {row.branch_id} · Shift {row.shift_id ?? '—'} · {tenderLabels[row.tender]} · {pretty(row.kind)} · {money(row.amount_minor)}</li>)}</ul>
      <h3 className="font-semibold">Ledger and fiscal references</h3>
      <ul className="text-sm">{report.data.entries.data.map((row) =>
        <li key={row.id}>#{row.id} · Agreement {row.layby_id} · {date(row.occurred_at ?? row.created_at)} · {pretty(row.kind)} · {row.amount_minor ? money(row.amount_minor) : row.reference ?? '—'}{row.source_id ? ` · Source #${row.source_id}` : ''}</li>)}</ul>
      <Pager page={report.data.entries} onChange={setPage} />
      <h3 className="font-semibold">Physical returns in period</h3>
      <ul className="text-sm">{report.data.returns.data.map((row) =>
        <li key={row.id}>{row.reference} · {date(row.returned_at)} · {money(row.total_minor)}</li>)}</ul>
      <Pager page={report.data.returns} onChange={setReturnsPage} />
    </>}
  </section>
}

function Pager({ page, onChange }: { page: Page<unknown>; onChange: (next: number) => void }) {
  if (page.last_page < 2) return null
  return <div className="flex items-center gap-2 text-sm">
    <ActionButton icon={ChevronLeft} variant="secondary" disabled={page.current_page <= 1} onClick={() => onChange(page.current_page - 1)}>Previous</ActionButton>
    <span>Page {page.current_page} of {page.last_page}</span>
    <ActionButton icon={ChevronRight} variant="secondary" disabled={page.current_page >= page.last_page} onClick={() => onChange(page.current_page + 1)}>Next</ActionButton>
  </div>
}

export default function LaybysPage() {
  const user = useAuthStore((state) => state.user)
  const roles = user?.roles?.map((role) => typeof role === 'string' ? role : role.name) ?? []
  const cashier = roles.includes('customer_service')
  const global = roles.includes('super_admin')
  const client = useQueryClient()
  const [tab, setTab] = useState('list')
  const [params] = useSearchParams()
  const [selected, setSelected] = useState<number | undefined>(() => Number(params.get('agreement')) || undefined)
  const detailPanel = useRef<HTMLDivElement>(null)
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const list = useQuery({ queryKey: ['laybys', 'list', search, status, page], enabled: tab === 'list', queryFn: async () =>
    (await apiClient.get<Page<Layby>>('/laybys', { params: { search: search || undefined, status: status || undefined, page } })).data })
  const detail = useQuery({ queryKey: ['laybys', 'detail', selected], enabled: !!selected && tab === 'list', queryFn: async () =>
    (await apiClient.get<Layby>(`/laybys/${selected}`)).data })
  const visibleAgreement = tab === 'list' ? detail.data?.id : undefined
  useEffect(() => {
    if (visibleAgreement) detailPanel.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }, [visibleAgreement])
  function refresh() {
    void client.invalidateQueries({ queryKey: ['laybys'] })
    void client.invalidateQueries({ queryKey: ['layby-report'] })
  }
  if (!user) return null
  return <div className="mx-auto max-w-6xl space-y-5 p-4 text-slate-900 lg:p-8">
    <header><h1 className="text-2xl font-bold">Layby</h1><p className="text-sm text-slate-600">Manage branch reservations, instalments, pickup, and refunds.</p></header>
    <nav className="flex flex-wrap gap-2" aria-label="Layby sections">
      <ActionButton icon={ListChecks} variant={tab === 'list' ? 'primary' : 'secondary'} aria-pressed={tab === 'list'} onClick={() => setTab('list')}>Agreements</ActionButton>
      <ActionButton icon={cashier ? FilePlus2 : FileCheck2} variant={tab === 'open' ? 'primary' : 'secondary'} aria-pressed={tab === 'open'} onClick={() => setTab('open')}>{cashier ? 'New agreement' : 'Approve pricing'}</ActionButton>
      {!cashier && <ActionButton icon={ChartNoAxesCombined} variant={tab === 'report' ? 'primary' : 'secondary'} aria-pressed={tab === 'report'} onClick={() => setTab('report')}>Reconciliation</ActionButton>}
      {global && <ActionButton icon={Settings2} variant={tab === 'policy' ? 'primary' : 'secondary'} aria-pressed={tab === 'policy'} onClick={() => setTab('policy')}>Branch policy</ActionButton>}
    </nav>
    {tab === 'open' && <OpenLayby cashier={cashier} actorId={user.id} onOpen={(agreement) => {
      client.setQueryData(['laybys', 'detail', agreement.id], agreement)
      setSelected(agreement.id); setTab('list'); refresh()
    }} />}
    {tab === 'report' && <Reconciliation global={global} />}
    {tab === 'policy' && <Policy />}
    {tab === 'list' && <>
      <section className={card}>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Search by reference, customer, or serial"><input value={search} maxLength={100} onChange={(event) => { setSearch(event.target.value); setPage(1) }} className={input} /></Field>
          <Field label="Status"><select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1) }} className={input}>
            <option value="">All statuses</option><option value="overdue">Overdue</option>
            {Object.entries(statuses).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select></Field>
        </div>
        <ErrorMessage error={list.error} />
        {list.isLoading && <p>Loading agreements…</p>}
        {list.data?.data.length === 0 && <p className="text-sm text-slate-600">No matching agreements.</p>}
        <ul className="divide-y divide-slate-200">{list.data?.data.map((row) =>
          <li key={row.id}><button type="button" aria-pressed={selected === row.id} className="flex w-full flex-wrap items-center justify-between gap-2 rounded-lg px-2 py-3 text-left text-sm hover:bg-teal-50 aria-pressed:bg-teal-50 focus-visible:outline-2 focus-visible:outline-teal-700" onClick={() => setSelected(row.id)}>
            <span className="flex items-center gap-2"><ReceiptText className="h-4 w-4 shrink-0 text-teal-700" aria-hidden="true" /><span><strong>{row.customer_name}</strong> · {row.reference} · {row.description}</span></span>
            <span className="flex items-center gap-2">{row.overdue ? 'Overdue' : statuses[row.status]} · {money(row.total_minor)}<ChevronRight className="h-4 w-4 shrink-0 text-teal-700" aria-hidden="true" /></span>
          </button></li>)}</ul>
        {list.data && <Pager page={list.data} onChange={setPage} />}
      </section>
      <ErrorMessage error={detail.error} />
      {detail.data && <div ref={detailPanel}><Agreement key={detail.data.id} agreement={detail.data} cashier={cashier} actorId={user.id} onChange={refresh} /></div>}
    </>}
  </div>
}
