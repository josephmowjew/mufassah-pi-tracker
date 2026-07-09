<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TestEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
    }

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
        return (new MailMessage)
            ->subject('Test Email - Mufasah Device Tracking')
            ->greeting('Hello Admin,')
            ->line('This is a test email to verify that your communication settings are configured correctly.')
            ->line('If you received this email, your email configuration is working properly!')
            ->line('**Notification Types You Will Receive:**')
            ->line('- New Sale Notifications: When a sale is made by customer service staff')
            ->line('- Shift End Summaries: When a staff member ends their shift')
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
            'type' => 'test_email',
        ];
    }
}
