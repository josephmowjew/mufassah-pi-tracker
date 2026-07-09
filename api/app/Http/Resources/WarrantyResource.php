<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarrantyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warranty_number' => $this->warranty_number,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'duration_months' => $this->duration_months,
            'coverage_details' => $this->coverage_details,
            'terms_conditions' => $this->terms_conditions,
            'provider' => $this->provider,
            'notes' => $this->notes,
            'is_active' => $this->isActive(),
            'days_remaining' => $this->end_date?->isFuture() ? now()->diffInDays($this->end_date) : 0,

            // Relationships
            'device' => new DeviceResource($this->whenLoaded('device')),
            'claims' => WarrantyClaimResource::collection($this->whenLoaded('claims')),
            'claims_count' => $this->whenCounted('claims'),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
