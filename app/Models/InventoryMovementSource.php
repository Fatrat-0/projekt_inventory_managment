<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovementSource extends Model
{
    public $timestamps = false;

    protected $fillable = ['source_type', 'warehouse_location_id', 'location_code', 'location_name', 'quantity'];

    protected function casts(): array
    {
        return ['warehouse_location_id' => 'integer', 'quantity' => 'integer'];
    }
}
