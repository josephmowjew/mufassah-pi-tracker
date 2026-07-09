<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    protected $model = SubscriptionPlan::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->word().'-'.fake()->numberBetween(1000, 9999);

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'description' => fake()->sentence(10),
            'price_monthly' => fake()->randomElement([5000, 10000, 20000, 50000]),
            'price_yearly' => fake()->randomElement([50000, 100000, 200000, 500000]),
            'currency' => 'MWK',
            'max_devices' => fake()->randomElement([2, 5, 10, 20, -1]),
            'max_geofences_per_device' => fake()->randomElement([3, 5, 10, -1]),
            'location_history_days' => fake()->randomElement([7, 30, 90, 365]),
            'real_time_tracking' => true,
            'theft_alerts' => true,
            'geofence_alerts' => fake()->boolean(80),
            'priority_support' => fake()->boolean(30),
            'features' => ['Location tracking', 'Mobile app access', 'Email alerts'],
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function basic(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Basic',
            'slug' => 'basic',
            'price_monthly' => 5000.00,
            'price_yearly' => 50000.00,
            'max_devices' => 2,
            'max_geofences_per_device' => 3,
            'location_history_days' => 7,
            'priority_support' => false,
            'sort_order' => 1,
        ]);
    }

    public function premium(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Premium',
            'slug' => 'premium',
            'price_monthly' => 20000.00,
            'price_yearly' => 200000.00,
            'max_devices' => 20,
            'max_geofences_per_device' => -1,
            'location_history_days' => 365,
            'priority_support' => true,
            'sort_order' => 3,
        ]);
    }
}
