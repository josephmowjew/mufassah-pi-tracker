<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'is_active',
        'home_latitude',
        'home_longitude',
        'home_radius_km',
        'home_label',
        'work_latitude',
        'work_longitude',
        'work_radius_km',
        'work_label',
        'custom_latitude',
        'custom_longitude',
        'custom_radius_km',
        'custom_label',
        'notify_via',
        'notify_with_bounty_only',
        'min_bounty_amount',
        'quiet_hours_start',
        'quiet_hours_end',
        'alerts_received',
        'last_alert_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'home_latitude' => 'decimal:8',
            'home_longitude' => 'decimal:8',
            'work_latitude' => 'decimal:8',
            'work_longitude' => 'decimal:8',
            'custom_latitude' => 'decimal:8',
            'custom_longitude' => 'decimal:8',
            'notify_via' => 'array',
            'notify_with_bounty_only' => 'boolean',
            'quiet_hours_start' => 'datetime:H:i',
            'quiet_hours_end' => 'datetime:H:i',
            'last_alert_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeWithHomeLocation($query)
    {
        return $query->whereNotNull('home_latitude')
            ->whereNotNull('home_longitude');
    }

    public function scopeWithWorkLocation($query)
    {
        return $query->whereNotNull('work_latitude')
            ->whereNotNull('work_longitude');
    }

    public function scopeWithCustomLocation($query)
    {
        return $query->whereNotNull('custom_latitude')
            ->whereNotNull('custom_longitude');
    }

    /**
     * Find subscriptions near a given location.
     */
    public function scopeNearLocation($query, float $latitude, float $longitude, string $locationType = 'home')
    {
        $latCol = "{$locationType}_latitude";
        $lonCol = "{$locationType}_longitude";
        $radiusCol = "{$locationType}_radius_km";

        $haversine = "(6371 * acos(cos(radians(?)) * cos(radians({$latCol})) * cos(radians({$lonCol}) - radians(?)) + sin(radians(?)) * sin(radians({$latCol}))))";

        return $query->whereNotNull($latCol)
            ->whereNotNull($lonCol)
            ->selectRaw("*, {$haversine} AS distance", [$latitude, $longitude, $latitude])
            ->havingRaw("distance <= {$radiusCol}");
    }

    // -------------------------------------------------------------------------
    // Helper Methods
    // -------------------------------------------------------------------------

    public function hasHomeLocation(): bool
    {
        return $this->home_latitude && $this->home_longitude;
    }

    public function hasWorkLocation(): bool
    {
        return $this->work_latitude && $this->work_longitude;
    }

    public function hasCustomLocation(): bool
    {
        return $this->custom_latitude && $this->custom_longitude;
    }

    public function getConfiguredLocations(): array
    {
        $locations = [];

        if ($this->hasHomeLocation()) {
            $locations['home'] = [
                'latitude' => $this->home_latitude,
                'longitude' => $this->home_longitude,
                'radius_km' => $this->home_radius_km,
                'label' => $this->home_label ?? 'Home',
            ];
        }

        if ($this->hasWorkLocation()) {
            $locations['work'] = [
                'latitude' => $this->work_latitude,
                'longitude' => $this->work_longitude,
                'radius_km' => $this->work_radius_km,
                'label' => $this->work_label ?? 'Work',
            ];
        }

        if ($this->hasCustomLocation()) {
            $locations['custom'] = [
                'latitude' => $this->custom_latitude,
                'longitude' => $this->custom_longitude,
                'radius_km' => $this->custom_radius_km,
                'label' => $this->custom_label ?? 'Custom',
            ];
        }

        return $locations;
    }

    /**
     * Check if a given location is within any of the user's monitored areas.
     */
    public function isLocationInRange(float $latitude, float $longitude): bool
    {
        foreach ($this->getConfiguredLocations() as $location) {
            $distance = $this->calculateDistance(
                $location['latitude'],
                $location['longitude'],
                $latitude,
                $longitude
            );

            if ($distance <= $location['radius_km']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if current time is within quiet hours.
     */
    public function isInQuietHours(): bool
    {
        if (! $this->quiet_hours_start || ! $this->quiet_hours_end) {
            return false;
        }

        $now = now()->format('H:i');
        $start = $this->quiet_hours_start->format('H:i');
        $end = $this->quiet_hours_end->format('H:i');

        // Handle overnight quiet hours (e.g., 22:00 to 06:00)
        if ($start > $end) {
            return $now >= $start || $now <= $end;
        }

        return $now >= $start && $now <= $end;
    }

    /**
     * Check if user should receive notification for an alert.
     */
    public function shouldReceiveAlert(CommunityAlert $alert): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->isInQuietHours()) {
            return false;
        }

        // Check bounty filter
        if ($this->notify_with_bounty_only && ! $alert->hasBounty()) {
            return false;
        }

        if ($this->min_bounty_amount && (! $alert->bounty_amount || $alert->bounty_amount < $this->min_bounty_amount)) {
            return false;
        }

        // Check location
        return $this->isLocationInRange(
            $alert->broadcast_latitude,
            $alert->broadcast_longitude
        );
    }

    public function incrementAlertsReceived(): void
    {
        $this->update([
            'alerts_received' => $this->alerts_received + 1,
            'last_alert_at' => now(),
        ]);
    }

    /**
     * Get or create subscription for a user.
     */
    public static function forUser(User $user): self
    {
        return self::firstOrCreate(
            ['user_id' => $user->id],
            [
                'is_active' => true,
                'home_radius_km' => 5,
                'work_radius_km' => 5,
                'custom_radius_km' => 5,
                'notify_via' => ['push'],
                'notify_with_bounty_only' => false,
                'alerts_received' => 0,
            ]
        );
    }

    /**
     * Calculate distance between two coordinates in kilometers.
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;

        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $a = sin($latDelta / 2) ** 2 +
            cos($latFrom) * cos($latTo) * sin($lonDelta / 2) ** 2;

        return $earthRadius * 2 * asin(sqrt($a));
    }
}
