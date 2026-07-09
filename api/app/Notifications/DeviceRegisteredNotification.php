<?php

namespace App\Notifications;

use App\Models\Device;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeviceRegisteredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Device $device) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        return (new MailMessage)
            ->subject('Device Registered Successfully')
            ->greeting('Hello, '.$notifiable->name.'!')
            ->line("Your device \"{$this->device->name}\" has been successfully registered.")
            ->line('Device Details:')
            ->line("- Name: {$this->device->name}")
            ->line("- Serial Number: {$this->device->serial_number}")
            ->line("- Category: {$this->device->category->name}")
            ->action('View Device', $frontendUrl.'/devices/'.$this->device->id)
            ->line('Keep your device information safe and updated.')
            ->salutation('Best regards, Mufasah Electronics Team');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'device_registered',
            'title' => 'Device Registered',
            'message' => "Your device \"{$this->device->name}\" has been successfully registered.",
            'device_id' => $this->device->id,
            'device_name' => $this->device->name,
            'icon' => 'device',
        ];
    }
}
