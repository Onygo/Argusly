<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->unsignedTinyInteger('content_health_score')->nullable()->after('aeo_score');
            $table->unsignedTinyInteger('ai_visibility_score')->nullable()->after('content_health_score');
            $table->unsignedTinyInteger('semantic_coverage_score')->nullable()->after('ai_visibility_score');
            $table->unsignedTinyInteger('freshness_score')->nullable()->after('semantic_coverage_score');
            $table->unsignedTinyInteger('internal_link_score')->nullable()->after('freshness_score');
            $table->unsignedTinyInteger('answer_block_score')->nullable()->after('internal_link_score');
            $table->unsignedTinyInteger('translation_parity_score')->nullable()->after('answer_block_score');
            $table->unsignedTinyInteger('competitor_freshness_risk')->nullable()->after('translation_parity_score');
            $table->unsignedTinyInteger('optimization_opportunity_score')->nullable()->after('competitor_freshness_risk');
            $table->string('decay_risk_level', 24)->nullable()->after('optimization_opportunity_score')->index();
            $table->string('intelligence_status', 24)->nullable()->after('decay_risk_level')->index();
            $table->timestamp('content_intelligence_computed_at')->nullable()->after('intelligence_status');
            $table->timestamp('ai_optimized_at')->nullable()->after('content_intelligence_computed_at');
        });

        Schema::create('content_ai_visibility_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('content_id')->index();
            $table->string('provider', 32)->index();
            $table->unsignedTinyInteger('visibility_score')->nullable();
            $table->unsignedInteger('citation_count')->default(0);
            $table->decimal('avg_position', 8, 2)->nullable();
            $table->string('sentiment', 32)->nullable();
            $table->json('entities_detected')->nullable();
            $table->timestamp('captured_at')->index();
            $table->timestamps();

            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
            $table->index(['content_id', 'provider', 'captured_at'], 'content_ai_visibility_snapshots_lookup_idx');
        });

        Schema::create('content_recommendations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('content_id')->index();
            $table->string('type', 80)->index();
            $table->string('priority', 16)->index();
            $table->string('status', 24)->default('pending')->index();
            $table->json('payload')->nullable();
            $table->string('generated_by', 40)->nullable();
            $table->timestamps();

            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
            $table->index(['content_id', 'status', 'priority'], 'content_recommendations_status_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_recommendations');
        Schema::dropIfExists('content_ai_visibility_snapshots');

        Schema::table('contents', function (Blueprint $table): void {
            $table->dropIndex(['decay_risk_level']);
            $table->dropIndex(['intelligence_status']);
            $table->dropColumn([
                'content_health_score',
                'ai_visibility_score',
                'semantic_coverage_score',
                'freshness_score',
                'internal_link_score',
                'answer_block_score',
                'translation_parity_score',
                'competitor_freshness_risk',
                'optimization_opportunity_score',
                'decay_risk_level',
                'intelligence_status',
                'content_intelligence_computed_at',
                'ai_optimized_at',
            ]);
        });
    }
};
