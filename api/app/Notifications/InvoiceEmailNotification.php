<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Services\InvoiceDocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoiceEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invoice $invoice) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoice = $this->invoice->loadMissing(['customer', 'items']);
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');
        $customerName = $invoice->customer?->name ?? 'Customer';
        $expiresAt = $invoice->expires_at?->copy()->timezone(config('app.timezone', 'UTC'));
        $taxText = $invoice->vat_applicable
            ? sprintf('%s (%s%%): MWK %s', $invoice->tax_label, $invoice->tax_rate, number_format((float) $invoice->tax_amount, 2))
            : 'VAT: Not applicable';
        $pdfData = app(InvoiceDocumentService::class)->generatePdfData($invoice);

        return (new MailMessage)
            ->subject("Your price-locked invoice: {$invoice->invoice_number}")
            ->greeting("Hello {$customerName},")
            ->line('Your price-locked device invoice is ready. The prices and taxes below are fixed for 24 hours from issue time.')
            ->line("**Invoice number:** {$invoice->invoice_number}")
            ->line("**Issued:** {$invoice->issued_at?->timezone(config('app.timezone', 'UTC'))->format('Y-m-d H:i')}")
            ->line("**Valid until:** {$expiresAt?->format('Y-m-d H:i')}")
            ->line("**Items:** {$invoice->items->count()}")
            ->line('**Subtotal:** MWK '.number_format((float) $invoice->subtotal, 2))
            ->line('**Discount:** MWK '.number_format((float) $invoice->discount_amount, 2))
            ->line("**{$taxText}**")
            ->line('**Total: MWK '.number_format((float) $invoice->total_amount, 2).'**')
            ->action('View your invoice', $frontendUrl.'/invoices/'.$invoice->public_token)
            ->attachData($pdfData, "invoice_{$invoice->invoice_number}.pdf", ['mime' => 'application/pdf'])
            ->line('Please bring the invoice number when you are ready to redeem it. The reserved device(s) are held until the expiry time.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'invoice_issued',
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->invoice_number,
            'expires_at' => $this->invoice->expires_at?->toIso8601String(),
            'total_amount' => $this->invoice->total_amount,
        ];
    }
}
