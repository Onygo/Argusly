<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ga4_properties', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->string('display_name');
            $table->string('website_url')->nullable();
            $table->string('status')->default('draft')->index();
            $table->json('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'status']);
            $table->index(['account_id', 'brand_id', 'last_synced_at']);
        });

        Schema::create('ga4_metric_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ga4_property_id')->constrained('ga4_properties')->cascadeOnDelete();
            $table->foreignId('content_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date')->index();
            $table->unsignedInteger('sessions')->nullable();
            $table->unsignedInteger('users')->nullable();
            $table->unsignedInteger('pageviews')->nullable();
            $table->decimal('engagement_rate', 5, 2)->nullable();
            $table->unsignedInteger('conversions')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'date']);
            $table->index(['content_asset_id', 'date']);
            $table->unique(['ga4_property_id', 'content_asset_id', 'date'], 'ga4_snapshot_unique_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ga4_metric_snapshots');
        Schema::dropIfExists('ga4_properties');
    }
};
