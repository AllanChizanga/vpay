<?php

namespace App\Services\Payments;

use App\Models\User;
use App\Models\VpayTransaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaynowService implements PaymentGatewayInterface
{
    protected string $integrationId;
    protected string $integrationKey;
    protected string $returnUrl;
    protected string $resultUrl;
    protected string $baseUrl = 'https://www.paynow.co.zw/interface/initiatetransaction';

    public function __construct()
    {
        $this->integrationId = config('services.paynow.integration_id');
        $this->integrationKey = config('services.paynow.integration_key');
        $this->returnUrl = config('services.paynow.return_url');
        $this->resultUrl = config('services.paynow.result_url');
    }

    public function initiateDeposit(User $user, float $amount, string $reference): array
    {
        $response = Http::asForm()->post($this->baseUrl, [
            'id' => $this->integrationId,
            'reference' => $reference,
            'amount' => $amount,
            'additionalinfo' => "Deposit for {$user->email}",
            'returnurl' => $this->returnUrl,
            'resulturl' => $this->resultUrl,
            'authemail' => $user->email,
        ]);

        if ($response->failed()) {
            Log::error('Paynow initiation failed', ['response' => $response->body()]);
            return ['success' => false, 'message' => 'Failed to connect to Paynow'];
        }

        parse_str($response->body(), $data);

        return [
            'success' => true,
            'poll_url' => $data['pollurl'] ?? null,
            'browser_url' => $data['browserurl'] ?? null,
            'reference' => $reference,
        ];
    }

    public function verifyTransaction(string $reference): array
    {
        $transaction = VpayTransaction::where('reference', $reference)->first();

        if (!$transaction || !$transaction->poll_url) {
            return ['success' => false, 'message' => 'Transaction not found'];
        }

        $response = Http::get($transaction->poll_url);

        parse_str($response->body(), $data);

        return [
            'success' => $data['status'] === 'Paid',
            'status' => $data['status'] ?? 'Pending',
            'amount' => $data['amount'] ?? 0,
        ];
    }

    public function processWithdrawal(User $user, float $amount, string $reference): array
    {
        // Paynow does not support automated payouts for all accounts
        // so this can be processed manually or integrated with another service
        return [
            'success' => true,
            'message' => 'Withdrawal request received. Manual processing required.'
        ];
    }
}
