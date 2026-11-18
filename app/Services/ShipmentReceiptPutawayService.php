<?php

namespace App\Services;

use App\Models\{Product, ProductPackaging, Shipment, ShipmentReceiptItem, ShipmentReceiptPutaway, User, WarehouseLocation, WarehousePlacement};
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\{DB, Gate, Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShipmentReceiptPutawayService
{
    public const MAX_VERSION = 4294967295;

    public function __construct(
        private SmartPutaway $smartPutaway,
        private WarehouseCapacity $capacity,
        private WarehouseStockAvailability $availability,
        private TransferWorkflowOperations $operations,
    ) {}

    public function recommendations(User $actor, ShipmentReceiptItem $item, array $input): array
    {
        return $this->evaluate($actor, $item, $input, false);
    }

    public function locations(User $actor, ShipmentReceiptItem $item, array $input): array
    {
        return $this->evaluate($actor, $item, $input, true);
    }

    private function evaluate(User $actor, ShipmentReceiptItem $item, array $input, bool $manual): array
    {
        $data = $this->validate($input, false, $manual);

        return DB::transaction(function () use ($actor, $item, $data, $manual) {
            $current = ShipmentReceiptItem::findOrFail($item->id);
            Gate::forUser($actor)->authorize('putawayReceiptItem', [Shipment::class, $current]);
            $this->assertRemaining($current, $data['quantity']);
            $product = $current->product;
            $warehouse = $current->warehouse;
            $weight = $this->resolveWeight($data, $this->packagingFor($product, $data));
            $cargo = [
                'quantity' => $data['quantity'],
                'cargo_weight_kg' => $weight === null ? null : $weight / 1000,
                'pallet_slots' => $data['pallet_slots'],
                'dimensions_cm' => $data['dimensions_cm'],
            ];

            $evaluation = $manual
                ? ['locations' => $this->smartPutaway->assessLocations($warehouse, $product, $cargo, $data['to_location_id'] ?? null, $data['search'] ?? null)]
                : $this->smartPutaway->recommend($warehouse, $product, $cargo);

            return $evaluation + [
                'warehouse_id' => (int) $current->to_warehouse_id,
                'product_id' => (int) $current->product_id,
                'quantity' => $data['quantity'],
                'item' => self::itemState($current),
                'cargo' => [
                    'cargo_weight_grams' => $weight,
                    'weight_source' => $weight === null ? null : $data['weight_source'],
                    'pallet_slots' => $data['pallet_slots'],
                    'dimensions_cm' => $data['dimensions_cm'] ?: null,
                ],
            ];
        });
    }

    public function putaway(User $actor, ShipmentReceiptItem $item, array $input): array
    {
        $data = $this->validate($input, true);
        $key = $data['operation_key'];
        $hash = $this->operations->fingerprint('shipment_receipt_putaway', $actor, null, [
            'receipt_item_id' => $item->id,
            'request' => array_diff_key($data, ['operation_key' => true]),
        ]);

        try {
            return DB::transaction(function () use ($actor, $item, $data, $key, $hash) {
                $actor = $this->operations->currentActor($actor);
                // The destination and product are immutable receipt identities. Take
                // the shared inventory locks before touching protected receipt rows.
                $product = Product::whereKey($item->getRawOriginal('product_id'))->lockForUpdate()->firstOrFail();
                $warehouseId = (int) $item->getRawOriginal('to_warehouse_id');
                $this->capacity->lockWarehouses([$warehouseId]);
                $current = ShipmentReceiptItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('putawayReceiptItem', [Shipment::class, $current]);
                $stale = $current->putaway_version !== $data['version'];
                if ($prior = $this->findEvent($key, $stale)) return $this->replay($prior, $hash);
                if ($stale || $current->putaway_version >= self::MAX_VERSION) {
                    throw new ConflictHttpException('A betárolási tétel megváltozott vagy elérte a verzióhatárt. Frissítsd az átvételi tételt.');
                }
                $this->assertRemaining($current, $data['quantity']);
                $location = WarehouseLocation::where('warehouse_id', $warehouseId)->whereKey($data['to_location_id'])->lockForUpdate()->first();
                if (!$location) {
                    throw ValidationException::withMessages(['to_location_id' => 'Válassz az átvétel célraktárához tartozó tárhelyet.']);
                }
                $stock = $this->availability->snapshotForUpdate($warehouseId, (int) $product->getKey());
                if (!$stock['consistent'] || !$stock['stock_id'] || $stock['pending_putaway_quantity'] < $current->pending_putaway_quantity) {
                    throw new ConflictHttpException('Az átvételi tétel és a raktári készlet adatai eltérnek. Ellenőrizd a készletet.');
                }

                $weight = $this->resolveWeight($data, $this->packagingFor($product, $data, true));
                $placed = $this->capacity->placeReceiptStock($location, $product, $stock['stock_id'], $data['quantity'],
                    $weight, $data['pallet_slots'], $data['dimensions_cm'], $data['site_verified']);
                $current->forceFill([
                    'pending_putaway_quantity' => $current->pending_putaway_quantity - $data['quantity'],
                    'putaway_version' => $current->putaway_version + 1,
                ])->save();

                return $this->record($actor, $current, $location, $placed['placement'], $placed['geometry_status'], $data, $weight, $hash);
            }, 3);
        } catch (UniqueConstraintViolationException $error) {
            if ($prior = $this->findEvent($key, true)) return $this->replay($prior, $hash);
            throw $error;
        }
    }

    public static function itemState(ShipmentReceiptItem $item): array
    {
        $placed = $item->received_quantity - $item->pending_putaway_quantity;

        return [
            'id' => $item->id,
            'received_quantity' => $item->received_quantity,
            'placed_quantity' => $placed,
            'pending_putaway_quantity' => $item->pending_putaway_quantity,
            'version' => $item->putaway_version,
            'status' => $item->pending_putaway_quantity === 0 ? 'completed' : ($placed > 0 ? 'partial' : 'pending'),
            'can_putaway' => $item->pending_putaway_quantity > 0 && $item->putaway_version < self::MAX_VERSION,
        ];
    }

    private function assertRemaining(ShipmentReceiptItem $item, int $quantity): void
    {
        if ($quantity > $item->pending_putaway_quantity) {
            throw new ConflictHttpException('Ebből az átvételi tételből legfeljebb '.$item->pending_putaway_quantity.' db vár betárolásra. Frissítsd az adatokat.');
        }
    }

    private function record(User $actor, ShipmentReceiptItem $item, WarehouseLocation $location, WarehousePlacement $placement,
        string $geometryStatus, array $data, int $weight, string $hash): array
    {
        $event = ShipmentReceiptPutaway::create([
            'shipment_receipt_item_id' => $item->id,
            'warehouse_placement_id' => $placement->id,
            'warehouse_location_id' => $location->id,
            'location_code' => $location->code,
            'location_name' => $location->name,
            'actor_id' => $actor->id,
            'quantity' => $data['quantity'],
            'cargo_weight_grams' => $weight,
            'pallet_slots' => $data['pallet_slots'],
            'dimensions_cm' => $data['dimensions_cm'] ?: null,
            'geometry_status' => $geometryStatus,
            'site_verified' => $data['site_verified'],
            'weight_source' => $data['weight_source'],
            'packaging_revision' => $data['cargo_weight_packaging'],
            'note' => $data['note'],
            'operation_key' => $data['operation_key'],
            'request_hash' => $hash,
            'placed_at' => now(),
            'result' => [
                'item' => self::itemState($item),
                'placement' => [
                    'id' => $placement->id, 'location_id' => $location->id,
                    'location_code' => $location->code, 'location_name' => $location->name,
                    'quantity' => $data['quantity'],
                ],
            ],
        ]);

        return $this->response($event->refresh());
    }

    private function findEvent(string $key, bool $current): ?ShipmentReceiptPutaway
    {
        $query = ShipmentReceiptPutaway::where('operation_key', $key);

        return ($current ? $query->sharedLock() : $query)->first();
    }

    private function replay(ShipmentReceiptPutaway $prior, string $hash): array
    {
        if (!hash_equals($prior->request_hash, $hash)) {
            throw new ConflictHttpException('Ez a műveleti azonosító már másik betárolási kéréshez tartozik.');
        }

        return $this->response($prior);
    }

    private function response(ShipmentReceiptPutaway $event): array
    {
        return $event->result + ['event' => $event->eventData()];
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

    private function validate(array $input, bool $write, bool $manual = false): array
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
