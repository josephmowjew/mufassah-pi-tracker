<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'altitude' => $this->altitude ? (float) $this->altitude : null,
            'accuracy' => $this->accuracy ? (float) $this->accuracy : null,
            'speed' => $this->speed ? (float) $this->speed : null,
            'heading' => $this->heading ? (float) $this->heading : null,
            'address' => $this->address,
            'is_manual' => $this->is_manual,
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            // Relationships
            'device' => new DeviceResource($this->whenLoaded('device')),
            'iot_module' => new IotModuleResource($this->whenLoaded('iotModule')),
        ];
    }
}
