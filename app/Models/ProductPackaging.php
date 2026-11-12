<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPackaging extends Model
{
    protected $fillable = [
        'name', 'pieces_per_package', 'gross_weight_kg', 'width_cm', 'depth_cm', 'height_cm', 'barcode',
    ];

    protected function casts(): array
    {
        return [
            'pieces_per_package' => 'integer',
            'gross_weight_kg' => 'decimal:3',
            'width_cm' => 'decimal:2',
            'depth_cm' => 'decimal:2',
            'height_cm' => 'decimal:2',
        ];
    }

    public function grossWeightGrams(): ?int
    {
        $kilograms = $this->gross_weight_kg;

        return $kilograms === null ? null : (int) str_replace('.', '', $kilograms);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}
