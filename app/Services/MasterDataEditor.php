<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MasterDataEditor
{
    public static function rules(): array
    {
        return [
            'lock_version' => 'required|integer|min:1|max:2147483647',
            'review_version' => 'nullable|integer|min:1|max:2147483647',
            'confirm_edit_conflict' => 'exclude_without:review_version|required|accepted',
        ];
    }

    public static function assertCurrent(Model $current, Request $request, string $editUrl): void
    {
        $expected = (int) ($request->input('review_version') ?? $request->input('lock_version'));
        if ((int) $current->lock_version !== $expected) {
            throw ValidationException::withMessages(['lock_version' => 'Az adatlapot közben módosították. A mentés nem történt meg; a beírt adataidat megőriztük. Nézd át az aktuális értékeket.'])
                ->status(409)->redirectTo($editUrl);
        }
    }

    public static function attributes(): array
    {
        return ['lock_version' => 'űrlap verziója', 'review_version' => 'átnézett változat', 'confirm_edit_conflict' => 'aktuális adatok átnézése'];
    }

    public static function messages(): array
    {
        return [
            'lock_version.*' => 'Az űrlap verziója hiányzik vagy érvénytelen. Töltsd újra az adatlapot.',
            'review_version.*' => 'Az átnézett változat érvénytelen. Töltsd újra az adatlapot.',
            'confirm_edit_conflict.*' => 'Mentés előtt erősítsd meg, hogy átnézted az aktuális adatokat.',
        ];
    }

    public static function update(Model $record, Request $request, array $data, string $editUrl): Model
    {
        try {
            return DB::transaction(function () use ($record, $request, $data, $editUrl) {
                // Always compare a current locking read, including inside an outer snapshot.
                $current = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                self::assertCurrent($current, $request, $editUrl);
                $current->fill($data);
                if ($current instanceof Product && $current->isDirty('category_id')) {
                    app(WarehouseCapacity::class)->assertProductCategoryChange($current);
                }
                $current->lock_version = (int) $current->lock_version + 1;
                $current->save();
                return $current;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // Two distinct products can pass SKU validation before either commits.
            // The database uniqueness constraint remains authoritative.
            if (!$record instanceof Product || !array_key_exists('sku', $data)) throw $exception;
            throw ValidationException::withMessages(['sku' => 'Ezt a cikkszámot közben már egy másik termékhez rögzítették. Válassz másikat.'])
                ->redirectTo($editUrl);
        }
    }
}
