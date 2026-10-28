<?php

namespace App\Support;

use Illuminate\Http\Request;

class LoadingPlanPdfOptions
{
    public const DEFAULTS = [
        'orientation' => 'portrait',
        'show_route' => true,
        'show_sku' => true,
        'show_addresses' => true,
        'show_note' => true,
        'show_loading_column' => false,
        'show_signatures' => false,
    ];

    public static function fromRequest(Request $request): array
    {
        $rules = ['preview' => 'nullable|boolean', 'orientation' => 'nullable|in:portrait,landscape'];
        foreach (self::DEFAULTS as $key => $value) if (is_bool($value)) $rules[$key] = 'sometimes|boolean';
        $data = $request->validate($rules);
        $options = self::DEFAULTS;
        $options['orientation'] = $data['orientation'] ?? self::DEFAULTS['orientation'];
        foreach (self::DEFAULTS as $key => $value) if (is_bool($value)) $options[$key] = $request->boolean($key, $value);
        return $options;
    }
}
