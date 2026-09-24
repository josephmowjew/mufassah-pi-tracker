<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoiceDocumentService
{
    public function generatePdf(Invoice $invoice): \Illuminate\Http\Response
    {
        $invoice->load(['customer', 'branch', 'createdBy', 'items.device.category']);

        return PDF::loadView('invoices.invoice', $this->data($invoice))
            ->setPaper('a4')
            ->download("invoice_{$invoice->invoice_number}.pdf");
    }

    public function generateHtml(Invoice $invoice): string
    {
        $invoice->load(['customer', 'branch', 'createdBy', 'items.device.category']);

        return view('invoices.invoice', $this->data($invoice))->render();
    }

    public function generatePdfData(Invoice $invoice): string
    {
        $invoice->load(['customer', 'branch', 'createdBy', 'items.device.category']);

        return PDF::loadView('invoices.invoice', $this->data($invoice))->output();
    }

    private function data(Invoice $invoice): array
    {
        return [
            'invoice' => $invoice,
            'items' => $invoice->items,
            'company' => [
                'name' => config('receipt.company_name', 'Mufasah Electronics'),
                'address' => config('receipt.address', '123 Business Street'),
                'phone' => config('receipt.phone', '+1 234 567 890'),
                'email' => config('receipt.email', 'info@mufassah.com'),
                'tpin' => config('receipt.tpin', '70474293'),
            ],
        ];
    }
}
