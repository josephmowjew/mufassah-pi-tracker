<?php

namespace Database\Factories;

use App\Enums\GeofenceShapeType;
use App\Models\Device;
use App\Models\Geofence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Geofence>
 */
class GeofenceFactory extends Factory
{
    protected $model = Geofence::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'name' => fake()->words(2, true).' Zone',
            'description' => fake()->sentence(),
            'shape_type' => GeofenceShapeType::Circle,
            'center_latitude' => fake()->latitude(-17, -9),
            'center_longitude' => fake()->longitude(32, 36),
            'radius_meters' => fake()->randomElement([100, 200, 500, 1000, 2000]),
            'polygon_coordinates' => null,
            'is_active' => true,
            'notify_on_entry' => true,
            'notify_on_exit' => true,
            'active_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            'active_time_start' => null,
            'active_time_end' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function entryOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'notify_on_entry' => true,
            'notify_on_exit' => false,
        ]);
    }

    public function exitOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'notify_on_entry' => false,
            'notify_on_exit' => true,
        ]);
    }

    public function withSchedule(): static
    {
        return $this->state(fn (array $attributes) => [
            'active_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'active_time_start' => '08:00',
            'active_time_end' => '18:00',
        ]);
    }

    public function home(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Home',
            'radius_meters' => 100,
        ]);
    }

    public function work(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Work',
            'radius_meters' => 200,
        ]);
    }
}
