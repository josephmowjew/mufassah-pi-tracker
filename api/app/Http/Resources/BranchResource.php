<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
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
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'city' => $this->city,
            'phone' => $this->phone,
            'email' => $this->email,
            'manager_id' => $this->manager_id,
            'manager' => $this->whenLoaded('manager'),
            'is_active' => $this->is_active,
            'devices_count' => $this->whenCounted('devices'),
            'users_count' => $this->whenCounted('users'),
            'sales_count' => $this->whenCounted('sales'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
