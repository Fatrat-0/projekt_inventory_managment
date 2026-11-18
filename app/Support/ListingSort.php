<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/** Whitelisted, at most two user-selected SQL ordering levels. */
final class ListingSort
{
    public static function rules(array $sorts): array
    {
        return [
            'sort_by' => ['nullable', 'string', Rule::in(array_keys($sorts))],
            'sort_dir' => 'nullable|string|in:asc,desc',
            'then_by' => ['nullable', 'string', Rule::in(array_keys($sorts))],
            'then_dir' => 'nullable|string|in:asc,desc',
        ];
    }

    public static function messages(): array
    {
        return [
            'sort_by.*' => 'Válassz érvényes elsődleges rendezési szempontot.',
            'sort_dir.*' => 'Válassz érvényes elsődleges rendezési irányt.',
            'then_by.*' => 'Válassz érvényes másodlagos rendezési szempontot.',
            'then_dir.*' => 'Válassz érvényes másodlagos rendezési irányt.',
        ];
    }

    public static function normalize(array $filters, array $sorts, string $defaultBy, string $defaultDir = 'desc'): array
    {
        $primary = $filters['sort_by'] ?? null;
        $filters['sort_by'] = is_string($primary) && isset($sorts[$primary]) ? $primary : $defaultBy;
        $filters['sort_dir'] = in_array($filters['sort_dir'] ?? null, ['asc', 'desc'], true)
            ? $filters['sort_dir'] : $defaultDir;
        $secondary = $filters['then_by'] ?? null;
        if (is_string($secondary) && isset($sorts[$secondary]) && $secondary !== $filters['sort_by']) {
            $filters['then_by'] = $secondary;
            $filters['then_dir'] = in_array($filters['then_dir'] ?? null, ['asc', 'desc'], true)
                ? $filters['then_dir'] : 'asc';
        } else {
            unset($filters['then_by'], $filters['then_dir']);
        }

        return $filters;
    }

    public static function levels(array $filters, array $sorts, string $defaultBy, string $defaultDir = 'desc'): array
    {
        $filters = self::normalize($filters, $sorts, $defaultBy, $defaultDir);
        $levels = [['field' => $filters['sort_by'], 'direction' => $filters['sort_dir']]];
        if (isset($filters['then_by'])) {
            $levels[] = ['field' => $filters['then_by'], 'direction' => $filters['then_dir']];
        }

        return $levels;
    }
}
