<?php

namespace Database\Factories;

use App\Enums\FamilyMemberStatus;
use App\Enums\FamilyRole;
use App\Models\Family;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FamilyMember>
 */
class FamilyMemberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'user_id' => User::factory(),
            'role' => FamilyRole::Member,
            'nickname' => null,
            'status' => FamilyMemberStatus::Active,
            'joined_at' => now(),
            'invited_by' => null,
            'permissions' => null,
            'location_sharing_enabled' => true,
            'receive_family_alerts' => true,
        ];
    }

    /**
     * Indicate that the member is an admin.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => FamilyRole::Admin,
        ]);
    }

    /**
     * Indicate that the member is a guardian.
     */
    public function guardian(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => FamilyRole::Guardian,
        ]);
    }

    /**
     * Indicate that the member is a child.
     */
    public function child(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => FamilyRole::Child,
        ]);
    }

    /**
     * Indicate that the membership is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FamilyMemberStatus::Pending,
            'joined_at' => null,
        ]);
    }

    /**
     * Indicate that the membership was removed.
     */
    public function removed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FamilyMemberStatus::Removed,
        ]);
    }

    /**
     * Indicate that an inviter is specified.
     */
    public function invitedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'invited_by' => $user->id,
        ]);
    }
}
