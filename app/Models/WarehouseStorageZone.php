<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseStorageZone extends Model
{
    public $timestamps = false;
    protected $fillable = ['warehouse_id', 'name'];

    public function systems() { return $this->hasMany(WarehouseStorageSystem::class, 'zone_id'); }
}
