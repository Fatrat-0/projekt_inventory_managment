<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    use HasFactory;

    public const TYPES = ['customer' => 'Vevő', 'supplier' => 'Beszállító', 'both' => 'Vevő és beszállító'];

    public function supportsMovement(string $type): bool
    {
        return in_array($type, ['in', 'out'], true)
            && ($this->type === 'both' || $this->type === ($type === 'in' ? 'supplier' : 'customer'));
    }

    public function movements() { return $this->hasMany(InventoryMovement::class); }

    protected function casts(): array { return ['archived_at' => 'datetime']; }

    public function isArchived(): bool { return $this->archived_at !== null; }

    protected $fillable = [
        'name',
        'type',
        'email',
        'phone',
        'contact_name', 'country', 'postal_code', 'city', 'street_address', 'notes',
    ];
}
