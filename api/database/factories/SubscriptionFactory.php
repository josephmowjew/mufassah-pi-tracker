<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('-6 months', 'now');
        $billingCycle = fake()->randomElement(['monthly', 'yearly']);
        $months = $billingCycle === 'monthly' ? 1 : 12;

        return [
            'user_id' => User::factory(),
            'plan_id' => SubscriptionPlan::factory(),
            'status' => SubscriptionStatus::Active,
            'billing_cycle' => $billingCycle,
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->modify("+{$months} months"),
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'auto_renew' => true,
            'last_payment_id' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addMonths(11),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::Expired,
            'starts_at' => now()->subYear(),
            'ends_at' => now()->subMonth(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now()->subWeek(),
            'cancellation_reason' => fake()->sentence(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::Pending,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::Suspended,
        ]);
    }

    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => [
            'billing_cycle' => 'monthly',
            'ends_at' => now()->addMonth(),
        ]);
    }

    public function yearly(): static
    {
        return $this->state(fn (array $attributes) => [
            'billing_cycle' => 'yearly',
            'ends_at' => now()->addYear(),
        ]);
    }
}
