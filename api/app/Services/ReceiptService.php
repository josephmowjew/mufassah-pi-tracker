<?php

namespace App\Services;

use App\Models\Sale;
use Barryvdh\DomPDF\Facade\Pdf;

class ReceiptService
{
    /**
     * Generate receipt as PDF.
     */
    public function generatePdf(Sale $sale, string $format = 'a4'): \Illuminate\Http\Response
    {
        $sale->load(['customer', 'soldBy', 'items.device.category', 'items.subscriptionPlan']);

        $data = $this->prepareReceiptData($sale);

        $pdf = PDF::loadView("receipts.{$format}_receipt", $data);

        $filename = "receipt_{$sale->sale_number}.pdf";

        return $pdf->download($filename);
    }

    /**
     * Generate receipt as HTML for printing.
     */
    public function generateHtml(Sale $sale, string $format = 'a4'): string
    {
        $sale->load(['customer', 'soldBy', 'items.device.category', 'items.subscriptionPlan']);

        $data = $this->prepareReceiptData($sale);

        return view("receipts.{$format}_receipt", $data)->render();
    }

    /**
     * Generate barcode for the sale.
     */
    public function generateBarcode(Sale $sale): string
    {
        $generator = new \Picqer\Barcode\BarcodeGeneratorHTML;

        return $generator->getBarcode(
            $sale->sale_number,
            $generator::TYPE_CODE_128,
            2,
            50
        );
    }

    /**
     * Prepare receipt data.
     */
    protected function prepareReceiptData(Sale $sale): array
    {
        $company = $this->getCompanyInfo();

        return [
            'company' => $company,
            'sale' => $sale,
            'items' => $sale->items,
            'customer' => $sale->customer,
            'soldBy' => $sale->soldBy,
            'barcode' => $this->generateBarcode($sale),
        ];
    }

    /**
     * Get company information for receipts.
     */
    protected function getCompanyInfo(): array
    {
        return [
            'name' => config('receipt.company_name', 'Mufasah Electronics'),
            'address' => config('receipt.address', '123 Business Street'),
            'phone' => config('receipt.phone', '+1 234 567 890'),
            'email' => config('receipt.email', 'info@mufassah.com'),
            'tpin' => config('receipt.tpin', '70474293'),
            'logo' => config('receipt.logo'),
        ];
    }

    /**
     * Calculate total items count.
     */
    protected function getTotalItems(Sale $sale): int
    {
        return $sale->items->sum('quantity') ?? $sale->items->count();
    }
}
