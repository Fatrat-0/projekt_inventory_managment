<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShipmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }
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
        ];
    }
    public function after(): array
    {
        return [function ($validator) {
            foreach ((array)$this->input('stops', []) as $index => $stop) {
                if (!is_array($stop) || !is_array($stop['items'] ?? null)) continue;
                $seen = [];
                foreach ($stop['items'] as $row => $item) {
                    if (!is_array($item) || !is_scalar($item['product_id'] ?? null)) continue;
                    $id = (string)(int)$item['product_id'];
                    if (isset($seen[$id])) $validator->errors()->add("stops.$index.items.$row.product_id", 'Egy megállón belül egy terméket csak egyszer adj meg.');
                    $seen[$id] = true;
                }
            }
        }];
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
