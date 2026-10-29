<?php

namespace App\Support;

use Illuminate\Http\Request;

class WarehouseInventoryPdfOptions
{
    public const DEFAULTS = [
        'orientation' => 'portrait',
        'show_sku' => true,
        'show_category' => false,
        'show_count_column' => true,
        'show_note_column' => false,
        'show_filters' => true,
        'show_signatures' => true,
        'blind_mode' => false,
    ];

    public static function fromRequest(Request $request): array
    {
        $rules = ['preview' => 'nullable|boolean', 'orientation' => 'nullable|in:portrait,landscape'];
        foreach (self::DEFAULTS as $key => $value) if (is_bool($value)) $rules[$key] = 'sometimes|boolean';
        $data = $request->validate($rules);
        $options = self::DEFAULTS;
        $options['orientation'] = $data['orientation'] ?? self::DEFAULTS['orientation'];
        foreach (self::DEFAULTS as $key => $value) if (is_bool($value)) $options[$key] = $request->boolean($key, $value);
        if ($options['blind_mode']) $options['show_count_column'] = true;
        return $options;
    }
}
