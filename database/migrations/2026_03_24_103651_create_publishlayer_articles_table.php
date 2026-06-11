<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('publishlayer_articles')) {
            return;
        }

        Schema::create('publishlayer_articles', function (Blueprint $table) {
            $table->id();
            $table->string('source_publishlayer_id')->unique();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('summary')->nullable();
            $table->longText('content_html');
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->text('featured_image_url')->nullable();
            $table->string('status', 32)->default('draft');
            $table->foreignId('category_id')->nullable()->constrained('publishlayer_categories')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->index('category_id');
            $table->index('source_updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publishlayer_articles');
    }
};
