<?php
namespace App\Models;

class VpayTransaction extends BaseModel
{
    protected $table = 'vpay_transactions';

    protected $fillable = [
        'id',
        'wallet_id',
        'amount',             
        'transaction_type',  
        'notes',
        'balance_before',
        'balance_after',
        'sender_id',
        'receiver_id',
        'idempotency_key',
        'reference',          
    ];

    public function wallet()
    {
        return $this->belongsTo(VpayWallet::class, 'wallet_id');
    }

    public function sender()
    {
        return $this->belongsTo(VpayWallet::class, 'sender_id');
    }

    public function receiver()
    {
        return $this->belongsTo(VpayWallet::class, 'receiver_id');
    }
}
