<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $primaryKey = 'category_id'; // Egyedi kulcs megadása
    // Engedélyezzük az új oszlopok mentését is
    protected $fillable = [
        'category_name', 
        'parent_id', 
        'default_item_min_stock', 
        'aggregate_min_stock'
    ];

    // Egy kategóriához TÖBB termék is tartozhat (1:N)
    public function products()
    {
        return $this->hasMany(Product::class, 'category_id', 'category_id');
    }

    // 1. Kapcsolat: Ki a szülőm? (Felfelé)
    public function parent()
    {
        // Megadjuk a külső kulcsot (parent_id) és a szülő tábla elsődleges kulcsát (category_id)
        return $this->belongsTo(Category::class, 'parent_id', 'category_id');
    }

    // 2. Kapcsolat: Kik a gyermekeim? (Lefelé az alkategóriák)
    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id', 'category_id');
    }
}
