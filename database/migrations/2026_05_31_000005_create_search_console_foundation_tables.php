<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_console_sites', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('site_url');
            $table->string('status')->default('draft')->index();
            $table->json('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'status']);
            $table->index(['account_id', 'brand_id', 'last_synced_at']);
            $table->unique(['brand_id', 'site_url'], 'search_console_site_brand_url_unique');
        });

        Schema::create('search_console_query_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('search_console_site_id')->constrained('search_console_sites')->cascadeOnDelete();
            $table->foreignId('content_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date')->index();
            $table->string('query')->nullable();
            $table->string('page')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('device')->nullable();
            $table->unsignedInteger('clicks')->nullable();
            $table->unsignedInteger('impressions')->nullable();
            $table->decimal('ctr', 6, 4)->nullable();
            $table->decimal('position', 8, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'date']);
            $table->index(['content_asset_id', 'date']);
            $table->index(['search_console_site_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_console_query_snapshots');
        Schema::dropIfExists('search_console_sites');
    }
};
