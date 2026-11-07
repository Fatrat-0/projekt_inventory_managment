<?php

namespace App\Http\Requests;

use App\Models\WarehouseLocation;
use App\Services\WarehouseCapacity;
use Illuminate\Foundation\Http\FormRequest;

class ShipmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        if (!$this->exists('stops_json')) return;

        // One serialized field keeps large route plans below PHP's form-field limit.
        $this->merge(['stops' => self::decodeStops($this->input('stops_json'))]);
        $this->offsetUnset('stops_json');
    }

    public static function decodeStops(mixed $json): ?array
    {
        $stops = is_string($json) ? json_decode($json, true, 8) : null;
        if (!is_array($stops)) return null;

        array_walk_recursive($stops, function (&$value) {
            if (!is_string($value)) return;
            $value = trim($value);
            if ($value === '') $value = null;
        });
        return $stops;
    }

    public function rules(): array
    {
        return [
            'reference' => 'required|string|max:100', 'vehicle_label' => 'nullable|string|max:100', 'note' => 'nullable|string|max:1000',
            'origin_warehouse_id' => 'required|integer|exists:warehouses,warehouse_id',
            'version' => 'sometimes|required|integer|min:1',
            'stops' => 'required|array|min:1|max:20',
            'stops.*.warehouse_id' => 'required|integer|exists:warehouses,warehouse_id|different:origin_warehouse_id|distinct',
            'stops.*.items' => 'required|array|min:1|max:50',
            'stops.*.items.*.product_id' => 'required|integer|exists:products,product_id',
            'stops.*.items.*.quantity' => 'required|integer|min:1|max:2147483647',
        ] + WarehouseCapacity::cargoRules('stops.*.items.*.');
    }
    public function after(): array
    {
        return [function ($validator) {
            $locations = WarehouseLocation::whereIntegerInRaw('id', array_filter(data_get($this->input(), 'stops.*.items.*.to_location_id', []),
                fn ($id) => is_scalar($id) && filter_var($id, FILTER_VALIDATE_INT) !== false))
                ->pluck('warehouse_id', 'id');
            foreach ((array)$this->input('stops', []) as $index => $stop) {
                if (!is_array($stop) || !is_array($stop['items'] ?? null)) continue;
                $seen = [];
                foreach ($stop['items'] as $row => $item) {
                    if (!is_array($item) || !is_scalar($item['product_id'] ?? null)) continue;
                    $id = (string)(int)$item['product_id'];
                    if (isset($seen[$id])) $validator->errors()->add("stops.$index.items.$row.product_id", 'Egy megállón belül egy terméket csak egyszer adj meg.');
                    $seen[$id] = true;
                    $locationId = $item['to_location_id'] ?? null;
                    if (is_scalar($locationId) && filter_var($locationId, FILTER_VALIDATE_INT) !== false
                        && (int) ($locations[(int) $locationId] ?? 0) !== (int) ($stop['warehouse_id'] ?? 0)) {
                        $validator->errors()->add("stops.$index.items.$row.to_location_id", 'A tárhelynek a megálló célraktárához kell tartoznia.');
                    }
                }
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'stops.*.items.*.to_location_id' => 'cél tárhely',
            'stops.*.items.*.cargo_weight_kg' => 'teljes tömeg',
            'stops.*.items.*.pallet_slots' => 'raklapok száma',
        ];
    }

    public function messages(): array
    {
        return [
            'reference.required' => 'Adj meg hivatkozást a szállítmányhoz.',
            'reference.max' => 'A hivatkozás legfeljebb 100 karakter lehet.',
            'vehicle_label.max' => 'A jármű megnevezése legfeljebb 100 karakter lehet.',
            'note.max' => 'A megjegyzés legfeljebb 1000 karakter lehet.',
            'origin_warehouse_id.required' => 'Válassz induló raktárt.',
            'origin_warehouse_id.exists' => 'Az induló raktár már nem érhető el.',
            'stops.required' => 'Adj meg legalább egy megállót.',
            'stops.min' => 'Adj meg legalább egy megállót.',
            'stops.max' => 'Legfeljebb 20 megálló tervezhető.',
            'stops.*.warehouse_id.required' => 'Minden megállóhoz válassz célraktárt.',
            'stops.*.warehouse_id.exists' => 'Az egyik célraktár már nem érhető el.',
            'stops.*.warehouse_id.distinct' => 'Egy célraktár csak egyszer szerepelhet az útvonalban.',
            'stops.*.warehouse_id.different' => 'A célraktár nem egyezhet meg az induló raktárral.',
            'stops.*.items.required' => 'Minden megállóhoz adj meg legalább egy terméket.',
            'stops.*.items.min' => 'Minden megállóhoz adj meg legalább egy terméket.',
            'stops.*.items.max' => 'Egy megállóhoz legfeljebb 50 termék adható meg.',
            'stops.*.items.*.product_id.required' => 'Minden rakománytételhez válassz terméket.',
            'stops.*.items.*.product_id.exists' => 'Az egyik kiválasztott termék már nem érhető el.',
            'stops.*.items.*.quantity.required' => 'Minden termékhez adj meg mennyiséget.',
            'stops.*.items.*.quantity.integer' => 'A mennyiség csak egész szám lehet.',
            'stops.*.items.*.quantity.min' => 'A mennyiség legalább 1 db legyen.',
            'stops.*.items.*.quantity.max' => 'A mennyiség legfeljebb 2 147 483 647 db lehet.',
        ];
    }
}
