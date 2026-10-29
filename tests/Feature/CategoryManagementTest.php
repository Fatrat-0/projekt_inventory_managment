<?php

namespace Tests\Feature;

use App\Models\{Category, Product, User};
use App\Support\{CategoryListContext, CategoryTree};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;
    private Category $root;
    private Category $child;
    private Category $leaf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->root = Category::create(['category_name' => 'Gépek', 'default_item_min_stock' => 5]);
        $this->child = Category::create(['category_name' => 'Fúró 100%_!', 'parent_id' => $this->root->category_id, 'default_item_min_stock' => 0]);
        $this->leaf = Category::create(['category_name' => 'Akkus', 'parent_id' => $this->child->category_id]);
    }

    public function test_search_combines_words_across_name_and_parent_and_treats_wildcards_literally(): void
    {
        $this->get(route('categories.index', ['search' => 'Fúró Gépek']))->assertOk()->assertViewHas('categories', fn ($rows) => $rows->pluck('category_id')->all() === [$this->child->category_id]);
        $this->get(route('categories.index', ['search' => '%_!']))->assertOk()->assertViewHas('categories', fn ($rows) => $rows->pluck('category_id')->sort()->values()->all() === [$this->child->category_id, $this->leaf->category_id]);
        $this->get('/categories?type=root')->assertViewHas('categories', fn ($rows) => $rows->total() === 1);
        $this->get('/categories?type=child')->assertViewHas('categories', fn ($rows) => $rows->total() === 2);
        $this->get(route('categories.index', ['category_id' => $this->leaf->category_id]))->assertViewHas('categories', fn ($rows) => $rows->pluck('category_id')->all() === [$this->leaf->category_id]);
    }

    public function test_counts_are_direct_and_paths_include_all_ancestors(): void
    {
        Product::create(['product_name' => 'Termék', 'sku' => 'CATEGORY-1', 'price' => 1, 'category_id' => $this->leaf->category_id]);
        $this->get('/categories')->assertOk()->assertSee('Gépek / Fúró 100%_! / Akkus')->assertViewHas('categories', function ($rows) {
            $this->assertSame(0, $rows->firstWhere('category_id', $this->root->category_id)->products_count);
            $this->assertSame(1, $rows->firstWhere('category_id', $this->root->category_id)->children_count);
            $this->assertSame(1, $rows->firstWhere('category_id', $this->leaf->category_id)->products_count);
            return true;
        });
    }

    public function test_threshold_sort_keeps_null_last_and_zero_is_not_missing(): void
    {
        foreach (['asc' => [$this->child->category_id, $this->root->category_id, $this->leaf->category_id], 'desc' => [$this->root->category_id, $this->child->category_id, $this->leaf->category_id]] as $direction => $expected) {
            $this->get('/categories?sort_by=default_item_min_stock&sort_dir='.$direction)->assertViewHas('categories', fn ($rows) => $rows->pluck('category_id')->all() === $expected);
        }
    }

    public function test_pagination_is_stable_and_out_of_range_pages_recover(): void
    {
        for ($i = 0; $i < 17; $i++) Category::create(['category_name' => 'Azonos']);
        $first = $this->get('/categories?per_page=15')->assertOk()->viewData('categories');
        $second = $this->get('/categories?per_page=15&page=2')->assertOk()->viewData('categories');
        $this->assertCount(15, $first); $this->assertCount(5, $second);
        $this->assertCount(0, array_intersect($first->pluck('category_id')->all(), $second->pluck('category_id')->all()));
        $response = $this->get('/categories?per_page=15&page=999');
        $this->assertStringContainsString('page=2', $response->headers->get('Location'));
        $this->get('/categories?search=NO-SUCH-CATEGORY&page=999')->assertRedirect(route('categories.index', ['search' => 'NO-SUCH-CATEGORY', 'category_id' => '', 'type' => '', 'sort_by' => 'category_name', 'sort_dir' => 'asc', 'per_page' => 15]));
    }

    public function test_create_update_clear_values_and_context_are_preserved(): void
    {
        $context = CategoryListContext::encode(['search' => 'Gépek', 'sort_by' => 'category_name', 'per_page' => 25, 'page' => 2]);
        $this->post('/categories', ['category_name' => 'Új', 'parent_id' => $this->root->category_id, 'default_item_min_stock' => 0, 'aggregate_min_stock' => 2147483647, 'context' => $context])->assertRedirect(CategoryListContext::url($context));
        $new = Category::where('category_name', 'Új')->firstOrFail();
        $this->assertSame(0, $new->default_item_min_stock);
        $this->put(route('categories.update', $new), ['category_name' => 'Módosított', 'parent_id' => '', 'default_item_min_stock' => '', 'aggregate_min_stock' => '', 'context' => $context])->assertRedirect(CategoryListContext::url($context));
        $this->assertNull($new->fresh()->parent_id); $this->assertNull($new->fresh()->default_item_min_stock); $this->assertNull($new->fresh()->aggregate_min_stock);
        $this->get(route('categories.edit', ['category' => $new, 'context' => $context]))->assertOk()->assertViewHas('returnUrl', CategoryListContext::url($context));
    }

    public function test_self_child_and_deep_descendant_cannot_become_parent_and_update_is_atomic(): void
    {
        foreach ([$this->root, $this->child, $this->leaf] as $parent) {
            $this->putJson(route('categories.update', $this->root), ['category_name' => 'Must not change', 'parent_id' => $parent->category_id])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
            $this->assertSame('Gépek', $this->root->fresh()->category_name);
            $this->assertNull($this->root->fresh()->parent_id);
        }
        $this->get(route('categories.edit', $this->root))->assertViewHas('categories', fn ($options) => $options->isEmpty());
        $this->putJson(route('categories.update', $this->leaf), ['category_name' => 'Akkus', 'parent_id' => $this->root->category_id])->assertRedirect();
        $this->assertSame($this->root->category_id, $this->leaf->fresh()->parent_id);
    }

    public function test_used_categories_cannot_be_deleted_and_unused_leaf_can_be_deleted(): void
    {
        $this->deleteJson(route('categories.destroy', $this->root))->assertUnprocessable()->assertJsonValidationErrors('category');
        Product::create(['product_name' => 'Termék', 'sku' => 'PROTECTED', 'price' => 0, 'category_id' => $this->leaf->category_id]);
        $this->deleteJson(route('categories.destroy', $this->leaf))->assertUnprocessable();
        $this->assertSame($this->root->category_id, $this->child->fresh()->parent_id);
        $unused = Category::create(['category_name' => 'Üres levél']);
        $context = CategoryListContext::encode(['category_id' => $unused->category_id, 'search' => 'Üres', 'page' => 2]);
        $this->delete(route('categories.destroy', $unused), ['context' => $context])->assertRedirect(route('categories.index', ['search' => 'Üres']));
        $this->assertDatabaseMissing('categories', ['category_id' => $unused->category_id]);
        $this->assertDatabaseCount('products', 1);
    }

    public static function badFields(): array
    {
        return [['category_name', ''], ['category_name', str_repeat('x', 101)], ['category_name', ['bad']], ['parent_id', 99999], ['parent_id', ['bad']], ['default_item_min_stock', -1], ['aggregate_min_stock', 1.5], ['aggregate_min_stock', 2147483648]];
    }

    #[DataProvider('badFields')]
    public function test_write_validation_matches_database_constraints(string $field, mixed $value): void
    {
        $this->postJson('/categories', array_replace(['category_name' => 'Valid'], [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('categories', 3);
    }

    public static function badFilters(): array { return [['sort_by', 'DROP TABLE'], ['sort_dir', 'bad'], ['type', 'bad'], ['per_page', 1], ['page', -1], ['category_id', 999999], ['search', ['bad']]]; }

    #[DataProvider('badFilters')]
    public function test_bad_filters_recover_in_html_and_remain_422_in_json(string $key, mixed $value): void
    {
        $url = route('categories.index', [$key => $value]);
        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors($key);
        $this->get($url)->assertRedirect(route('categories.index'))->assertSessionHasErrors('filters');
    }

    public function test_context_cannot_redirect_externally_or_accept_arrays(): void
    {
        foreach (['https://evil.example', ['invalid'], str_repeat('x', 5000), Crypt::encryptString('{"redirect":"https://evil.example","search":"safe"}')] as $token) {
            $url = CategoryListContext::url($token);
            $this->assertStringStartsWith(route('categories.index'), $url);
            $this->assertStringNotContainsString('evil.example', $url);
        }
    }

    public function test_foreign_key_failure_is_friendly_and_transaction_rolls_back(): void
    {
        Category::deleting(fn ($category) => Product::create(['product_name' => 'Late dependency', 'sku' => 'LATE-CATEGORY', 'price' => 0, 'category_id' => $category->category_id]));
        try {
            $this->deleteJson(route('categories.destroy', $this->leaf))->assertUnprocessable()->assertJsonValidationErrors('category');
            $this->assertModelExists($this->leaf);
            $this->assertDatabaseCount('products', 0);
        } finally { Category::flushEventListeners(); }
    }

    public function test_legacy_cycle_does_not_hang_path_or_branch_traversal(): void
    {
        $this->root->update(['parent_id' => $this->leaf->category_id]);
        $tree = new CategoryTree(Category::all());
        $this->assertCount(3, $tree->branchIds($this->root->category_id));
        $this->assertLessThan(150, strlen($tree->path($this->leaf->category_id)));
        $this->get('/categories')->assertOk();
    }

    public function test_categories_cannot_be_attached_to_a_pre_existing_cyclic_branch(): void
    {
        $this->root->update(['parent_id' => $this->leaf->category_id]);
        $healthy = Category::create(['category_name' => 'Egészséges ág']);
        $this->postJson('/categories', ['category_name' => 'Új kategória', 'parent_id' => $this->root->category_id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->putJson(route('categories.update', $healthy), ['category_name' => 'Nem menthető', 'parent_id' => $this->child->category_id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->assertSame('Egészséges ág', $healthy->fresh()->category_name);
        $this->assertNull($healthy->fresh()->parent_id);
        $this->assertDatabaseCount('categories', 4);
        // Removing an invalid parent repairs the old branch and must remain possible.
        $this->putJson(route('categories.update', $this->root), ['category_name' => 'Gépek', 'parent_id' => null])->assertRedirect();
        $this->postJson('/categories', ['category_name' => 'Most már menthető', 'parent_id' => $this->leaf->category_id])->assertRedirect();
    }

    public function test_malformed_form_values_render_safely_after_validation_failure(): void
    {
        $url = route('categories.edit', $this->leaf);
        $this->from($url)->put(route('categories.update', $this->leaf), [
            'category_name' => ['bad'], 'parent_id' => ['bad'], 'default_item_min_stock' => ['bad'], 'aggregate_min_stock' => ['bad'], 'context' => ['bad'],
        ])->assertRedirect($url)->assertSessionHasErrors(['category_name', 'parent_id', 'default_item_min_stock', 'aggregate_min_stock']);
        $this->get($url)->assertOk();
        $this->assertSame('Akkus', $this->leaf->fresh()->category_name);
    }

    public function test_category_names_are_escaped_in_list_form_and_search_suggestions(): void
    {
        $name = '<script>alert(1)</script>';
        $category = Category::create(['category_name' => $name]);
        $this->get('/categories')->assertOk()->assertSee(e($name), false)->assertDontSee($name, false);
        $this->get(route('categories.edit', $category))->assertOk()->assertSee(e($name), false)->assertDontSee($name, false);
    }

    public function test_role_permissions_cover_all_management_actions(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'worker']));
        foreach (['/categories', '/categories/create', route('categories.edit', $this->root)] as $url) $this->get($url)->assertForbidden();
        $this->post('/categories', ['category_name' => 'Forbidden'])->assertForbidden();
        $this->put(route('categories.update', $this->root), ['category_name' => 'Forbidden'])->assertForbidden();
        $this->delete(route('categories.destroy', $this->leaf))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'manager']));
        $this->get('/categories')->assertOk();
        $this->get('/categories/create')->assertOk();
        $this->get(route('categories.edit', $this->root))->assertOk();
    }
}
