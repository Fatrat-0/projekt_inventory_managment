<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    use HasFactory;

    protected $primaryKey = 'warehouse_id';
    protected $fillable = ['warehouse_name', 'location'];

    // Egy raktárban TÖBB készletsor is van (1:N)
    public function stocks()
    {
        return $this->hasMany(Stock::class, 'warehouse_id', 'warehouse_id');
    }
}
