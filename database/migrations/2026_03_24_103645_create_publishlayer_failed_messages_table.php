<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('publishlayer_failed_messages')) {
            return;
        }

        Schema::create('publishlayer_failed_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_event_id')->nullable()->constrained('publishlayer_webhook_events')->nullOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained('publishlayer_deliveries')->nullOnDelete();
            $table->string('site_key', 128);
            $table->string('event_id', 128)->nullable();
            $table->string('error_class', 191);
            $table->text('error_message');
            $table->json('payload')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('failed_at');
            $table->timestamps();

            $table->index(['site_key', 'failed_at']);
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publishlayer_failed_messages');
    }
};
