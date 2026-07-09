<?php

namespace App\Events;

use App\Models\Device;
use App\Models\DeviceLocation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LocationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Device $device,
        public DeviceLocation $location
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            // Channel for the device owner
            new PrivateChannel("user.{$this->device->owner_id}.devices"),
            // Channel for specific device tracking
            new PrivateChannel("device.{$this->device->id}.location"),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'device_id' => $this->device->id,
            'device_name' => $this->device->name,
            'location' => [
                'id' => $this->location->id,
                'latitude' => (float) $this->location->latitude,
                'longitude' => (float) $this->location->longitude,
                'altitude' => $this->location->altitude ? (float) $this->location->altitude : null,
                'accuracy' => $this->location->accuracy ? (float) $this->location->accuracy : null,
                'speed' => $this->location->speed ? (float) $this->location->speed : null,
                'heading' => $this->location->heading ? (float) $this->location->heading : null,
                'recorded_at' => $this->location->recorded_at->toIso8601String(),
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'location.updated';
    }
}
