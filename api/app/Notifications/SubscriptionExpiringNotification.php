<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Subscription $subscription,
        public int $daysRemaining
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $plan = $this->subscription->plan;

        $mailMessage = (new MailMessage)
            ->subject('Subscription Expiring Soon')
            ->greeting('Hello, '.$notifiable->name)
            ->line("Your {$plan->name} subscription will expire in {$this->daysRemaining} days.")
            ->line('Subscription Details:')
            ->line("- Plan: {$plan->name}")
            ->line("- Expires: {$this->subscription->ends_at->format('M d, Y')}")
            ->line('- Auto-Renew: '.($this->subscription->auto_renew ? 'Enabled' : 'Disabled'));

        if ($this->subscription->auto_renew) {
            $mailMessage->line('Your subscription will automatically renew before expiration.');
        } else {
            $mailMessage->line('To continue enjoying our services, please renew your subscription.');
            $mailMessage->action('Renew Now', $frontendUrl.'/subscriptions/renew');
        }

        return $mailMessage->salutation('Best regards, Mufasah Electronics Team');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'subscription_expiring',
            'title' => 'Subscription Expiring Soon',
            'message' => "Your {$this->subscription->plan->name} subscription expires in {$this->daysRemaining} days.",
            'subscription_id' => $this->subscription->id,
            'plan_name' => $this->subscription->plan->name,
            'expires_at' => $this->subscription->ends_at->toIso8601String(),
            'days_remaining' => $this->daysRemaining,
            'auto_renew' => $this->subscription->auto_renew,
            'icon' => 'warning',
        ];
    }
}
