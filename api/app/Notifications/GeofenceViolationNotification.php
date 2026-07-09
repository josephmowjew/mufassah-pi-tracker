<?php

namespace App\Notifications;

use App\Enums\GeofenceEventType;
use App\Models\Geofence;
use App\Models\GeofenceEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GeofenceViolationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public GeofenceEvent $event,
        public Geofence $geofence
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $device = $this->geofence->device;
        $eventType = $this->event->event_type;

        $subject = $eventType === GeofenceEventType::Entry
            ? "Device Entered Geofence: {$this->geofence->name}"
            : "Device Left Geofence: {$this->geofence->name}";

        $action = $eventType === GeofenceEventType::Entry ? 'entered' : 'left';

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Hello, '.$notifiable->name)
            ->line("Your device \"{$device->name}\" has {$action} the geofence \"{$this->geofence->name}\".")
            ->line('Event Details:')
            ->line("- Device: {$device->name}")
            ->line("- Geofence: {$this->geofence->name}")
            ->line('- Event: '.ucfirst($action))
            ->line("- Time: {$this->event->event_time->format('M d, Y H:i:s')}")
            ->line('- Distance from center: '.number_format($this->event->distance_from_center, 2).'m')
            ->action('View Device', $frontendUrl.'/devices/'.$device->id)
            ->salutation('Best regards, Mufasah Electronics Team');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $device = $this->geofence->device;
        $eventType = $this->event->event_type;
        $action = $eventType === GeofenceEventType::Entry ? 'entered' : 'left';

        return [
            'type' => 'geofence_violation',
            'title' => "Device {$action} geofence",
            'message' => "Your device \"{$device->name}\" has {$action} \"{$this->geofence->name}\"",
            'event_id' => $this->event->id,
            'geofence_id' => $this->geofence->id,
            'geofence_name' => $this->geofence->name,
            'device_id' => $device->id,
            'device_name' => $device->name,
            'event_type' => $eventType->value,
            'event_time' => $this->event->event_time->toIso8601String(),
            'icon' => $eventType === GeofenceEventType::Entry ? 'enter' : 'exit',
        ];
    }
}
