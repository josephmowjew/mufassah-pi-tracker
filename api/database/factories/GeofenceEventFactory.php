<?php

namespace Database\Factories;

use App\Enums\GeofenceEventType;
use App\Models\Device;
use App\Models\DeviceLocation;
use App\Models\Geofence;
use App\Models\GeofenceEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GeofenceEvent>
 */
class GeofenceEventFactory extends Factory
{
    protected $model = GeofenceEvent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'geofence_id' => Geofence::factory(),
            'device_id' => Device::factory(),
            'location_id' => DeviceLocation::factory(),
            'event_type' => fake()->randomElement(GeofenceEventType::cases()),
            'distance_from_center' => fake()->randomFloat(2, 0, 5000),
            'alert_sent' => true,
            'event_time' => fake()->dateTimeBetween('-1 week', 'now'),
        ];
    }

    public function entry(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_type' => GeofenceEventType::Entry,
        ]);
    }

    public function exit(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_type' => GeofenceEventType::Exit,
        ]);
    }

    public function alertNotSent(): static
    {
        return $this->state(fn (array $attributes) => [
            'alert_sent' => false,
        ]);
    }
}
