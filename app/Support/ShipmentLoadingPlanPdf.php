<?php

namespace App\Support;

use App\Models\{Product, Shipment, ShipmentIntentAssignment, ShipmentItem, ShipmentStop};
use Closure;
use Illuminate\Support\Collection;

class ShipmentLoadingPlanPdf
{
    public static function forShipment(Shipment $shipment): array
    {
        if ($shipment->isTransferIntentPlan()) {
            return $shipment->status === 'draft' ? self::draft($shipment) : self::dispatched($shipment);
        }

        $shipment->loadMissing(['origin', 'stops.warehouse', 'stops.items.product']);

        return self::heading($shipment, $shipment->vehicle_label) + self::route($shipment->stops, fn (ShipmentStop $stop) =>
            $stop->items->map(fn (ShipmentItem $item) => [
                'name' => $item->product->product_name, 'sku' => $item->product->sku, 'quantity' => $item->quantity,
            ])->all());
    }

    private static function draft(Shipment $shipment): array
    {
        $groups = $shipment->intentAssignments()->active()->orderBy('id')->with('intent.product')->get()->groupBy('shipment_stop_id');
        abort_if($groups->isEmpty(), 404, 'A rakodási tervhez legalább egy aktív fuvarigény szükséges.');
        $stops = $shipment->stops()->whereKey($groups->keys())->with('warehouse')->get();
        $shipment->loadMissing(['origin', 'vehicle']);

        return self::heading($shipment, self::vehicleLabel($shipment->vehicle?->capacitySnapshot()) ?? $shipment->vehicle_label)
            + self::route($stops, fn (ShipmentStop $stop) => $groups[$stop->id]->map(fn (ShipmentIntentAssignment $assignment) => [
                'name' => $assignment->intent->product->product_name, 'sku' => $assignment->intent->product->sku,
                'quantity' => $assignment->intent->quantity, 'intent_reference' => $assignment->intent->reference,
            ])->all());
    }

    private static function dispatched(Shipment $shipment): array
    {
        $dispatch = $shipment->dispatch()->first();
        $snapshot = $dispatch?->snapshot ?? [];
        $intents = collect($snapshot['intents'] ?? []);
        $stops = collect($snapshot['stops'] ?? []);
        $groups = $intents->groupBy('shipment_stop_id');
        abort_if($stops->isEmpty() || $groups->isEmpty() || $stops->pluck('id')->diff($groups->keys())->isNotEmpty()
            || $groups->keys()->diff($stops->pluck('id'))->isNotEmpty(), 404, 'A fuvarhoz nem érhető el teljes feladási pillanatkép.');

        // A previously dispatched 4/B shipment may have recorded only the product ID.
        $missingLabels = $intents->filter(fn ($intent) => !isset($intent['product_name'], $intent['sku']))->pluck('product_id')->unique();
        $products = $missingLabels->isEmpty() ? collect() : Product::whereKey($missingLabels)->get(['product_id', 'product_name', 'sku'])->keyBy('product_id');
        $byId = $stops->mapWithKeys(fn ($stop) => [$stop['id'] => [
            'name' => $stop['warehouse_name'], 'location' => $stop['warehouse_location'] ?? null,
            'position' => $stop['position'], 'received' => false,
            'items' => $groups[$stop['id']]->map(function ($intent) use ($products) {
                $product = $products->get($intent['product_id']);

                return [
                    'name' => $intent['product_name'] ?? $product?->product_name ?? 'Termék #'.$intent['product_id'],
                    'sku' => $intent['sku'] ?? $product?->sku ?? '—',
                    'quantity' => $intent['quantity'], 'intent_reference' => $intent['reference'],
                ];
            })->all(),
        ]]);

        return [
            'shipment_number' => $snapshot['shipment']['shipment_number'], 'reference' => $snapshot['shipment']['reference'],
            'version' => $snapshot['readiness']['version'] ?? $shipment->version, 'status' => $shipment->status,
            'origin' => $snapshot['shipment']['origin_warehouse_name'], 'location' => $snapshot['shipment']['origin_warehouse_location'] ?? null,
            'vehicle' => self::vehicleLabel($snapshot['vehicle'] ?? null), 'note' => $dispatch->note,
            'stops' => $stops->sortBy('position')->map(fn ($stop) => $byId[$stop['id']])->values()->all(),
            'loading_stops' => $stops->sortBy('loading_position')->map(fn ($stop) =>
                $byId[$stop['id']] + ['loading_position' => $stop['loading_position']])->values()->all(),
        ];
    }

    private static function heading(Shipment $shipment, ?string $vehicle): array
    {
        return [
            'shipment_number' => $shipment->shipment_number, 'reference' => $shipment->reference,
            'version' => $shipment->version, 'status' => $shipment->status,
            'origin' => $shipment->origin->warehouse_name, 'location' => $shipment->origin->location,
            'vehicle' => $vehicle, 'note' => $shipment->note,
        ];
    }

    private static function route(Collection $stops, Closure $items): array
    {
        $byId = $stops->mapWithKeys(fn (ShipmentStop $stop) => [$stop->id => [
            'name' => $stop->warehouse->warehouse_name, 'location' => $stop->warehouse->location,
            'position' => $stop->position, 'received' => $stop->received_at !== null, 'items' => $items($stop),
        ]]);

        return [
            'stops' => $byId->values()->all(),
            'loading_stops' => Shipment::loadingStops($stops)->values()->map(fn (ShipmentStop $stop, int $index) =>
                $byId[$stop->id] + ['loading_position' => $index + 1])->all(),
        ];
    }

    private static function vehicleLabel(?array $vehicle): ?string
    {
        if (!isset($vehicle['name'])) return null;

        return $vehicle['name'].(empty($vehicle['registration_number']) ? '' : ' · '.$vehicle['registration_number']);
    }
}
