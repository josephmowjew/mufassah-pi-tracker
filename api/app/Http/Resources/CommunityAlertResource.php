<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommunityAlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],

            // Broadcast location
            'broadcast_latitude' => $this->broadcast_latitude,
            'broadcast_longitude' => $this->broadcast_longitude,
            'alert_radius_km' => $this->alert_radius_km,
            'opt_in_notifications' => $this->opt_in_notifications,

            // Bounty information
            'bounty_amount' => $this->bounty_amount,
            'bounty_currency' => $this->bounty_currency,
            'bounty_status' => $this->when($this->bounty_status, [
                'value' => $this->bounty_status?->value,
                'label' => $this->bounty_status?->label(),
                'color' => $this->bounty_status?->color(),
            ]),
            'has_bounty' => $this->hasBounty(),

            // Engagement stats
            'view_count' => $this->view_count,
            'share_count' => $this->share_count,
            'tips_count' => $this->tips_count,

            // Status
            'is_active' => $this->isActive(),
            'is_expired' => $this->isExpired(),
            'can_receive_tips' => $this->canReceiveTips(),

            // Dates
            'expires_at' => $this->expires_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),

            // Relationships
            'theft_report' => new TheftReportResource($this->whenLoaded('theftReport')),
            'creator' => new UserResource($this->whenLoaded('creator')),
            'tips' => CommunityTipResource::collection($this->whenLoaded('tips')),
            'claims' => BountyClaimResource::collection($this->whenLoaded('claims')),
            'success_story' => new SuccessStoryResource($this->whenLoaded('successStory')),

            // Distance (when using nearby scope)
            'distance_km' => $this->when(isset($this->distance), fn () => round($this->distance, 2)),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
