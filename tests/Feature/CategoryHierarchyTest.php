<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Category;

class CategoryHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_hierarchy_behaves_correctly_when_parent_is_deleted()
    {
        // 1. ARRANGE (Előkészítés - Felépítjük a családfát)
        
        // Nagyszülő (Főkategória)
        $grandparent = Category::create([
            'category_name' => 'Számítástechnika'
        ]);

        // Szülő (Alkategória)
        $parent = Category::create([
            'category_name' => 'Laptopok',
            'parent_id' => $grandparent->category_id
        ]);

        // Gyerek (Al-alkategória)
        $child = Category::create([
            'category_name' => 'Gamer Laptopok',
            'parent_id' => $parent->category_id
        ]);


        // 2. ACT (Cselekvés - Töröljük a Nagyszülőt, és megnézzük, mi történik a gyerekekkel)
        $grandparent->delete();


        // 3. ASSERT (Ellenőrzés - Megnézzük, mi történt az adatbázisban)
        
        // A) A Nagyszülő tényleg eltűnt az adatbázisból
        $this->assertDatabaseMissing('categories', [
            'category_id' => $grandparent->category_id
        ]);

        // B) A Szülő megmaradt, és Főkategória lett belőle (parent_id = null)
        $this->assertDatabaseHas('categories', [
            'category_id' => $parent->category_id,
            'parent_id' => null
        ]);

        // C) A Gyerek megmaradt, és továbbra is a Szülő gyereke maradt
        $this->assertDatabaseHas('categories', [
            'category_id' => $child->category_id,
            'parent_id' => $parent->category_id
        ]);
    }
}