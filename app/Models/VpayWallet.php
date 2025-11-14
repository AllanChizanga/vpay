<?php
namespace App\Models;

use Exception;
use Illuminate\Support\Facades\DB;


class VpayWallet extends BaseModel
{
    protected $table = 'vpay_wallets';

    protected $fillable = [
        'id',
        'user_id',
        'currency',
        'balance',   
        'version',   
    ];

    // money decimal scale
    public const MONEY_SCALE = 8;

    protected $casts = [
        'version' => 'integer',
    ];

    // public function user()
    // {
    //     return $this->belongsTo(User::class);
    // }

    public function transactions()
    {
        return $this->hasMany(VpayTransaction::class, 'wallet_id');
    }

    public function setCurrencyAttribute($value)
{
    // Load allowed currencies from config
    $allowed = config('currency.allowed');

    if (!in_array($value, $allowed, true)) {
        throw new Exception("Unsupported currency: ".$value);
    }

    // If wallet already exists in DB, block currency changes
    if ($this->exists && $this->currency !== $value) {
        throw new Exception("Wallet currency cannot be changed once created.");
    }

    $this->attributes['currency'] = $value;
}

}
