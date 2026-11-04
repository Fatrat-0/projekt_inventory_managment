<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnQualityAction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return array_fill_keys(['quantity', 'stock_before', 'stock_after', 'quarantined_before', 'quarantined_after'], 'integer');
    }

    public function returnMovement() { return $this->belongsTo(InventoryMovement::class, 'return_movement_id'); }
    public function movement() { return $this->belongsTo(InventoryMovement::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function getActionLabelAttribute(): string { return $this->action === 'release' ? 'Felszabadítás' : 'Selejtezés'; }
}
