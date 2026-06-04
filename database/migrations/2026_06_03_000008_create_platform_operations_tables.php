<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('scope')->default('platform')->index();
            $table->boolean('enabled')->default(false)->index();
            $table->json('rules')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url');
            $table->string('status')->default('active')->index();
            $table->json('events');
            $table->text('signing_secret')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'status']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event')->index();
            $table->string('status')->default('pending')->index();
            $table->string('idempotency_key')->unique();
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'created_at']);
            $table->index(['webhook_endpoint_id', 'status', 'available_at']);
        });

        Schema::create('worker_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('worker_name');
            $table->string('queue')->nullable()->index();
            $table->string('status')->default('running')->index();
            $table->json('metadata')->nullable();
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();

            $table->unique(['worker_name', 'queue']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_heartbeats');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('feature_flags');
    }
};
