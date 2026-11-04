<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'user_id',
        'type',
        'quantity',
        'reference',
        'stock_before',
        'stock_after',
        'partner_id', 'partner_name',
        'return_of_id', 'return_reason',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'stock_before' => 'integer', 'stock_after' => 'integer'];
    }

    public function getMovementNumberAttribute(): string
    {
        return 'MOZ-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function partner() { return $this->belongsTo(Partner::class); }

    public function returnOf() { return $this->belongsTo(self::class, 'return_of_id'); }

    public function returns() { return $this->hasMany(self::class, 'return_of_id'); }

    public function isReturn(): bool { return $this->return_of_id !== null; }

    public function getOriginalMovementNumberAttribute(): ?string
    {
        return $this->isReturn() ? 'MOZ-'.str_pad((string)$this->return_of_id, 6, '0', STR_PAD_LEFT) : null;
    }

    // Kapcsolat a Termékkel
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    // Kapcsolat a Raktárral
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id', 'warehouse_id');
    }

    // Melyik felhasználó csinálta a tranzakciót
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }



}
