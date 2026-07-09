<?php

namespace Database\Factories;

use App\Enums\TheftReportStatus;
use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TheftReport>
 */
class TheftReportFactory extends Factory
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
            'reported_by' => User::factory(),
            'status' => TheftReportStatus::Reported,
            'theft_date' => fake()->dateTimeBetween('-30 days', 'now'),
            'theft_location' => fake()->address(),
            'theft_latitude' => fake()->latitude(-17, -9),
            'theft_longitude' => fake()->longitude(32, 36),
            'description' => fake()->paragraph(),
        ];
    }

    public function investigating(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TheftReportStatus::Investigating,
            'assigned_to' => User::factory(),
        ]);
    }

    public function recovered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TheftReportStatus::Recovered,
            'recovered_at' => fake()->dateTimeBetween('-7 days', 'now'),
            'recovery_location' => fake()->address(),
            'recovery_notes' => fake()->sentence(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TheftReportStatus::Closed,
        ]);
    }

    public function withPoliceReport(): static
    {
        return $this->state(fn (array $attributes) => [
            'police_report_number' => 'PR-'.fake()->numerify('######'),
            'police_station' => fake()->city().' Police Station',
            'officer_name' => fake()->name(),
            'officer_contact' => '+265'.fake()->numerify('#########'),
        ]);
    }

    public function withEvidence(): static
    {
        return $this->state(fn (array $attributes) => [
            'evidence_photos' => [
                'evidence/'.fake()->uuid().'.jpg',
                'evidence/'.fake()->uuid().'.jpg',
            ],
        ]);
    }
}
