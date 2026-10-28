<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    public const STATUSES = ['draft' => 'Tervezet', 'in_transit' => 'Úton', 'completed' => 'Teljesítve', 'cancelled' => 'Visszavonva'];
    protected $fillable = ['origin_warehouse_id', 'user_id', 'reference', 'vehicle_label', 'note', 'status', 'version', 'started_at', 'finished_at'];
    protected function casts(): array { return ['version' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime']; }
    public function origin() { return $this->belongsTo(Warehouse::class, 'origin_warehouse_id', 'warehouse_id'); }
    public function user() { return $this->belongsTo(User::class); }
    public function stops() { return $this->hasMany(ShipmentStop::class)->orderBy('position'); }
    public function getShipmentNumberAttribute(): string { return 'SZALL-'.str_pad((string)$this->id, 6, '0', STR_PAD_LEFT); }
}
