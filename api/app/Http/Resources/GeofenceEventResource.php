<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GeofenceEventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'geofence_id' => $this->geofence_id,
            'device_id' => $this->device_id,
            'location_id' => $this->location_id,
            'event_type' => $this->event_type->value,
            'event_type_label' => $this->event_type->label(),
            'distance_from_center' => (float) $this->distance_from_center,
            'alert_sent' => $this->alert_sent,
            'event_time' => $this->event_time->toIso8601String(),
            'geofence' => new GeofenceResource($this->whenLoaded('geofence')),
            'location' => $this->whenLoaded('location', fn () => [
                'latitude' => (float) $this->location->latitude,
                'longitude' => (float) $this->location->longitude,
                'address' => $this->location->address,
            ]),
        ];
    }
}
