<?php

namespace App\Services;

use App\Models\ProductPackaging;
use Illuminate\Validation\ValidationException;

class TransferCargoWeight
{
    public const SOURCE_CALCULATED = 'calculated';
    public const PACKAGING_REVISION_RULE = 'sometimes|required|regex:/\A[a-f0-9]{64}\z/';

    private const MAX_GRAMS = 1_000_000_000;

    public static function packagingRevision(?ProductPackaging $packaging): string
    {
        $data = $packaging === null ? null : [
            $packaging->getKey(), $packaging->name, $packaging->pieces_per_package, $packaging->grossWeightGrams(),
            $packaging->width_cm, $packaging->depth_cm, $packaging->height_cm, $packaging->barcode,
            $packaging->getRawOriginal('updated_at'),
        ];

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public static function assertPackagingRevision(?ProductPackaging $packaging, string $revision): void
    {
        if (!hash_equals(self::packagingRevision($packaging), $revision)) {
            throw ValidationException::withMessages([
                'cargo_weight_packaging' => 'A termék csomagolási adatai megváltoztak. Töltsd be az aktuális adatokat, majd erősítsd meg újra a teljes tömeget.',
            ]);
        }
    }

    public static function assertCalculated(?ProductPackaging $packaging, int $quantity, ?int $weightGrams): void
    {
        $boxWeightGrams = $packaging?->grossWeightGrams();
        $piecesPerBox = $packaging?->pieces_per_package ?? 0;
        if ($boxWeightGrams === null || $boxWeightGrams < 1 || $piecesPerBox < 1) {
            throw ValidationException::withMessages([
                'cargo_weight_kg' => 'A csomagolás tömege vagy darabszáma hiányzik. Add meg a teljes tömeget kézzel.',
            ]);
        }
        if ($quantity < 1 || $quantity % $piecesPerBox !== 0) {
            throw ValidationException::withMessages([
                'cargo_weight_kg' => 'A tömeg csak egész dobozokra számítható. Add meg a teljes tömeget kézzel.',
            ]);
        }

        $boxCount = intdiv($quantity, $piecesPerBox);
        if ($boxCount > intdiv(self::MAX_GRAMS, $boxWeightGrams)) {
            throw ValidationException::withMessages([
                'cargo_weight_kg' => 'A számított teljes tömeg nem haladhatja meg az 1 000 000 kg-ot.',
            ]);
        }
        if ($boxCount * $boxWeightGrams !== $weightGrams) {
            throw ValidationException::withMessages([
                'cargo_weight_kg' => 'A tömeg eltér a csomagolás aktuális adataiból számolt értéktől. Ellenőrizd a teljes tömeget.',
            ]);
        }
    }
}
