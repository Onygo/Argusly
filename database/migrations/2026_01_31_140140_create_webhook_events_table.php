<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('provider', 32); // mollie, stripe, adyen, etc
            $table->string('provider_event_id', 128); // unique per provider

            $table->string('event_type', 64)->nullable();

            $table->json('payload');

            // Optional, helpful for debugging and signature verification
            $table->json('headers')->nullable();
            $table->string('source_ip', 64)->nullable();

            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('handled_at')->nullable();

            $table->json('handler_result')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'provider_event_id']);
            $table->index(['provider']);
            $table->index(['handled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
