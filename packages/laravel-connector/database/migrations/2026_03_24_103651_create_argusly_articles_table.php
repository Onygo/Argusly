<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('argusly_articles')) {
            return;
        }

        Schema::create('argusly_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('source_argusly_id')->unique();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('summary')->nullable();
            $table->longText('content_html');
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->text('featured_image_url')->nullable();
            $table->string('locale', 32)->nullable()->index();
            $table->string('source_locale', 32)->nullable();
            $table->text('canonical_url')->nullable();
            $table->string('canonical_content_id')->nullable()->index();
            $table->json('hreflang_alternates')->nullable();
            $table->text('x_default_url')->nullable();
            $table->string('translation_group_id')->nullable()->index();
            $table->string('family_id')->nullable()->index();
            $table->json('answer_blocks')->nullable();
            $table->json('structured_output')->nullable();
            $table->json('schema_data')->nullable();
            $table->json('ai_visibility')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 32)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->index('source_updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('argusly_articles');
    }
};
