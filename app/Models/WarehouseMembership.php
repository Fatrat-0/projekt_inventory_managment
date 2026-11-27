<?php

namespace App\Models;

use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WarehouseMembership extends Model
{
    public const ROLES = [
        'requester' => 'Igénylő',
        'dispatch_operator' => 'Kitároló',
        'receiving_operator' => 'Átvevő',
        'supervisor' => 'Felügyelő',
    ];

    public const ROLE_DESCRIPTIONS = [
        'requester' => 'Átmozgatási igényt nyújthat be, ha ez az egyik érintett raktár.',
        'dispatch_operator' => 'Igényt nyújthat be, kitárolhat, manuálisan kiadhat és megtekintheti a forrásoldali fuvarokat.',
        'receiving_operator' => 'Igényt nyújthat be, átveheti és betárolhatja a beérkező árut.',
        'supervisor' => 'Mindkét operátori feladatkör, továbbá igényelbírálás, készletfoglalás, feladatkiadás, fuvartervezés, feladás és terhelés rögzítése.',
    ];

    // Each capability is evaluated independently in the warehouse that owns the operation.
    public const CAPABILITIES = [
        'request' => ['label' => 'Igény benyújtása', 'roles' => ['requester', 'dispatch_operator', 'receiving_operator', 'supervisor']],
        'pick' => ['label' => 'Kitárolás, manuális kiadás és forrásoldali fuvarok megtekintése', 'roles' => ['dispatch_operator', 'supervisor']],
        'receive' => ['label' => 'Átvétel és betárolás', 'roles' => ['receiving_operator', 'supervisor']],
        'operate' => ['label' => 'Belső áthelyezés és terhelésadatok megtekintése', 'roles' => ['dispatch_operator', 'receiving_operator', 'supervisor']],
        'supervise' => ['label' => 'Igényelbírálás, készletfoglalás, feladatkiadás, fuvartervezés, feladás és terhelés rögzítése', 'roles' => ['supervisor']],
    ];

    protected $fillable = ['user_id', 'warehouse_id'];

    public function scopeWithCapability(Builder $query, string $capability): Builder
    {
        return $query->whereHas('roleAssignments', fn (Builder $roles) => $roles
            ->whereIn('role', self::CAPABILITIES[$capability]['roles']));
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(WarehouseMembershipRole::class);
    }

    public function roleCodes(): array
    {
        return $this->roleAssignments->pluck('role')->sort(SORT_STRING)->values()->all();
    }

    public static function roleLabels(array|string|null $roles): string
    {
        return implode(', ', array_map(fn ($role) => self::ROLES[$role] ?? $role, (array) $roles));
    }

    public function syncRoles(array $roles): void
    {
        if (!$roles || array_diff($roles, array_keys(self::ROLES)) || count(array_unique($roles)) !== count($roles)) {
            throw new InvalidArgumentException('A tagsághoz legalább egy érvényes, különböző szerepkör szükséges.');
        }

        DB::transaction(function () use ($roles) {
            // Serialize assignment changes even for callers outside the account editor.
            self::whereKey($this->getKey())->lockForUpdate()->firstOrFail(['id']);
            $existing = $this->roleAssignments()->lockForUpdate()->pluck('role')->all();
            foreach (array_diff($roles, $existing) as $role) {
                $this->roleAssignments()->create(['role' => $role]);
            }
            $this->roleAssignments()->whereIn('role', array_diff($existing, $roles))->delete();
            $this->unsetRelation('roleAssignments');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
