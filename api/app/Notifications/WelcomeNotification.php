<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct() {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        return (new MailMessage)
            ->subject('Welcome to Mufasah Device Tracking!')
            ->greeting('Welcome, '.$notifiable->name.'!')
            ->line('Thank you for registering with Mufasah Device Tracking.')
            ->line('With our service, you can:')
            ->line('- Register and track your electronic devices')
            ->line('- Set up geofences and receive alerts')
            ->line('- Report theft and aid in recovery')
            ->line('- Manage warranties and claims')
            ->action('Get Started', $frontendUrl.'/dashboard')
            ->line('If you have any questions, our customer service team is here to help.')
            ->salutation('Best regards, Mufasah Electronics Team');
    }

    public function toArray(object $notifiable): array
    {
        return [];
    }
}
