<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subscription_id' => Subscription::factory(),
            'payment_number' => 'PAY-'.strtoupper(fake()->unique()->bothify('######')),
            'amount' => fake()->randomElement([5000, 10000, 20000, 50000]),
            'currency' => 'MWK',
            'status' => PaymentStatus::Completed,
            'payment_method' => PaymentMethod::MobileMoney,
            'payment_provider' => 'paychangu',
            'provider_transaction_id' => fake()->uuid(),
            'provider_reference' => 'tx_'.fake()->bothify('##########'),
            'description' => 'Subscription payment',
            'metadata' => [
                'plan_name' => 'Basic',
                'billing_cycle' => 'monthly',
            ],
            'paid_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Pending,
            'paid_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Failed,
            'paid_at' => null,
        ]);
    }

    public function refunded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Refunded,
        ]);
    }

    public function mobileMoney(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_method' => PaymentMethod::MobileMoney,
        ]);
    }

    public function bankTransfer(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_method' => PaymentMethod::BankTransfer,
        ]);
    }
}
