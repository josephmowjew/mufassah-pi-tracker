<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Family>
 */
class FamilyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->lastName().' Family',
            'code' => strtoupper(Str::random(8)),
            'owner_id' => User::factory(),
            'avatar' => null,
            'settings' => null,
        ];
    }

    /**
     * Indicate that the family has custom settings.
     */
    public function withSettings(array $settings = []): static
    {
        return $this->state(fn (array $attributes) => [
            'settings' => array_merge([
                'location_update_interval' => 5,
                'allow_member_invites' => false,
                'require_approval_for_join' => false,
            ], $settings),
        ]);
    }

    /**
     * Indicate that join requires approval.
     */
    public function requiresApproval(): static
    {
        return $this->withSettings(['require_approval_for_join' => true]);
    }
}
