<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncCarerSubscriptionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'platform' => ['required', 'in:ios'],
            'product_id' => ['required', 'string'],
            'transaction_id' => ['required', 'string'],
            'expiration_date' => ['nullable', 'date'],
            'app_store_receipt_base64' => ['nullable', 'string'],
        ];
    }
}