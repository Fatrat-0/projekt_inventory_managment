<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    protected $fillable = [
        'name', 'registration_number', 'payload_capacity_grams', 'pallet_slots_capacity', 'active', 'note', 'version',
    ];

    protected $attributes = ['active' => true, 'version' => 1];

    protected function casts(): array
    {
        return [
            'payload_capacity_grams' => 'integer',
            'pallet_slots_capacity' => 'integer',
            'active' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function operations(): HasMany
    {
        return $this->hasMany(VehicleOperation::class);
    }

    public function capacitySnapshot(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'name' => $this->name,
            'registration_number' => $this->registration_number,
            'payload_capacity_grams' => $this->payload_capacity_grams,
            'pallet_slots_capacity' => $this->pallet_slots_capacity,
            'active' => $this->active,
        ];
    }

    public function registrySnapshot(): array
    {
        return $this->capacitySnapshot() + [
            'payload_capacity_kg' => $this->payload_capacity_grams === null ? null : $this->payload_capacity_grams / 1000,
            'note' => $this->note,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
