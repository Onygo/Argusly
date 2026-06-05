<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_render_artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('content_id');
            $table->uuid('content_version_id')->nullable();
            $table->longText('rendered_html')->nullable();
            $table->longText('rendered_markdown')->nullable();
            $table->string('markdown_checksum', 64)->nullable();
            $table->timestamp('markdown_generated_at')->nullable();
            $table->unsignedInteger('markdown_version')->default(1);
            $table->string('markdown_locale', 10)->default('en');
            $table->string('markdown_status', 32)->default('pending');
            $table->string('markdown_source', 32)->nullable();
            $table->text('markdown_excerpt')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['content_id', 'markdown_locale'], 'content_render_artifacts_content_locale_unique');
            $table->index(['markdown_status', 'markdown_generated_at'], 'content_render_artifacts_status_generated_idx');
            $table->index('markdown_checksum', 'content_render_artifacts_checksum_idx');
            $table->index('content_version_id', 'content_render_artifacts_version_idx');

            $table->foreign('content_id', 'content_render_artifacts_content_fk')
                ->references('id')
                ->on('contents')
                ->cascadeOnDelete();

            $table->foreign('content_version_id', 'content_render_artifacts_version_fk')
                ->references('id')
                ->on('content_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_render_artifacts');
    }
};
