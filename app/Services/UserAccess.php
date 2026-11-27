<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\{DB, Password};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class UserAccess
{
    public static function activeAdmins(): Builder
    {
        return User::where('role', 'admin')->whereNull('deactivated_at');
    }

    public static function usableAdmins(): Builder
    {
        return self::activeAdmins()->where('password_change_required', false);
    }

    public static function mailAvailable(): bool
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');

        return in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'], true)
            || ($transport === 'array' && app()->environment('testing'));
    }

    public function create(User $actor, array $data): User
    {
        return DB::transaction(function () use ($actor, $data) {
            $this->assertAdmin($actor, self::activeAdmins()->whereKey($actor->id)->sharedLock()->first());
            $memberships = $this->membershipMap($data);
            $this->assertMemberships($data['role'], $memberships);
            $account = User::create([
                'name' => $data['name'], 'email' => $data['email'], 'role' => $data['role'],
                'password' => $data['password'] ?? Str::random(48),
            ]);
            $account->forceFill(['password_change_required' => true])->save();
            foreach ($memberships as $warehouseId => $roles) {
                $account->addWarehouseMembership($warehouseId, $roles);
            }
            $this->audit($actor, $account, 'created', [
                'role' => ['before' => null, 'after' => $account->role],
                'memberships' => $this->membershipChanges([], $memberships),
            ]);

            return $account;
        }, 3);
    }

    public function update(User $actor, User $target, array $data): User
    {
        return $this->administer($actor, $target, function (User $account, int $usableAdminCount) use ($actor, $data) {
            $version = (int) $data['lock_version'];
            $reviewed = !empty($data['confirm_edit_conflict']) && (int) ($data['review_version'] ?? 0) === $account->lock_version;
            $this->checkVersion($account, $reviewed ? $account->lock_version : $version);
            if ($account->isUsableAdministrator() && $data['role'] !== 'admin' && $usableAdminCount === 1) {
                $this->fail('role', 'Az utolsó használható adminisztrátori fiók szerepköre nem fokozható le.');
            }

            $current = $account->warehouseMemberships()->orderBy('warehouse_id')->lockForUpdate()
                ->with(['roleAssignments' => fn ($roles) => $roles->orderBy('id')->lockForUpdate()])->get()->keyBy('warehouse_id');
            $before = $current->map->roleCodes()->all();
            $memberships = array_key_exists('memberships', $data) || !empty($data['memberships_submitted'])
                ? $this->membershipMap($data)
                : $before;
            $this->assertMemberships($data['role'], $memberships, $before);
            $changes = $this->membershipChanges($before, $memberships);
            $role = $account->role;
            $account->fill(array_intersect_key($data, array_flip(['name', 'email', 'role'])));
            $identity = array_keys($account->getDirty());
            if (!$identity && !$changes) return $account;

            foreach ($changes as $change) {
                $membership = $current->get($change['warehouse_id']);
                if ($change['after'] === []) {
                    $membership->delete();
                } elseif ($membership) {
                    $membership->syncRoles($change['after']);
                    $membership->touch();
                } else {
                    $account->addWarehouseMembership($change['warehouse_id'], $change['after']);
                }
            }

            $this->saveIdentity($account, $role !== $account->role || $changes !== []);
            $this->audit($actor, $account, 'updated', [
                'fields' => array_values(array_diff($identity, ['role'])),
                'role' => $role !== $account->role ? ['before' => $role, 'after' => $account->role] : null,
                'memberships' => $changes,
            ]);

            return $account;
        });
    }

    public function updateProfile(User $actor, array $data): User
    {
        return DB::transaction(function () use ($actor, $data) {
            $current = app(TransferWorkflowOperations::class)->currentActor($actor, exclusive: true);
            $current->fill($data);
            $fields = array_keys($current->getDirty());
            if ($fields) {
                $this->saveIdentity($current);
                $this->audit($actor, $current, 'updated', ['fields' => $fields]);
            }

            return $current;
        }, 3);
    }

    public function setActive(User $actor, User $target, bool $active, ?int $version = null): void
    {
        $this->administer($actor, $target, function (User $account, int $usableAdminCount) use ($actor, $active, $version) {
            if ($version !== null) $this->checkVersion($account, $version);
            if ($account->isActive() === $active) return;

            if (!$active && $account->isUsableAdministrator() && $usableAdminCount === 1) {
                $this->fail('user', 'Az utolsó használható adminisztrátori fiók nem inaktiválható.');
            }
            if ($actor->id === $account->id) {
                $this->fail('user', 'A saját fiókodat egy másik adminisztrátor inaktiválhatja.');
            }

            $account->deactivated_at = $active ? null : now();
            $account->lock_version++;
            if (!$active) self::revoke($account);
            $account->save();
            $this->audit($actor, $account, $active ? 'reactivated' : 'deactivated', []);
        });
    }

    public function sendPasswordLink(User $actor, User $target, int $version): void
    {
        if (!self::mailAvailable()) $this->fail('password_delivery', 'A jelszóbeállító linkhez kézbesítő levelezőt kell beállítani.');
        try {
            $this->administer($actor, $target, function (User $account) use ($actor, $version) {
                $this->checkVersion($account, $version);
                if (!$account->isActive()) $this->fail('user', 'Jelszóbeállító linket aktív fiókhoz küldhetsz.');
                $this->audit($actor, $account, 'password_link', []);
                $status = Password::sendResetLink(['email' => $account->email, 'deactivated_at' => null]);
                if ($status !== Password::RESET_LINK_SENT) {
                    $this->fail('password_delivery', 'Most nem küldhető új hivatkozás. Próbáld újra egy perc múlva.');
                }
            }, attempts: 1);
        } catch (TransportExceptionInterface) {
            $this->fail('password_delivery', 'A levelező nem tudta elküldeni a hivatkozást.');
        }
    }

    public static function revoke(User $account): void
    {
        $account->access_version++;
        $account->setRememberToken(Str::random(60));
        Password::deleteToken($account);
    }

    private function saveIdentity(User $account, bool $accessChanged = false): void
    {
        if ($account->isDirty('email')) {
            Password::deleteToken(new User(['email' => $account->getOriginal('email')]));
            $account->email_verified_at = null;
            $accessChanged = true;
        }
        if ($accessChanged) self::revoke($account);
        $account->lock_version++;
        $account->save();
    }

    private function administer(User $actor, User $target, callable $change, int $attempts = 3): mixed
    {
        return DB::transaction(function () use ($actor, $target, $change) {
            // State changes and role changes serialize on the same active administrator rows.
            $admins = self::activeAdmins()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->assertAdmin($actor, $admins->get($actor->id));
            $account = $admins->get($target->id) ?? User::whereKey($target->id)->lockForUpdate()->firstOrFail();

            return $change($account, $admins->filter->isUsableAdministrator()->count());
        }, $attempts);
    }

    private function assertAdmin(User $actor, ?User $current): void
    {
        abort_unless($current?->isUsableAdministrator() && (int) $current->access_version === (int) $actor->access_version, 403,
            'A fiókkezeléshez aktív adminisztrátori jogosultság szükséges.');
    }

    private function checkVersion(User $account, int $version): void
    {
        if ($account->lock_version !== $version) {
            throw new ConflictHttpException('A fiókot közben módosították. Nézd át az aktuális adatokat.');
        }
    }

    private function membershipMap(array $data): array
    {
        $map = array_column($data['memberships'] ?? [], 'roles', 'warehouse_id');
        foreach ($map as &$roles) {
            sort($roles, SORT_STRING);
        }
        unset($roles);
        ksort($map, SORT_NUMERIC);

        return $map;
    }

    private function assertMemberships(string $role, array $memberships, array $legacy = []): void
    {
        if ($role !== 'admin') return;

        foreach ($memberships as $warehouseId => $roles) {
            if ($roles !== ($legacy[$warehouseId] ?? null)) {
                $this->fail('memberships', 'Az adminisztrátor minden raktárhoz hozzáfér. Megtarthatod a korábbi tagságokat. Az új vagy módosított helyi hozzárendeléseket állítsd vissza a mentés előtt.');
            }
        }
    }

    private function membershipChanges(array $before, array $after): array
    {
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $id) {
            if (($before[$id] ?? []) !== ($after[$id] ?? [])) {
                $changes[] = ['warehouse_id' => (int) $id, 'before' => $before[$id] ?? [], 'after' => $after[$id] ?? []];
            }
        }

        return $changes;
    }

    private function audit(User $actor, User $target, string $action, array $changes): void
    {
        $target->accessEvents()->create(['actor_id' => $actor->id, 'action' => $action, 'changes' => $changes, 'occurred_at' => now()]);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
