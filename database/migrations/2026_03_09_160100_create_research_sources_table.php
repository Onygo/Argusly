<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('research_project_id');
            $table->string('source_type', 40);
            $table->string('source_classification', 64)->nullable();
            $table->text('url')->nullable();
            $table->string('title', 500)->nullable();
            $table->longText('content_text')->nullable();
            $table->string('fetch_status', 32)->default('pending');
            $table->timestamp('fetched_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['research_project_id', 'fetch_status'], 'research_sources_project_fetch_status_idx');
            $table->index(['research_project_id', 'created_at'], 'research_sources_project_created_idx');
            $table->index(['research_project_id', 'source_type'], 'research_sources_project_type_idx');

            $table->foreign('research_project_id')->references('id')->on('research_projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_sources');
    }
};
