<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('publishlayer_deliveries')) {
            return;
        }

        Schema::create('publishlayer_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_event_id')->constrained('publishlayer_webhook_events')->cascadeOnDelete();
            $table->string('site_key', 128);
            $table->string('action', 120);
            $table->enum('status', ['queued', 'processing', 'processed', 'failed', 'ignored'])->default('queued');
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['site_key', 'status']);
            $table->index(['site_key', 'created_at']);
            $table->unique(['webhook_event_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publishlayer_deliveries');
    }
};
