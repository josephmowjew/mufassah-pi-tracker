<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GeofenceResource extends JsonResource
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
            'device_id' => $this->device_id,
            'name' => $this->name,
            'description' => $this->description,
            'shape_type' => $this->shape_type->value,
            'center_latitude' => (float) $this->center_latitude,
            'center_longitude' => (float) $this->center_longitude,
            'radius_meters' => $this->radius_meters,
            'polygon_coordinates' => $this->polygon_coordinates,
            'is_active' => $this->is_active,
            'notify_on_entry' => $this->notify_on_entry,
            'notify_on_exit' => $this->notify_on_exit,
            'active_days' => $this->active_days,
            'active_time_start' => $this->active_time_start?->format('H:i'),
            'active_time_end' => $this->active_time_end?->format('H:i'),
            'device' => new DeviceResource($this->whenLoaded('device')),
            'events_count' => $this->whenCounted('events'),
            'created_at' => $this->created_at->toLocalIso8601String(),
            'updated_at' => $this->updated_at->toLocalIso8601String(),
        ];
    }
}
