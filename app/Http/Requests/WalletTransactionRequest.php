<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WalletTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; 
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'type' => ['required', 'in:credit,debit'],
            'reference' => ['nullable', 'string'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
