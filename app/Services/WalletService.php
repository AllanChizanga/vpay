<?php

namespace App\Services;

use App\Models\VpayWallet;
use App\Models\VpayTransaction;
use App\Models\VpayLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use App\Events\WalletCredited;
use App\Events\WalletDebited;
use App\Events\TransferSucceeded;
use Exception;

class WalletService
{
    /**
     * Get or create a user's wallet
     */
    public function getUserWallet(string $userId, ?string $currency = null): VpayWallet
    {
        $currency = $currency ?: config('currency.allowed')[0];

        return VpayWallet::firstOrCreate(
            ['user_id' => $userId],
            [
                'balance' => '0.00',
                'currency' => $currency,
                'version' => 0,
            ]
        );
    }

    /**
     * Normalize amount as decimal string
     */
    protected function normalizeAmount($amount): string
    {
        
        return bcadd((string)$amount, '0', VpayWallet::MONEY_SCALE);
    }


    /**
     * Deposit / Credit
     */
    public function deposit(
        VpayWallet $wallet,
        $amount,
        ?string $notes = null,
        ?string $idempotencyKey = null,
        ?string $currency = null
    ): VpayTransaction {

        // Validate currency
        $currency = $currency ?: $wallet->currency;
        $this->validateCurrency($wallet, $currency);

        // Convert to decimal string safely
        $amountStr = $this->normalizeAmount($amount);

        if (bccomp($amountStr, '0', VpayWallet::MONEY_SCALE) !== 1) {
            throw new Exception('Deposit amount must be positive.');
        }

        return DB::transaction(function () use ($wallet, $amountStr, $notes, $idempotencyKey) {

      
            if ($idempotencyKey) {
                $existing = VpayTransaction::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) return $existing;
            }

            $before = $wallet->balance ?? '0.00';
            $after  = bcadd($before, $amountStr, VpayWallet::MONEY_SCALE);
            $currentVersion = $wallet->version ?? 0;

            // Optimistic Locking
            $updated = VpayWallet::where('id', $wallet->id)
                ->where('version', $currentVersion)
                ->update([
                    'balance' => $after,
                    'version' => $currentVersion + 1
                ]);

            if ($updated === 0) {
                throw new Exception('Concurrent wallet update detected. Please retry.');
            }

            // Update in-memory model
            $wallet->balance = $after;
            $wallet->version = $currentVersion + 1;

            // Create transaction
            $txn = VpayTransaction::create([
                'wallet_id' => $wallet->id,
                'amount' => $amountStr,
                'transaction_type' => 'cashin',
                'notes' => $notes ?? 'Deposit',
                'balance_before' => $before,
                'balance_after' => $after,
                'idempotency_key' => $idempotencyKey,
            ]);

            // Ledger
            VpayLedgerEntry::create([
                'wallet_id' => $wallet->id,
                'transaction_id' => $txn->id,
                'entry_type' => 'credit',
                'amount' => $amountStr,
                'balance' => $after,
                'narration' => 'Wallet deposit (credit)',
            ]);

            // Dispatch event after commit
            DB::afterCommit(function () use ($wallet, $txn) {
                Event::dispatch(new WalletCredited($wallet->id, $txn->id));
                $wallet->forgetCache();
                $wallet->refreshCache();
            });

            return $txn;
        });
    }


    /**
     * Withdraw / Debit
     */
    public function withdraw(
        VpayWallet $wallet,
        $amount,
        ?string $notes = null,
        ?string $idempotencyKey = null,
        ?string $currency = null
    ): VpayTransaction {

        // Validate currency
        $currency = $currency ?: $wallet->currency;
        $this->validateCurrency($wallet, $currency);

        $amountStr = $this->normalizeAmount($amount);

        if (bccomp($amountStr, '0', VpayWallet::MONEY_SCALE) !== 1) {
            throw new Exception('Withdrawal amount must be positive.');
        }

        return DB::transaction(function () use ($wallet, $amountStr, $notes, $idempotencyKey) {

            // Idempotency
            if ($idempotencyKey) {
                $existing = VpayTransaction::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) return $existing;
            }

            $before = $wallet->balance ?? '0.00';

            if (bccomp($before, $amountStr, VpayWallet::MONEY_SCALE) === -1) {
                throw new Exception('Insufficient balance.');
            }

            $after = bcsub($before, $amountStr, VpayWallet::MONEY_SCALE);
            $currentVersion = $wallet->version ?? 0;

            $updated = VpayWallet::where('id', $wallet->id)
                ->where('version', $currentVersion)
                ->update([
                    'balance' => $after,
                    'version' => $currentVersion + 1
                ]);

            if ($updated === 0) {
                throw new Exception('Concurrent wallet update detected. Please retry.');
            }

            $wallet->balance = $after;
            $wallet->version = $currentVersion + 1;

            $txn = VpayTransaction::create([
                'wallet_id' => $wallet->id,
                'amount' => $amountStr,
                'transaction_type' => 'cashout',
                'notes' => $notes ?? 'Withdrawal',
                'balance_before' => $before,
                'balance_after' => $after,
                'idempotency_key' => $idempotencyKey,
            ]);

            // Ledger
            VpayLedgerEntry::create([
                'wallet_id' => $wallet->id,
                'transaction_id' => $txn->id,
                'entry_type' => 'debit',
                'amount' => $amountStr,
                'balance' => $after,
                'narration' => 'Wallet withdrawal (debit)',
            ]);

            DB::afterCommit(function () use ($wallet, $txn) {
                Event::dispatch(new WalletDebited($wallet->id, $txn->id));
                $wallet->forgetCache();
                $wallet->refreshCache();
            });

            return $txn;
        });
    }


    /**
     * Transfer
     */
    public function transfer(
        VpayWallet $sender,
        VpayWallet $receiver,
        $amount,
        ?string $notes = null,
        ?string $idempotencyKey = null
    ): array {

        if ($sender->id === $receiver->id) {
            throw new Exception('Cannot transfer to the same wallet.');
        }

        // FIXED: double-check currency on both wallets
        $this->validateCurrency($sender, $sender->currency);
        $this->validateCurrency($receiver, $receiver->currency);

        if ($sender->currency !== $receiver->currency) {
            throw new Exception('Currency mismatch between sender and receiver.');
        }

        $amountStr = $this->normalizeAmount($amount);

        if (bccomp($amountStr, '0', VpayWallet::MONEY_SCALE) !== 1) {
            throw new Exception('Transfer amount must be positive.');
        }

        return DB::transaction(function () use ($sender, $receiver, $amountStr, $notes, $idempotencyKey) {

            $senderBefore = $sender->balance ?? '0.00';

            if (bccomp($senderBefore, $amountStr, VpayWallet::MONEY_SCALE) === -1) {
                throw new Exception('Insufficient balance in sender wallet.');
            }

            $receiverBefore = $receiver->balance ?? '0.00';

            $senderAfter = bcsub($senderBefore, $amountStr, VpayWallet::MONEY_SCALE);
            $receiverAfter = bcadd($receiverBefore, $amountStr, VpayWallet::MONEY_SCALE);

            // Update sender first (optimistic locking)
            $u1 = VpayWallet::where('id', $sender->id)
                ->where('version', $sender->version)
                ->update([
                    'balance' => $senderAfter,
                    'version' => $sender->version + 1
                ]);

            if ($u1 === 0) {
                throw new Exception('Concurrent update on sender wallet.');
            }

            // Update receiver
            $u2 = VpayWallet::where('id', $receiver->id)
                ->where('version', $receiver->version)
                ->update([
                    'balance' => $receiverAfter,
                    'version' => $receiver->version + 1
                ]);

            if ($u2 === 0) {
                throw new Exception('Concurrent update on receiver wallet.');
            }

            // Update in-memory
            $sender->balance = $senderAfter;
            $sender->version++;
            $receiver->balance = $receiverAfter;
            $receiver->version++;

            $senderTxn = VpayTransaction::create([
                'wallet_id' => $sender->id,
                'amount' => $amountStr,
                'transaction_type' => 'cashout',
                'notes' => $notes ?? "Transfer to wallet {$receiver->id}",
                'balance_before' => $senderBefore,
                'balance_after' => $senderAfter,
                'receiver_id' => $receiver->user_id,
                'idempotency_key' => $idempotencyKey,
            ]);

            $receiverTxn = VpayTransaction::create([
                'wallet_id' => $receiver->id,
                'amount' => $amountStr,
                'transaction_type' => 'cashin',
                'notes' => $notes ?? "Transfer from wallet {$sender->id}",
                'balance_before' => $receiverBefore,
                'balance_after' => $receiverAfter,
                'sender_id' => $sender->user_id,
                'idempotency_key' => $idempotencyKey,
            ]);

            // Ledger entries
            VpayLedgerEntry::create([
                'wallet_id' => $sender->id,
                'transaction_id' => $senderTxn->id,
                'entry_type' => 'debit',
                'amount' => $amountStr,
                'balance' => $senderAfter,
                'narration' => 'Transfer out',
            ]);

            VpayLedgerEntry::create([
                'wallet_id' => $receiver->id,
                'transaction_id' => $receiverTxn->id,
                'entry_type' => 'credit',
                'amount' => $amountStr,
                'balance' => $receiverAfter,
                'narration' => 'Transfer in',
            ]);

            DB::afterCommit(function () use ($sender, $receiver, $senderTxn, $receiverTxn) {
                Event::dispatch(new TransferSucceeded(
                    $sender->id,
                    $receiver->id,
                    $senderTxn->id,
                    $receiverTxn->id
                ));

                $sender->forgetCache();
                $sender->refreshCache();

                $receiver->forgetCache();
                $receiver->refreshCache();
            });

            return [$senderTxn, $receiverTxn];
        });
    }


    /**
     * Validate wallet currency
     */
    private function validateCurrency(VpayWallet $wallet, string $currency)
    {
        $allowed = config('currency.allowed');

        if (!is_string($currency) || !in_array($currency, $allowed, true)) {
            throw new Exception("Invalid currency: {$currency}");
        }

        if ($wallet->currency !== $currency) {
            throw new Exception(
                "Currency mismatch: wallet is {$wallet->currency}, attempted {$currency}"
            );
        }
    }
}
