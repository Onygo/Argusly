<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant scoping
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->uuid('workspace_id')->nullable();
            $table->uuid('client_site_id');

            // Wallet and actor
            $table->uuid('credit_wallet_id');
            $table->unsignedBigInteger('user_id')->nullable();

            // Credit details
            $table->integer('amount');
            $table->string('currency_unit', 20)->default('credits');

            // Status lifecycle: reserved -> captured | released | expired
            $table->string('status', 20)->default('reserved');

            // Polymorphic link to context (Draft, ContentImage, ContentSeries, etc.)
            $table->string('context_type')->nullable();
            $table->uuid('context_id')->nullable();

            // AI provider (openai, anthropic, gemini, etc.)
            $table->string('provider', 50)->nullable();

            // Purpose (image_generate, draft_generate, series_generate, etc.)
            $table->string('purpose', 100);

            // Idempotency key for safe retries
            $table->string('idempotency_key', 255)->unique();

            // Timestamps for lifecycle tracking
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Failure tracking
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();

            // Release/capture reason
            $table->string('reason', 255)->nullable();

            // Admin override tracking
            $table->unsignedBigInteger('admin_user_id')->nullable();

            // Linked ledger entries
            $table->uuid('reservation_ledger_entry_id')->nullable();
            $table->uuid('capture_ledger_entry_id')->nullable();
            $table->uuid('release_ledger_entry_id')->nullable();

            // Metadata for extensibility
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Indexes for efficient queries
            $table->index(['credit_wallet_id', 'status']);
            $table->index(['expires_at', 'status']);
            $table->index(['context_type', 'context_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('user_id');
            $table->index('purpose');
            $table->index('provider');

            // Foreign keys
            $table->foreign('credit_wallet_id')
                ->references('id')
                ->on('credit_wallets')
                ->cascadeOnDelete();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('admin_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('reservation_ledger_entry_id')
                ->references('id')
                ->on('credit_ledger_entries')
                ->nullOnDelete();

            $table->foreign('capture_ledger_entry_id')
                ->references('id')
                ->on('credit_ledger_entries')
                ->nullOnDelete();

            $table->foreign('release_ledger_entry_id')
                ->references('id')
                ->on('credit_ledger_entries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_reservations');
    }
};
