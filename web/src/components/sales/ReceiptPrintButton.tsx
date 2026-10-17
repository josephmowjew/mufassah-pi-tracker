import { Printer } from 'lucide-react'

interface ReceiptPrintButtonProps {
  onClick: () => void
  saleNumber?: string
}

export default function ReceiptPrintButton({ onClick, saleNumber }: ReceiptPrintButtonProps) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={`Print customer and company receipts${saleNumber ? ` for ${saleNumber}` : ''}`}
      className="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-teal-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600"
    >
      <Printer className="h-4 w-4 shrink-0" aria-hidden="true" />
      Print receipts
    </button>
  )
}
