<?php

namespace App\Services;

use App\Models\{Product, ProductPackaging};
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** The quantity and measurements describe one receipt portion ready for placement. */
class ReceiptPutawayCargo
{
    public const MAX_VERSION = 4294967295;

    public function weight(array $data, Product $product, bool $locked = false): ?int
    {
        return $this->resolveWeight($data, $this->packagingFor($product, $data, $locked));
    }

    public function assessmentInput(array $data, ?int $weight): array
    {
        return [
            'quantity' => $data['quantity'], 'cargo_weight_kg' => $weight === null ? null : $weight / 1000,
            'pallet_slots' => $data['pallet_slots'], 'dimensions_cm' => $data['dimensions_cm'],
        ];
    }

    public function summary(array $data, ?int $weight): array
    {
        return [
            'cargo_weight_grams' => $weight, 'weight_source' => $weight === null ? null : $data['weight_source'],
            'pallet_slots' => $data['pallet_slots'], 'dimensions_cm' => $data['dimensions_cm'] ?: null,
        ];
    }

    public static function packagingData(?ProductPackaging $packaging): ?array
    {
        return $packaging === null ? null : [
            'id' => $packaging->id, 'name' => $packaging->name, 'pieces_per_package' => $packaging->pieces_per_package,
            'gross_weight_grams' => $packaging->grossWeightGrams(),
            'dimensions_cm' => ['width' => $packaging->width_cm, 'depth' => $packaging->depth_cm, 'height' => $packaging->height_cm],
            'revision' => TransferCargoWeight::packagingRevision($packaging),
        ];
    }

    private function packagingFor(Product $product, array $data, bool $locked = false): ?ProductPackaging
    {
        if ($data['weight_source'] !== TransferCargoWeight::SOURCE_CALCULATED) return null;

        return $product->defaultPackaging()->when($locked, fn ($query) => $query->sharedLock())->first();
    }

    private function resolveWeight(array $data, ?ProductPackaging $packaging): ?int
    {
        if ($data['weight_source'] !== TransferCargoWeight::SOURCE_CALCULATED) return $data['cargo_weight_grams'];

        if (!hash_equals(TransferCargoWeight::packagingRevision($packaging), $data['cargo_weight_packaging'])) {
            throw new ConflictHttpException('A csomagolási adatok megváltoztak. Frissítsd a tételt, majd ellenőrizd a betárolandó részlet tömegét.');
        }
        $weight = TransferCargoWeight::calculatedGrams($packaging, $data['quantity']);
        if ($data['cargo_weight_grams'] !== null) {
            TransferCargoWeight::assertCalculated($packaging, $data['quantity'], $data['cargo_weight_grams']);
        }

        return $weight;
    }

    public function validate(array $input, bool $write, bool $manual = false): array
    {
        if (is_string($input['note'] ?? null)) {
            $note = Str::trim($input['note']);
            $input['note'] = $note === '' ? null : $note;
        }
        $calculated = ($input['weight_source'] ?? null) === TransferCargoWeight::SOURCE_CALCULATED;
        $rules = [
            'quantity' => ['required', $this->integer(1, 2147483647, 'Adj meg pozitív egész darabszámot.')],
            'cargo_weight_kg' => ($write && !$calculated ? 'required|' : 'nullable|').WarehouseCapacity::WEIGHT_RULE,
            'pallet_slots' => [$write ? 'required' : 'nullable', $this->integer(0, 1000000, 'Adj meg érvényes egész raklaphelyigényt.')],
            'dimensions_cm' => 'nullable|array:width,depth,height',
            'dimensions_cm.*' => ['nullable', $this->integer(1, 65535, 'A méreteket pozitív egész centiméterben add meg.')],
            'weight_source' => 'sometimes|required|in:measured,calculated',
            'cargo_weight_packaging' => $calculated ? 'required|regex:/\A[a-f0-9]{64}\z/' : 'prohibited',
            'packaging_confirmed' => $calculated ? 'required|accepted' : 'prohibited',
            'warehouse_id' => 'missing',
            'to_warehouse_id' => 'missing',
            'product_id' => 'missing',
        ];
        if ($write) {
            $rules += [
                'operation_key' => 'required|string|uuid',
                'version' => ['required', $this->integer(1, self::MAX_VERSION, 'Add meg a betárolási tétel aktuális verzióját.')],
                'confirmed' => 'required|accepted',
                'to_location_id' => ['required', $this->integer(1, PHP_INT_MAX, 'Válassz célrekeszt.')],
                'site_verified' => 'sometimes|boolean',
                'note' => 'nullable|string|max:500',
            ];
        } elseif ($manual) {
            $rules += [
                'to_location_id' => ['nullable', $this->integer(1, PHP_INT_MAX, 'Válassz célrekeszt.')],
                'search' => 'nullable|string|max:100',
            ];
        }

        $validated = Validator::make($input, $rules, [
            'quantity.required' => 'Add meg a betárolandó mennyiséget.',
            'cargo_weight_kg.*' => 'Add meg a betárolandó rakomány 0,001–1 000 000 kg közötti bruttó tömegét, legfeljebb három tizedesjeggyel.',
            'pallet_slots.required' => 'Add meg a raklaphelyigényt. Raklap nélküli tároláshoz adj meg 0-t.',
            'dimensions_cm.array' => 'A betárolandó rakomány szélességét, mélységét és magasságát centiméterben add meg.',
            'weight_source.*' => 'Válassz érvényes tömegforrást.',
            'cargo_weight_packaging.*' => 'A számított tömeghez töltsd be az aktuális csomagolási adatokat.',
            'packaging_confirmed.*' => 'Erősítsd meg, hogy a betárolandó mennyiség egész, a megadott adatoknak megfelelő csomagokból áll.',
            'operation_key.*' => 'Adj meg érvényes műveleti azonosítót (UUID).',
            'version.required' => 'Add meg a betárolási tétel aktuális verzióját.',
            'confirmed.*' => 'Erősítsd meg az áru tényleges tárhelyre helyezését.',
            'to_location_id.required' => 'Válassz célrekeszt.',
            'site_verified.*' => 'Add meg, hogy megtörtént-e a helyszíni illeszkedés ellenőrzése.',
            'note.*' => 'A megjegyzés legfeljebb 500 karakter lehet.',
            'search.*' => 'A tárhelykeresés legfeljebb 100 karakteres szöveg lehet.',
            'warehouse_id.missing' => 'A célraktárt az átvételi tétel határozza meg.',
            'to_warehouse_id.missing' => 'A célraktárt az átvételi tétel határozza meg.',
            'product_id.missing' => 'A betárolandó terméket az átvételi tétel határozza meg.',
        ])->validate();

        $dimensions = [];
        foreach (['width', 'depth', 'height'] as $axis) {
            if (isset($validated['dimensions_cm'][$axis])) $dimensions[$axis] = (int) $validated['dimensions_cm'][$axis];
        }
        $normalized = [
            'quantity' => (int) $validated['quantity'],
            'cargo_weight_grams' => isset($validated['cargo_weight_kg']) ? WarehouseCapacity::grams($validated['cargo_weight_kg']) : null,
            'pallet_slots' => isset($validated['pallet_slots']) ? (int) $validated['pallet_slots'] : null,
            'dimensions_cm' => $dimensions,
            'weight_source' => $validated['weight_source'] ?? 'measured',
            'cargo_weight_packaging' => $validated['cargo_weight_packaging'] ?? null,
        ];
        if (isset($validated['to_location_id'])) $normalized['to_location_id'] = (int) $validated['to_location_id'];
        if (isset($validated['search'])) $normalized['search'] = Str::trim($validated['search']);

        return $write ? $normalized + [
            'operation_key' => strtolower($validated['operation_key']), 'version' => (int) $validated['version'],
            'site_verified' => (bool) ($validated['site_verified'] ?? false), 'note' => $validated['note'] ?? null,
        ] : $normalized;
    }

    private function integer(int $min, int $max, string $message): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($min, $max, $message): void {
            $valid = is_int($value) || (is_string($value) && ctype_digit($value) && filter_var($value, FILTER_VALIDATE_INT) !== false);
            if (!$valid || (int) $value < $min || (int) $value > $max) $fail($message);
        };
    }
}
