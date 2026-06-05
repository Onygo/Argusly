<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('research_project_id');
            $table->uuid('research_source_id')->nullable();
            $table->string('finding_type', 40);
            $table->longText('finding_text');
            $table->json('citations')->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->boolean('is_selected')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['research_project_id', 'finding_type'], 'research_findings_project_type_idx');
            $table->index(['research_project_id', 'is_selected'], 'research_findings_project_selected_idx');
            $table->index(['research_project_id', 'created_at'], 'research_findings_project_created_idx');
            $table->index(['research_project_id', 'confidence_score'], 'research_findings_project_confidence_idx');

            $table->foreign('research_project_id')->references('id')->on('research_projects')->cascadeOnDelete();
            $table->foreign('research_source_id')->references('id')->on('research_sources')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_findings');
    }
};
