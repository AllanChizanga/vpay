<?php
namespace App\Models;

class VpayLedgerEntry extends BaseModel
{
    protected $table = 'vpay_ledger_entries';

    protected $fillable = [
        'id',
        'wallet_id',
        'transaction_id',  // vpay_transactions.id
        'entry_type',      // 'debit'|'credit'
        'amount',          // decimal string
        'balance',         // wallet balance after entry
        'narration',
        'meta',            
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function wallet()
    {
        return $this->belongsTo(VpayWallet::class, 'wallet_id');
    }

    public function transaction()
    {
        return $this->belongsTo(VpayTransaction::class, 'transaction_id');
    }
}
