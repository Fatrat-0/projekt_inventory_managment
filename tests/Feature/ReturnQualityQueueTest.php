<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryMovement, Partner, Product, ReturnQualityAction, Stock, User, Warehouse};
use App\Services\InventoryService;
use App\Support\MovementListContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReturnQualityQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $product;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'worker']);
        $this->actingAs($this->user);
        $category = Category::create(['category_name' => 'Minősítési munkalista', 'default_item_min_stock' => 0]);
        $this->product = Product::create(['product_name' => 'Alpha minősítendő termék', 'sku' => 'QUEUE-001', 'price' => 100,
            'category_id' => $category->category_id]);
        $this->warehouse = Warehouse::create(['warehouse_name' => 'Alpha raktár', 'location' => 'Budapest']);
        Stock::create(['product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id, 'quantity' => 1000]);
    }

    private function returned(int $quantity = 8, array $attributes = []): InventoryMovement
    {
        $productId = $attributes['product_id'] ?? $this->product->product_id;
        $warehouseId = $attributes['warehouse_id'] ?? $this->warehouse->warehouse_id;
        Stock::firstOrCreate(['product_id' => $productId, 'warehouse_id' => $warehouseId], ['quantity' => 1000]);
        $data = ['product_id' => $productId, 'warehouse_id' => $warehouseId, 'user_id' => $this->user->id,
            'type' => 'out', 'quantity' => $quantity];
        if (isset($attributes['partner_id'])) $data['partner_id'] = $attributes['partner_id'];
        $inventory = app(InventoryService::class);
        $source = $inventory->recordMovement($data);
        $return = $inventory->recordReturn($source, ['user_id' => $this->user->id, 'quantity' => $quantity,
            'return_reason' => $attributes['return_reason'] ?? 'Vizsgálatra beérkezett visszáru', 'reference' => $attributes['reference'] ?? null]);
        $return->forceFill(['created_at' => $attributes['created_at'] ?? '2026-11-01 12:00:00'])->save();
        return $return;
    }

    private function process(InventoryMovement $return, string $action, int $quantity): ReturnQualityAction
    {
        return app(InventoryService::class)->processReturnQuality($return, ['user_id' => $this->user->id,
            'action' => $action, 'quantity' => $quantity, 'reason' => 'A minősítés eredménye rögzítve']);
    }

    private function rows(array $filters = [])
    {
        return $this->get(route('quality-returns.index', $filters))->assertOk()->viewData('returns');
    }

    private function snapshot(): array
    {
        return array_map(fn($table) => DB::table($table)->orderBy('id')->get()->map(fn($row) => (array)$row)->all(),
            ['stocks', 'inventory_movements', 'return_quality_actions', 'inventory_movement_requests']);
    }

    public function test_default_queue_uses_remaining_quantity_and_includes_partially_processed_returns(): void
    {
        $waiting = $this->returned(8, ['created_at' => '2026-10-30 10:00:00']);
        $partial = $this->returned(8, ['created_at' => '2026-11-01 10:00:00']);
        $this->process($partial, 'release', 5);
        $closed = $this->returned();
        $this->process($closed, 'release', 3);
        $this->process($closed, 'scrap', 5);
        $before = $this->snapshot();
        $response = $this->get(route('quality-returns.index'))->assertOk();
        $rows = $response->viewData('returns');
        $this->assertSame([$waiting->id, $partial->id], $rows->pluck('id')->all());
        $this->assertSame('open', $response->viewData('filters')['status']);
        $this->assertSame(25, $rows->perPage());
        $listed = $rows->firstWhere('id', $partial->id);
        $this->assertSame([8, 5, 0, 3], array_map('intval', [$listed->quarantined_quantity, $listed->released_quantity,
            $listed->scrapped_quantity, $listed->remaining_quantity]));
        $summary = $response->viewData('summary');
        $this->assertSame([2, 1, 1, 1, 11], array_map('intval', [$summary['open_count'], $summary['waiting_count'],
            $summary['partial_count'], $summary['closed_count'], $summary['pending_quantity']]));
        $this->assertSame('2026-10-30', \Illuminate\Support\Carbon::parse($summary['oldest_at'])->toDateString());
        $this->assertSame($before, $this->snapshot());
    }

    public function test_each_status_and_summary_share_the_same_product_and_warehouse_scope(): void
    {
        $waiting = $this->returned(4);
        $partial = $this->returned(8);
        $this->process($partial, 'release', 5);
        $closed = $this->returned(3);
        $this->process($closed, 'scrap', 3);
        $otherWarehouse = Warehouse::create(['warehouse_name' => 'Másik raktár']);
        $this->returned(99, ['warehouse_id' => $otherWarehouse->warehouse_id]);
        $base = ['product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id];
        foreach (['waiting' => [$waiting->id], 'partial' => [$partial->id], 'closed' => [$closed->id],
            'open' => [$waiting->id, $partial->id], 'all' => [$waiting->id, $partial->id, $closed->id]] as $status => $ids) {
            $response = $this->get(route('quality-returns.index', $base + ['status' => $status]))->assertOk();
            $this->assertEqualsCanonicalizing($ids, $response->viewData('returns')->pluck('id')->all());
            $summary = $response->viewData('summary');
            $this->assertSame([2, 1, 1, 1, 7], array_map('intval', [$summary['open_count'], $summary['waiting_count'],
                $summary['partial_count'], $summary['closed_count'], $summary['pending_quantity']]));
        }
    }

    public function test_multiple_quality_actions_are_aggregated_once_and_closed_returns_leave_the_open_queue(): void
    {
        $return = $this->returned(8);
        $this->process($return, 'release', 2);
        $this->process($return, 'release', 3);
        $this->process($return, 'scrap', 1);
        $row = $this->rows()->sole();
        $this->assertSame([5, 1, 2], array_map('intval', [$row->released_quantity, $row->scrapped_quantity, $row->remaining_quantity]));
        $this->process($return, 'scrap', 2);
        $this->assertSame(0, $this->rows()->total());
        $row = $this->rows(['status' => 'closed'])->sole();
        $this->assertSame([8, 5, 3, 0], array_map('intval', [$row->quarantined_quantity, $row->released_quantity,
            $row->scrapped_quantity, $row->remaining_quantity]));
        $this->assertSame(8, $return->fresh()->quantity);
        $this->assertSame(8, $return->fresh()->quarantined_quantity);
    }

    public function test_ordinary_supplier_legacy_and_scrap_movements_are_not_quality_tasks(): void
    {
        $wanted = $this->returned(8);
        $this->process($wanted, 'scrap', 2);
        $inventory = app(InventoryService::class);
        $receipt = $inventory->recordMovement(['product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id,
            'user_id' => $this->user->id, 'type' => 'in', 'quantity' => 9]);
        $inventory->recordReturn($receipt, ['user_id' => $this->user->id, 'quantity' => 2, 'return_reason' => 'Beszállítónak visszaküldve']);
        InventoryMovement::create(['product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id,
            'user_id' => $this->user->id, 'type' => 'in', 'quantity' => 2, 'return_of_id' => $wanted->return_of_id,
            'return_reason' => 'Korábbi szabad készletként bevett visszáru', 'quarantined_quantity' => 0]);
        $this->assertSame([$wanted->id], $this->rows(['status' => 'all'])->pluck('id')->all());
    }

    public function test_empty_queue_has_zero_summary_and_no_pending_date(): void
    {
        $response = $this->get(route('quality-returns.index'))->assertOk();
        $this->assertSame(0, $response->viewData('returns')->total());
        $summary = $response->viewData('summary');
        foreach (['open_count', 'waiting_count', 'partial_count', 'closed_count', 'pending_quantity'] as $key) $this->assertSame(0, (int)$summary[$key]);
        $this->assertNull($summary['oldest_at']);
    }

    public function test_search_combines_tokens_across_fields_and_treats_like_metacharacters_literally(): void
    {
        $partner = Partner::create(['name' => 'Régi partner', 'type' => 'customer']);
        $wanted = $this->returned(8, ['partner_id' => $partner->id, 'reference' => 'DOC_100%!',
            'return_reason' => '<script>alert("queue")</script> Sérült burkolat']);
        $this->returned(8, ['reference' => 'DOCX1000!', 'return_reason' => 'Sérült burkolat']);
        $partner->forceFill(['name' => 'Jelenlegi partnernév', 'archived_at' => now()])->save();
        foreach (['Alpha QUEUE Sérült DOC_100%!', 'Régi partner', 'Jelenlegi partnernév', strtolower($wanted->movement_number), '%!'] as $search) {
            $this->assertSame([$wanted->id], $this->rows(['search' => $search])->pluck('id')->all(), $search);
        }
        $this->assertSame(0, $this->rows(['search' => 'Sérült hiányzó-szó'])->total());
        $this->assertSame([$wanted->id], $this->rows(['partner_id' => $partner->id])->pluck('id')->all());
        $response = $this->get(route('quality-returns.index', ['partner_id' => $partner->id]))->assertOk();
        $response->assertDontSee('<script>alert("queue")</script>', false);
    }

    public static function sorting(): array
    {
        $cases = [];
        foreach (['created_at', 'product_name', 'warehouse_name', 'original_quantity', 'released_quantity', 'scrapped_quantity', 'remaining_quantity'] as $field) {
            foreach (['asc', 'desc'] as $direction) $cases[$field.' '.$direction] = [$field, $direction];
        }
        return $cases;
    }

    public function test_search_finds_quality_action_reasons_and_combines_them_with_return_fields(): void
    {
        $wanted = $this->returned(8, ['reference' => 'DOC_100%!', 'return_reason' => 'Beérkezett vizsgálatra']);
        app(InventoryService::class)->processReturnQuality($wanted, ['user_id' => $this->user->id,
            'action' => 'release', 'quantity' => 1, 'reason' => 'Működő motor A_B% ellenőrizve']);
        $this->returned(8, ['return_reason' => 'Működő motor AXB0 ellenőrizve']);
        $this->assertSame([$wanted->id], $this->rows(['search' => 'Alpha Beérkezett A_B%'])->pluck('id')->all());
        $this->process($wanted, 'scrap', 7);
        $this->assertSame([$wanted->id], $this->rows(['search' => 'A_B%', 'status' => 'closed'])->pluck('id')->all());
    }

    #[DataProvider('sorting')]
    public function test_all_column_sorts_work_in_both_directions(string $field, string $direction): void
    {
        $product = Product::create(['product_name' => 'Zeta termék', 'sku' => 'ZETA-QUEUE', 'price' => 100, 'category_id' => $this->product->category_id]);
        $warehouse = Warehouse::create(['warehouse_name' => 'Zeta raktár']);
        $high = $this->returned(9, ['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'created_at' => '2026-11-02 12:00:00']);
        $this->process($high, 'release', 2);
        $this->process($high, 'scrap', 2);
        $low = $this->returned(5, ['created_at' => '2026-11-01 12:00:00']);
        $this->process($low, 'release', 1);
        $this->process($low, 'scrap', 1);
        $this->assertSame($direction === 'asc' ? [$low->id, $high->id] : [$high->id, $low->id],
            $this->rows(['sort_by' => $field, 'sort_dir' => $direction])->pluck('id')->all());
    }

    public function test_pagination_has_a_stable_tie_breaker_and_summary_covers_every_page(): void
    {
        $ids = [];
        for ($i=0; $i<26; $i++) $ids[] = $this->returned(1)->id;
        $params = ['product_id' => $this->product->product_id, 'per_page' => 10, 'page' => 2];
        $response = $this->get(route('quality-returns.index', $params))->assertOk();
        $this->assertSame(array_slice($ids, 10, 10), $response->viewData('returns')->pluck('id')->all());
        $this->assertSame(26, (int)$response->viewData('summary')['pending_quantity']);
        $this->assertSame(26, (int)$response->viewData('summary')['open_count']);
        $this->assertStringContainsString('product_id='.$this->product->product_id, $response->viewData('returns')->url(1));
        $this->assertSame(array_reverse($ids), $this->rows(['sort_dir' => 'desc', 'per_page' => 50])->pluck('id')->all());
        $redirect = $this->get(route('quality-returns.index', array_replace($params, ['page' => 999])))->assertRedirect();
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $redirected);
        $this->assertSame('/quality-returns', parse_url($redirect->headers->get('Location'), PHP_URL_PATH));
        $this->assertSame('3', (string)$redirected['page']);
        $this->assertSame('10', (string)$redirected['per_page']);
        $this->assertSame((string)$this->product->product_id, (string)$redirected['product_id']);
    }

    public static function invalidFilters(): array
    {
        return [['status', 'bad'], ['status', ['bad']], ['search', ['bad']], ['search', str_repeat('a', 201)],
            ['sort_by', 'remaining_quantity DESC; DROP TABLE stocks'], ['sort_by', ['bad']], ['sort_dir', 'bad'],
            ['per_page', 3], ['per_page', ['bad']], ['page', 0], ['page', ['bad']], ['product_id', 999999], ['product_id', ['bad']],
            ['warehouse_id', 999999], ['warehouse_id', ['bad']], ['partner_id', 999999], ['partner_id', ['bad']]];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected_without_mutating_inventory(string $field, mixed $value): void
    {
        $this->returned();
        $before = $this->snapshot();
        $this->getJson(route('quality-returns.index', [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_quality_context_returns_to_the_filtered_queue_after_partial_and_full_processing(): void
    {
        $return = $this->returned(8);
        $filters = ['status' => 'open', 'product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id,
            'sort_by' => 'remaining_quantity', 'sort_dir' => 'desc', 'per_page' => 10, 'page' => 2];
        $token = MovementListContext::encodeQuality($filters);
        $this->assertQueueUrl($filters, MovementListContext::url($token));
        $detail = route('movements.show', ['movement' => $return, 'context' => $token]);
        $response = $this->get($detail)->assertOk()->assertSee('← Minősítési munkalista');
        $this->assertNotEmpty(array_filter($this->queueLinkFilters($response->getContent()), fn($f) => $f == array_map('strval', $filters)));
        foreach ([3, 5] as $quantity) {
            $this->post(route('movements.quality.store', $return), ['request_key' => (string)Str::uuid(), 'action' => 'release',
                'quantity' => $quantity, 'reason' => 'Munkalistából minősítve', 'context' => $token])->assertRedirect($detail);
            $this->get($detail)->assertOk()->assertSee('← Minősítési munkalista');
        }
        $this->assertSame(0, $this->rows()->total());
        $this->assertSame([$return->id], $this->rows(['status' => 'closed'])->pluck('id')->all());
    }

    public function test_failed_quality_processing_preserves_the_queue_context_and_remains_read_only(): void
    {
        $return = $this->returned(3);
        $token = MovementListContext::encodeQuality(['status' => 'open', 'warehouse_id' => $this->warehouse->warehouse_id]);
        $detail = route('movements.show', ['movement' => $return, 'context' => $token]);
        $key = (string)Str::uuid();
        $before = $this->snapshot();
        $this->from($detail)->post(route('movements.quality.store', $return), ['request_key' => $key, 'action' => 'scrap',
            'quantity' => 4, 'reason' => 'Megőrzendő indok', 'context' => $token])->assertRedirect($detail.'#return-quality')->assertSessionHasErrors('quantity');
        $this->assertSame($token, session()->getOldInput('context'));
        $this->assertSame($key, session()->getOldInput('request_key'));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_invalid_html_filters_recover_even_when_the_referer_is_the_same_invalid_url(): void
    {
        $this->returned();
        $before = $this->snapshot();
        foreach ([['status' => 'invalid'], ['search' => ['bad']], ['sort_by' => 'invalid']] as $params) {
            $invalidUrl = route('quality-returns.index', $params);
            $this->from($invalidUrl)->get($invalidUrl)->assertRedirect(route('quality-returns.index'))->assertSessionHasErrors('filters');
            $this->get(route('quality-returns.index'))->assertOk()->assertSee('A szűrőket visszaállítottuk.');
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_maximum_length_emoji_search_keeps_queue_context_through_details_and_processing(): void
    {
        $search = str_repeat('📦', 200);
        $return = $this->returned(2, ['return_reason' => $search]);
        $filters = ['search' => $search, 'status' => 'waiting', 'product_id' => $this->product->product_id,
            'warehouse_id' => $this->warehouse->warehouse_id, 'sort_by' => 'remaining_quantity', 'sort_dir' => 'desc', 'per_page' => 10];
        $before = $this->snapshot();
        $response = $this->get(route('quality-returns.index', $filters))->assertOk();
        $this->assertSame([$return->id], $response->viewData('returns')->pluck('id')->all());
        $token = $response->viewData('listContext');
        $this->assertLessThanOrEqual(4096, strlen($token), 'A valid 200-character search must fit the accepted context limit.');
        $expected = array_filter($response->viewData('filters') + ['page' => 1], fn($value) => $value !== null);
        $this->assertQueueUrl($expected, MovementListContext::url($token));
        $detail = route('movements.show', ['movement' => $return, 'context' => $token]);
        $page = $this->get($detail)->assertOk()->assertSee('← Minősítési munkalista')->assertViewHas('context', $token);
        $this->assertQueueUrl($expected, $page->viewData('returnUrl'));
        $this->assertSame($before, $this->snapshot());
        $this->post(route('movements.quality.store', $return), ['request_key' => (string)Str::uuid(), 'action' => 'release',
            'quantity' => 1, 'reason' => 'Unicode keresésből megnyitott minősítés', 'context' => $token])->assertRedirect($detail);
        $page = $this->get($detail)->assertOk()->assertViewHas('context', $token);
        $this->assertQueueUrl($expected, $page->viewData('returnUrl'));
    }

    public function test_previous_unicode_escaped_queue_and_movement_contexts_remain_readable(): void
    {
        $filters = ['search' => 'Árvíztűrő tükörfúrógép', 'status' => 'closed', 'product_id' => $this->product->product_id,
            'sort_by' => 'remaining_quantity', 'sort_dir' => 'desc', 'per_page' => 10];
        $legacy = Crypt::encryptString(json_encode(['view' => 'return_quality'] + $filters, JSON_THROW_ON_ERROR));
        $this->assertQueueUrl($filters, MovementListContext::url($legacy));
        $this->assertSame('← Minősítési munkalista', MovementListContext::destination($legacy)['label']);
        $movementFilters = ['search' => 'régi készlet', 'kind' => 'return', 'sort_by' => 'created_at'];
        $legacyMovement = Crypt::encryptString(json_encode($movementFilters, JSON_THROW_ON_ERROR));
        $this->assertSame(route('movements.index', $movementFilters), MovementListContext::url($legacyMovement));
    }

    public function test_context_whitelists_queue_fields_and_cannot_redirect_to_an_external_url(): void
    {
        $token = Crypt::encryptString(json_encode(['view' => 'return_quality', 'status' => 'closed', 'warehouse_id' => $this->warehouse->warehouse_id,
            'next' => 'https://example.test/evil', 'url' => 'https://example.test/evil', 'search' => ['bad']], JSON_THROW_ON_ERROR));
        $this->assertQueueUrl(['status' => 'closed', 'warehouse_id' => $this->warehouse->warehouse_id], MovementListContext::url($token));
        foreach (['https://example.test/evil', [], str_repeat('x', 4097)] as $bad) {
            $this->assertSame(route('movements.index'), MovementListContext::url($bad));
        }
    }

    public function test_dashboard_highlights_pending_quality_even_when_available_stock_is_healthy_and_clears_after_closure(): void
    {
        $empty = $this->get(route('dashboard'))->assertOk()->viewData('qualitySummary');
        $this->assertSame([0, 0, null], [$empty['returns_count'], $empty['quantity'], $empty['oldest_at']]);
        $return = $this->returned(8, ['created_at' => '2026-10-29 09:00:00']);
        $response = $this->get(route('dashboard'))->assertOk()->assertSee(route('quality-returns.index'));
        $this->assertCount(0, $response->viewData('lowStockProducts'));
        $summary = $response->viewData('qualitySummary');
        $this->assertSame([1, 8], [$summary['returns_count'], $summary['quantity']]);
        $this->assertSame('2026-10-29', \Illuminate\Support\Carbon::parse($summary['oldest_at'])->toDateString());
        $this->process($return, 'release', 8);
        $summary = $this->get(route('dashboard'))->assertOk()->viewData('qualitySummary');
        $this->assertSame([0, 0, null], [$summary['returns_count'], $summary['quantity'], $summary['oldest_at']]);
    }

    private function queueLinkFilters(string $html): array
    {
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $filters = [];
        foreach ($document->getElementsByTagName('a') as $link) {
            if (parse_url($link->getAttribute('href'), PHP_URL_PATH) !== '/quality-returns') continue;
            parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY) ?? '', $params);
            $filters[] = $params;
        }
        return $filters;
    }

    private function assertQueueUrl(array $filters, string $url): void
    {
        $this->assertSame('/quality-returns', parse_url($url, PHP_URL_PATH));
        $this->assertSame(parse_url(route('quality-returns.index'), PHP_URL_HOST), parse_url($url, PHP_URL_HOST));
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $actual);
        $this->assertEquals(array_map('strval', $filters), $actual);
    }

    public function test_product_and_warehouse_quarantine_links_open_the_scoped_queue(): void
    {
        $this->returned(8);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $productLinks = $this->queueLinkFilters($this->get(route('products.index'))->assertOk()->getContent());
        $this->assertNotEmpty(array_filter($productLinks, fn($f) => ($f['product_id'] ?? '') === (string)$this->product->product_id && ($f['status'] ?? 'open') === 'open'));
        $params = ['warehouse' => $this->warehouse, 'stock' => 'quarantined'];
        $warehouseLinks = $this->queueLinkFilters($this->get(route('warehouses.show', $params))->assertOk()->getContent());
        $this->assertNotEmpty(array_filter($warehouseLinks, fn($f) => ($f['warehouse_id'] ?? '') === (string)$this->warehouse->warehouse_id
            && ($f['product_id'] ?? '') === (string)$this->product->product_id && ($f['status'] ?? 'open') === 'open'));
    }

    public function test_reference_zero_is_visible_in_the_movement_list(): void
    {
        $movement = app(InventoryService::class)->recordMovement(['user_id' => $this->user->id, 'product_id' => $this->product->product_id,
            'warehouse_id' => $this->warehouse->warehouse_id, 'type' => 'in', 'quantity' => 1, 'reference' => '0']);
        $html = $this->get(route('movements.index', ['search' => $movement->movement_number]))->assertOk()->getContent();
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $referenceCell = (new \DOMXPath($document))->query('//tbody/tr[1]/td[last()]')->item(0);
        $this->assertNotNull($referenceCell);
        $this->assertSame('0', trim($referenceCell->textContent));
    }

    public static function roles(): array { return [['admin'], ['manager'], ['worker']]; }

    #[DataProvider('roles')]
    public function test_inventory_operator_roles_can_open_the_queue(string $role): void
    {
        $return = $this->returned();
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->assertSame([$return->id], $this->rows()->pluck('id')->all());
    }

    public function test_guest_and_unsupported_roles_cannot_read_the_queue(): void
    {
        auth()->logout();
        $this->get(route('quality-returns.index'))->assertRedirect(route('login'));
        $this->getJson(route('quality-returns.index'))->assertUnauthorized();
        $this->user->role = 'invalid';
        $this->actingAs($this->user)->getJson(route('quality-returns.index'))->assertForbidden();
    }
}
