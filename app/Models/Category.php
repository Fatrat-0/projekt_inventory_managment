<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $primaryKey = 'category_id'; // Egyedi kulcs megadása
    protected $fillable = ['category_name']; // Mely mezők tölthetők fel tömegesen

    // Egy kategóriához TÖBB termék is tartozhat (1:N)
    public function products()
    {
        return $this->hasMany(Product::class, 'category_id', 'category_id');
    }
}
