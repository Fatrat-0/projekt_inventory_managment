<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    protected $fillable = ['stock_id', 'type', 'quantity', 'reference_id', 'user_id'];

    public function stock()
    {
        return $this->belongsTo(Stock::class, 'stock_id', 'id');
    }

    // Melyik felhasználó csinálta a tranzakciót
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
