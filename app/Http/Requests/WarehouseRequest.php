<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WarehouseRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['warehouse_name' => 'required|string|max:100', 'location' => 'required|string|max:255'];
    }

    public function attributes(): array
    {
        return ['warehouse_name' => 'raktár neve', 'location' => 'cím / lokáció'];
    }
}
