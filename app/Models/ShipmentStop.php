<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentStop extends Model
{
    protected $fillable = ['warehouse_id', 'position', 'received_by', 'received_at'];
    protected function casts(): array { return ['position' => 'integer', 'received_at' => 'datetime']; }
    public function shipment() { return $this->belongsTo(Shipment::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class, 'warehouse_id', 'warehouse_id'); }
    public function receiver() { return $this->belongsTo(User::class, 'received_by'); }
    public function items() { return $this->hasMany(ShipmentItem::class)->orderBy('id'); }
}
