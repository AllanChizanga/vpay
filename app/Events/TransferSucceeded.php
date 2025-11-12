<?php
namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransferSucceeded
{
    use Dispatchable, SerializesModels;

    public string $senderWalletId;
    public string $receiverWalletId;
    public string $senderTransactionId;
    public string $receiverTransactionId;

    public function __construct(string $senderWalletId, string $receiverWalletId, string $senderTransactionId, string $receiverTransactionId)
    {
        $this->senderWalletId = $senderWalletId;
        $this->receiverWalletId = $receiverWalletId;
        $this->senderTransactionId = $senderTransactionId;
        $this->receiverTransactionId = $receiverTransactionId;
    }
}


   

