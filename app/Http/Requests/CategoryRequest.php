<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'category_name' => 'required|string|max:100',
            'parent_id' => 'nullable|integer|exists:categories,category_id',
            'default_item_min_stock' => 'nullable|integer|min:0|max:2147483647',
            'aggregate_min_stock' => 'nullable|integer|min:0|max:2147483647',
        ];
    }

    public function attributes(): array
    {
        return ['category_name' => 'kategória neve', 'parent_id' => 'szülő kategória', 'default_item_min_stock' => 'termékenkénti minimum', 'aggregate_min_stock' => 'kategória minimum'];
    }
}
