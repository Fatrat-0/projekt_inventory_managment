<?php

namespace App\Services;

use App\Models\{Product, Shipment, ShipmentStop, User};
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Shipment lock precedes sorted product locks; every state transition is atomic. */
class ShipmentService
{
    public function __construct(private InventoryService $inventory, private WarehouseCapacity $capacity)
    {
    }

    /** Integer actor IDs are for trusted internal jobs such as demo seeding. */
    public function save(array $data, User|int $actor, ?Shipment $shipment = null): Shipment
    {
        return DB::transaction(function () use ($data, $actor, $shipment) {
            if ($actor instanceof User) $actor = app(TransferWorkflowOperations::class)->currentActor($actor);
            if ($shipment) {
                $shipment = Shipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();
                $this->requireLegacy($shipment);
                if ($actor instanceof User) Gate::forUser($actor)->authorize('managePlan', $shipment);
                if ($shipment->status !== 'draft' || (int)($data['version'] ?? 0) !== $shipment->version) {
                    $this->fail('A tervezet közben megváltozott vagy már elindult. Frissítsd az oldalt.');
                }
                $shipment->stops()->delete();
                $shipment->version++;
            } else {
                $shipment = new Shipment(['user_id' => $actor instanceof User ? $actor->id : $actor, 'status' => 'draft', 'version' => 1]);
            }
            if ($actor instanceof User) Gate::forUser($actor)->authorize('createPlan', [Shipment::class, (int) $data['origin_warehouse_id']]);
            $shipment->fill(collect($data)->only(['origin_warehouse_id', 'reference', 'vehicle_label', 'note'])->all());
            $shipment->save();
            foreach (array_values($data['stops']) as $index => $stopData) {
                $stop = $shipment->stops()->create(['warehouse_id' => $stopData['warehouse_id'], 'position' => $index + 1]);
                foreach ($stopData['items'] as $item) {
                    $stop->items()->create(collect($item)->only(['product_id', 'quantity'])->all() + WarehouseCapacity::cargo($item));
                }
            }
            return $shipment;
        }, 3);
    }

    public function start(Shipment $shipment, User|int $actor, int $version): void
    {
        DB::transaction(function () use ($shipment, $actor, $version) {
            if ($actor instanceof User) $actor = app(TransferWorkflowOperations::class)->currentActor($actor);
            $locked = Shipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $this->requireLegacy($locked);
            if ($actor instanceof User) Gate::forUser($actor)->authorize('dispatchPlan', $locked);
            if (in_array($locked->status, ['in_transit', 'completed'], true)) return;
            if ($locked->status !== 'draft' || $locked->version !== $version) $this->fail('Csak a megtekintett, aktuális tervezet indítható el. Frissítsd az oldalt.');
            $locked->load('stops.items');
            $items = $locked->stops->flatMap->items;
            if ($items->isEmpty()) $this->fail('Üres szállítmány nem indítható.');
            $this->lockProducts($items);
            $this->capacity->lockWarehouses([$locked->origin_warehouse_id, ...$locked->stops->pluck('warehouse_id')->all()]);
            foreach ($locked->stops as $stop) {
                foreach ($stop->items as $item) {
                    $transfer = $this->inventory->startTransfer(['product_id' => $item->product_id,
                        'from_warehouse_id' => $locked->origin_warehouse_id, 'to_warehouse_id' => $stop->warehouse_id,
                        'quantity' => $item->quantity, 'user_id' => $actor instanceof User ? $actor->id : $actor] + WarehouseCapacity::cargo($item->getAttributes()));
                    $item->update(['transfer_id' => $transfer->id]);
                }
            }
            $locked->update(['status' => 'in_transit', 'started_at' => now(), 'version' => $locked->version + 1]);
        }, 3);
    }

    public function receive(Shipment $shipment, ShipmentStop $stop, User|int $actor): void
    {
        DB::transaction(function () use ($shipment, $stop, $actor) {
            if ($actor instanceof User) $actor = app(TransferWorkflowOperations::class)->currentActor($actor);
            $locked = Shipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $this->requireLegacy($locked);
            $stop = $locked->stops()->whereKey($stop->id)->firstOrFail();
            if ($actor instanceof User) Gate::forUser($actor)->authorize('receiveStop', [Shipment::class, $stop]);
            if ($stop->received_at) return;
            if ($locked->status !== 'in_transit') $this->fail('Csak úton lévő szállítmány vehető át.');
            $next = $locked->stops()->whereNull('received_at')->firstOrFail();
            if ($next->id !== $stop->id) $this->fail('Előbb az útvonal korábbi megállóját kell átvenni.');
            $stop->load('items.transfer');
            $this->lockProducts($stop->items);
            $this->capacity->lockWarehouses([$locked->origin_warehouse_id, $stop->warehouse_id]);
            foreach ($stop->items as $item) $this->inventory->finalizeTransfer($item->transfer, true, true);
            $stop->update(['received_at' => now(), 'received_by' => $actor instanceof User ? $actor->id : $actor]);
            if (!$locked->stops()->whereNull('received_at')->exists()) $locked->update(['status' => 'completed', 'finished_at' => now()]);
        }, 3);
    }

    public function cancel(Shipment $shipment, ?User $actor = null): void
    {
        DB::transaction(function () use ($shipment, $actor) {
            if ($actor) $actor = app(TransferWorkflowOperations::class)->currentActor($actor);
            $locked = Shipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $this->requireLegacy($locked);
            if ($actor) Gate::forUser($actor)->authorize('managePlan', $locked);
            if ($locked->status === 'cancelled') return;
            if ($locked->status === 'completed') $this->fail('Teljesített szállítmány nem vonható vissza.');
            if ($locked->status === 'in_transit') {
                $locked->load('stops.items.transfer');
                $pendingStops = $locked->stops->whereNull('received_at');
                $items = $pendingStops->flatMap->items;
                $this->lockProducts($items);
                $this->capacity->lockWarehouses([$locked->origin_warehouse_id, ...$pendingStops->pluck('warehouse_id')->all()]);
                foreach ($items as $item) $this->inventory->finalizeTransfer($item->transfer, false, true);
            }
            $locked->update(['status' => 'cancelled', 'finished_at' => now()]);
        }, 3);
    }

    private function lockProducts($items): void
    {
        // Lock one row at a time in ascending order, including items for multiple stops.
        foreach ($items->pluck('product_id')->unique()->sort()->values() as $id) {
            Product::whereKey($id)->lockForUpdate()->firstOrFail();
        }
    }

    private function fail(string $message): never { throw ValidationException::withMessages(['shipment' => $message]); }

    private function requireLegacy(Shipment $shipment): void
    {
        if ($shipment->isTransferIntentPlan()) {
            throw new ConflictHttpException('Az átmozgatási igényekből készült fuvarhoz ezen a végponton nem végezhető művelet.');
        }
    }
}
