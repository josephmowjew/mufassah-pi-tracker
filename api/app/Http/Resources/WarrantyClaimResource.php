<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarrantyClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'claim_number' => $this->claim_number,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'issue_description' => $this->issue_description,
            'issue_category' => $this->issue_category,
            'evidence_photos' => $this->evidence_photos,

            // Resolution details
            'resolution_type' => $this->resolution_type,
            'resolution_notes' => $this->resolution_notes,
            'repair_cost' => $this->repair_cost,
            'processed_at' => $this->processed_at?->toIso8601String(),

            // Relationships
            'warranty' => new WarrantyResource($this->whenLoaded('warranty')),
            'processor' => new UserResource($this->whenLoaded('processor')),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
