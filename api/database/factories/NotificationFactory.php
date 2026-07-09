<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Notification>
 */
class NotificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $types = [
            'App\\Notifications\\DeviceLocationUpdate',
            'App\\Notifications\\GeofenceAlert',
            'App\\Notifications\\TheftReportUpdate',
            'App\\Notifications\\SubscriptionRenewal',
            'App\\Notifications\\PaymentReceived',
        ];

        return [
            'id' => Str::uuid()->toString(),
            'type' => fake()->randomElement($types),
            'notifiable_type' => User::class,
            'notifiable_id' => User::factory(),
            'data' => json_encode([
                'title' => fake()->sentence(3),
                'message' => fake()->sentence(),
                'action_url' => fake()->optional()->url(),
            ]),
            'read_at' => fake()->optional(0.4)->dateTimeBetween('-1 week', 'now'),
        ];
    }

    public function unread(): static
    {
        return $this->state(fn (array $attributes) => [
            'read_at' => null,
        ]);
    }

    public function read(): static
    {
        return $this->state(fn (array $attributes) => [
            'read_at' => fake()->dateTimeBetween('-1 week', 'now'),
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
        ]);
    }
}
