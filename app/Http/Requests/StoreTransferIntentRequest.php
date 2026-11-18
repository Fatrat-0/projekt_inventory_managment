<?php

namespace App\Http\Requests;

use App\Services\TransferIntentService;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransferIntentRequest extends FormRequest
{
    public function rules(): array
    {
        return TransferIntentService::creationRules();
    }

    public function messages(): array
    {
        return TransferIntentService::creationMessages();
    }
}
