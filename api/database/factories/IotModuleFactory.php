<?php

namespace Database\Factories;

use App\Enums\IotModuleStatus;
use App\Enums\ModemType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\IotModule>
 */
class IotModuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'module_id' => 'MOD-'.strtoupper(Str::random(10)),
            'api_token' => Str::random(64),
            'sim_number' => fake()->optional(0.7)->numerify('###########'),
            'imei' => fake()->optional(0.8)->numerify('###############'),
            'modem_type' => fake()->randomElement(ModemType::cases()),
            'firmware_version' => fake()->semver(),
            'hardware_version' => 'v'.fake()->numberBetween(1, 3).'.'.fake()->numberBetween(0, 9),
            'status' => IotModuleStatus::Active,
            'battery_level' => fake()->numberBetween(20, 100),
            'signal_strength' => fake()->numberBetween(-100, -30),
            'configuration' => [
                'update_interval' => fake()->randomElement([30, 60, 120, 300]),
                'sleep_mode' => fake()->boolean(),
            ],
            'last_ping' => fake()->optional(0.9)->dateTimeBetween('-1 hour', 'now'),
            'last_location_update' => fake()->optional(0.8)->dateTimeBetween('-2 hours', 'now'),
            'activated_at' => fake()->optional(0.9)->dateTimeBetween('-1 year', '-1 day'),
        ];
    }

    /**
     * Indicate that the module is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IotModuleStatus::Inactive,
            'activated_at' => null,
        ]);
    }

    /**
     * Indicate that the module is offline.
     */
    public function offline(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IotModuleStatus::Offline,
            'last_ping' => fake()->dateTimeBetween('-1 week', '-1 day'),
        ]);
    }

    /**
     * Indicate that the module has been tampered with.
     */
    public function tampered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IotModuleStatus::Tampered,
        ]);
    }

    /**
     * Indicate that the module is in maintenance mode.
     */
    public function maintenance(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IotModuleStatus::Maintenance,
        ]);
    }

    /**
     * Indicate the module has a GPS modem.
     */
    public function gps(): static
    {
        return $this->state(fn (array $attributes) => [
            'modem_type' => ModemType::Gps,
        ]);
    }

    /**
     * Indicate the module has a GPS+GSM modem.
     */
    public function gpsGsm(): static
    {
        return $this->state(fn (array $attributes) => [
            'modem_type' => ModemType::GpsGsm,
        ]);
    }

    /**
     * Indicate the module has low battery.
     */
    public function lowBattery(): static
    {
        return $this->state(fn (array $attributes) => [
            'battery_level' => fake()->numberBetween(1, 20),
        ]);
    }
}
