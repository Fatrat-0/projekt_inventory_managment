<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentCargoConfirmation extends Model
{
    public $timestamps = false;

    protected $fillable = ['gross_weight_grams', 'pallet_slots', 'shared_materials_weight_grams', 'basis_revision',
        'confirmed_by', 'confirmed_at', 'reason'];

    protected function casts(): array
    {
        return ['gross_weight_grams' => 'integer', 'pallet_slots' => 'integer', 'shared_materials_weight_grams' => 'integer',
            'confirmed_by' => 'integer', 'confirmed_at' => 'immutable_datetime'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
