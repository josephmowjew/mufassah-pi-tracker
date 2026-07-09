<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FamilyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $membership = $user ? $this->getMemberRecord($user) : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->when($membership?->isGuardianOrAbove(), $this->code),
            'avatar' => $this->avatar,
            'settings' => $this->when($membership?->isAdmin(), $this->settings),

            // Ownership
            'owner' => new UserResource($this->whenLoaded('owner')),
            'is_owner' => $user ? $this->isOwner($user) : false,

            // Current user's membership
            'my_membership' => $this->when($membership, function () use ($membership) {
                return [
                    'role' => [
                        'value' => $membership->role->value,
                        'label' => $membership->role->label(),
                        'color' => $membership->role->color(),
                    ],
                    'nickname' => $membership->nickname,
                    'joined_at' => $membership->joined_at?->toIso8601String(),
                    'status' => [
                        'value' => $membership->status->value,
                        'label' => $membership->status->label(),
                    ],
                    'location_sharing_enabled' => $membership->location_sharing_enabled,
                    'receive_family_alerts' => $membership->receive_family_alerts,
                    'can_manage_members' => $membership->canManageMembers(),
                    'can_manage_settings' => $membership->canManageSettings(),
                ];
            }),

            // Members
            'members' => FamilyMemberResource::collection($this->whenLoaded('activeMemberRecords')),
            'members_count' => $this->whenCounted('activeMemberRecords'),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
