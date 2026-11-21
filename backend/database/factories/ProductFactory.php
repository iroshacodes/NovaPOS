<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name' => fake()->words(2, true),
            'sku' => strtoupper(fake()->unique()->bothify('???-###')),
            'price' => fake()->randomFloat(2, 1, 100),
            'cost_price' => fake()->randomFloat(2, 0, 50),
            'stock_quantity' => fake()->numberBetween(10, 50),
            'low_stock_threshold' => 5,
            'image_url' => 'https://example.com/'.fake()->word().'.jpg',
        ];
    }
}
