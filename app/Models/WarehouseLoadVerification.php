<?php

namespace App\Models;

use Illuminate\Database\Eloquent\{Builder, Model};

class WarehouseLoadVerification extends Model
{
    public const VIOLATIONS = [
        'location_weight' => [1, ' kg szabad teherbírás maradt.'],
        'level_weight' => [2, 'állványszint szabad teherbírását.'],
        'system_weight' => [4, 'A tétel meghaladná a(z) '],
        'pallet_space' => [8, ' szabad raklaphely van.'],
        'pallet_kind' => [16, 'raklap nélküli rakományt fogad.'],
    ];

    public $timestamps = false;

    protected $fillable = [
        'warehouse_id', 'product_id', 'location_id', 'placement_id', 'warehouse_name', 'location_code', 'location_name',
        'product_name', 'sku', 'quantity', 'previous_weight_grams', 'measured_weight_grams', 'previous_pallet_slots',
        'measured_pallet_slots', 'placement_revision', 'result', 'errors', 'actor_id', 'actor_name',
        'measured_at', 'reason', 'note', 'operation_key', 'request_hash', 'capacity_violation_mask', 'resolves_verification_id',
    ];

    protected $hidden = ['request_hash', 'capacity_violation_mask'];

    protected $appends = ['capacity_violations'];

    public function placement()
    {
        return $this->belongsTo(WarehousePlacement::class, 'placement_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function location()
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    public function getCapacityViolationsAttribute(): array
    {
        $errors = collect($this->errors)->flatten()->implode(' ');

        return array_keys(array_filter(self::VIOLATIONS, fn ($rule) => $this->capacity_violation_mask !== null
            ? (bool) ($this->capacity_violation_mask & $rule[0])
            : $this->result === 'rejected' && str_contains($errors, $rule[1])));
    }

    public function scopeOpenDiscrepancies(Builder $query, bool $locked = false): Builder
    {
        $table = $this->getTable();

        return self::violationEvents($query, $table)->whereNotExists(function ($later) use ($table, $locked) {
            $later->selectRaw('1')->from($table.' as newer')->when($locked, fn ($query) => $query->sharedLock())
                ->whereColumn('newer.warehouse_id', $table.'.warehouse_id')
                ->whereColumn('newer.placement_id', $table.'.placement_id')
                ->whereColumn('newer.id', '>', $table.'.id')
                ->where(fn ($events) => $events->where('newer.result', 'verified')
                    ->orWhere(fn ($rejected) => self::violationEvents($rejected, 'newer')));
        });
    }

    private static function violationEvents($query, string $prefix)
    {
        return $query->where($prefix.'.result', 'rejected')->where(fn ($events) => $events
            ->where($prefix.'.capacity_violation_mask', '>', 0)
            ->orWhere(fn ($legacy) => $legacy->whereNull($prefix.'.capacity_violation_mask')
                ->where(function ($messages) use ($prefix) {
                    foreach (self::VIOLATIONS as [, $message]) {
                        $messages->orWhere($prefix.'.errors', 'like', '%'.$message.'%');
                        if ($messages->getConnection()->getDriverName() === 'sqlite') {
                            $messages->orWhere($prefix.'.errors', 'like', '%'.substr(json_encode($message, JSON_THROW_ON_ERROR), 1, -1).'%');
                        }
                    }
                })));
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer', 'previous_weight_grams' => 'integer', 'measured_weight_grams' => 'integer',
            'previous_pallet_slots' => 'integer', 'measured_pallet_slots' => 'integer',
            'capacity_violation_mask' => 'integer', 'errors' => 'array', 'measured_at' => 'immutable_datetime',
        ];
    }
}
