<?php

namespace Database\Factories;

use App\Enums\WarrantyStatus;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Warranty>
 */
class WarrantyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-6 months', 'now');
        $durationMonths = fake()->randomElement([12, 24, 36]);

        return [
            'device_id' => Device::factory(),
            'warranty_number' => 'WRN-'.fake()->unique()->numerify('######'),
            'status' => WarrantyStatus::Active,
            'start_date' => $startDate,
            'end_date' => (clone $startDate)->modify("+{$durationMonths} months"),
            'duration_months' => $durationMonths,
            'coverage_details' => fake()->sentence(),
            'provider' => fake()->company(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WarrantyStatus::Expired,
            'start_date' => now()->subYears(2),
            'end_date' => now()->subYear(),
        ]);
    }

    public function voided(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WarrantyStatus::Voided,
        ]);
    }

    public function claimed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WarrantyStatus::Claimed,
        ]);
    }

    public function withTerms(): static
    {
        return $this->state(fn (array $attributes) => [
            'terms_conditions' => [
                'coverage' => ['Hardware defects', 'Manufacturing issues'],
                'exclusions' => ['Physical damage', 'Water damage', 'Unauthorized modifications'],
                'claim_process' => 'Submit claim within 30 days of issue discovery',
            ],
        ]);
    }
}
