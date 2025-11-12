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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(VpayTransaction::class, 'wallet_id');
    }

    
}
