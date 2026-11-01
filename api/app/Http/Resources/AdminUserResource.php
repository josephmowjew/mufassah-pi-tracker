<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * User payload for the admin user-management screens.
 *
 * Adds the fields only an administrator administering the account should
 * receive: `national_id` and the suspension state.
 *
 * Deliberately a separate class rather than a conditional inside UserResource:
 * that resource is embedded in ~25 payloads, several of them customer-facing
 * (family members, community tips, theft-report participants), so a runtime
 * flag there is one careless edit away from handing identity numbers - or
 * another account's suspension status - to other customers. Keeping the
 * admin-only fields on a class that only the admin controller imports makes
 * the boundary structural rather than a condition someone can flip.
 */
class AdminUserResource extends UserResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'national_id' => $this->national_id,
            // The admin UI reads these to render the SUSPENDED badge and the
            // suspend/unsuspend toggle. Without them every account looked
            // active and "unsuspend" was unreachable.
            'suspended_at' => $this->suspended_at?->toLocalIso8601String(),
            'is_suspended' => $this->isSuspended(),
        ];
    }
}
