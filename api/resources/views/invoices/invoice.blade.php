<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 12px; margin: 28px; }
        h1 { margin: 0; font-size: 24px; }
        h2 { font-size: 16px; margin: 0 0 12px; }
        .muted { color: #64748b; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #0f766e; padding-bottom: 18px; }
        .company { font-weight: bold; font-size: 16px; }
        .meta { margin-top: 20px; width: 100%; border-collapse: collapse; }
        .meta td { padding: 5px 0; vertical-align: top; }
        .meta td:first-child { width: 150px; color: #64748b; }
        .items { width: 100%; border-collapse: collapse; margin-top: 24px; }
        .items th { background: #f1f5f9; text-align: left; padding: 9px; border-bottom: 1px solid #cbd5e1; }
        .items td { padding: 9px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .right { text-align: right; }
        .totals { width: 290px; margin: 18px 0 0 auto; border-collapse: collapse; }
        .totals td { padding: 5px; }
        .totals td:last-child { text-align: right; }
        .total { border-top: 2px solid #0f766e; font-size: 15px; font-weight: bold; }
        .vat-box { margin-top: 20px; padding: 10px; background: #f0fdfa; border: 1px solid #99f6e4; }
        .footer { margin-top: 32px; border-top: 1px solid #cbd5e1; padding-top: 12px; color: #64748b; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <div class="company">{{ $company['name'] }}</div>
            <div>{{ $company['address'] }}</div>
            <div>{{ $company['phone'] }} · {{ $company['email'] }}</div>
            <div class="muted">TPIN: {{ $company['tpin'] }}</div>
        </div>
        <div class="right">
            <h1>INVOICE</h1>
            <div><strong>{{ $invoice->invoice_number }}</strong></div>
            <div class="muted">Issued: {{ $invoice->issued_at?->toLocalFormat('Y-m-d H:i') }}</div>
            <div class="muted">Valid until: {{ $invoice->expires_at?->toLocalFormat('Y-m-d H:i') }}</div>
        </div>
    </div>

    <table class="meta">
        <tr>
            <td>Bill to</td>
            <td>
                <strong>{{ $invoice->customer?->name }}</strong><br>
                {{ $invoice->recipient_email }}<br>
                @if ($invoice->branch)
                    Branch: {{ $invoice->branch->name }}<br>
                @endif
            </td>
        </tr>
        <tr>
            <td>Payment terms</td>
            <td>Price locked for 24 hours. This invoice is not a receipt until redeemed.</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>#</th>
                <th>Description</th>
                <th class="right">Unit price</th>
                <th class="right">Discount</th>
                <th class="right">VAT</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item->item_description }}</td>
                    <td class="right">MWK {{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="right">MWK {{ number_format((float) $item->discount, 2) }}</td>
                    <td class="right">
                        @if ($invoice->vat_applicable)
                            {{ number_format((float) $item->tax_rate, 2) }}%: MWK {{ number_format((float) $item->tax, 2) }}
                        @else
                            Not applicable
                        @endif
                    </td>
                    <td class="right">MWK {{ number_format((float) $item->total_price, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td>MWK {{ number_format((float) $invoice->subtotal, 2) }}</td></tr>
        <tr><td>Discount</td><td>-MWK {{ number_format((float) $invoice->discount_amount, 2) }}</td></tr>
        <tr>
            <td>{{ $invoice->vat_applicable ? $invoice->tax_label . ' (' . number_format((float) $invoice->tax_rate, 2) . '%)' : 'VAT' }}</td>
            <td>{{ $invoice->vat_applicable ? 'MWK ' . number_format((float) $invoice->tax_amount, 2) : 'Not applicable' }}</td>
        </tr>
        <tr class="total"><td>Total</td><td>MWK {{ number_format((float) $invoice->total_amount, 2) }}</td></tr>
    </table>

    <div class="vat-box">
        <strong>Tax treatment:</strong>
        @if ($invoice->vat_applicable)
            {{ $invoice->tax_label }} is included at {{ number_format((float) $invoice->tax_rate, 2) }}% and was calculated on the discounted line amount.
        @else
            VAT is not applicable to this invoice; no VAT was charged.
        @endif
    </div>

    @if ($invoice->notes)
        <p><strong>Notes:</strong> {{ $invoice->notes }}</p>
    @endif

    <div class="footer">
        Please present invoice number <strong>{{ $invoice->invoice_number }}</strong> when redeeming this offer.
        The exact reserved device(s) remain subject to the 24-hour validity period.
    </div>
</body>
</html>
