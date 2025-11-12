<?php

namespace Database\Factories;

use App\Models\VpayTransaction;
use App\Models\VpayWallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class VpayTransactionFactory extends Factory
{
    protected $model = VpayTransaction::class;

    public function definition()
    {
        return [
            'id' => (string) Str::uuid(),
            'wallet_id' => VpayWallet::factory(),
            'amount' => '0.00',
            'transaction_type' => 'cashin',
            'balance_before' => '0.00',
            'balance_after' => '0.00',
            'notes' => null,
            'idempotency_key' => null,
            'receiver_id' => null,
        ];
    }
}
