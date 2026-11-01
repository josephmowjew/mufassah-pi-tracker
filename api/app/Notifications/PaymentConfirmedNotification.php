<?php

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Payment $payment) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        return (new MailMessage)
            ->subject('Payment Confirmed - '.$this->payment->payment_number)
            ->greeting('Hello, '.$notifiable->name)
            ->line('Your payment has been confirmed.')
            ->line('Payment Details:')
            ->line("- Payment Number: {$this->payment->payment_number}")
            ->line("- Amount: {$this->payment->currency} ".number_format($this->payment->amount, 2))
            ->line("- Description: {$this->payment->description}")
            ->line("- Date: {$this->payment->paid_at?->toLocalFormat('M d, Y H:i')}")
            ->action('View Payment', $frontendUrl.'/payments/'.$this->payment->id)
            ->line('Thank you for your payment!')
            ->salutation('Best regards, Mufasah Electronics Team');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payment_confirmed',
            'title' => 'Payment Confirmed',
            'message' => "Payment of {$this->payment->currency} ".number_format($this->payment->amount, 2).' confirmed.',
            'payment_id' => $this->payment->id,
            'payment_number' => $this->payment->payment_number,
            'amount' => $this->payment->amount,
            'currency' => $this->payment->currency,
            'icon' => 'payment',
        ];
    }
}
