<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommunityTipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tip_type' => $this->tip_type,
            'description' => $this->description,

            // Location details
            'spotted_latitude' => $this->spotted_latitude,
            'spotted_longitude' => $this->spotted_longitude,
            'spotted_address' => $this->spotted_address,
            'spotted_at' => $this->spotted_at?->toIso8601String(),
            'has_location' => $this->hasLocation(),

            // Anonymity
            'is_anonymous' => $this->isAnonymous(),
            'contact_preference' => $this->when(! $this->isAnonymous(), $this->contact_preference),
            'contact_info' => $this->when(! $this->isAnonymous(), $this->contact_info),

            // Photos
            'photos' => $this->photos,

            // Status
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'is_helpful' => $this->is_helpful,
            'is_pending' => $this->isPending(),
            'is_verified' => $this->isVerified(),

            // Admin review
            'admin_notes' => $this->when(
                $request->user()?->hasRole(['super_admin', 'store_admin', 'customer_service']),
                $this->admin_notes
            ),

            // Relationships
            'alert' => new CommunityAlertResource($this->whenLoaded('alert')),
            'tipster' => $this->when(! $this->isAnonymous(), new UserResource($this->whenLoaded('tipster'))),
            'tipster_display_name' => $this->tipster_display_name,
            'reviewer' => new UserResource($this->whenLoaded('reviewer')),

            // Review timestamps
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
