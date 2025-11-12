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
        Schema::create('vpay_ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('wallet_id')->constrained('vpay_wallets')->cascadeOnDelete();
            $table->foreignUuid('transaction_id')->constrained('vpay_transactions')->cascadeOnDelete();
            $table->enum('entry_type', ['credit', 'debit']);
            $table->decimal('amount', 20, 8);
            $table->decimal('balance', 20, 8);
            $table->string('narration')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vpay_ledger_entries');
    }
};
