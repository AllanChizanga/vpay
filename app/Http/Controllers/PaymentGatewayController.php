<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Payments\GatewayFactory;
use App\Models\User;
use App\Models\VpayTransaction;
use Illuminate\Support\Str;

class PaymentGatewayController extends Controller
{
    public function deposit(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'amount' => 'required|numeric|min:1',
            'gateway' => 'required|string|in:paynow',
        ]);

        $user = User::findOrFail($validated['user_id']);
        $reference = Str::uuid()->toString();

        $gateway = GatewayFactory::make($validated['gateway']);
        $result = $gateway->initiateDeposit($user, $validated['amount'], $reference);

        if (!$result['success']) {
            return response()->json(['error' => $result['message']], 400);
        }

        VpayTransaction::create([
            'user_id' => $user->id,
            'amount' => $validated['amount'],
            'reference' => $reference,
            'gateway' => $validated['gateway'],
            'type' => 'deposit',
            'status' => 'Pending',
            'poll_url' => $result['poll_url'] ?? null,
        ]);

        return response()->json([
            'message' => 'Deposit initiated successfully',
            'payment_url' => $result['browser_url'] ?? null,
            'reference' => $reference,
        ]);
    }

    public function verify(Request $request)
    {
        $validated = $request->validate([
            'reference' => 'required|string',
            'gateway' => 'required|string|in:paynow',
        ]);

        $gateway = GatewayFactory::make($validated['gateway']);
        $result = $gateway->verifyTransaction($validated['reference']);

        if (!$result['success']) {
            return response()->json(['status' => $result['status']], 202);
        }

        // Update wallet and transaction
        $transaction = VpayTransaction::where('reference', $validated['reference'])->first();
        if ($transaction && $result['success']) {
            $wallet = $transaction->user->wallet;
            $wallet->balance = (string) ((float) $wallet->balance + (float) $result['amount']);
            $wallet->save();

            $transaction->update(['status' => 'Paid']);
        }

        return response()->json([
            'message' => 'Payment verified successfully',
            'status' => $result['status'],
        ]);
    }
}
