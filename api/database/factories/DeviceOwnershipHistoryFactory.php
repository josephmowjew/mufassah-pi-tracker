<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DeviceOwnershipHistory>
 */
class DeviceOwnershipHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $reasons = [
            'Sale',
            'Gift',
            'Inheritance',
            'Company transfer',
            'Lease agreement',
            'Trade-in',
            'Return to manufacturer',
        ];

        return [
            'device_id' => Device::factory(),
            'from_user_id' => User::factory(),
            'to_user_id' => User::factory(),
            'transfer_reason' => fake()->randomElement($reasons),
            'notes' => fake()->optional(0.4)->paragraph(),
            'transferred_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'created_at' => now(),
        ];
    }

    /**
     * Indicate that this is the initial ownership (no previous owner).
     */
    public function initial(): static
    {
        return $this->state(fn (array $attributes) => [
            'from_user_id' => null,
            'transfer_reason' => 'Initial registration',
        ]);
    }

    /**
     * Set a specific device for the history record.
     */
    public function forDevice(Device $device): static
    {
        return $this->state(fn (array $attributes) => [
            'device_id' => $device->id,
        ]);
    }

    /**
     * Set the previous owner.
     */
    public function fromUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'from_user_id' => $user->id,
        ]);
    }

    /**
     * Set the new owner.
     */
    public function toUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'to_user_id' => $user->id,
        ]);
    }
}
