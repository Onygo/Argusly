<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('credit_wallet_id');

            // allowance, pack_purchase, reservation, usage, refund, adjustment, release
            $table->string('type', 32);

            // positief of negatief
            $table->integer('amount');

            // voor FIFO, rollover, expiry
            $table->timestamp('expires_at')->nullable();

            // referentie naar oorzaak: subscription, pack_purchase, draft, admin
            $table->string('source_type', 64)->nullable();
            $table->uuid('source_id')->nullable();

            // optioneel: voor reporting en debugging
            $table->uuid('brief_id')->nullable();
            $table->uuid('client_site_id')->nullable();
            $table->uuid('user_id')->nullable();

            // extra data: action_key, model, locale, etc.
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['credit_wallet_id', 'type']);
            $table->index(['credit_wallet_id', 'expires_at']);
            $table->index(['source_type', 'source_id']);
            $table->index(['client_site_id', 'brief_id']);

            $table->foreign('credit_wallet_id')
                ->references('id')
                ->on('credit_wallets')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger_entries');
    }
};
