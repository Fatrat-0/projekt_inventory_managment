<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Létrehozunk 5 kategóriát
        \App\Models\Category::factory(5)->create();

        // 2. Létrehozunk 3 raktárat
        \App\Models\Warehouse::factory(3)->create();

        // 3. Létrehozunk 50 db terméket (ezek automatikusan megkapják valamelyik kategóriát)
        \App\Models\Product::factory(50)->create();
    }
}
