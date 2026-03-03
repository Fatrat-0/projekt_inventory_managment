<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_name' => fake()->words(3, true), // 3 szavas terméknév
            'sku' => fake()->unique()->bothify('SKU-####-????'), // pl. SKU-1234-ABCD
            'price' => fake()->numberBetween(1000, 100000), // Ár 1.000 és 100.000 Ft között
            // Véletlenszerűen kiválaszt egy már létező kategória ID-t
            'category_id' => \App\Models\Category::inRandomOrder()->first()->category_id,
        ];
    }
}
