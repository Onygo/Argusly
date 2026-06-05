<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('api_webhook_id');
            $table->uuid('workspace_id');
            $table->string('event_type', 120);
            $table->uuid('event_id')->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->json('request_headers')->nullable();
            $table->json('request_body')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'event_type', 'created_at'], 'api_webhook_deliveries_ws_event_idx');
            $table->index(['api_webhook_id', 'delivered_at'], 'api_webhook_deliveries_webhook_delivered_idx');

            $table->foreign('api_webhook_id')->references('id')->on('api_webhooks')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_webhook_deliveries');
    }
};
