<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  mixed  $notifiable
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->subject('Verify Your Email Address - Mufasah Device Tracking')
            ->greeting("Hello {$notifiable->name}!")
            ->line('Thank you for registering with Mufasah Device Tracking. Please click the button below to verify your email address.')
            ->action('Verify Email Address', $verificationUrl)
            ->line('This verification link will expire in '.Config::get('auth.verification.expire', 60).' minutes.')
            ->line('If you did not create an account, no further action is required.')
            ->salutation('Best regards, Mufasah Electronics Team');
    }

    protected function verificationUrl(object $notifiable): string
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        // Generate signed parameters for the API to verify
        $params = [
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
        ];

        // Create a temporary signed URL (we'll extract the signature)
        $signedUrl = URL::temporarySignedRoute(
            'auth.verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            $params
        );

        // Extract signature and expiry from the signed URL
        $urlParts = parse_url($signedUrl);
        parse_str($urlParts['query'] ?? '', $queryParams);

        // Build frontend URL with all required params
        return "{$frontendUrl}/verify-email/{$params['id']}/{$params['hash']}?expires={$queryParams['expires']}&signature={$queryParams['signature']}";
    }

    public function toArray(object $notifiable): array
    {
        return [
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
        ];
    }
}
