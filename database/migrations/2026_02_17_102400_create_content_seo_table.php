<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_seo', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('content_id')->unique();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->string('primary_keyword')->nullable();
            $table->json('secondary_keywords')->nullable();
            $table->boolean('schema_enabled')->default(false);
            $table->boolean('toc_enabled')->default(false);
            $table->timestamps();

            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
            $table->index(['primary_keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_seo');
    }
};
