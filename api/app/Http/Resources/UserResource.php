<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * This resource is embedded in ~25 other resources (device owner, sale
     * customer, family member, community tipster, ...) and most of those are
     * customer-facing. Two rules follow from that:
     *
     *  1. Only what a co-participant may legitimately see. `national_id` and
     *     the suspension state live on AdminUserResource instead: a customer
     *     can enumerate other customers through families and community posts,
     *     and neither an identity number nor "this account is suspended" is
     *     theirs to know.
     *  2. Never touch a relation that the caller did not eager-load. A lazy load
     *     here becomes an N+1 across every list endpoint that embeds a user.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'address' => $this->address,
            'city' => $this->city,
            'branch_id' => $this->branch_id,
            'avatar' => $this->avatar ? asset('storage/'.$this->avatar) : null,
            'email_verified' => ! is_null($this->email_verified_at),
            // Derived from the eager-loaded relation only. A fallback that
            // resolves the relation lazily costs one query per embedded user
            // AND forces the `roles` key below to appear in payloads that never
            // had it.
            'is_staff' => $this->whenLoaded(
                'roles',
                fn () => $this->roles->whereIn('name', User::STAFF_ROLES)->isNotEmpty()
            ),
            'two_factor_enabled' => ! is_null($this->two_factor_secret),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->getAllPermissions()->pluck('name')),
            'branch' => $this->whenLoaded('branch', fn () => new BranchResource($this->branch)),
            'created_at' => $this->created_at?->toLocalIso8601String(),
            'updated_at' => $this->updated_at?->toLocalIso8601String(),
        ];
    }
}
