<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_pack_purchases', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('client_site_id');
            $table->uuid('credit_pack_id');

            // pending, paid, failed, refunded, canceled
            $table->string('status', 32)->default('pending');

            $table->unsignedInteger('credits_amount');
            $table->unsignedInteger('price_cents');
            $table->string('currency', 8)->default('EUR');

            // Provider refs voor Mollie
            $table->string('provider', 32)->nullable(); // mollie
            $table->string('provider_payment_id', 128)->nullable();
            $table->string('provider_customer_id', 128)->nullable();

            // Koppeling naar ledger (als betaald)
            $table->uuid('credit_ledger_entry_id')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('canceled_at')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['client_site_id']);
            $table->index(['status']);
            $table->index(['provider_payment_id']);

            $table->foreign('client_site_id')
                ->references('id')
                ->on('client_sites')
                ->cascadeOnDelete();

            $table->foreign('credit_pack_id')
                ->references('id')
                ->on('credit_packs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_pack_purchases');
    }
};
