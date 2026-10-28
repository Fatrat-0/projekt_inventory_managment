<?php

namespace Tests\Feature;

use App\Models\{Shipment, Stock};
use Database\Seeders\{DatabaseSeeder, ShipmentDemoSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentSeederTest extends TestCase
{
    use RefreshDatabase;
    public function test_demo_is_an_additive_repeatable_draft_without_stock_changes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $shipment = Shipment::with('stops.items')->sole();
        $this->assertSame('draft', $shipment->status);
        $this->assertCount(3, $shipment->stops);
        $this->assertCount(6, $shipment->stops->flatMap->items);
        $before = $shipment->toArray();
        $stocks = Stock::orderBy('id')->get()->toArray();
        $this->seed(ShipmentDemoSeeder::class);
        $this->assertSame($before, $shipment->fresh()->load('stops.items')->toArray());
        $this->assertSame($stocks, Stock::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseCount('inventory_transfers', 10);
    }
}
