<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SuccessStoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'story' => $this->story,
            'photos' => $this->photos,

            // Device info snapshot
            'device_brand' => $this->device_brand,
            'device_model' => $this->device_model,
            'device_type' => $this->device_type,
            'device_description' => $this->device_description,

            // Recovery details
            'days_to_recover' => $this->days_to_recover,
            'bounty_paid' => $this->bounty_paid,
            'recovery_city' => $this->recovery_city,

            // Publication status
            'is_published' => $this->is_published,
            'is_featured' => $this->is_featured,
            'published_at' => $this->published_at?->toIso8601String(),

            // Status helpers
            'is_pending' => $this->isPending(),
            'is_rejected' => $this->isRejected(),

            // Engagement
            'view_count' => $this->view_count,
            'likes_count' => $this->likes_count,
            'shares_count' => $this->shares_count,

            // Current user interaction
            'is_liked' => $this->when(
                $request->user(),
                fn () => $this->isLikedBy($request->user())
            ),

            // Moderation (admin only)
            'rejection_reason' => $this->when(
                $request->user()?->hasRole(['super_admin', 'store_admin', 'customer_service']) ||
                $request->user()?->id === $this->author_id,
                $this->rejection_reason
            ),
            'approved_at' => $this->approved_at?->toIso8601String(),

            // Relationships
            'alert' => new CommunityAlertResource($this->whenLoaded('alert')),
            'author' => new UserResource($this->whenLoaded('author')),
            'approver' => new UserResource($this->whenLoaded('approver')),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
