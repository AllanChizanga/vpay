<?php
namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WalletDebited
{
    use Dispatchable, SerializesModels;

    public string $walletId;
    public string $transactionId;

    public function __construct(string $walletId, string $transactionId)
    {
        $this->walletId = $walletId;
        $this->transactionId = $transactionId;
    }
}


