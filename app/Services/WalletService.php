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

/**
 * WalletService
 *
 * - Performs deposit/withdraw/transfer with optimistic locking
 * - Creates transaction records and double-entry ledger entries
 * - Ensures idempotency by checking idempotency_key
 * - Refreshes Redis cache on success
 *
 * NOTE: Amounts are decimal strings (scale defined on VpayWallet::MONEY_SCALE)
 */
class WalletService
{

    public function getUserWallet(string $userId): VpayWallet
    {
        return VpayWallet::firstOrCreate(['user_id' => $userId], [
            'balance' => 0,
            'version' => 0,
        ]);
    }
    // helper to check and normalize amount (string)
    protected function normalizeAmount($amount): string
    {
        // Accept integer/float/string, return decimal string with scale
        $scale = VpayWallet::MONEY_SCALE;
        if (is_int($amount)) {
            // treat as whole units
            return number_format($amount, $scale, '.', '');
        }
        if (is_float($amount)) {
            // cast to string with scale
            return number_format($amount, $scale, '.', '');
        }
        // assume string
        return number_format((float)$amount, $scale, '.', '');
    }

    /**
     * Deposit
     * @throws Exception
     */
    public function deposit(VpayWallet $wallet, $amount, ?string $notes = null, ?string $idempotencyKey = null): VpayTransaction
    {
        $amountStr = $this->normalizeAmount($amount);
        if (bccomp($amountStr, '0', VpayWallet::MONEY_SCALE) !== 1) {
            throw new Exception('Deposit amount must be positive.');
        }

        return DB::transaction(function () use ($wallet, $amountStr, $notes, $idempotencyKey) {
            // idempotency check - race-proofed by unique index on idempotency_key in migration
            if ($idempotencyKey && VpayTransaction::where('idempotency_key', $idempotencyKey)->exists()) {
                // return the existing transaction
                return VpayTransaction::where('idempotency_key', $idempotencyKey)->first();
            }

            // optimistic locking - use current version
            $currentVersion = $wallet->version ?? 0;
            $before = $wallet->balance ?? '0';
            $after = bcadd($before, $amountStr, VpayWallet::MONEY_SCALE);

            // attempt guarded update
            $updated = VpayWallet::where('id', $wallet->id)
                ->where('version', $currentVersion)
                ->update([
                    'balance' => $after,
                    'version' => $currentVersion + 1,
                ]);

            if ($updated === 0) {
                throw new Exception('Concurrent wallet update detected. Retry the operation.');
            }

            // create transaction record
            $txn = VpayTransaction::create([
                'wallet_id' => $wallet->id,
                'amount' => $amountStr,
                'transaction_type' => 'cashin',
                'notes' => $notes ?? 'Deposit',
                'balance_before' => $before,
                'balance_after' => $after,
                'idempotency_key' => $idempotencyKey,
            ]);

            // ledger entries (double-entry)
            VpayLedgerEntry::create([
                'wallet_id' => $wallet->id,
                'transaction_id' => $txn->id,
                'entry_type' => 'credit',
                'amount' => $amountStr,
                'balance' => $after,
                'narration' => 'Wallet deposit (credit)',
            ]);

            // dispatch domain event AFTER DB transaction commit
            Event::dispatch(new WalletCredited($wallet->id, $txn->id));

            // refresh cache for wallet
            $wallet->forgetCache();
            $wallet->refreshCache();

            return $txn;
        });
    }

    /**
     * Withdraw
     * @throws Exception
     */
    public function withdraw(VpayWallet $wallet, $amount, ?string $notes = null, ?string $idempotencyKey = null): VpayTransaction
    {
        $amountStr = $this->normalizeAmount($amount);
        if (bccomp($amountStr, '0', VpayWallet::MONEY_SCALE) !== 1) {
            throw new Exception('Withdrawal amount must be positive.');
        }

        return DB::transaction(function () use ($wallet, $amountStr, $notes, $idempotencyKey) {
            if ($idempotencyKey && VpayTransaction::where('idempotency_key', $idempotencyKey)->exists()) {
                return VpayTransaction::where('idempotency_key', $idempotencyKey)->first();
            }

            $currentVersion = $wallet->version ?? 0;
            $before = $wallet->balance ?? '0';

            if (bccomp($before, $amountStr, VpayWallet::MONEY_SCALE) === -1) {
                throw new Exception('Insufficient balance.');
            }

            $after = bcsub($before, $amountStr, VpayWallet::MONEY_SCALE);

            $updated = VpayWallet::where('id', $wallet->id)
                ->where('version', $currentVersion)
                ->update([
                    'balance' => $after,
                    'version' => $currentVersion + 1,
                ]);

            if ($updated === 0) {
                throw new Exception('Concurrent wallet update detected. Retry the operation.');
            }

            $txn = VpayTransaction::create([
                'wallet_id' => $wallet->id,
                'amount' => $amountStr,
                'transaction_type' => 'cashout',
                'notes' => $notes ?? 'Withdrawal',
                'balance_before' => $before,
                'balance_after' => $after,
                'idempotency_key' => $idempotencyKey,
            ]);

            // ledger (debit)
            VpayLedgerEntry::create([
                'wallet_id' => $wallet->id,
                'transaction_id' => $txn->id,
                'entry_type' => 'debit',
                'amount' => $amountStr,
                'balance' => $after,
                'narration' => 'Wallet withdrawal (debit)',
            ]);

            Event::dispatch(new WalletDebited($wallet->id, $txn->id));

            $wallet->forgetCache();
            $wallet->refreshCache();

            return $txn;
        });
    }

    /**
     * Transfer between wallets (atomic)
     * Returns array [senderTxn, receiverTxn]
     */
    public function transfer(VpayWallet $sender, VpayWallet $receiver, $amount, ?string $notes = null, ?string $idempotencyKey = null): array
    {
        if ($sender->id === $receiver->id) {
            throw new Exception('Cannot transfer to the same wallet.');
        }

        $amountStr = $this->normalizeAmount($amount);
        if (bccomp($amountStr, '0', VpayWallet::MONEY_SCALE) !== 1) {
            throw new Exception('Transfer amount must be positive.');
        }

        return DB::transaction(function () use ($sender, $receiver, $amountStr, $notes, $idempotencyKey) {
            // idempotency: single key may be used for both txns; check existence
            if ($idempotencyKey && VpayTransaction::where('idempotency_key', $idempotencyKey)->exists()) {
                // return both transactions (if present)
                $existing = VpayTransaction::where('idempotency_key', $idempotencyKey)->get();
                return $existing->all();
            }

            // reload the wallets with FOR UPDATE semantics? Eloquent doesn't provide easily.
            // We rely on optimistic locking per-wallet.
            $senderVersion = $sender->version ?? 0;
            $receiverVersion = $receiver->version ?? 0;

            $senderBefore = $sender->balance ?? '0';
            if (bccomp($senderBefore, $amountStr, VpayWallet::MONEY_SCALE) === -1) {
                throw new Exception('Insufficient balance in sender.');
            }
            $receiverBefore = $receiver->balance ?? '0';

            $senderAfter = bcsub($senderBefore, $amountStr, VpayWallet::MONEY_SCALE);
            $receiverAfter = bcadd($receiverBefore, $amountStr, VpayWallet::MONEY_SCALE);

            // update sender
            $u1 = VpayWallet::where('id', $sender->id)
                ->where('version', $senderVersion)
                ->update(['balance' => $senderAfter, 'version' => $senderVersion + 1]);

            if ($u1 === 0) {
                throw new Exception('Concurrent update on sender wallet. Retry.');
            }

            $u2 = VpayWallet::where('id', $receiver->id)
                ->where('version', $receiverVersion)
                ->update(['balance' => $receiverAfter, 'version' => $receiverVersion + 1]);

            if ($u2 === 0) {
                // attempt to roll back by failing the transaction
                throw new Exception('Concurrent update on receiver wallet. Retry.');
            }

            // create transactions
            $senderTxn = VpayTransaction::create([
                'wallet_id' => $sender->id,
                'amount' => $amountStr,
                'transaction_type' => 'cashout',
                'notes' => $notes ?? 'Transfer to ' . ($receiver->user->name ?? $receiver->id),
                'balance_before' => $senderBefore,
                'balance_after' => $senderAfter,
                'receiver_id' => $receiver->user_id,
                'idempotency_key' => $idempotencyKey,
            ]);

            $receiverTxn = VpayTransaction::create([
                'wallet_id' => $receiver->id,
                'amount' => $amountStr,
                'transaction_type' => 'cashin',
                'notes' => $notes ?? 'Transfer from ' . ($sender->user->name ?? $sender->id),
                'balance_before' => $receiverBefore,
                'balance_after' => $receiverAfter,
                'sender_id' => $sender->user_id,
                'idempotency_key' => $idempotencyKey,
            ]);

            // ledger entries: sender debit, receiver credit
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

            Event::dispatch(new TransferSucceeded($sender->id, $receiver->id, $senderTxn->id, $receiverTxn->id));

            $sender->forgetCache();
            $sender->refreshCache();
            $receiver->forgetCache();
            $receiver->refreshCache();

            return [$senderTxn, $receiverTxn];
        });
    }
}
