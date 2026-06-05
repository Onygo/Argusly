<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Link to domain record: CreditPackPurchase or Subscription, etc.
            $table->string('billable_type', 255);
            $table->uuid('billable_id');

            $table->string('provider', 32); // mollie, stripe, adyen, etc
            $table->string('status', 32)->default('pending'); // pending, open, paid, failed, canceled, expired

            $table->unsignedInteger('amount_cents');
            $table->string('currency', 8)->default('EUR');

            $table->string('provider_payment_id', 128)->nullable();
            $table->string('checkout_url', 2048)->nullable();

            // Unique key used for safe retries and webhook idempotency
            $table->string('idempotency_key', 120)->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['billable_type', 'billable_id']);
            $table->index(['provider', 'status']);
            $table->index(['provider_payment_id']);
            $table->unique(['provider', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
