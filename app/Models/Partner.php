<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    use HasFactory;

    public const TYPES = ['customer' => 'Vevő', 'supplier' => 'Beszállító'];

    protected $fillable = [
        'name',
        'type',
        'email',
        'phone',
    ];
}
