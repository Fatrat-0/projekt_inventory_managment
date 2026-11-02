<?php

namespace App\Queries;

use App\Models\Partner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PartnerListing
{
    public const SORTS = ['name' => 'Név / Cégnév', 'type' => 'Típus', 'email' => 'E-mail', 'phone' => 'Telefon'];
    public const CONTACTS = ['complete' => 'Mindkét elérhetőség megadva', 'missing' => 'Hiányos elérhetőség'];
    public const STATUSES = ['active' => 'Aktív partnerek', 'archived' => 'Archivált partnerek', 'all' => 'Összes állapot'];
    public array $filters;

    public function __construct(Request $request)
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:200',
            'partner_id' => 'nullable|integer|exists:partners,id',
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'type' => ['nullable', Rule::in(array_keys(Partner::TYPES))],
            'contact' => ['nullable', Rule::in(array_keys(self::CONTACTS))],
            'sort_by' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'sort_dir' => 'nullable|in:asc,desc',
            'per_page' => ['nullable', 'integer', Rule::in([15,25,50,100])],
            'page' => 'nullable|integer|min:1',
        ]);
        $this->filters = [
            'search' => trim($data['search'] ?? ''), 'partner_id' => $data['partner_id'] ?? '',
            'type' => $data['type'] ?? '', 'contact' => $data['contact'] ?? '',
            'status' => $data['status'] ?? 'active',
            'sort_by' => $data['sort_by'] ?? 'name', 'sort_dir' => $data['sort_dir'] ?? 'asc',
            'per_page' => (int) ($data['per_page'] ?? 15),
        ];
    }

    public function baseQuery()
    {
        $query = Partner::query();
        if ($this->filters['status'] === 'active') $query->whereNull('archived_at');
        if ($this->filters['status'] === 'archived') $query->whereNotNull('archived_at');
        return $query;
    }

    public function query()
    {
        $f = $this->filters;
        $query = $this->baseQuery();
        if ($f['partner_id'] !== '') $query->whereKey($f['partner_id']);
        if ($f['type'] !== '') $query->where('type', $f['type']);
        if ($f['contact'] === 'complete') $query->whereRaw("COALESCE(TRIM(email), '') <> '' AND COALESCE(TRIM(phone), '') <> ''");
        if ($f['contact'] === 'missing') $query->whereRaw("(COALESCE(TRIM(email), '') = '' OR COALESCE(TRIM(phone), '') = '')");
        foreach (preg_split('/\s+/u', $f['search'], -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
            $query->where(fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("email LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("phone LIKE ? ESCAPE '!'", [$pattern]));
        }
        if ($f['sort_by'] === 'type') {
            return $query->orderByRaw("CASE type WHEN 'supplier' THEN 0 WHEN 'customer' THEN 1 ELSE 2 END ".$f['sort_dir'])->orderBy('id');
        }
        if (in_array($f['sort_by'], ['email', 'phone'], true)) $query->orderByRaw("CASE WHEN COALESCE(TRIM({$f['sort_by']}), '') = '' THEN 1 ELSE 0 END");
        return $query->orderBy($f['sort_by'], $f['sort_dir'])->orderBy('id');
    }
}
