<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IotModuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'module_id' => $this->module_id,
            'sim_number' => $this->sim_number,
            'imei' => $this->imei,
            'modem_type' => [
                'value' => $this->modem_type->value,
                'label' => $this->modem_type->label(),
            ],
            'firmware_version' => $this->firmware_version,
            'hardware_version' => $this->hardware_version,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'battery_level' => $this->battery_level,
            'signal_strength' => $this->signal_strength,
            'is_online' => $this->isOnline(),
            'last_ping' => $this->last_ping?->toLocalIso8601String(),
            'last_location_update' => $this->last_location_update?->toLocalIso8601String(),
            'activated_at' => $this->activated_at?->toLocalIso8601String(),

            // Only include configuration for admin users
            'configuration' => $this->when(
                $request->user()?->hasRole(['super_admin', 'store_admin']),
                $this->configuration
            ),

            // Relationships
            'device' => new DeviceResource($this->whenLoaded('device')),

            'created_at' => $this->created_at?->toLocalIso8601String(),
            'updated_at' => $this->updated_at?->toLocalIso8601String(),
        ];
    }
}
