<?php

namespace App\Services;

use App\Models\{InventoryMovement, Product, Shipment, ShipmentReceipt, ShipmentStop, ShipmentTransitItem, Stock, User};
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Gate, Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShipmentReceiving
{
    public const MAX_VERSION = 4294967295;

    public function __construct(
        private TransferWorkflowOperations $operations,
        private WarehouseCapacity $capacity,
        private InventoryService $inventory,
    ) {}

    public function receive(User $actor, Shipment $shipment, ShipmentStop $stop, array $input): array
    {
        $data = $this->validate($input);
        $key = strtolower($data['operation_key']);
        $version = (int) $data['version'];
        $note = $this->note($data['note'] ?? null);
        $lines = collect($data['items'])->map(fn (array $row) => [
            'transit_item_id' => (int) $row['transit_item_id'],
            'quantity' => (int) $row['quantity'],
            'note' => $this->note($row['note'] ?? null),
        ])->sortBy('transit_item_id')->values();
        $hash = $this->operations->fingerprint('shipment_receive', $actor, null, [
            'shipment_id' => $shipment->id, 'shipment_stop_id' => $stop->id, 'version' => $version,
            'note' => $note, 'items' => $lines->all(),
        ]);

        try {
            return DB::transaction(function () use ($actor, $shipment, $stop, $key, $version, $note, $lines, $hash) {
                $actor = $this->operations->currentActor($actor);
                $current = Shipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();
                $destination = $current->stops()->whereKey($stop->id)->lockForUpdate()->first();
                abort_unless($destination, 404, 'A megálló nem tartozik ehhez a fuvarhoz.');
                Gate::forUser($actor)->authorize('receiveStop', [Shipment::class, $destination]);

                $stale = $destination->receiving_version !== $version;
                if ($prior = $this->findReceipt($key, $stale)) return $this->replay($prior, $hash);
                if ($stale || $version >= self::MAX_VERSION || $current->version >= self::MAX_VERSION) {
                    throw new ConflictHttpException('Az átvétel adatai megváltoztak vagy elérték a verzióhatárt. Frissítsd a megálló adatlapját.');
                }
                $this->assertReceivable($current, $destination);

                $transit = $current->transitItems()->where('shipment_stop_id', $destination->id)
                    ->lockForUpdate()->get()->keyBy('id');
                $this->assertLines($lines, $transit, $destination);
                $this->lockDestinationStock($lines, $transit, (int) $destination->warehouse_id);

                return $this->book($actor, $current, $destination, $lines, $transit, $key, $hash, $note);
            }, 3);
        } catch (UniqueConstraintViolationException $error) {
            // Different shipment roots can compete for the same unique key. The
            // losing transaction is already rolled back before the current read.
            return DB::transaction(function () use ($actor, $shipment, $stop, $key, $hash, $error) {
                $currentActor = $this->operations->currentActor($actor);
                $destination = $shipment->stops()->whereKey($stop->id)->sharedLock()->firstOrFail();
                Gate::forUser($currentActor)->authorize('receiveStop', [Shipment::class, $destination]);
                if ($prior = $this->findReceipt($key, true)) return $this->replay($prior, $hash);
                throw $error;
            });
        }
    }

    private function assertReceivable(Shipment $shipment, ShipmentStop $stop): void
    {
        if (!$shipment->isTransferIntentPlan() || $shipment->status !== 'in_transit'
            || !$shipment->dispatch()->sharedLock()->first(['id'])) {
            throw new ConflictHttpException('Csak ténylegesen feladott, úton lévő átmozgatási fuvar tételei vehetők át.');
        }
        if ($stop->received_at !== null) {
            throw new ConflictHttpException('A megálló minden tételét már átvették.');
        }
    }

    private function assertLines(Collection $lines, Collection $transit, ShipmentStop $stop): void
    {
        foreach ($lines as $line) {
            $item = $transit->get($line['transit_item_id']);
            if (!$item || (int) $item->to_warehouse_id !== (int) $stop->warehouse_id) {
                throw ValidationException::withMessages(['items' => 'Csak a kiválasztott célmegállóhoz tartozó tranzittételek vehetők át.']);
            }
            if ($item->remaining_quantity < $line['quantity']) {
                throw ValidationException::withMessages([
                    'items' => "A(z) {$item->id}. tranzittételből legfeljebb {$item->remaining_quantity} db vehető át.",
                ]);
            }
        }
    }

    private function lockDestinationStock(Collection $lines, Collection $transit, int $warehouseId): void
    {
        $productIds = $lines->map(fn (array $line) => (int) $transit[$line['transit_item_id']]->product_id)
            ->unique()->sort(SORT_NUMERIC)->values();

        // Match picking, dispatch and the ordinary inventory writers: product,
        // warehouse, then stock. These locks cover missing stock rows as well.
        foreach ($productIds as $productId) Product::whereKey($productId)->lockForUpdate()->firstOrFail(['product_id']);
        $this->capacity->lockWarehouses([$warehouseId]);
        foreach ($productIds as $productId) {
            Stock::where('warehouse_id', $warehouseId)->where('product_id', $productId)->lockForUpdate()->first(['id']);
        }
    }

    private function book(
        User $actor, Shipment $shipment, ShipmentStop $stop, Collection $lines, Collection $transit,
        string $key, string $hash, ?string $note,
    ): array {
        $at = now();
        $receipt = $stop->receipts()->create([
            'shipment_id' => $shipment->id,
            'to_warehouse_id' => $stop->warehouse_id,
            'actor_id' => $actor->id,
            'received_at' => $at,
            'note' => $note,
            'operation_key' => $key,
            'request_hash' => $hash,
        ]);
        $items = $lines->map(function (array $line) use ($receipt, $stop, $transit) {
            $item = $transit[$line['transit_item_id']];
            $remaining = $item->remaining_quantity - $line['quantity'];
            $item->update([
                'remaining_quantity' => $remaining,
                'status' => $remaining === 0 ? ShipmentTransitItem::RECEIVED : ShipmentTransitItem::IN_TRANSIT,
            ]);

            return $receipt->items()->create([
                'shipment_stop_id' => $stop->id,
                'shipment_transit_item_id' => $item->id,
                'transfer_intent_id' => $item->transfer_intent_id,
                'to_warehouse_id' => $item->to_warehouse_id,
                'product_id' => $item->product_id,
                'received_quantity' => $line['quantity'],
                'pending_putaway_quantity' => $line['quantity'],
                'note' => $line['note'],
            ]);
        });
        $receipt->setRelation('shipment', $shipment);
        $this->inventory->bookShipmentReceipt($receipt, $items);

        $remaining = $transit->sum('remaining_quantity');
        $received = $transit->sum('dispatched_quantity') - $remaining;
        $stop->receiving_version++;
        if ($remaining === 0) {
            $stop->fill(['received_at' => $at, 'received_by' => $actor->id]);
        }
        $stop->save();

        $shipment->version++;
        if ($remaining === 0 && !$shipment->transitItems()->where('remaining_quantity', '>', 0)->sharedLock()->first(['id'])) {
            $shipment->fill(['status' => 'completed', 'finished_at' => $at]);
        }
        $shipment->save();

        $receipt->update(['result' => $this->result($receipt, $shipment, $stop, $items, $transit, $remaining, $received)]);
        return $receipt->refresh()->result;
    }

    private function result(
        ShipmentReceipt $receipt, Shipment $shipment, ShipmentStop $stop, Collection $items, Collection $transit,
        int $remaining, int $received,
    ): array {
        $movementIds = InventoryMovement::whereIn('shipment_receipt_item_id', $items->pluck('id'))
            ->sharedLock()->pluck('id', 'shipment_receipt_item_id');

        return [
            'shipment' => [
                'id' => $shipment->id, 'status' => $shipment->status, 'version' => $shipment->version,
                'finished_at' => $shipment->finished_at?->toISOString(),
            ],
            'stop' => [
                'id' => $stop->id, 'warehouse_id' => (int) $stop->warehouse_id, 'position' => $stop->position,
                'version' => $stop->receiving_version, 'receiving_status' => $remaining === 0 ? 'received' : 'partial',
                'received_at' => $stop->received_at?->toISOString(), 'received_by' => $stop->received_by,
                'received_quantity' => $received, 'remaining_quantity' => $remaining,
            ],
            'receipt' => [
                'id' => $receipt->id, 'shipment_id' => (int) $receipt->shipment_id, 'shipment_stop_id' => (int) $receipt->shipment_stop_id,
                'to_warehouse_id' => (int) $receipt->to_warehouse_id, 'actor_id' => (int) $receipt->actor_id,
                'received_at' => $receipt->received_at->toISOString(), 'note' => $receipt->note,
            ],
            'items' => $items->map(fn ($line) => [
                'id' => $line->id, 'transit_item_id' => (int) $line->shipment_transit_item_id,
                'transfer_intent_id' => (int) $line->transfer_intent_id, 'product_id' => (int) $line->product_id,
                'received_quantity' => $line->received_quantity, 'pending_putaway_quantity' => $line->pending_putaway_quantity,
                'remaining_quantity' => $transit[$line->shipment_transit_item_id]->remaining_quantity,
                'movement_id' => (int) $movementIds[$line->id], 'note' => $line->note,
            ])->values()->all(),
        ];
    }

    private function findReceipt(string $key, bool $current): ?ShipmentReceipt
    {
        $query = ShipmentReceipt::where('operation_key', $key);
        return ($current ? $query->sharedLock() : $query)->first();
    }

    private function replay(ShipmentReceipt $prior, string $hash): array
    {
        if (!hash_equals($prior->request_hash, $hash)) {
            throw new ConflictHttpException('Ez a műveleti azonosító már másik átvételi kéréshez tartozik.');
        }

        return $prior->result;
    }

    private function validate(array $input): array
    {
        return Validator::make($input, [
            'operation_key' => 'required|string|uuid',
            'version' => ['required', $this->positiveInteger(self::MAX_VERSION, 'Add meg a célmegálló aktuális átvételi verzióját.')],
            'confirmed' => 'required|accepted',
            'note' => 'nullable|string|max:500',
            'items' => 'required|array|list|min:1|max:1000',
            'items.*' => 'required|array:transit_item_id,quantity,note',
            'items.*.transit_item_id' => ['required', $this->positiveInteger(PHP_INT_MAX, 'Válassz érvényes tranzittételt.'), 'distinct'],
            'items.*.quantity' => ['required', $this->positiveInteger(2147483647, 'Az átvett mennyiség pozitív egész darabszám legyen.')],
            'items.*.note' => 'nullable|string|max:500',
            'warehouse_id' => 'missing',
            'to_warehouse_id' => 'missing',
        ], [
            'operation_key.*' => 'Adj meg érvényes műveleti azonosítót (UUID).',
            'version.required' => 'Add meg a célmegálló aktuális átvételi verzióját.',
            'confirmed.*' => 'Erősítsd meg a megadott áru tényleges fizikai átvételét.',
            'note.*' => 'Az átvételi megjegyzés legfeljebb 500 karakter lehet.',
            'items.required' => 'Adj meg legalább egy tranzittételt.',
            'items.array' => 'Az átvett tételeket listában add meg.',
            'items.list' => 'Az átvett tételeket listában add meg.',
            'items.min' => 'Adj meg legalább egy tranzittételt.',
            'items.max' => 'Egy átvételhez legfeljebb 1000 tranzittételt adhatsz meg.',
            'items.*.required' => 'Add meg a tranzittételt és az átvett mennyiséget.',
            'items.*.array' => 'A tételhez csak a tranzittétel azonosítóját, a mennyiséget és a megjegyzést add meg.',
            'items.*.transit_item_id.required' => 'Válassz érvényes tranzittételt.',
            'items.*.transit_item_id.distinct' => 'Minden tranzittételt legfeljebb egyszer adj meg.',
            'items.*.quantity.required' => 'Add meg az átvett darabszámot.',
            'items.*.note.*' => 'A tétel megjegyzése legfeljebb 500 karakter lehet.',
            'warehouse_id.missing' => 'A célraktárt a fuvar megállója határozza meg.',
            'to_warehouse_id.missing' => 'A célraktárt a fuvar megállója határozza meg.',
        ])->validate();
    }

    private function positiveInteger(int $max, string $message): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($max, $message): void {
            $integer = is_int($value) || (is_string($value) && ctype_digit($value) && filter_var($value, FILTER_VALIDATE_INT) !== false);
            if (!$integer || (int) $value < 1 || (int) $value > $max) $fail($message);
        };
    }

    private function note(?string $value): ?string
    {
        $trimmed = Str::trim($value ?? '');
        return $trimmed === '' ? null : $trimmed;
    }
}
