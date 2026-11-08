<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseStorageSystem extends Model
{
    public const KINDS = ['rack' => 'Polcos állvány', 'pallet' => 'Raklapos állvány', 'floor' => 'Padlótároló zóna'];

    protected $guarded = ['id'];

    public function displayCode(): string { return $this->code ?? 'T'.$this->id; }
    public function frontWidth(): int { return $this->front_width_cm ?? $this->floor_width * 50; }
    public function depth(): int { return $this->depth_cm ?? $this->floor_height * 50; }

    public function zone() { return $this->belongsTo(WarehouseStorageZone::class, 'zone_id'); }
    public function levels() { return $this->hasMany(WarehouseStorageLevel::class, 'system_id')->orderBy('number'); }
    public function locations() { return $this->hasMany(WarehouseLocation::class, 'system_id'); }
}
