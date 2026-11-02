<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    use HasFactory;

    public const TYPES = ['customer' => 'Vevő', 'supplier' => 'Beszállító'];

    protected function casts(): array { return ['archived_at' => 'datetime']; }

    public function isArchived(): bool { return $this->archived_at !== null; }

    protected $fillable = [
        'name',
        'type',
        'email',
        'phone',
    ];
}
