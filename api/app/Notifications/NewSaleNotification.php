<?php

namespace App\Notifications;

use App\Models\Sale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewSaleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Sale $sale) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $this->sale->loadMissing(['soldBy', 'branch', 'customer', 'items']);

        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $staffName = $this->sale->soldBy?->name ?? 'Unknown Staff';
        $branchName = $this->sale->branch?->name ?? 'Unknown Branch';
        $customerName = $this->sale->customer?->name ?? 'Guest';

        return (new MailMessage)
            ->subject("New Sale: {$this->sale->sale_number}")
            ->greeting('Hello Admin,')
            ->line('A new sale has been completed in the system.')
            ->line('**Sale Details:**')
            ->line("- Sale Number: {$this->sale->sale_number}")
            ->line("- Customer: {$customerName}")
            ->line("- Staff: {$staffName}")
            ->line("- Branch: {$branchName}")
            ->line('- Total Amount: MWK '.number_format($this->sale->total_amount, 2))
            ->line('- Payment Method: '.($this->sale->payment_method ? str_replace('_', ' ', ucfirst($this->sale->payment_method->value)) : 'Layby'))
            ->line("- Items Sold: {$this->sale->items->count()}")
            ->line("- Date: {$this->sale->created_at->toLocalFormat('Y-m-d H:i')}")
            ->action('View Sale Details', $frontendUrl."/admin/sales/{$this->sale->id}")
            ->line('Thank you for using Mufasah Device Tracking!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'sale_id' => $this->sale->id,
            'sale_number' => $this->sale->sale_number,
            'total_amount' => $this->sale->total_amount,
        ];
    }
}
