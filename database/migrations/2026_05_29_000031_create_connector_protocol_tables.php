<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('connector_manifests')) {
            Schema::create('connector_manifests', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('key')->unique();
                $table->string('type')->index();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('homepage_url')->nullable();
                $table->string('documentation_url')->nullable();
                $table->string('status')->default('active')->index();
                $table->boolean('is_system')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('connector_versions')) {
            Schema::create('connector_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('connector_manifest_id')->constrained()->cascadeOnDelete();
                $table->string('version');
                $table->string('status')->default('active')->index();
                $table->string('minimum_argusly_version')->nullable();
                $table->string('checksum')->nullable();
                $table->text('release_notes')->nullable();
                $table->json('config_schema')->nullable();
                $table->json('api_schema')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['connector_manifest_id', 'version']);
                $table->index(['connector_manifest_id', 'status']);
            });
        }

        if (! Schema::hasTable('connector_capabilities')) {
            Schema::create('connector_capabilities', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('connector_manifest_id')->constrained()->cascadeOnDelete();
                $table->foreignId('connector_version_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('capability')->index();
                $table->boolean('is_enabled')->default(true)->index();
                $table->json('settings')->nullable();
                $table->timestamps();

                $table->unique(['connector_manifest_id', 'connector_version_id', 'capability'], 'connector_capabilities_unique_scope');
            });
        }

        if (! Schema::hasTable('connector_installations')) {
            Schema::create('connector_installations', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('channel_id')->nullable()->constrained('publishing_channels')->nullOnDelete();
                $table->foreignId('connector_manifest_id')->constrained()->restrictOnDelete();
                $table->foreignId('connector_version_id')->constrained()->restrictOnDelete();
                $table->foreignId('installed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name');
                $table->string('status')->default('pending')->index();
                $table->string('endpoint_url')->nullable();
                $table->string('api_key_prefix')->nullable()->index();
                $table->text('api_access_token')->nullable();
                $table->json('enabled_capabilities')->nullable();
                $table->json('settings')->nullable();
                $table->json('last_health_check')->nullable();
                $table->timestamp('last_health_checked_at')->nullable();
                $table->timestamp('installed_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['account_id', 'brand_id', 'status']);
                $table->index(['account_id', 'property_id']);
                $table->index(['account_id', 'channel_id']);
                $table->index(['connector_manifest_id', 'connector_version_id'], 'conn_inst_manifest_version_idx');
            });
        } elseif (! $this->indexExists('connector_installations', 'conn_inst_manifest_version_idx')) {
            Schema::table('connector_installations', function (Blueprint $table): void {
                $table->index(['connector_manifest_id', 'connector_version_id'], 'conn_inst_manifest_version_idx');
            });
        }

        if (! Schema::hasTable('connector_logs')) {
            Schema::create('connector_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('connector_installation_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
                $table->string('level')->default('info')->index();
                $table->string('event')->index();
                $table->string('status')->nullable()->index();
                $table->text('message')->nullable();
                $table->json('context')->nullable();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();

                $table->index(['account_id', 'brand_id', 'occurred_at']);
                $table->index(['connector_installation_id', 'occurred_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connector_logs');
        Schema::dropIfExists('connector_installations');
        Schema::dropIfExists('connector_capabilities');
        Schema::dropIfExists('connector_versions');
        Schema::dropIfExists('connector_manifests');
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = DB::getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
