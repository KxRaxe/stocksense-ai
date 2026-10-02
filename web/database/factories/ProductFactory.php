<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cost = fake()->randomFloat(2, 5, 500);

        return [
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####-??')),
            'name' => ucfirst(fake()->unique()->word()).' '.fake()->word().' '.fake()->word(),
            'category_id' => Category::factory(),
            'unit' => 'pc',
            'unit_cost' => $cost,
            'unit_price' => round($cost * 1.3, 2),
            'lead_time_days' => fake()->numberBetween(2, 21),
            'moq' => 1,
            'pack_size' => 1,
            'reorder_point_override' => null,
            'safety_stock_override' => null,
            'is_active' => true,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function reorderAt(int $quantity): static
    {
        return $this->state(fn () => ['reorder_point_override' => $quantity]);
    }
}
