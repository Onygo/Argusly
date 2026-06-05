<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_destination_sync_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('content_destination_id');
            $table->uuid('content_id')->nullable();
            $table->uuid('content_publish_target_id')->nullable();
            $table->string('sync_type', 64);
            $table->string('trigger_source', 64)->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('request_url', 2048)->nullable();
            $table->string('idempotency_key', 255)->nullable();
            $table->json('request_headers')->nullable();
            $table->json('request_body')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_headers')->nullable();
            $table->text('response_body')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status', 'created_at'], 'destination_sync_attempts_ws_status_idx');
            $table->index(['content_destination_id', 'created_at'], 'destination_sync_attempts_destination_created_idx');
            $table->index(['content_id', 'created_at'], 'destination_sync_attempts_content_created_idx');
            $table->index(['content_publish_target_id', 'created_at'], 'destination_sync_attempts_target_created_idx');
            $table->index(['idempotency_key', 'created_at'], 'destination_sync_attempts_idempotency_created_idx');

            $table->foreign('workspace_id', 'cdsa_workspace_fk')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('content_destination_id', 'cdsa_destination_fk')->references('id')->on('content_destinations')->cascadeOnDelete();
            $table->foreign('content_id', 'cdsa_content_fk')->references('id')->on('contents')->cascadeOnDelete();
            $table->foreign('content_publish_target_id', 'cdsa_publish_target_fk')->references('id')->on('content_publish_targets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_destination_sync_attempts');
    }
};
