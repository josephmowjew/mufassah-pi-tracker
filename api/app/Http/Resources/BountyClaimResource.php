<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BountyClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'claim_type' => $this->claim_type,
            'description' => $this->description,

            // Evidence
            'evidence_photos' => $this->evidence_photos,

            // Recovery details
            'recovery_latitude' => $this->recovery_latitude,
            'recovery_longitude' => $this->recovery_longitude,
            'recovery_address' => $this->recovery_address,
            'recovery_date' => $this->recovery_date?->toIso8601String(),

            // Status
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'is_pending' => $this->isPending(),
            'is_approved' => $this->isApproved(),
            'is_paid' => $this->isPaid(),

            // Amounts
            'claim_amount' => $this->claim_amount,
            'approved_amount' => $this->approved_amount,

            // Payment details
            'payment_method' => $this->payment_method,
            'payment_account' => $this->when(
                $request->user()?->id === $this->claimant_id ||
                $request->user()?->hasRole(['super_admin', 'store_admin']),
                $this->payment_account
            ),
            'payment_reference' => $this->payment_reference,
            'paid_at' => $this->paid_at?->toIso8601String(),

            // Review details
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),

            // Rating
            'owner_rating' => $this->owner_rating,
            'owner_feedback' => $this->owner_feedback,

            // Relationships
            'alert' => new CommunityAlertResource($this->whenLoaded('alert')),
            'claimant' => new UserResource($this->whenLoaded('claimant')),
            'reviewer' => new UserResource($this->whenLoaded('reviewer')),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
