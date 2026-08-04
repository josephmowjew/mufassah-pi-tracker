<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InventoryActionApprovalPinNotification extends Notification
{
    public function __construct(
        public readonly string $pin,
        public readonly string $actionLabel,
        public readonly string $deviceLabel,
        public readonly \DateTimeInterface $expiresAt,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Mufasah verification PIN: {$this->actionLabel}")
            ->greeting("Hello {$notifiable->name},")
            ->line("A {$this->actionLabel} was requested for {$this->deviceLabel}.")
            ->line("Your one-time verification PIN is: **{$this->pin}**")
            ->line('This PIN expires in 10 minutes and can be used only once. Do not share it.')
            ->line('If you did not request this action, contact a system administrator immediately.')
            ->salutation('Mufasah Device Tracking');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'action' => $this->actionLabel,
            'device' => $this->deviceLabel,
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
        ];
    }
}
