<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('auth_type')->default('oauth2')->index();
            $table->json('default_scopes')->nullable();
            $table->boolean('supports_refresh_tokens')->default(true);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });

        Schema::create('integration_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('status')->default('active')->index();
            $table->string('provider_account_id')->nullable()->index();
            $table->string('provider_account_name')->nullable();
            $table->json('scopes')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->text('token_payload')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['owner_user_id', 'integration_id']);
            $table->index(['account_id', 'brand_id']);
            $table->index(['integration_id', 'provider_account_id']);
        });

        Schema::create('integration_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('permission')->default('use')->index();
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_connection_id', 'user_id', 'account_id', 'brand_id', 'permission'], 'integration_permission_unique_scope');
            $table->index(['account_id', 'brand_id', 'permission']);
            $table->index(['user_id', 'permission']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_permissions');
        Schema::dropIfExists('integration_connections');
        Schema::dropIfExists('integrations');
    }
};
