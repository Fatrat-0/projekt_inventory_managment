<?php

namespace Tests\Feature;

use App\Models\{Category, Partner, Product, User, Warehouse};
use App\Services\PartnerDuplicateCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MasterDataConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public static function resources(): array
    {
        return array_map(fn ($resource) => [$resource], ['products', 'partners', 'warehouses', 'categories']);
    }

    private function fixture(string $resource): array
    {
        [$model, $data, $field] = match ($resource) {
            'products' => [Product::class, ['product_name' => 'Eredeti termék', 'sku' => 'EDIT-001', 'price' => 100,
                'category_id' => Category::create(['category_name' => 'Termékkategória'])->getKey(), 'min_stock_override' => null], 'product_name'],
            'partners' => [Partner::class, ['name' => 'Eredeti partner', 'type' => 'customer', 'email' => null, 'phone' => '0'], 'name'],
            'warehouses' => [Warehouse::class, ['warehouse_name' => 'Eredeti raktár', 'location' => 'Eredeti cím'], 'warehouse_name'],
            'categories' => [Category::class, ['category_name' => 'Eredeti kategória', 'parent_id' => null,
                'default_item_min_stock' => 0, 'aggregate_min_stock' => null], 'category_name'],
        };
        return [$model::create($data)->fresh(), $data, $field];
    }

    #[DataProvider('resources')]
    public function test_two_users_cannot_overwrite_each_other_even_in_the_same_second(string $resource): void
    {
        $this->freezeTime();
        [$record, $data, $field] = $this->fixture($resource);
        $url = route($resource.'.update', $record);
        $openedVersion = $record->lock_version;
        $this->get(route($resource.'.edit', $record))->assertOk()->assertSee('name="lock_version" value="1"', false);
        $this->putJson($url, array_replace($data, ['lock_version' => $openedVersion, $field => 'Első felhasználó']))->assertRedirect();
        $current = $record->fresh()->getAttributes();
        $this->assertSame(2, (int) $current['lock_version']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson($url, array_replace($data, ['lock_version' => $openedVersion, $field => 'Második felhasználó']))
            ->assertStatus(409)->assertJsonValidationErrors('lock_version');
        $this->assertSame($current, $record->fresh()->getAttributes());
    }

    #[DataProvider('resources')]
    public function test_conflict_preserves_input_and_review_requires_explicit_confirmation(string $resource): void
    {
        [$record, $data, $field] = $this->fixture($resource);
        $url = route($resource.'.update', $record);
        $this->putJson($url, array_replace($data, ['lock_version' => 1, $field => 'Aktuális mentett érték']))->assertRedirect();
        $own = array_replace($data, ['lock_version' => 1, $field => '<script>saját adat</script>']);
        $response = $this->from('/unrelated')->put($url, $own)->assertRedirect()->assertSessionHasErrors('lock_version')
            ->assertSessionHasInput($field, $own[$field])->assertSessionHasInput('lock_version', 1);
        $form = $this->get($response->headers->get('Location'))->assertOk();
        $form->assertSee('Aktuális mentett érték')->assertSee($own[$field])->assertDontSee($own[$field], false)
            ->assertSee('name="lock_version" value="1"', false)->assertSee('name="review_version" value="2"', false);
        $this->putJson($url, $own + ['review_version' => 2])->assertUnprocessable()->assertJsonValidationErrors('confirm_edit_conflict');
        $this->assertSame('Aktuális mentett érték', $record->fresh()->getAttribute($field));
        $this->putJson($url, $own + ['review_version' => 2, 'confirm_edit_conflict' => 1])->assertRedirect();
        $this->assertSame($own[$field], $record->fresh()->getAttribute($field));
        $this->assertSame(3, (int) $record->fresh()->lock_version);
    }

    #[DataProvider('resources')]
    public function test_review_is_rejected_if_someone_saves_again_while_differences_are_being_read(string $resource): void
    {
        [$record, $data, $field] = $this->fixture($resource);
        $url = route($resource.'.update', $record);
        foreach ([1, 2] as $version) {
            $this->putJson($url, array_replace($data, ['lock_version' => $version, $field => 'Mentett '.$version]))->assertRedirect();
        }
        $this->putJson($url, array_replace($data, ['lock_version' => 1, 'review_version' => 2, 'confirm_edit_conflict' => 1, $field => 'Elavult felülírás']))
            ->assertStatus(409)->assertJsonValidationErrors('lock_version');
        $this->assertSame('Mentett 2', $record->fresh()->getAttribute($field));
        $this->assertSame(3, (int) $record->fresh()->lock_version);
    }

    #[DataProvider('resources')]
    public function test_missing_invalid_or_future_versions_do_not_modify_records(string $resource): void
    {
        [$record, $data] = $this->fixture($resource);
        $before = $record->getAttributes();
        foreach ([null, [], ['bad'], '', 0, -1, 1.5, 'bad', 2147483648] as $invalid) {
            $this->putJson(route($resource.'.update', $record), $data + ['lock_version' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('lock_version');
        }
        $this->putJson(route($resource.'.update', $record), $data)->assertUnprocessable()->assertJsonValidationErrors('lock_version');
        $this->putJson(route($resource.'.update', $record), $data + ['lock_version' => 999])->assertStatus(409);
        foreach ([[], ['bad'], -1, 'bad', 2147483648] as $invalidReview) {
            $this->putJson(route($resource.'.update', $record), $data + ['lock_version' => 1, 'review_version' => $invalidReview, 'confirm_edit_conflict' => 1])
                ->assertUnprocessable()->assertJsonValidationErrors('review_version');
        }
        $this->assertSame($before, $record->fresh()->getAttributes());
    }

    #[DataProvider('resources')]
    public function test_an_ordinary_validation_error_does_not_silently_upgrade_the_opened_version(string $resource): void
    {
        [$record, $data, $field] = $this->fixture($resource);
        $this->putJson(route($resource.'.update', $record), $data + ['lock_version' => 1])->assertRedirect();
        $editUrl = route($resource.'.edit', $record);
        $this->from($editUrl)->put(route($resource.'.update', $record), array_replace($data, ['lock_version' => 1, $field => '']))
            ->assertSessionHasErrors($field)->assertSessionHasInput('lock_version', 1);
        $this->get($editUrl)->assertOk()->assertSee('name="lock_version" value="1"', false)
            ->assertSee('name="review_version" value="2"', false)->assertSee('Az aktuális adatok átnézése szükséges');
        $this->assertSame(2, (int) $record->fresh()->lock_version);
    }

    public function test_archive_and_restore_invalidate_open_partner_forms_and_retries_do_not_increment_versions(): void
    {
        [$partner, $data] = $this->fixture('partners');
        $this->patch(route('partners.archive', $partner))->assertRedirect();
        $this->patch(route('partners.archive', $partner))->assertRedirect();
        $this->assertSame(2, (int) $partner->fresh()->lock_version);
        $this->patch(route('partners.restore', $partner))->assertRedirect();
        $this->patch(route('partners.restore', $partner))->assertRedirect();
        $this->assertSame(3, (int) $partner->fresh()->lock_version);
        $this->putJson(route('partners.update', $partner), $data + ['lock_version' => 1])->assertStatus(409);
        $this->assertFalse($partner->fresh()->isArchived());
    }

    public function test_duplicate_warning_keeps_the_original_version_until_the_actual_save(): void
    {
        [$partner, $data] = $this->fixture('partners');
        Partner::create(['name' => 'Egyező partner', 'type' => 'supplier']);
        $data = array_replace($data, ['name' => 'Egyező partner', 'lock_version' => 1]);
        $this->put(route('partners.update', $partner), $data)->assertSessionHas('duplicate_warning', true)->assertSessionHasInput('lock_version', 1);
        $this->assertSame(1, (int) $partner->fresh()->lock_version);
        $this->patch(route('partners.archive', $partner))->assertRedirect();
        $check = app(PartnerDuplicateCheck::class)->check($data['name'], '', $partner->fresh());
        $this->putJson(route('partners.update', $partner), $data + ['confirm_duplicates' => 1, 'duplicate_token' => $check['token']])->assertStatus(409);
        $this->assertSame('Eredeti partner', $partner->fresh()->name);
        $this->assertTrue($partner->fresh()->isArchived());
    }

    public function test_product_price_and_other_fields_are_preserved_in_a_stale_form(): void
    {
        [$product, $data] = $this->fixture('products');
        $url = route('products.update', $product);
        $this->putJson($url, array_replace($data, ['lock_version' => 1, 'price' => '149.50']))->assertRedirect();
        $own = array_replace($data, ['lock_version' => 1, 'product_name' => 'Saját név', 'sku' => 'OWN-SKU', 'price' => '0', 'min_stock_override' => 0]);
        $response = $this->put($url, $own)->assertSessionHasErrors('lock_version')->assertSessionHasInput('price', '0')->assertSessionHasInput('min_stock_override', 0);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('value="OWN-SKU"', false)->assertSee('value="0"', false)->assertSee((string) $product->fresh()->price);
        $this->assertEquals(149.50, $product->fresh()->price);
        $this->assertSame($data['sku'], $product->fresh()->sku);
    }
}
