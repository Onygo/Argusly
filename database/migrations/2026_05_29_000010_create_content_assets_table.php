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
        Schema::create('content_assets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained('publishing_channels')->nullOnDelete();
            $table->string('type')->index();
            $table->string('status')->default('draft')->index();
            $table->string('title');
            $table->string('slug');
            $table->string('language', 16)->default('en')->index();
            $table->string('locale', 32)->default('en_US')->index();
            $table->string('source')->default('manual')->index();
            $table->text('source_url')->nullable();
            $table->text('canonical_url')->nullable();
            $table->text('excerpt')->nullable();
            $table->longText('body')->nullable();
            $table->json('metadata')->nullable();
            $table->json('seo_metadata')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('first_published_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['account_id', 'brand_id', 'slug', 'locale']);
            $table->index(['account_id', 'brand_id', 'status', 'published_at']);
            $table->index(['account_id', 'brand_id', 'type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_assets');
    }
};
