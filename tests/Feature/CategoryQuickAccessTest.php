<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryMovement, Product, Stock, User, Warehouse};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryQuickAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function category(string $name, ?Category $parent = null): Category
    {
        return Category::create(['category_name' => $name, 'parent_id' => $parent?->category_id]);
    }

    private function product(Category $category, string $sku): Product
    {
        return Product::create(['category_id' => $category->category_id, 'sku' => $sku, 'product_name' => $sku, 'price' => 1]);
    }

    public function test_parent_suggestions_have_complete_paths_and_distinguish_duplicate_names(): void
    {
        $a = $this->category('Gépek'); $b = $this->category('Szerszámok');
        $first = $this->category('Azonos név', $a); $second = $this->category('Azonos név', $b);
        $response = $this->get('/categories/create')->assertOk();
        $items = $response->viewData('parentItems')->keyBy('value');
        $this->assertSame('Gépek / Azonos név · #'.$first->category_id, $items[$first->category_id]['label']);
        $this->assertSame('Szerszámok / Azonos név · #'.$second->category_id, $items[$second->category_id]['label']);
        $response->assertSee('role="combobox"', false)->assertSee('Üres mező: főkategória.');
        $this->assertNull($response->viewData('branchProductCount'));
        $response->assertDontSee('Termékek az ágban:');
    }

    public function test_parent_picker_excludes_self_descendants_and_all_pre_existing_cyclic_branches(): void
    {
        $root = $this->category('Gyökér'); $child = $this->category('Gyerek', $root); $leaf = $this->category('Levél', $child);
        $a = $this->category('Hibás A'); $b = $this->category('Hibás B', $a); $a->update(['parent_id' => $b->category_id]);
        $dependent = $this->category('Hibás ág gyereke', $a);
        $valid = $this->category('Érvényes szülő');
        $this->get(route('categories.edit', $root))->assertOk()->assertViewHas('parentItems', fn ($items) => $items->pluck('value')->all() === [(string) $valid->category_id]);
        $this->get('/categories/create')->assertOk()->assertViewHas('parentItems', function ($items) use ($a, $b, $dependent, $root, $child, $leaf, $valid) {
            $ids = $items->pluck('value')->all();
            foreach ([$a, $b, $dependent] as $invalid) $this->assertNotContains((string) $invalid->category_id, $ids);
            foreach ([$root, $child, $leaf, $valid] as $allowed) $this->assertContains((string) $allowed->category_id, $ids);
            return true;
        });
    }

    public function test_branch_product_count_and_link_match_entire_unpaginated_product_list(): void
    {
        $root = $this->category('Ág'); $child = $this->category('Gyerek', $root); $leaf = $this->category('Levél', $child);
        $other = $this->category('Másik ág');
        $expected = [];
        for ($i = 0; $i < 17; $i++) $expected[] = $this->product([$root, $child, $leaf][$i % 3], 'BRANCH-'.sprintf('%02d', $i))->product_id;
        $this->product($other, 'FOREIGN');
        $warehouse = Warehouse::create(['warehouse_name' => 'Teszt', 'location' => 'Teszt cím']);
        // No stock row, zero stock and positive stock must all count as one product each.
        Stock::create(['warehouse_id' => $warehouse->warehouse_id, 'product_id' => $expected[0], 'quantity' => 0]);
        Stock::create(['warehouse_id' => $warehouse->warehouse_id, 'product_id' => $expected[1], 'quantity' => 999]);
        $before = Stock::orderBy('id')->get()->toArray();
        $url = route('products.index', ['category_id' => $root->category_id, 'sort_by' => 'product_name', 'sort_dir' => 'asc']);
        $this->get(route('categories.edit', $root))->assertOk()->assertViewHas('branchProductCount', 17)->assertSee($url)->assertSee('Termékek az ágban:');
        $first = $this->get($url)->assertOk()->viewData('products');
        $this->assertSame(17, $first->total());
        $second = $this->get($url.'&page=2')->assertOk()->viewData('products');
        $this->assertSame($expected, $first->pluck('product_id')->merge($second->pluck('product_id'))->all());
        $this->assertSame($before, Stock::orderBy('id')->get()->toArray());
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_empty_branch_shows_zero_and_link_opens_an_empty_list(): void
    {
        $empty = $this->category('Üres'); $other = $this->category('Másik'); $this->product($other, 'NOT-IN-BRANCH');
        $this->get(route('categories.edit', $empty))->assertOk()->assertViewHas('branchProductCount', 0);
        $this->get(route('products.index', ['category_id' => $empty->category_id]))->assertOk()->assertViewHas('products', fn ($products) => $products->total() === 0);
    }

    public function test_reparenting_and_product_recategorization_update_counts(): void
    {
        $a = $this->category('Első'); $b = $this->category('Második'); $child = $this->category('Mozgó ág', $a);
        $product = $this->product($child, 'MOVING');
        $this->get(route('categories.edit', $a))->assertViewHas('branchProductCount', 1);
        $this->put(route('categories.update', $child), ['category_name' => $child->category_name, 'parent_id' => $b->category_id])->assertRedirect();
        $this->get(route('categories.edit', $a))->assertViewHas('branchProductCount', 0);
        $this->get(route('categories.edit', $b))->assertViewHas('branchProductCount', 1);
        $product->update(['category_id' => $a->category_id]);
        $this->get(route('categories.edit', $a))->assertViewHas('branchProductCount', 1);
        $this->get(route('categories.edit', $b))->assertViewHas('branchProductCount', 0);
    }

    public function test_pre_existing_cycle_count_is_finite_and_contains_each_product_once(): void
    {
        $a = $this->category('A'); $b = $this->category('B', $a); $a->update(['parent_id' => $b->category_id]);
        $this->product($a, 'CYCLE-A'); $this->product($b, 'CYCLE-B');
        $this->get(route('categories.edit', $a))->assertOk()->assertViewHas('branchProductCount', 2);
        $this->get(route('products.index', ['category_id' => $a->category_id]))->assertOk()->assertViewHas('products', fn ($products) => $products->total() === 2);
    }
}
