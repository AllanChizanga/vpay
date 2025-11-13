<?php

namespace App\Services\Payments;

use App\Models\VpayTransaction;
use App\Models\User;

interface PaymentGatewayInterface
{
    public function initiateDeposit(User $user, float $amount, string $reference): array;

    public function verifyTransaction(string $reference): array;

    public function processWithdrawal(User $user, float $amount, string $reference): array;
}
