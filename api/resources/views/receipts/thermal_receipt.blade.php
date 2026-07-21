<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - {{ $sale->sale_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 14px;
            line-height: 1.4;
            width: 80mm;
            padding: 3mm;
            background: #fff;
            color: #000;
            font-weight: bold;
        }

        .receipt {
            width: 100%;
        }

        .header {
            text-align: center;
            border-bottom: 1px dashed #000;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }

        .company-name {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
            text-transform: uppercase;
        }

        .company-info {
            font-size: 12px;
            margin-bottom: 3px;
            font-weight: bold;
        }

        .receipt-info {
            margin-bottom: 10px;
            font-size: 13px;
        }

        .receipt-info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }

        .divider {
            border-top: 1px dashed #000;
            margin: 8px 0;
        }

        .items-section {
            margin-bottom: 10px;
        }

        .item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 13px;
        }

        .item-name {
            flex: 1;
        }

        .item-price {
            text-align: right;
            white-space: nowrap;
        }

        .totals-section {
            border-top: 1px dashed #000;
            padding-top: 8px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 13px;
        }

        .total-row.final {
            font-weight: bold;
            font-size: 16px;
            border-top: 1px solid #000;
            padding-top: 5px;
            margin-top: 5px;
        }

        .payment-section {
            border-top: 1px dashed #000;
            padding-top: 8px;
            margin-top: 10px;
            font-size: 13px;
        }

        .barcode {
            margin: 10px 0;
            text-align: center;
        }

        .footer {
            text-align: center;
            border-top: 1px dashed #000;
            padding-top: 10px;
            margin-top: 10px;
            font-size: 11px;
        }

        .footer p {
            margin-bottom: 5px;
        }

        .thank-you {
            font-weight: bold;
            font-size: 13px;
            margin-bottom: 8px;
        }

        @media print {
            body {
                width: 80mm;
                padding: 0;
            }

            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="receipt">
        <!-- Header -->
        <div class="header">
            <div class="company-name">{{ $company['name'] }}</div>
            @if($company['address'])
                <div class="company-info">{{ $company['address'] }}</div>
            @endif
            @if($company['phone'])
                <div class="company-info">{{ $company['phone'] }}</div>
            @endif
            @if($company['tpin'])
                <div class="company-info">T-PIN: {{ $company['tpin'] }}</div>
            @endif
        </div>

        <!-- Receipt Info -->
        <div class="receipt-info">
            <div class="receipt-info-row">
                <span>Date:</span>
                <span>{{ $sale->sale_date->format('Y-m-d H:i') }}</span>
            </div>
            <div class="receipt-info-row">
                <span>Receipt #:</span>
                <span>{{ $sale->sale_number }}</span>
            </div>
            @if($customer)
                <div class="receipt-info-row">
                    <span>Customer:</span>
                    <span>{{ $customer->name }}</span>
                </div>
            @endif
            @if($soldBy)
                <div class="receipt-info-row">
                    <span>Cashier:</span>
                    <span>{{ $soldBy->name }}</span>
                </div>
            @endif
        </div>

        <div class="divider"></div>

        <!-- Items -->
        <div class="items-section">
            @foreach($items as $item)
                <div class="item">
                    <div class="item-name">
                        {{ \Illuminate\Support\Str::limit($item->item_description, 35) }}
                    </div>
                    <div class="item-price">
                        {{ number_format($item->total_price, 2) }}
                    </div>
                </div>
            @endforeach
        </div>

        <div class="divider"></div>

        <!-- Totals -->
        <div class="totals-section">
            <div class="total-row">
                <span>Subtotal:</span>
                <span>{{ number_format($sale->subtotal, 2) }}</span>
            </div>
            @if($sale->vat_applicable && $sale->tax_amount > 0)
                <div class="total-row">
                    <span>VAT (17.5%):</span>
                    <span>{{ number_format($sale->tax_amount, 2) }}</span>
                </div>
            @endif
            @if($sale->discount_amount > 0)
                <div class="total-row">
                    <span>Discount:</span>
                    <span>-{{ number_format($sale->discount_amount, 2) }}</span>
                </div>
            @endif
            <div class="total-row final">
                <span>TOTAL:</span>
                <span>{{ number_format($sale->total_amount, 2) }}</span>
            </div>
        </div>

        <!-- Payment Info -->
        <div class="payment-section">
            <div class="total-row">
                <span>Paid by:</span>
                <span>{{ $sale->payment_method->label() }}</span>
            </div>
            @if($sale->payment_reference)
                <div class="total-row">
                    <span>Reference:</span>
                    <span>{{ $sale->payment_reference }}</span>
                </div>
            @endif
        </div>

        <!-- Barcode -->
        <div class="barcode">
            {!! $barcode !!}
        </div>

        <!-- Footer -->
        <div class="footer">
            <div class="thank-you">THANK YOU FOR YOUR PURCHASE!</div>
            @if($company['email'])
                <p>{{ $company['email'] }}</p>
            @endif
        </div>
    </div>

    <script>
        // Auto print when page loads
        window.onload = function() { window.print(); };
    </script>
</body>
</html>
