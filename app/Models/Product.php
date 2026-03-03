<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $primaryKey = 'product_id';
    protected $fillable = ['product_name', 'sku', 'price', 'category_id'];

    // Egy termék EGY kategóriához tartozik
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', 'category_id');
    }

    // Egy termék TÖBB raktárban is lehet (Készletsorok)
    public function stocks()
    {
        return $this->hasMany(Stock::class, 'product_id', 'product_id');
    }
}
