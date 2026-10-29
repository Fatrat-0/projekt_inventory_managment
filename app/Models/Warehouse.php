<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    use HasFactory;

    protected $primaryKey = 'warehouse_id';
    protected $fillable = ['warehouse_name', 'location'];

    private const USAGE_RELATIONS = [
        'stocks' => 'has_stock_records',
        'movements' => 'has_movements',
        'outgoingTransfers' => 'has_outgoing_transfers',
        'incomingTransfers' => 'has_incoming_transfers',
        'shipments' => 'has_shipments',
        'shipmentStops' => 'has_shipment_stops',
    ];

    public function movements() { return $this->hasMany(InventoryMovement::class, 'warehouse_id', 'warehouse_id'); }
    public function outgoingTransfers() { return $this->hasMany(InventoryTransfer::class, 'from_warehouse_id', 'warehouse_id'); }
    public function incomingTransfers() { return $this->hasMany(InventoryTransfer::class, 'to_warehouse_id', 'warehouse_id'); }
    public function shipments() { return $this->hasMany(Shipment::class, 'origin_warehouse_id', 'warehouse_id'); }
    public function shipmentStops() { return $this->hasMany(ShipmentStop::class, 'warehouse_id', 'warehouse_id'); }

    public function scopeWithUsage($query)
    {
        foreach (self::USAGE_RELATIONS as $relation => $attribute) {
            $query->withExists([$relation.' as '.$attribute]);
        }
        return $query;
    }

    public function getIsInUseAttribute(): bool
    {
        foreach (self::USAGE_RELATIONS as $relation => $attribute) {
            // Use listing flags when available, otherwise check the actual relation.
            if ($this->getAttribute($attribute) ?? $this->$relation()->exists()) return true;
        }
        return false;
    }

    // Egy raktárban TÖBB készletsor is van (1:N)
    public function stocks()
    {
        return $this->hasMany(Stock::class, 'warehouse_id', 'warehouse_id');
    }
}
