<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseStorageLevel extends Model
{
    public $timestamps = false;
    protected $fillable = ['number', 'max_weight_grams'];

    public function locations(): HasMany
    {
        return $this->hasMany(WarehouseLocation::class, 'level_id');
    }
}
