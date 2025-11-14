<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\WalletTransactionRequest;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;

class WalletController extends Controller
{
    protected WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    /**
     * Show the authenticated user's wallet
     */
    public function show(Request $request, string $userId): JsonResponse
    {
        try {
            // Retrieve user injected by VerifyAuthToken middleware
            $authUser = $request->get('auth_user');
            $authUserId = (string) data_get($authUser, 'id');

            // Ensure user can only access their own wallet
            if ($authUserId !== (string) $userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden: cannot access this wallet',
                ], 403);
            }

            $wallet = $this->walletService->getUserWallet($authUserId);

            return response()->json([
                'success' => true,
                'wallet' => $wallet,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Credit the authenticated user's wallet
     */
    public function credit(WalletTransactionRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            // Retrieve user injected by middleware
            $authUser = $request->get('auth_user');
            $authUserId = (string) data_get($authUser, 'id');

            $wallet = $this->walletService->getUserWallet(
                $authUserId,
                $data['currency'] ?? null
            );

            $transaction = $this->walletService->deposit(
                $wallet,
                $data['amount'],
                $data['reference'] ?? null,
                $data['idempotency_key'] ?? null,
                $data['currency'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Wallet credited successfully',
                'transaction' => $transaction,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Debit the authenticated user's wallet
     */
    public function debit(WalletTransactionRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            // Retrieve authenticated user
            $authUser = $request->get('auth_user');
            $authUserId = (string) data_get($authUser, 'id');

            $wallet = $this->walletService->getUserWallet(
                $authUserId,
                $data['currency'] ?? null
            );

            $transaction = $this->walletService->withdraw(
                $wallet,
                $data['amount'],
                $data['reference'] ?? null,
                $data['idempotency_key'] ?? null,
                $data['currency'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Wallet debited successfully',
                'transaction' => $transaction,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
