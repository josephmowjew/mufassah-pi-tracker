<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionPlanResource extends JsonResource
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
            'slug' => $this->slug,
            'description' => $this->description,
            'pricing' => [
                'monthly' => (float) $this->price_monthly,
                'yearly' => (float) $this->price_yearly,
                'currency' => $this->currency,
                'yearly_savings' => (float) (($this->price_monthly * 12) - $this->price_yearly),
            ],
            'limits' => [
                'max_devices' => $this->max_devices === -1 ? 'unlimited' : $this->max_devices,
                'max_geofences_per_device' => $this->max_geofences_per_device === -1 ? 'unlimited' : $this->max_geofences_per_device,
                'location_history_days' => $this->location_history_days,
            ],
            'features' => [
                'real_time_tracking' => $this->real_time_tracking,
                'theft_alerts' => $this->theft_alerts,
                'geofence_alerts' => $this->geofence_alerts,
                'priority_support' => $this->priority_support,
                'feature_list' => $this->features,
            ],
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toLocalIso8601String(),
        ];
    }
}
