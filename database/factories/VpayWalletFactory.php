<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VpayWallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class VpayWalletFactory extends Factory
{
    protected $model = VpayWallet::class;

    public function definition()
    {
        return [
            'id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'balance' => '0.00',
            'version' => 0,
        ];
    }
}
