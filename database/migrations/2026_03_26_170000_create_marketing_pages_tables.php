<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_pages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('section')->nullable()->index();
            $table->string('template')->default('topic');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('marketing_page_translations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('marketing_page_id');
            $table->string('locale', 8)->index();
            $table->string('title');
            $table->string('slug');
            $table->string('seo_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('canonical_path')->nullable();
            $table->json('content')->nullable();
            $table->timestamps();

            $table->foreign('marketing_page_id')
                ->references('id')
                ->on('marketing_pages')
                ->cascadeOnDelete();

            $table->unique(['marketing_page_id', 'locale']);
            $table->unique(['locale', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_page_translations');
        Schema::dropIfExists('marketing_pages');
    }
};
