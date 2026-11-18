<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentAssignmentCargo extends Model
{
    protected $fillable = ['gross_weight_grams', 'source', 'dimensions_cm', 'packaging_note', 'packaging_basis',
        'confirmed_by', 'confirmed_at'];

    protected function casts(): array
    {
        return ['gross_weight_grams' => 'integer', 'dimensions_cm' => 'array', 'packaging_basis' => 'array',
            'confirmed_by' => 'integer', 'confirmed_at' => 'immutable_datetime'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ShipmentIntentAssignment::class, 'shipment_intent_assignment_id');
    }
}
