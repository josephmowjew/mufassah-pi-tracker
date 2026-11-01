<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FamilyMemberResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => new UserResource($this->whenLoaded('user')),
            'user_id' => $this->user_id,

            // Role and permissions
            'role' => [
                'value' => $this->role->value,
                'label' => $this->role->label(),
                'color' => $this->role->color(),
            ],
            'nickname' => $this->nickname,
            'display_name' => $this->display_name,

            // Status
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'is_active' => $this->isActive(),
            'is_pending' => $this->isPending(),

            // Permissions
            'can_manage_members' => $this->canManageMembers(),
            'can_manage_settings' => $this->canManageSettings(),
            'must_share_location' => $this->mustShareLocation(),

            // Settings
            'location_sharing_enabled' => $this->location_sharing_enabled,
            'receive_family_alerts' => $this->receive_family_alerts,

            // Invitation info
            'inviter' => new UserResource($this->whenLoaded('inviter')),
            'joined_at' => $this->joined_at?->toLocalIso8601String(),

            // Timestamps
            'created_at' => $this->created_at?->toLocalIso8601String(),
            'updated_at' => $this->updated_at?->toLocalIso8601String(),
        ];
    }
}
