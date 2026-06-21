<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_questions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('question');
            $table->text('answer');
            $table->string('language', 5)->index();
            $table->string('faq_type', 40)->index();
            $table->string('search_intent', 40)->index();
            $table->string('funnel_stage', 40)->index();
            $table->unsignedSmallInteger('priority')->default(50)->index();
            $table->decimal('seo_score', 5, 2)->default(0);
            $table->decimal('ai_visibility_score', 5, 2)->default(0);
            $table->decimal('conversion_score', 5, 2)->default(0);
            $table->boolean('is_global')->default(false)->index();
            $table->string('status', 40)->default('draft')->index();
            $table->json('internal_links')->nullable();
            $table->string('recommended_cta')->nullable();
            $table->json('source_context')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['language', 'status', 'faq_type']);
        });

        Schema::create('faq_page_assignments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('faq_id');
            $table->string('page_type', 40)->index();
            $table->string('page_slug')->index();
            $table->string('locale', 5)->index();
            $table->unsignedSmallInteger('weight')->default(50);
            $table->timestamps();

            $table->foreign('faq_id')->references('id')->on('faq_questions')->cascadeOnDelete();
            $table->unique(['faq_id', 'page_type', 'page_slug', 'locale'], 'faq_assignment_unique');
            $table->index(['page_type', 'page_slug', 'locale', 'weight'], 'faq_assignment_lookup');
        });

        Schema::create('faq_opportunity_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('page_type', 40)->index();
            $table->string('page_slug')->index();
            $table->string('locale', 5)->index();
            $table->string('page_title')->nullable();
            $table->string('sector')->nullable();
            $table->string('solution_type')->nullable();
            $table->decimal('faq_coverage_score', 5, 2)->default(0);
            $table->decimal('faq_opportunity_score', 5, 2)->default(0);
            $table->decimal('ai_visibility_impact_score', 5, 2)->default(0);
            $table->decimal('seo_impact_score', 5, 2)->default(0);
            $table->decimal('conversion_impact_score', 5, 2)->default(0);
            $table->json('score_rationale')->nullable();
            $table->json('missing_questions')->nullable();
            $table->json('generated_faqs')->nullable();
            $table->json('suggested_internal_links')->nullable();
            $table->json('suggested_ctas')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['page_type', 'page_slug', 'locale', 'created_at'], 'faq_audit_page_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_opportunity_audits');
        Schema::dropIfExists('faq_page_assignments');
        Schema::dropIfExists('faq_questions');
    }
};
