<?php

namespace Database\Factories;

use App\Enums\TheftReportStatus;
use App\Models\TheftReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TheftReportUpdate>
 */
class TheftReportUpdateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'theft_report_id' => TheftReport::factory(),
            'user_id' => User::factory(),
            'old_status' => TheftReportStatus::Reported,
            'new_status' => TheftReportStatus::Investigating,
            'notes' => fake()->sentence(),
            'is_public' => true,
            'created_at' => now(),
        ];
    }

    public function private(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_public' => false,
        ]);
    }

    public function toRecovered(): static
    {
        return $this->state(fn (array $attributes) => [
            'old_status' => TheftReportStatus::Investigating,
            'new_status' => TheftReportStatus::Recovered,
        ]);
    }

    public function toClosed(): static
    {
        return $this->state(fn (array $attributes) => [
            'old_status' => TheftReportStatus::Recovered,
            'new_status' => TheftReportStatus::Closed,
        ]);
    }
}
