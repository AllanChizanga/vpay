<?php

namespace Tests\Feature\Vpay;

use Tests\TestCase;
use App\Models\User;
use App\Models\VpayWallet;
use App\Services\WalletService;
use App\Events\WalletCredited;
use App\Events\WalletDebited;
use App\Events\TransferSucceeded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WalletService $walletService;
    protected array $allowedCurrencies;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock Redis
        Redis::shouldReceive('setex')->andReturnTrue();
        Redis::shouldReceive('get')->andReturnNull();
        Redis::shouldReceive('del')->andReturnTrue();

        $this->walletService = app(WalletService::class);
        Event::fake();

        $this->allowedCurrencies = config('currency.allowed');
    }

    #[Test]
    public function it_creates_a_wallet_for_a_user_with_default_currency(): void
    {
        $user = User::factory()->create();
        $wallet = $this->walletService->getUserWallet($user->id);

        $this->assertDatabaseHas('vpay_wallets', [
            'user_id' => $user->id,
            'currency' => $this->allowedCurrencies[0],
        ]);

        $expectedBalance = bcadd('0', '0', VpayWallet::MONEY_SCALE);
        $this->assertEquals($expectedBalance, $wallet->balance);
    }

    #[Test]
    public function it_creates_a_wallet_with_specified_currency(): void
    {
        $user = User::factory()->create();
        $currency = $this->allowedCurrencies[1] ?? $this->allowedCurrencies[0];

        $wallet = VpayWallet::factory()->create([
            'user_id' => $user->id,
            'currency' => $currency,
            'balance' => bcadd('0', '0', VpayWallet::MONEY_SCALE),
        ]);

        $this->assertEquals($currency, $wallet->currency);
        $this->assertEquals(bcadd('0', '0', VpayWallet::MONEY_SCALE), $wallet->balance);
    }

    #[Test]
    public function it_fails_to_create_wallet_with_invalid_currency(): void
    {
        $this->expectException(\Exception::class);

        VpayWallet::factory()->create([
            'user_id' => User::factory()->create()->id,
            'currency' => 'INVALID',
        ]);
    }

    #[Test]
    public function it_can_credit_a_wallet_and_fire_event(): void
    {
        $wallet = VpayWallet::factory()->create([
            'balance' => bcadd('0', '0', VpayWallet::MONEY_SCALE)
        ]);

        $txn = $this->walletService->deposit($wallet, '50.00', 'Test credit', null, $wallet->currency);

        $wallet->refresh();
        $expectedBalance = bcadd('0', '50.00', VpayWallet::MONEY_SCALE);

        $this->assertEquals($expectedBalance, $wallet->balance);
        $this->assertEquals($expectedBalance, $txn->balance_after);

        Event::assertDispatched(WalletCredited::class);
    }

    #[Test]
    public function it_fails_deposit_if_currency_mismatch(): void
    {
        $wallet = VpayWallet::factory()->create(['balance' => bcadd('0', '0', VpayWallet::MONEY_SCALE)]);
        $this->expectException(\Exception::class);

        $this->walletService->deposit($wallet, '10.00', 'Test', null, 'EUR');
    }

    #[Test]
    public function it_can_debit_a_wallet_and_fire_event(): void
    {
        $wallet = VpayWallet::factory()->create([
            'balance' => bcadd('100', '0', VpayWallet::MONEY_SCALE)
        ]);

        $txn = $this->walletService->withdraw($wallet, '30.00', 'Test debit', null, $wallet->currency);

        $wallet->refresh();
        $expectedBalance = bcsub('100', '30.00', VpayWallet::MONEY_SCALE);

        $this->assertEquals($expectedBalance, $wallet->balance);
        $this->assertEquals($expectedBalance, $txn->balance_after);

        Event::assertDispatched(WalletDebited::class);
    }

    #[Test]
    public function it_fails_withdraw_if_currency_mismatch(): void
    {
        $wallet = VpayWallet::factory()->create(['balance' => bcadd('50', '0', VpayWallet::MONEY_SCALE)]);
        $this->expectException(\Exception::class);

        $this->walletService->withdraw($wallet, '10.00', 'Test', null, 'EUR');
    }

    #[Test]
    public function it_prevents_overdraft(): void
    {
        $wallet = VpayWallet::factory()->create(['balance' => bcadd('20', '0', VpayWallet::MONEY_SCALE)]);
        $this->expectException(\Exception::class);

        $this->walletService->withdraw($wallet, '50.00', 'Overdraft test', null, $wallet->currency);
    }

    #[Test]
    public function it_respects_idempotency_key_on_deposit(): void
    {
        $wallet = VpayWallet::factory()->create(['balance' => bcadd('0', '0', VpayWallet::MONEY_SCALE)]);
        $key = 'unique-deposit-key';

        $firstTxn = $this->walletService->deposit($wallet, '10.00', 'First', $key, $wallet->currency);
        $secondTxn = $this->walletService->deposit($wallet, '10.00', 'Duplicate', $key, $wallet->currency);

        $this->assertEquals($firstTxn->id, $secondTxn->id);
    }

    #[Test]
    public function it_respects_idempotency_key_on_withdraw(): void
    {
        $wallet = VpayWallet::factory()->create(['balance' => bcadd('50', '0', VpayWallet::MONEY_SCALE)]);
        $key = 'unique-withdraw-key';

        $firstTxn = $this->walletService->withdraw($wallet, '10.00', 'First', $key, $wallet->currency);
        $secondTxn = $this->walletService->withdraw($wallet, '10.00', 'Duplicate', $key, $wallet->currency);

        $this->assertEquals($firstTxn->id, $secondTxn->id);
    }

    #[Test]
    public function it_transfers_funds_between_wallets_and_dispatches_event(): void
    {
        $sender = VpayWallet::factory()->create([
            'balance' => '100.00',
            'currency' => $this->allowedCurrencies[0]
        ]);
        $receiver = VpayWallet::factory()->create([
            'balance' => '20.00',
            'currency' => $this->allowedCurrencies[0]
        ]);

        [$senderTxn, $receiverTxn] = $this->walletService->transfer($sender, $receiver, '50.00', 'Test transfer');

        $sender->refresh();
        $receiver->refresh();

        $this->assertEquals(bcsub('100', '50.00', VpayWallet::MONEY_SCALE), $sender->balance);
        $this->assertEquals(bcadd('20.00', '50.00', VpayWallet::MONEY_SCALE), $receiver->balance);

        Event::assertDispatched(TransferSucceeded::class);
    }

    #[Test]
    public function it_prevents_transfer_between_different_currencies(): void
    {
        $sender = VpayWallet::factory()->create([
            'balance' => '100.00',
            'currency' => $this->allowedCurrencies[0]
        ]);
        $receiver = VpayWallet::factory()->create([
            'balance' => '50.00',
            'currency' => 'EUR'
        ]);

        $this->expectException(\Exception::class);
        $this->walletService->transfer($sender, $receiver, '10.00');
    }

    #[Test]
    public function it_fails_transfer_if_insufficient_balance(): void
    {
        $sender = VpayWallet::factory()->create([
            'balance' => '10.00',
            'currency' => $this->allowedCurrencies[0]
        ]);
        $receiver = VpayWallet::factory()->create([
            'balance' => '0.00',
            'currency' => $this->allowedCurrencies[0]
        ]);

        $this->expectException(\Exception::class);
        $this->walletService->transfer($sender, $receiver, '50.00');
    }

    #[Test]
    public function it_handles_decimal_precision_correctly(): void
    {
        $wallet = VpayWallet::factory()->create([
            'balance' => bcadd('0', '0', VpayWallet::MONEY_SCALE)
        ]);
        $txn = $this->walletService->deposit($wallet, '0.12345678', 'Decimal test', null, $wallet->currency);

        $wallet->refresh();
        $this->assertEquals('0.12345678', $wallet->balance);
        $this->assertEquals('0.12345678', $txn->balance_after);
    }

    #[Test]
    public function it_rolls_back_on_error(): void
    {
        $wallet = VpayWallet::factory()->create([
            'balance' => '100.00',
            'currency' => $this->allowedCurrencies[0]
        ]);
        $this->expectException(\Exception::class);

        $this->walletService->withdraw($wallet, '-10.00', 'Invalid negative withdrawal', null, $wallet->currency);
    }
}
