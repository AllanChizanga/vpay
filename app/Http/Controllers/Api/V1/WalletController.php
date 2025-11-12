<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\WalletTransactionRequest;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;

class WalletController extends Controller
{
    protected WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    /**
     * Show a user's wallet
     */
    public function show(string $userId): JsonResponse
    {
        $wallet = $this->walletService->getUserWallet($userId);

        return response()->json([
            'wallet' => $wallet,
        ]);
    }

    /**
     * Credit a user's wallet
     */
    public function credit(WalletTransactionRequest $request): JsonResponse
    {
        $data = $request->validated();

        $wallet = $this->walletService->getUserWallet($data['user_id']);

        // Deposit returns a VpayTransaction
        $transaction = $this->walletService->deposit(
            $wallet,
            $data['amount'],
            $data['reference'] ?? null
        );

        return response()->json([
            'message' => 'Wallet credited successfully',
            'transaction' => $transaction,
        ]);
    }

    /**
     * Debit a user's wallet
     */
    public function debit(WalletTransactionRequest $request): JsonResponse
    {
        $data = $request->validated();

        $wallet = $this->walletService->getUserWallet($data['user_id']);

        // Withdraw returns a VpayTransaction
        $transaction = $this->walletService->withdraw(
            $wallet,
            $data['amount'],
            $data['reference'] ?? null
        );

        return response()->json([
            'message' => 'Wallet debited successfully',
            'transaction' => $transaction,
        ]);
    }
}
