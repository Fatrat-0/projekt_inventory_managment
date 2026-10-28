<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentItem extends Model
{
    protected $fillable = ['product_id', 'quantity', 'transfer_id'];
    protected function casts(): array { return ['quantity' => 'integer']; }
    public function stop() { return $this->belongsTo(ShipmentStop::class, 'shipment_stop_id'); }
    public function product() { return $this->belongsTo(Product::class, 'product_id', 'product_id'); }
    public function transfer() { return $this->belongsTo(InventoryTransfer::class, 'transfer_id'); }
}
