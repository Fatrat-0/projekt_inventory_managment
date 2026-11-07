<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentItem extends Model
{
    protected $fillable = ['product_id', 'quantity', 'transfer_id', 'to_location_id', 'cargo_weight_grams', 'pallet_slots'];
    protected function casts(): array { return ['quantity' => 'integer', 'cargo_weight_grams' => 'integer', 'pallet_slots' => 'integer']; }
    public function stop() { return $this->belongsTo(ShipmentStop::class, 'shipment_stop_id'); }
    public function product() { return $this->belongsTo(Product::class, 'product_id', 'product_id'); }
    public function transfer() { return $this->belongsTo(InventoryTransfer::class, 'transfer_id'); }
    public function destinationLocation() { return $this->belongsTo(WarehouseLocation::class, 'to_location_id'); }
}
