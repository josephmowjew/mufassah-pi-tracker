<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AlertSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'is_active' => $this->is_active,

            // Home location
            'home_location' => $this->when($this->hasHomeLocation(), [
                'latitude' => $this->home_latitude,
                'longitude' => $this->home_longitude,
                'radius_km' => $this->home_radius_km,
                'label' => $this->home_label ?? 'Home',
            ]),

            // Work location
            'work_location' => $this->when($this->hasWorkLocation(), [
                'latitude' => $this->work_latitude,
                'longitude' => $this->work_longitude,
                'radius_km' => $this->work_radius_km,
                'label' => $this->work_label ?? 'Work',
            ]),

            // Custom location
            'custom_location' => $this->when($this->hasCustomLocation(), [
                'latitude' => $this->custom_latitude,
                'longitude' => $this->custom_longitude,
                'radius_km' => $this->custom_radius_km,
                'label' => $this->custom_label ?? 'Custom',
            ]),

            // Notification preferences
            'notify_via' => $this->notify_via ?? ['push'],
            'notify_with_bounty_only' => $this->notify_with_bounty_only,
            'min_bounty_amount' => $this->min_bounty_amount,

            // Quiet hours
            'quiet_hours' => $this->when(
                $this->quiet_hours_start && $this->quiet_hours_end,
                [
                    'start' => $this->quiet_hours_start?->format('H:i'),
                    'end' => $this->quiet_hours_end?->format('H:i'),
                ]
            ),

            // Stats
            'alerts_received' => $this->alerts_received,
            'last_alert_at' => $this->last_alert_at?->toIso8601String(),

            // Computed
            'configured_locations' => $this->getConfiguredLocations(),
            'is_in_quiet_hours' => $this->isInQuietHours(),

            // Relationships
            'user' => new UserResource($this->whenLoaded('user')),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
