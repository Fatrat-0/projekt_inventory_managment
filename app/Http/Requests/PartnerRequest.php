<?php

namespace App\Http\Requests;

use App\Models\Partner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PartnerRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function messages(): array { return \App\Services\MasterDataEditor::messages(); }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(array_keys(Partner::TYPES))],
            'email' => ['nullable', 'string', 'email', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
            'street_address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'confirm_duplicates' => ['nullable', 'boolean'],
            'duplicate_token' => ['nullable', 'string', 'max:64'],
        ] + ($this->isMethod('PUT') || $this->isMethod('PATCH') ? \App\Services\MasterDataEditor::rules() : []);
    }

    public function attributes(): array
    {
        return ['name' => 'név / cégnév', 'type' => 'partner típusa', 'email' => 'e-mail-cím', 'phone' => 'telefonszám',
            'contact_name' => 'kapcsolattartó', 'country' => 'ország', 'postal_code' => 'irányítószám',
            'city' => 'település', 'street_address' => 'utca, házszám', 'notes' => 'belső megjegyzés',
            'confirm_duplicates' => 'egyezések megerősítése', 'duplicate_token' => 'egyezések megerősítése'] + \App\Services\MasterDataEditor::attributes();
    }
}
