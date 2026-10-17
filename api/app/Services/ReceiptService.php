<?php

namespace App\Services;

use App\Models\Sale;
use Barryvdh\DomPDF\Facade\Pdf;

class ReceiptService
{
    /**
     * Generate receipt as PDF.
     */
    public function generatePdf(Sale $sale, string $format = 'a4', string $receiptCopy = 'customer'): \Illuminate\Http\Response
    {
        $sale->load(['customer', 'soldBy', 'items.device.category', 'items.subscriptionPlan']);

        $data = $this->prepareReceiptData($sale, $receiptCopy);
        $data['interactive'] = false;

        $pdf = Pdf::loadView("receipts.{$format}_receipt", $data)
            ->setOption('defaultMediaType', 'print');

        // Roll PDFs must have the same width as their receipt layout. Long
        // receipts paginate at that width rather than shrinking an A4 page.
        $pdf->setPaper($format === 'thermal'
            ? [0, 0, $data['paperWidth'] * 72 / 25.4, 297 * 72 / 25.4]
            : 'a4');

        $filename = "receipt_{$sale->sale_number}_{$receiptCopy}.pdf";

        return $pdf->download($filename);
    }

    /**
     * Generate receipt as HTML for printing.
     */
    public function generateHtml(Sale $sale, string $format = 'a4', string $receiptCopy = 'customer'): string
    {
        $sale->load(['customer', 'soldBy', 'items.device.category', 'items.subscriptionPlan']);

        $data = $this->prepareReceiptData($sale, $receiptCopy);
        $data['interactive'] = true;

        return view("receipts.{$format}_receipt", $data)->render();
    }

    /**
     * Generate barcode for the sale.
     */
    public function generateBarcode(Sale $sale): string
    {
        $generator = new \Picqer\Barcode\BarcodeGeneratorSVG;

        $svg = $generator->getBarcode(
            $sale->sale_number,
            $generator::TYPE_CODE_128,
            2,
            50
        );

        // Scale the complete vector barcode within the paper, preserving all
        // bars instead of clipping fixed-width HTML bars at the receipt edge.
        return '<img src="data:image/svg+xml;base64,'.base64_encode($svg).'" alt="Receipt barcode" style="display:block;max-width:100%;height:auto;margin:0 auto;">';
    }

    /**
     * Prepare receipt data.
     */
    protected function prepareReceiptData(Sale $sale, string $receiptCopy = 'customer'): array
    {
        $company = $this->getCompanyInfo();
        $paperWidth = (int) config('receipt.thermal.width', 80);

        return [
            'company' => $company,
            'sale' => $sale,
            'items' => $sale->items,
            'customer' => $sale->customer,
            'soldBy' => $sale->soldBy,
            'barcode' => $this->generateBarcode($sale),
            'receiptCopy' => $receiptCopy,
            'paperWidth' => in_array($paperWidth, [58, 80], true) ? $paperWidth : 80,
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
