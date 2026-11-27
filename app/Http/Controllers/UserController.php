<?php

namespace App\Http\Controllers;

use App\Models\{User, Warehouse, WarehouseMembership};
use App\Services\UserAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\{Rule, Rules, ValidationException};
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class UserController extends Controller
{
    public const STATES = ['active' => 'Aktív', 'inactive' => 'Inaktív', 'unassigned' => 'Aktív, tagság nélküli munkatárs'];

    public const SORTS = [
        'name' => 'Név', 'email' => 'E-mail', 'role' => 'Globális szerepkör',
        'memberships_count' => 'Raktári hozzáférés', 'state' => 'Állapot', 'created_at' => 'Fiók létrehozása',
    ];

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:200',
            'role' => ['nullable', Rule::in(array_keys(User::ROLES))],
            'state' => ['nullable', Rule::in(array_keys(self::STATES))],
            'warehouse' => ['nullable', function ($attribute, $value, $fail) {
                if ($value !== 'none' && (!is_scalar($value) || !ctype_digit((string) $value) || !Warehouse::whereKey($value)->exists())) {
                    $fail('Válassz létező raktárat.');
                }
            }],
            'sort_by' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'sort_dir' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|in:10,25,50,100',
        ]) + ['search' => null, 'role' => null, 'state' => null, 'warehouse' => null, 'sort_by' => null, 'sort_dir' => null, 'per_page' => null];
        $filters['sort_by'] ??= 'created_at';
        $filters['sort_dir'] ??= 'desc';
        $filters['per_page'] = (int) ($filters['per_page'] ?? 10);
        $query = User::query()->select(['id', 'name', 'email', 'role', 'deactivated_at', 'created_at', 'lock_version', 'password_change_required'])
            ->withCount('warehouseMemberships as memberships_count')
            ->with(['warehouseMemberships.warehouse:warehouse_id,warehouse_name', 'warehouseMemberships.roleAssignments']);
        foreach (preg_split('/\s+/u', trim((string) $filters['search']), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $pattern = '%'.strtr($word, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->where(fn ($q) => $q->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(email) LIKE LOWER(?) ESCAPE '!'", [$pattern]));
        }
        if ($filters['role']) $query->where('role', $filters['role']);
        if ($filters['state'] === 'active') $query->whereNull('deactivated_at');
        if ($filters['state'] === 'inactive') $query->whereNotNull('deactivated_at');
        if ($filters['state'] === 'unassigned') $this->unassignedWorkers($query);
        if ($filters['warehouse'] === 'none') $query->doesntHave('warehouseMemberships');
        elseif ($filters['warehouse']) $query->whereHas('warehouseMemberships', fn ($q) => $q->where('warehouse_id', $filters['warehouse']));
        if ($filters['sort_by'] === 'state') {
            $query->orderByRaw('CASE WHEN deactivated_at IS NULL THEN 0 ELSE 1 END '.$filters['sort_dir']);
        } else {
            $query->orderBy($filters['sort_by'], $filters['sort_dir']);
        }
        $users = $query->orderBy('id', $filters['sort_dir'])->paginate($filters['per_page'])->withQueryString();
        $totals = DB::table('users')->selectRaw('COUNT(*) AS total, COUNT(deactivated_at) AS inactive')->first();
        $totals->unassigned = $this->unassignedWorkers(User::query())->count();
        $warehouses = $this->warehouses();
        $filterLabels = array_filter([
            'search' => $filters['search'],
            'role' => User::ROLES[$filters['role']] ?? null,
            'state' => self::STATES[$filters['state']] ?? null,
            'warehouse' => $filters['warehouse'] === 'none' ? 'Tagság nélkül'
                : $warehouses->firstWhere('warehouse_id', $filters['warehouse'])?->warehouse_name,
        ], fn ($label) => $label !== null && $label !== '');

        return $this->page('users.index', [
            'users' => $users, 'filters' => $filters, 'filterLabels' => $filterLabels, 'warehouses' => $warehouses, 'totals' => $totals,
            'mailAvailable' => UserAccess::mailAvailable(), 'onlyUsableAdmin' => UserAccess::usableAdmins()->count() === 1,
        ]);
    }

    public function create()
    {
        return $this->form(new User(['role' => 'worker']));
    }

    public function store(Request $request, UserAccess $access)
    {
        $data = $this->validateAccount($request);
        if ($data['password_delivery'] === 'link' && !UserAccess::mailAvailable()) {
            throw ValidationException::withMessages(['password_delivery' => 'A jelszóbeállító linkhez kézbesítő levelező szükséges.']);
        }
        try {
            $user = $access->create($request->user(), $data)->refresh();
        } catch (UniqueConstraintViolationException $error) {
            $this->duplicateEmail($data['email'], $error);
        }
        if ($data['password_delivery'] === 'link') {
            try {
                $access->sendPasswordLink($request->user(), $user, $user->lock_version);
            } catch (ValidationException) {
                return redirect()->route('users.show', $user)
                    ->withErrors(['password_delivery' => 'A fiók létrejött. A link küldését az adatlapon ismét megpróbálhatod.']);
            }
        }

        return redirect()->route('users.index')->with('success', $data['password_delivery'] === 'link'
            ? 'A fiók létrejött, a jelszóbeállító linket elküldtük.'
            : 'A fiók létrejött. Az első belépéskor kötelező a jelszócsere.');
    }

    public function show(User $user)
    {
        $user->load(['warehouseMemberships.warehouse:warehouse_id,warehouse_name', 'warehouseMemberships.roleAssignments']);
        $history = $user->accessEvents()->with('actor:id,name')->latest('id')->paginate(15)->fragment('jogosultsagi-elozmenyek');
        $warehouseNames = Warehouse::whereKey($history->getCollection()->flatMap(
            fn ($event) => array_column($event->changes['memberships'] ?? [], 'warehouse_id'),
        )->unique()->values()->all())->pluck('warehouse_name', 'warehouse_id');

        return $this->page('users.show', [
            'user' => $user, 'history' => $history, 'warehouseNames' => $warehouseNames,
            'mailAvailable' => UserAccess::mailAvailable(), 'onlyUsableAdmin' => UserAccess::usableAdmins()->count() === 1,
        ]);
    }

    public function edit(User $user)
    {
        return $this->form($user);
    }

    public function update(Request $request, User $user, UserAccess $access)
    {
        $data = $this->validateAccount($request, $user);
        $actor = $request->user();
        try {
            $updated = $access->update($actor, $user, $data);
        } catch (ConflictHttpException $error) {
            if ($request->expectsJson()) throw $error;
            $request->session()->flashInput($data);
            return $this->form($user->refresh(), 409);
        } catch (UniqueConstraintViolationException $error) {
            $this->duplicateEmail($data['email'], $error, $user);
        }

        if ($updated->is($actor) && $updated->access_version !== $actor->access_version) {
            Auth::guard('web')->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'A fiók módosításai elmentve. Jelentkezz be újra.');
        }

        return redirect()->route('users.show', $user)->with('success', $updated->wasChanged('lock_version')
            ? 'A fiók módosításai elmentve.' : 'A fiók adatai változatlanok.');
    }

    public function access(Request $request, User $user, UserAccess $access)
    {
        $data = $request->validate(['active' => 'required|boolean', 'lock_version' => 'required|integer|min:1']);
        $active = $request->boolean('active');
        try {
            $access->setActive($request->user(), $user, $active, (int) $data['lock_version']);
        } catch (ConflictHttpException $error) {
            if ($request->expectsJson()) throw $error;
            return redirect()->route('users.show', $user)->withErrors(['user' => $error->getMessage()]);
        }

        return back()->with('success', $active ? 'A fiók ismét aktív.' : 'A fiók inaktív. A korábbi műveletek megmaradtak.');
    }

    public function passwordLink(Request $request, User $user, UserAccess $access)
    {
        $data = $request->validate(['lock_version' => 'required|integer|min:1']);
        try {
            $access->sendPasswordLink($request->user(), $user, (int) $data['lock_version']);
        } catch (ConflictHttpException $error) {
            if ($request->expectsJson()) throw $error;
            return redirect()->route('users.show', $user)->withErrors(['user' => $error->getMessage()]);
        }

        return redirect()->route('users.show', $user)->with('success', 'A jelszóbeállító link elküldve.');
    }

    private function validateAccount(Request $request, ?User $user = null): array
    {
        if (is_string($request->input('email')) && $request->input('email') !== $user?->email) {
            $request->merge(['email' => Str::lower($request->input('email'))]);
        }
        if (!$user && !$request->has('password_delivery')) {
            $request->merge(['password_delivery' => $request->filled('password') ? 'temporary' : (UserAccess::mailAvailable() ? 'link' : 'temporary')]);
        }
        $identity = [
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($user)],
            'role' => [$user ? 'required' : 'sometimes', 'required', Rule::in(array_keys(User::ROLES))],
            'memberships_submitted' => 'sometimes|accepted',
            'memberships_complete' => 'required_with:memberships_submitted|in:1',
            'memberships' => 'sometimes|array|max:250',
            'memberships.*' => 'required|array:warehouse_id,roles',
            'memberships.*.warehouse_id' => 'required|integer|distinct|exists:warehouses,warehouse_id',
            'memberships.*.roles' => ['bail', 'required', 'array', 'list', 'min:1', 'max:4', function ($attribute, $roles, $fail) {
                $strings = array_filter($roles, 'is_string');
                if (count($strings) !== count(array_unique($strings))) {
                    $fail('Egy helyi szerepkör raktáranként csak egyszer szerepelhet.');
                }
            }],
            'memberships.*.roles.*' => ['required', 'string', Rule::in(array_keys(WarehouseMembership::ROLES))],
        ];
        $operation = $user ? [
            'current_password' => [
                Rule::excludeIf($request->input('email') === $user->email),
                'required', 'string', 'current_password:web',
            ],
            'lock_version' => 'required|integer|min:1',
            'review_version' => 'sometimes|integer|min:1',
            'confirm_edit_conflict' => 'sometimes|accepted',
        ] : [
            'password_delivery' => 'required|in:link,temporary',
            'password' => ['exclude_unless:password_delivery,temporary', 'required', 'confirmed', Rules\Password::defaults()],
        ];

        $data = $request->validate($identity + $operation, [
            'name.*' => 'Adj meg teljes nevet, legfeljebb 255 karakterrel.',
            'email.unique' => $user ? 'Ezzel az e-mail-címmel már létezik fiók.'
                : 'Ezzel az e-mail-címmel már létezik fiók. Keresd meg a Felhasználók listáján.',
            'email.*' => 'Adj meg érvényes e-mail-címet, legfeljebb 255 karakterrel.',
            'current_password.*' => 'Az e-mail-cím módosításához add meg a saját jelenlegi jelszavadat.',
            'role.*' => 'Válassz érvényes globális szerepkört.',
            'memberships_complete.*' => 'A tagságok adatai hiányosan érkeztek meg. Töltsd újra az űrlapot.',
            'memberships.*.array' => 'A raktári tagsághoz válassz raktárat és helyi szerepkört.',
            'memberships.*.warehouse_id.distinct' => 'Minden raktárhoz egy tagság adható meg.',
            'memberships.*.warehouse_id.*' => 'Válassz létező raktárat.',
            'memberships.*.roles.*' => 'Válassz legalább egy érvényes helyi szerepkört minden tagsághoz.',
            'memberships.*.roles.*.*' => 'Válassz érvényes helyi szerepkört.',
            'password_delivery.*' => 'Válassz elérhető jelszóbeállítási módot.',
            'password.required' => 'Adj meg ideiglenes jelszót.',
            'password.min' => 'Az ideiglenes jelszó legalább :min karakterből álljon.',
            'password.confirmed' => 'A két jelszó nem egyezik.',
        ]) + ['role' => 'worker'];
        unset($data['current_password']);

        return $data;
    }

    private function duplicateEmail(string $email, UniqueConstraintViolationException $error, ?User $user = null): never
    {
        if (User::where('email', $email)->when($user, fn ($q) => $q->whereKeyNot($user->id))->exists()) {
            throw ValidationException::withMessages(['email' => 'Ezzel az e-mail-címmel már létezik fiók.']);
        }
        throw $error;
    }

    private function form(User $user, int $status = 200)
    {
        $user->load(['warehouseMemberships.warehouse:warehouse_id,warehouse_name', 'warehouseMemberships.roleAssignments']);
        return $this->page('users.create', [
            'user' => $user, 'warehouses' => $this->warehouses(), 'mailAvailable' => UserAccess::mailAvailable(),
            'lastUsableAdmin' => $user->isUsableAdministrator() && UserAccess::usableAdmins()->count() === 1,
        ], $status);
    }

    private function warehouses()
    {
        return Warehouse::orderBy('warehouse_name')->orderBy('warehouse_id')->get(['warehouse_id', 'warehouse_name']);
    }

    private function unassignedWorkers(Builder $users): Builder
    {
        return $users->whereNull('deactivated_at')->whereIn('role', ['worker', 'manager'])->doesntHave('warehouseMemberships');
    }

    private function page(string $view, array $data, int $status = 200)
    {
        return response()->view($view, $data, $status)->header('Cache-Control', 'private, no-store');
    }
}
