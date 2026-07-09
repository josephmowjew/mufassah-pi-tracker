<?php

namespace Database\Factories;

use App\Enums\WarrantyClaimStatus;
use App\Models\User;
use App\Models\Warranty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WarrantyClaim>
 */
class WarrantyClaimFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'warranty_id' => Warranty::factory(),
            'claim_number' => 'WC-'.fake()->unique()->numerify('######'),
            'status' => WarrantyClaimStatus::Pending,
            'issue_description' => fake()->paragraph(),
            'issue_category' => fake()->randomElement(['hardware', 'software', 'battery', 'screen', 'other']),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WarrantyClaimStatus::Approved,
            'processed_by' => User::factory(),
            'processed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WarrantyClaimStatus::Rejected,
            'processed_by' => User::factory(),
            'processed_at' => now(),
            'resolution_notes' => 'Claim rejected: Issue not covered under warranty.',
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WarrantyClaimStatus::Processing,
            'processed_by' => User::factory(),
            'processed_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WarrantyClaimStatus::Completed,
            'processed_by' => User::factory(),
            'processed_at' => now()->subDays(3),
            'resolution_type' => fake()->randomElement(['repair', 'replacement', 'refund']),
            'resolution_notes' => fake()->sentence(),
            'repair_cost' => fake()->randomFloat(2, 50, 500),
        ]);
    }

    public function withEvidence(): static
    {
        return $this->state(fn (array $attributes) => [
            'evidence_photos' => [
                'claims/'.fake()->uuid().'.jpg',
                'claims/'.fake()->uuid().'.jpg',
            ],
        ]);
    }
}
