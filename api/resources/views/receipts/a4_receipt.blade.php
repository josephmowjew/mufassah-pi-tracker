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
            font-family: 'Arial', sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: #1f2937;
            background: #f5f5f5;
            font-weight: 600;
        }

        .receipt-container {
            max-width: 210mm;
            margin: 20px auto;
            padding: 20mm;
            background: #fff;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }

        .company-info {
            flex: 1;
        }

        .company-name {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #333;
        }

        .company-details {
            font-size: 13px;
            color: #374151;
            line-height: 1.6;
        }

        .company-details p {
            margin-bottom: 5px;
        }

        .receipt-title {
            text-align: right;
        }

        .receipt-title h1 {
            font-size: 32px;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }

        .receipt-number {
            font-size: 18px;
            color: #374151;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .info-box {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            border-left: 4px solid #333;
        }

        .info-box h3 {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #333;
            text-transform: uppercase;
        }

        .info-box p {
            font-size: 13px;
            margin-bottom: 5px;
            color: #374151;
        }

        .info-box strong {
            color: #333;
        }

        .items-table {
            width: 100%;
            margin-bottom: 30px;
            border-collapse: collapse;
        }

        .items-table thead {
            background: #333;
            color: #fff;
        }

        .items-table th {
            padding: 12px 10px;
            text-align: left;
            font-weight: bold;
            font-size: 13px;
            text-transform: uppercase;
        }

        .items-table th:last-child {
            text-align: right;
        }

        .items-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #eee;
            font-size: 13px;
            font-weight: 600;
        }

        .items-table td:last-child {
            text-align: right;
            font-weight: bold;
        }

        .items-table tbody tr:nth-child(even) {
            background: #f9f9f9;
        }

        .items-table tbody tr:hover {
            background: #f0f0f0;
        }

        .totals-section {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 30px;
        }

        .totals-box {
            width: 300px;
            background: #f9f9f9;
            padding: 20px;
            border-radius: 5px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #ddd;
            font-size: 14px;
            font-weight: 600;
        }

        .total-row:last-child {
            border-bottom: none;
        }

        .total-row.final {
            font-size: 18px;
            font-weight: bold;
            color: #333;
            border-top: 2px solid #333;
            border-bottom: none;
            margin-top: 10px;
            padding-top: 15px;
        }

        .payment-section {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 30px;
        }

        .payment-section h3 {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #333;
        }

        .payment-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .payment-item {
            display: flex;
            flex-direction: column;
        }

        .payment-item label {
            font-size: 12px;
            color: #374151;
            margin-bottom: 3px;
        }

        .payment-item span {
            font-size: 14px;
            font-weight: bold;
            color: #333;
        }

        .codes-section {
            display: flex;
            justify-content: space-around;
            align-items: center;
            padding: 30px 0;
            border-top: 1px solid #ddd;
            border-bottom: 1px solid #ddd;
            margin-bottom: 30px;
        }

        .code-item {
            text-align: center;
        }

        .code-item p {
            font-size: 12px;
            color: #374151;
            margin-bottom: 10px;
        }

        .qrcode img {
            width: 120px;
            height: 120px;
            border: 1px solid #ddd;
            padding: 10px;
            background: #fff;
        }

        .footer {
            text-align: center;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 5px;
        }

        .footer .thank-you {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #333;
        }

        .footer p {
            font-size: 13px;
            color: #374151;
            margin-bottom: 8px;
        }

        .footer .return-policy {
            font-size: 12px;
            color: #4b5563;
            line-height: 1.6;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        @media print {
            body {
                background: #fff;
            }

            .receipt-container {
                box-shadow: none;
                margin: 0;
                padding: 15mm;
            }

            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <!-- Header -->
        <div class="header">
            <div class="company-info">
                @if($company['logo'])
                    <img src="{{ $company['logo'] }}" alt="Company Logo" style="max-height: 60px; margin-bottom: 10px;">
                @endif
                <div class="company-name">{{ $company['name'] }}</div>
                <div class="company-details">
                    @if($company['address'])
                        <p><strong>Address:</strong> {{ $company['address'] }}</p>
                    @endif
                    @if($company['phone'])
                        <p><strong>Phone:</strong> {{ $company['phone'] }}</p>
                    @endif
                    @if($company['email'])
                        <p><strong>Email:</strong> {{ $company['email'] }}</p>
                    @endif
                    @if($company['tpin'])
                        <p><strong>T-PIN:</strong> {{ $company['tpin'] }}</p>
                    @endif
                </div>
            </div>
            <div class="receipt-title">
                <h1>RECEIPT</h1>
                <div class="receipt-number">#{{ $sale->sale_number }}</div>
            </div>
        </div>

        <!-- Info Grid -->
        <div class="info-grid">
            <div class="info-box">
                <h3>Sale Information</h3>
                <p><strong>Date:</strong> {{ $sale->sale_date->format('F j, Y - g:i A') }}</p>
                <p><strong>Status:</strong>
                    <span class="status-badge status-{{ $sale->status->value }}">
                        {{ $sale->status->label() }}
                    </span>
                </p>
                <p><strong>Cashier:</strong> {{ $soldBy->name }}</p>
            </div>
            <div class="info-box">
                <h3>Customer Information</h3>
                @if($customer)
                    <p><strong>Name:</strong> {{ $customer->name }}</p>
                    @if($customer->phone)
                        <p><strong>Phone:</strong> {{ $customer->phone }}</p>
                    @endif
                    @if($customer->email)
                        <p><strong>Email:</strong> {{ $customer->email }}</p>
                    @endif
                @else
                    <p><strong>Guest Customer</strong></p>
                @endif
            </div>
        </div>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th>Item Description</th>
                    <th style="width: 100px;">Unit Price</th>
                    <th style="width: 100px;">Discount</th>
                    <th style="width: 100px;">Tax</th>
                    <th style="width: 100px;">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $index => $item)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>
                            <strong>{{ $item->item_description }}</strong>
                            @if($item->subscriptionPlan)
                                <br><small style="color: #666;">{{ $item->subscriptionPlan->name }}</small>
                            @endif
                            @if($item->device && $item->device->serial_number)
                                <br><small style="color: #999;">S/N: {{ $item->device->serial_number }}</small>
                            @endif
                        </td>
                        <td>{{ config('receipt.currency', '$') . number_format($item->unit_price, 2) }}</td>
                        <td>{{ $item->discount > 0 ? config('receipt.currency', '$') . number_format($item->discount, 2) : '-' }}</td>
                        <td>{{ $item->tax > 0 ? config('receipt.currency', '$') . number_format($item->tax, 2) : '-' }}</td>
                        <td>{{ config('receipt.currency', '$') . number_format($item->total_price, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Totals -->
        <div class="totals-section">
            <div class="totals-box">
                <div class="total-row">
                    <span>Subtotal:</span>
                    <span>{{ config('receipt.currency', '$') . number_format($sale->subtotal, 2) }}</span>
                </div>
                @if($sale->discount_amount > 0)
                    <div class="total-row">
                        <span>Total Discount:</span>
                        <span>-{{ config('receipt.currency', '$') . number_format($sale->discount_amount, 2) }}</span>
                    </div>
                @endif
                @if($sale->vat_applicable && $sale->tax_amount > 0)
                    <div class="total-row">
                        <span>VAT (17.5%):</span>
                        <span>{{ config('receipt.currency', '$') . number_format($sale->tax_amount, 2) }}</span>
                    </div>
                @elseif($sale->tax_amount > 0)
                    <div class="total-row">
                        <span>Total Tax:</span>
                        <span>{{ config('receipt.currency', '$') . number_format($sale->tax_amount, 2) }}</span>
                    </div>
                @endif
                <div class="total-row final">
                    <span>Grand Total:</span>
                    <span>{{ config('receipt.currency', '$') . number_format($sale->total_amount, 2) }}</span>
                </div>
            </div>
        </div>

        <!-- Payment Information -->
        <div class="payment-section">
            <h3>Payment Details</h3>
            <div class="payment-grid">
                <div class="payment-item">
                    <label>Payment Method</label>
                    <span>{{ $sale->payment_method->label() }}</span>
                </div>
                <div class="payment-item">
                    <label>Payment Status</label>
                    <span>{{ $sale->payment_status->label() }}</span>
                </div>
                @if($sale->payment_reference)
                    <div class="payment-item">
                        <label>Reference Number</label>
                        <span>{{ $sale->payment_reference }}</span>
                    </div>
                @endif
            </div>
        </div>

        <!-- Barcode -->
        <div class="codes-section">
            <div class="code-item">
                <p>Receipt Barcode</p>
                <div class="barcode">
                    {!! $barcode !!}
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <div class="thank-you">Thank you for your purchase!</div>
            <p>This receipt serves as proof of purchase and warranty coverage</p>
            @if($sale->notes)
                <p><strong>Notes:</strong> {{ $sale->notes }}</p>
            @endif
            <div class="return-policy">
                <strong>Return Policy:</strong> Items can be returned within
                {{ config('receipt.return_days', 7) }} days of purchase
                in original condition with this receipt.<br>
                For inquiries, please contact us at
                {{ $company['email'] ?? $company['phone'] ?? 'our store' }}.
            </div>
        </div>
    </div>

    <script>
        // Auto print when page loads (remove if not desired)
        // window.onload = function() { window.print(); };
    </script>
</body>
</html>
