<?php

namespace App\Models;

use App\Support\WarehouseStorageOverview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WarehouseLocation extends Model
{
    public const KINDS = ['shelf' => 'Polc', 'pallet' => 'Raklaphely'];

    protected $fillable = ['warehouse_id', 'code', 'name', 'kind', 'max_weight_grams', 'max_pallet_slots', 'active'];

    protected function casts(): array
    {
        return ['max_weight_grams' => 'integer', 'max_pallet_slots' => 'integer', 'active' => 'boolean', 'lock_version' => 'integer',
            'floor_x' => 'integer', 'floor_y' => 'integer', 'floor_width' => 'integer', 'floor_height' => 'integer'];
    }

    public function placements()
    {
        return $this->hasMany(WarehousePlacement::class);
    }

    public function system() { return $this->belongsTo(WarehouseStorageSystem::class, 'system_id'); }
    public function level() { return $this->belongsTo(WarehouseStorageLevel::class, 'level_id'); }

    public function allowedCategories()
    {
        return $this->belongsToMany(Category::class, 'warehouse_location_categories', 'location_id', 'category_id');
    }

    public function reservations()
    {
        return $this->hasMany(InventoryTransfer::class, 'to_location_id')->where('status', 'pending');
    }

    public function scopeWithCapacityUsage(Builder $query): Builder
    {
        return $query->withSum('placements as occupied_weight', 'cargo_weight_grams')
            ->withSum('placements as occupied_slots', 'pallet_slots')
            ->withSum('reservations as reserved_weight', 'cargo_weight_grams')
            ->withSum('reservations as reserved_slots', 'pallet_slots');
    }

    public function freeWeight(): int
    {
        return $this->max_weight_grams - (int) $this->occupied_weight - (int) $this->reserved_weight;
    }

    public function freeSlots(): ?int
    {
        if ($this->max_pallet_slots === null) return null;

        return $this->max_pallet_slots - (int) $this->occupied_slots - (int) $this->reserved_slots;
    }

    public static function pickerOptions(): array
    {
        $locations = self::withCapacityUsage()->orderBy('code')->get();
        $free = WarehouseStorageOverview::locationFreeWeights($locations, allBinsLoaded: true);

        return $locations->groupBy('warehouse_id')
            ->map(fn ($locations) => $locations->where('active', true)->map(fn (self $location) => [
                'id' => (string) $location->id,
                'kind' => $location->kind,
                'label' => $location->code.' · '.$location->name.' ('.self::formatWeight($free[$location->id] ?? $location->freeWeight()).' kg'
                    .($location->freeSlots() === null ? '' : ', '.$location->freeSlots().' raklaphely').' szabad)',
            ])->values()->all())->all();
    }

    public static function formatWeight(int $grams): string
    {
        return rtrim(rtrim(number_format($grams / 1000, 3, ',', ' '), '0'), ',');
    }
}
