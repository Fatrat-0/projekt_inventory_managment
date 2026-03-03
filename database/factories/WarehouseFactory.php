<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Warehouse>
 */
class WarehouseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Generál egy városnevet + a "Raktár" szót (pl. "Budapest Raktár")
            'warehouse_name' => fake()->unique()->city() . ' Raktár',
            // Generál egy kamu címet
            'location' => fake()->address(),
        ];
    }
}
