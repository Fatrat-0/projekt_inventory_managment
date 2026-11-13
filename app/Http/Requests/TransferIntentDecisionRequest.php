<?php

namespace App\Http\Requests;

use App\Services\TransferIntentService;
use Illuminate\Foundation\Http\FormRequest;

class TransferIntentDecisionRequest extends FormRequest
{
    public function rules(): array
    {
        return TransferIntentService::decisionRules($this->routeIs('transfer-intents.reject', 'transfer-intents.cancel'));
    }

    public function messages(): array
    {
        return TransferIntentService::decisionMessages();
    }
}
