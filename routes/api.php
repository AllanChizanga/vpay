<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\WalletController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/wallet/{userId}', [WalletController::class, 'show']);
    Route::post('/wallet/deposit', [WalletController::class, 'credit']);
    Route::post('/wallet/withdraw', [WalletController::class, 'debit']);
});
