<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TheftReportUpdateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'old_status' => $this->old_status ? [
                'value' => $this->old_status->value,
                'label' => $this->old_status->label(),
                'color' => $this->old_status->color(),
            ] : null,
            'new_status' => $this->new_status ? [
                'value' => $this->new_status->value,
                'label' => $this->new_status->label(),
                'color' => $this->new_status->color(),
            ] : null,
            'notes' => $this->notes,
            'is_public' => $this->is_public,

            // Relationships
            'user' => new UserResource($this->whenLoaded('user')),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
