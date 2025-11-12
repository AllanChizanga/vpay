<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\VpayWallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use App\Events\WalletCredited;
use App\Events\WalletDebited;

class WalletControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected VpayWallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a user and wallet
        $this->user = User::factory()->create();
        $this->wallet = VpayWallet::factory()->create([
            'user_id' => $this->user->id,
            'balance' => '100.00', 
        ]);
    }

    public function test_it_can_show_a_user_wallet(): void
    {
        // Authenticate user
        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson("/api/wallet/{$this->user->id}");

        $response->assertStatus(200)
                 ->assertJson([
                     'wallet' => [
                         'id' => $this->wallet->id,
                         'user_id' => (string) $this->user->id,
                         'balance' => number_format((float)$this->wallet->balance, 2, '.', ''),
                     ],
                 ]);
    }

    // public function test_it_can_credit_a_wallet_and_fire_event(): void
    // {
    //     Event::fake();
    //     $this->actingAs($this->user, 'sanctum');

    //     $response = $this->postJson("/api/wallet/deposit", [
    //         'user_id' => $this->user->id,
    //         'amount' => '50.00',
    //         'reference' => 'Test credit',
    //         'type' => 'credit',
    //     ]);

    //     $response->assertStatus(200)
    //              ->assertJsonFragment(['message' => 'Wallet credited successfully'])
    //              ->assertJsonStructure(['wallet' => ['id', 'user_id', 'balance', 'version']]);

    //     Event::assertDispatched(WalletCredited::class);
    // }

    // public function test_it_can_debit_a_wallet_and_fire_event(): void
    // {
    //     Event::fake();
    //     $this->actingAs($this->user, 'sanctum');

    //     $response = $this->postJson("/api/wallet/withdraw", [
    //         'user_id' => $this->user->id,
    //         'amount' => '30.00',
    //         'reference' => 'Test debit',
    //         'type' => 'debit',
    //     ]);

    //     $response->assertStatus(200)
    //              ->assertJsonFragment(['message' => 'Wallet debited successfully'])
    //              ->assertJsonStructure(['wallet' => ['id', 'user_id', 'balance', 'version']]);

    //     Event::assertDispatched(WalletDebited::class);
    // }

    public function test_it_validates_required_fields_on_credit_and_debit(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson("/api/wallet/deposit", []);
        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['user_id', 'amount', 'type']);

        $response = $this->postJson("/api/wallet/withdraw", []);
        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['user_id', 'amount', 'type']);
    }
}
