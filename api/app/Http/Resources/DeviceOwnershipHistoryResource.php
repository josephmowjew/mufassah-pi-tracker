<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceOwnershipHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transfer_reason' => $this->transfer_reason,
            'notes' => $this->notes,
            'transferred_at' => $this->transferred_at?->toLocalIso8601String(),
            'created_at' => $this->created_at?->toLocalIso8601String(),

            // Relationships
            'device' => new DeviceResource($this->whenLoaded('device')),
            'from_user' => new UserResource($this->whenLoaded('fromUser')),
            'to_user' => new UserResource($this->whenLoaded('toUser')),
        ];
    }
}
