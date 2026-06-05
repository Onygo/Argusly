<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the content_delivery_events table for delivery history tracking.
 *
 * Records every delivery attempt and outcome for audit trail and debugging.
 * Event types: verify_remote, create_remote, update_remote, recreate_remote, fail_remote
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_delivery_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('content_publication_id');

            // Event classification
            $table->string('event_type', 32)->comment('verify_remote, create_remote, update_remote, recreate_remote, fail_remote');
            $table->string('status', 32)->default('pending')->comment('pending, success, failed');
            $table->text('message')->nullable()->comment('Human-readable event description or error message');

            // Request/response capture for debugging
            $table->json('request_payload_json')->nullable()->comment('Outgoing payload (truncated for large payloads)');
            $table->json('response_payload_json')->nullable()->comment('Remote system response');

            // HTTP details
            $table->unsignedSmallInteger('http_status')->nullable()->comment('HTTP response status code');
            $table->string('correlation_id', 64)->nullable()->comment('Request correlation ID for tracing');

            // Duration tracking
            $table->unsignedInteger('duration_ms')->nullable()->comment('Request duration in milliseconds');

            $table->timestamps();

            // Indexes for common queries
            $table->index(['content_publication_id', 'created_at'], 'delivery_events_publication_created_idx');
            $table->index(['event_type', 'status', 'created_at'], 'delivery_events_type_status_idx');
            $table->index(['correlation_id'], 'delivery_events_correlation_idx');

            // Foreign key
            $table->foreign('content_publication_id', 'cde_publication_fk')
                ->references('id')
                ->on('content_publications')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_delivery_events');
    }
};
