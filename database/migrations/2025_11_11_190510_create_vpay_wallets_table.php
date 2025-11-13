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
        Schema::create('vpay_wallets', function (Blueprint $table) {
           $table->uuid('id')->primary();

            // Wallet owner
            $table->foreignUuid('user_id');

            // Currency and balance
            $table->string('currency', 3)->default('USD');
            $table->decimal('balance', 24, 8)->default(0);

            // Version for optimistic concurrency (helps prevent race conditions)
            $table->unsignedBigInteger('version')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vpay_wallets');
    }
};
