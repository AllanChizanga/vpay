<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vpay_transactions', function (Blueprint $table) {
             $table->uuid('id')->primary();

            // Foreign wallet reference
            $table->foreignUuid('wallet_id')->constrained('vpay_wallets')->cascadeOnDelete();

            // Transaction type and details
            $table->decimal('amount', 24, 8);
            $table->enum('transaction_type', ['cashin', 'cashout', 'transfer'])->default('cashin');
            $table->text('notes')->nullable();

            // Audit trail of balances
            $table->decimal('balance_before', 24, 8);
            $table->decimal('balance_after', 24, 8);

            // Optional sender/receiver (for transfers)
            $table->foreignUuid('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('receiver_id')->nullable()->constrained('users')->nullOnDelete();

            // Optional idempotency key to prevent duplicate transactions
            $table->string('idempotency_key')->nullable()->index();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vpay_tansactions');
    }
};
