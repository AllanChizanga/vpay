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

    protected function setUp(): void
    {
        parent::setUp();

        // Mock Redis to avoid connection errors during tests
        Redis::shouldReceive('setex')->andReturnTrue();
        Redis::shouldReceive('get')->andReturnNull();
        Redis::shouldReceive('del')->andReturnTrue();

        $this->walletService = app(WalletService::class);

        Event::fake();
    }

    #[Test]
    public function it_creates_a_wallet_for_a_user(): void
    {
        $user = User::factory()->create();

        $wallet = VpayWallet::factory()->create([
            'user_id' => $user->id,
            'balance' => '0.00',
            'version' => 0,
        ]);

        $this->assertDatabaseHas('vpay_wallets', ['user_id' => $user->id]);
        $this->assertEquals('0.00', $wallet->balance);
    }

    #[Test]
    public function it_can_credit_a_wallet_and_fire_event(): void
    {
        $wallet = VpayWallet::factory()->create(['balance' => '0.00']);

        // Use string amounts for bccomp precision
        $this->walletService->deposit($wallet, '50.00', 'Test credit');

        $wallet->refresh();
        $this->assertEquals('50.00', $wallet->balance);

        Event::assertDispatched(WalletCredited::class);
    }

    #[Test]
    public function it_can_debit_a_wallet_and_fire_event(): void
    {
        $wallet = VpayWallet::factory()->create(['balance' => '100.00']);

        $this->walletService->withdraw($wallet, '30.00', 'Test debit');

        $wallet->refresh();
        $this->assertEquals('70.00', $wallet->balance);

        Event::assertDispatched(WalletDebited::class);
    }

    #[Test]
    public function it_prevents_overdraft(): void
    {
        $wallet = VpayWallet::factory()->create(['balance' => '20.00']);
        $this->expectException(\Exception::class);

        $this->walletService->withdraw($wallet, '50.00');
    }

    #[Test]
    public function it_transfers_funds_between_wallets_and_dispatches_event(): void
    {
        $sender = VpayWallet::factory()->create(['balance' => '100.00']);
        $receiver = VpayWallet::factory()->create(['balance' => '20.00']);

        [$senderTxn, $receiverTxn] = $this->walletService->transfer($sender, $receiver, '50.00');

        $sender->refresh();
        $receiver->refresh();

        $this->assertEquals('50.00', $sender->balance);
        $this->assertEquals('70.00', $receiver->balance);

        Event::assertDispatched(TransferSucceeded::class);
    }

    #[Test]
    public function it_respects_idempotency_key(): void
    {
        $wallet = VpayWallet::factory()->create(['balance' => '0.00']);
        $key = 'unique-123';

        $firstTxn = $this->walletService->deposit($wallet, '10.00', 'First', $key);
        $secondTxn = $this->walletService->deposit($wallet, '10.00', 'Duplicate', $key);

        $this->assertEquals($firstTxn->id, $secondTxn->id);
    }

    #[Test]
    public function it_rolls_back_on_error(): void
    {
        $wallet = VpayWallet::factory()->create(['balance' => '100.00']);

        $this->expectException(\Exception::class);
        $this->walletService->withdraw($wallet, '-10.00');
    }
}
