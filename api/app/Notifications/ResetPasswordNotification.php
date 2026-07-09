<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $token
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = $this->getResetUrl($notifiable);
        $expireMinutes = config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset Your Password - Mufasah Device Tracking')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $url)
            ->line("This password reset link will expire in {$expireMinutes} minutes.")
            ->line('If you did not request a password reset, no further action is required.')
            ->salutation('Best regards, Mufasah Electronics Team');
    }

    protected function getResetUrl(object $notifiable): string
    {
        // Frontend URL for password reset
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        return "{$frontendUrl}/reset-password?token={$this->token}&email={$notifiable->getEmailForPasswordReset()}";
    }

    public function toArray(object $notifiable): array
    {
        return [
            'token' => $this->token,
        ];
    }
}
