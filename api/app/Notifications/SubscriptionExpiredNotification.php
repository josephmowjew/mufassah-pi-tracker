<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionExpiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Subscription $subscription) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $plan = $this->subscription->plan;

        return (new MailMessage)
            ->subject('Subscription Expired')
            ->greeting('Hello, '.$notifiable->name)
            ->line("Your {$plan->name} subscription has expired.")
            ->line('As a result, the following features are no longer available:')
            ->line('- Real-time device tracking')
            ->line('- Geofence alerts')
            ->line('- Theft report priority processing')
            ->line('To restore full access, please renew your subscription.')
            ->action('Renew Subscription', $frontendUrl.'/subscriptions/renew')
            ->line('We hope to see you back soon!')
            ->salutation('Best regards, Mufasah Electronics Team');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'subscription_expired',
            'title' => 'Subscription Expired',
            'message' => "Your {$this->subscription->plan->name} subscription has expired.",
            'subscription_id' => $this->subscription->id,
            'plan_name' => $this->subscription->plan->name,
            'expired_at' => $this->subscription->ends_at->toLocalIso8601String(),
            'icon' => 'error',
        ];
    }
}
