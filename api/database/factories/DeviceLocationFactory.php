<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\IotModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DeviceLocation>
 */
class DeviceLocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'iot_module_id' => null,
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'altitude' => fake()->optional(0.6)->randomFloat(2, 0, 3000),
            'accuracy' => fake()->randomFloat(2, 1, 50),
            'speed' => fake()->optional(0.5)->randomFloat(2, 0, 120),
            'heading' => fake()->optional(0.5)->randomFloat(2, 0, 360),
            'address' => fake()->optional(0.7)->address(),
            'is_manual' => false,
            'recorded_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'created_at' => now(),
        ];
    }

    /**
     * Indicate that the location was recorded manually.
     */
    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_manual' => true,
            'iot_module_id' => null,
        ]);
    }

    /**
     * Indicate that the location was recorded by an IoT module.
     */
    public function fromIotModule(?IotModule $module = null): static
    {
        return $this->state(fn (array $attributes) => [
            'iot_module_id' => $module?->id ?? IotModule::factory(),
            'is_manual' => false,
        ]);
    }

    /**
     * Set a specific device for the location.
     */
    public function forDevice(Device $device): static
    {
        return $this->state(fn (array $attributes) => [
            'device_id' => $device->id,
        ]);
    }

    /**
     * Set a location within a specific area (bounding box).
     */
    public function inArea(float $latMin, float $latMax, float $lonMin, float $lonMax): static
    {
        return $this->state(fn (array $attributes) => [
            'latitude' => fake()->randomFloat(8, $latMin, $latMax),
            'longitude' => fake()->randomFloat(8, $lonMin, $lonMax),
        ]);
    }

    /**
     * Set a recent location (within the last hour).
     */
    public function recent(): static
    {
        return $this->state(fn (array $attributes) => [
            'recorded_at' => fake()->dateTimeBetween('-1 hour', 'now'),
        ]);
    }

    /**
     * Set the location to a specific point.
     */
    public function atCoordinates(float $latitude, float $longitude): static
    {
        return $this->state(fn (array $attributes) => [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }
}
