<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('connector_oauth_states')) {
            return;
        }

        Schema::create('connector_oauth_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('state_hash', 64)->unique();
            $table->string('nonce_hash', 64);
            $table->uuid('workspace_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->uuid('connector_provider_id')->nullable()->index();
            $table->uuid('connector_account_id')->nullable()->index();
            $table->string('provider_key', 120)->index();
            $table->string('redirect_uri', 2048)->nullable();
            $table->json('scopes_json')->nullable();
            $table->text('pkce_code_verifier');
            $table->string('pkce_code_challenge', 128);
            $table->string('pkce_code_challenge_method', 20)->default('S256');
            $table->json('context_json')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('connector_provider_id')->references('id')->on('connector_providers')->nullOnDelete();
            $table->foreign('connector_account_id')->references('id')->on('connector_accounts')->nullOnDelete();
            $table->index(['provider_key', 'expires_at'], 'connector_oauth_states_provider_expires_idx');
        });
    }

    public function down(): void
    {
        // Intentionally no-op: this repairs a table owned by the core connector migration.
    }
};
