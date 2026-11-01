<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommunityHeroResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->display_name,

            // Stats
            'successful_recoveries' => $this->successful_recoveries,
            'tips_submitted' => $this->tips_submitted,
            'tips_verified' => $this->tips_verified,
            'claims_approved' => $this->claims_approved,
            'total_bounties_earned' => $this->total_bounties_earned,
            'reputation_score' => $this->reputation_score,

            // Monthly stats
            'monthly_recoveries' => $this->monthly_recoveries,
            'monthly_tips' => $this->monthly_tips,

            // Achievements
            'badges' => $this->badges ?? [],
            'rank' => $this->rank,

            // Visibility
            'is_featured' => $this->is_featured,
            'is_public' => $this->is_public,

            // User relationship (limited info for public display)
            'user' => $this->when(
                $this->is_public || $request->user()?->id === $this->user_id,
                new UserResource($this->whenLoaded('user'))
            ),

            // Stats reset
            'stats_reset_at' => $this->stats_reset_at?->toLocalIso8601String(),

            // Timestamps
            'created_at' => $this->created_at?->toLocalIso8601String(),
            'updated_at' => $this->updated_at?->toLocalIso8601String(),
        ];
    }
}
