<?php

namespace App\Http\Requests;

use App\Models\Partner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PartnerRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(array_keys(Partner::TYPES))],
            'email' => ['nullable', 'string', 'email', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'confirm_duplicates' => ['nullable', 'boolean'],
            'duplicate_token' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'név / cégnév', 'type' => 'partner típusa', 'email' => 'e-mail-cím', 'phone' => 'telefonszám',
            'confirm_duplicates' => 'egyezések megerősítése', 'duplicate_token' => 'egyezések megerősítése'];
    }
}
