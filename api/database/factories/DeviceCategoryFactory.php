<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DeviceCategory>
 */
class DeviceCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $categories = [
            'Smartphone' => 'mobile',
            'Laptop' => 'laptop',
            'Tablet' => 'tablet',
            'Smartwatch' => 'watch',
            'Camera' => 'camera',
            'Drone' => 'drone',
            'Gaming Console' => 'gamepad',
            'TV' => 'tv',
            'Audio Equipment' => 'headphones',
            'Vehicle' => 'car',
        ];

        $name = fake()->unique()->randomElement(array_keys($categories));

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'icon' => $categories[$name],
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the category is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Create a category with a specific name.
     */
    public function withName(string $name): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }
}
