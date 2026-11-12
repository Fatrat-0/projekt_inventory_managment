<?php

namespace Tests\Feature;

use App\Models\{Category, Product, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPackagingTest extends TestCase
{
    use RefreshDatabase;

    private array $productData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->productData = [
            'product_name' => 'Csavar (100 db/doboz)',
            'sku' => 'PKG-001',
            'category_id' => Category::create(['category_name' => 'Szerelvények'])->getKey(),
            'price' => 150,
        ];
    }

    public function test_product_creation_accepts_optional_structured_packaging(): void
    {
        $this->post(route('products.store'), $this->productData)->assertRedirect(route('products.index'));
        $this->assertDatabaseCount('product_packagings', 0);

        $packaging = [
            'name' => 'Műanyag doboz',
            'pieces_per_package' => 100,
            'gross_weight_kg' => '2.345',
            'width_cm' => '12.25',
            'depth_cm' => '20.50',
            'barcode' => '001234567890',
        ];
        $this->post(route('products.store'), array_replace($this->productData, ['sku' => 'PKG-002', 'packaging' => $packaging]))
            ->assertRedirect(route('products.index'));

        $product = Product::where('sku', 'PKG-002')->sole();
        $saved = $product->defaultPackaging()->sole();
        $this->assertSame(100, $saved->pieces_per_package);
        $this->assertSame('2.345', $saved->gross_weight_kg);
        $this->assertSame('12.25', $saved->width_cm);
        $this->assertSame('20.50', $saved->depth_cm);
        $this->assertNull($saved->height_cm);
        $this->assertSame('001234567890', $saved->barcode);

        $this->get(route('products.show', $product))->assertOk()->assertSee('Műanyag doboz')->assertSee('100 db');
        $this->get(route('products.edit', $product))->assertOk()->assertSee('name="packaging[barcode]"', false)->assertSee('001234567890');
    }

    public function test_invalid_packaging_is_rejected_before_saving_the_product(): void
    {
        $this->postJson(route('products.store'), $this->productData + ['packaging' => ['width_cm' => '8.25']])
            ->assertUnprocessable()->assertJsonValidationErrors(['packaging.name', 'packaging.pieces_per_package']);

        $this->postJson(route('products.store'), $this->productData + [
            'packaging' => ['name' => 'Doboz', 'pieces_per_package' => 1.5, 'gross_weight_kg' => '0.0001', 'depth_cm' => -1],
        ])->assertUnprocessable()->assertJsonValidationErrors(['packaging.pieces_per_package', 'packaging.gross_weight_kg', 'packaging.depth_cm']);

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_packagings', 0);
    }

    public function test_product_edit_preserves_or_updates_packaging_under_the_existing_version_check(): void
    {
        $product = Product::create($this->productData)->fresh();
        $product->defaultPackaging()->create(['name' => 'Doboz', 'pieces_per_package' => 10]);
        $url = route('products.update', $product);

        $this->putJson($url, $this->productData + ['lock_version' => 1])->assertRedirect(route('products.index'));
        $this->assertSame(10, $product->defaultPackaging()->sole()->pieces_per_package);

        $updated = ['name' => 'Nagy doboz', 'pieces_per_package' => 100];
        $this->putJson($url, $this->productData + ['lock_version' => 2, 'packaging' => $updated])->assertRedirect(route('products.index'));
        $this->assertSame(100, $product->defaultPackaging()->sole()->pieces_per_package);

        $stale = $this->productData + ['lock_version' => 2, 'packaging' => ['name' => 'Saját csomagolás', 'pieces_per_package' => 15]];
        $response = $this->put($url, $stale)->assertRedirect(route('products.edit', $product))->assertSessionHasErrors('lock_version');
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Nagy doboz')->assertSee('Saját csomagolás');
        $this->assertSame(100, $product->defaultPackaging()->sole()->pieces_per_package);

        $cleared = array_fill_keys(['name', 'pieces_per_package', 'gross_weight_kg', 'width_cm', 'depth_cm', 'height_cm', 'barcode'], '');
        $this->putJson($url, $this->productData + ['lock_version' => 3, 'packaging' => $cleared])->assertRedirect(route('products.index'));
        $this->assertNull($product->defaultPackaging()->first());
        $this->assertDatabaseCount('products', 1);
    }
}
