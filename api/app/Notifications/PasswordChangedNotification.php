<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirmation sent when an administrator sets a password on someone's behalf.
 *
 * Deliberately carries the new password in clear text. That is a real
 * trade-off: the message lives in the recipient's inbox indefinitely and a
 * mailbox compromise becomes an account compromise. It is done this way
 * because the admin-issued password has to reach the user somehow, and a
 * reset link would defeat the purpose of the admin setting it directly.
 * The mail therefore instructs the recipient to change it on first login.
 */
class PasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $plainPassword,
        public ?string $changedBy = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $loginUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/').'/login';
        $actor = $this->changedBy ?: 'an administrator';

        return (new MailMessage)
            ->subject('Your password has been changed - Mufasah Device Tracking')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line("An administrator ({$actor}) has set a new password for your Mufasah Device Tracking account.")
            ->line("Your new password is: **{$this->plainPassword}**")
            ->line('Please sign in with this password and change it to something only you know.')
            ->action('Sign In', $loginUrl)
            ->line('Any other device you were signed in on has been signed out.')
            ->line('If you were not expecting this change, contact us immediately.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'password_changed',
            'changed_by' => $this->changedBy,
        ];
    }
}
