<?php
namespace Tests\Feature;

use App\Models\{Category, Product, Stock, User, Warehouse, ReturnQualityAction};
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReturnQualityConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmation_expires_at_the_exact_thirty_minute_boundary(): void
    {
        $this->freezeTime();
        [$return, $data] = $this->fixture();
        $url = route('movements.quality.store', $return);
        $token = $this->post($url, $data)->viewData('confirmation');
        $this->travel(30)->minutes();
        $this->postJson($url, $data + ['confirmation' => $token])->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->assertDatabaseCount('return_quality_actions', 0);
        $this->assertSame(5, Stock::sole()->quantity);
    }

    public function test_two_confirmed_tabs_cannot_scrap_more_than_the_return_and_corrected_quantity_needs_new_confirmation(): void
    {
        [$return, $first] = $this->fixture();
        $url = route('movements.quality.store', $return);
        $second = array_replace($first, ['request_key' => (string)Str::uuid()]);
        $tokenOne = $this->post($url, $first)->viewData('confirmation');
        $tokenTwo = $this->post($url, $second)->viewData('confirmation');
        $this->postJson($url, $first + ['confirmation' => $tokenOne])->assertRedirect();
        $this->postJson($url, $second + ['confirmation' => $tokenTwo])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $corrected = array_replace($second, ['quantity' => 2]);
        $this->postJson($url, $corrected + ['confirmation' => $tokenTwo])->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $newToken = $this->post($url, $corrected)->viewData('confirmation');
        $this->postJson($url, $corrected + ['confirmation' => $newToken])->assertRedirect();
        $this->assertDatabaseCount('return_quality_actions', 2);
        $this->assertSame(0, Stock::sole()->quantity);
        $this->assertSame(0, Stock::sole()->quarantined_quantity);
    }

    public function test_malformed_confirmation_values_never_change_stock(): void
    {
        [$return, $data] = $this->fixture();
        foreach ([null, '', [], ['bad'], str_repeat('x', 8193)] as $token) {
            $this->postJson(route('movements.quality.store', $return), $data + ['confirmation' => $token])
                ->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        }
        $this->assertDatabaseCount('return_quality_actions', 0);
        $this->assertSame(5, Stock::sole()->quantity);
    }
    private function fixture(): array
    {
        $user = User::factory()->create(['role' => 'worker']);
        $this->actingAs($user);
        $category = Category::create(['category_name' => 'Confirmation']);
        $product = Product::create(['product_name' => 'Minta termék', 'sku' => 'CONFIRM', 'price' => 100, 'category_id' => $category->category_id]);
        $warehouse = Warehouse::create(['warehouse_name' => 'Minta raktár', 'location' => 'Test']);
        Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'quantity' => 5]);
        $service = app(InventoryService::class);
        $source = $service->recordMovement(['user_id' => $user->id, 'product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'type' => 'out', 'quantity' => 5]);
        $return = $service->recordReturn($source, ['user_id' => $user->id, 'quantity' => 5, 'return_reason' => 'Vizsgálat']);
        $data = ['request_key' => (string)Str::uuid(), 'action' => 'scrap', 'quantity' => 3, 'reason' => '<script>sérült</script>'];
        return [$return, $data, $user];
    }

    public function test_preview_is_read_only_then_confirmed_scrap_replays_once(): void
    {
        [$return, $data] = $this->fixture();
        $url = route('movements.quality.store', $return);
        $preview = $this->post($url, $data)->assertOk()->assertSee('Selejtezés megerősítése')->assertSee('Minta termék')->assertSee('Minta raktár')->assertSee($data['reason'])->assertDontSee($data['reason'], false);
        $this->assertSame(5, Stock::sole()->quantity);
        $this->assertDatabaseCount('return_quality_actions', 0);
        $confirmed = $data + ['confirmation' => $preview->viewData('confirmation')];
        $this->post($url, $confirmed)->assertRedirect();
        $this->post($url, $confirmed)->assertRedirect();
        $this->post($url, $data)->assertRedirect()->assertSessionHas('success', 'Ezt a selejtezést már rögzítettük: 3 db.');
        $this->assertSame(2, Stock::sole()->quantity);
        $this->assertDatabaseCount('return_quality_actions', 1);
    }

    public function test_confirmation_is_bound_to_data_key_actor_source_and_expiry(): void
    {
        [$return, $data] = $this->fixture();
        $url = route('movements.quality.store', $return);
        $token = $this->post($url, $data)->viewData('confirmation');
        foreach (['quantity' => 2, 'reason' => 'Más indok', 'action' => 'release', 'request_key' => (string)Str::uuid(), 'confirmation' => 'bad'] as $key => $value) {
            $this->postJson($url, array_replace($data + ['confirmation' => $token], [$key => $value]))->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        }
        $this->postJson(route('movements.quality.store', $return->returnOf), $data + ['confirmation' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->actingAs(User::factory()->create(['role' => 'manager']));
        $this->postJson($url, $data + ['confirmation' => $token])->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->assertDatabaseCount('return_quality_actions', 0);
    }

    public function test_stale_confirmation_rechecks_quantity_and_failed_form_returns_to_get_page(): void
    {
        [$return, $data, $user] = $this->fixture();
        $url = route('movements.quality.store', $return);
        $token = $this->post($url, $data)->viewData('confirmation');
        app(InventoryService::class)->processReturnQuality($return, ['user_id' => $user->id, 'action' => 'release', 'quantity' => 4, 'reason' => 'Másik vizsgálat']);
        $this->from($url)->post($url, $data + ['confirmation' => $token])->assertRedirect(route('movements.show', ['movement' => $return, 'context' => '']).'#return-quality')->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('return_quality_actions', 1);
        $this->assertSame(5, Stock::sole()->quantity);
    }

    public function test_edit_and_expired_confirmation_do_not_change_stock(): void
    {
        [$return, $data] = $this->fixture();
        $url = route('movements.quality.store', $return);
        $token = $this->post($url, $data)->viewData('confirmation');
        $this->post($url, $data + ['confirmation' => $token, 'edit' => '1'])->assertRedirect()->assertSessionHasInput('reason', $data['reason']);
        $this->travel(31)->minutes();
        $this->postJson($url, $data + ['confirmation' => $token])->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->assertSame(5, Stock::sole()->quantity);
        $this->assertDatabaseCount('return_quality_actions', 0);
    }
}
