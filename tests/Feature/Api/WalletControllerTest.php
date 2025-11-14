<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use Illuminate\Auth\GenericUser;
use App\Models\VpayWallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use App\Events\WalletCredited;
use App\Events\WalletDebited;

class WalletControllerTest extends TestCase
{
    use RefreshDatabase;

    protected GenericUser $user;
    protected VpayWallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a non-persistent user (no users table required)
        $this->user = new GenericUser([
            'id' => 9999,
            'name' => 'Test User',
            'email' => 'test@example.test',
        ]);

        // Create a wallet for that user id
        $this->wallet = VpayWallet::factory()->create([
            'user_id' => (string) $this->user->id,
            'balance' => '100.00',
        ]);
    }

    public function test_it_can_show_a_user_wallet(): void
    {
        // Authenticate user (GenericUser works with actingAs)
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