<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('argusly_article_relations')) {
            return;
        }

        Schema::create('argusly_article_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('argusly_articles')->cascadeOnDelete();
            $table->foreignId('related_article_id')->constrained('argusly_articles')->cascadeOnDelete();
            $table->string('relation_type', 64)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['article_id', 'related_article_id', 'relation_type'], 'argusly_article_relations_unique');
            $table->index(['article_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('argusly_article_relations');
    }
};
