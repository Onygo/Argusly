<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('query_intent_classifications');

        Schema::create('query_intent_classifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->uuid('workspace_id')->nullable();
            $table->uuid('client_site_id')->nullable();
            $table->nullableUuidMorphs('classifiable', 'query_intel_classifiable_idx');
            $table->string('source_type', 80)->default('manual');
            $table->string('source_key', 191)->nullable();
            $table->string('locale', 20)->nullable();
            $table->string('title', 255)->nullable();
            $table->string('query', 255)->nullable();
            $table->text('text_excerpt')->nullable();
            $table->string('primary_intent', 80);
            $table->json('secondary_intents')->nullable();
            $table->string('funnel_stage', 40);
            $table->string('buyer_role', 80);
            $table->string('urgency', 40);
            $table->string('business_impact', 40);
            $table->decimal('intent_confidence', 5, 2)->default(0);
            $table->decimal('urgency_score', 5, 2)->default(0);
            $table->decimal('business_impact_score', 5, 2)->default(0);
            $table->decimal('priority_score', 5, 2)->default(0);
            $table->json('score_breakdown')->nullable();
            $table->json('signals')->nullable();
            $table->json('ai_enrichment')->nullable();
            $table->json('normalized_payload')->nullable();
            $table->string('payload_hash', 64);
            $table->timestamp('classified_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'payload_hash'], 'query_intel_workspace_payload_unique');
            $table->index(['workspace_id', 'primary_intent', 'funnel_stage'], 'query_intel_intent_stage_idx');
            $table->index(['workspace_id', 'buyer_role', 'business_impact'], 'query_intel_audience_impact_idx');
            $table->index(['source_type', 'source_key'], 'query_intel_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_intent_classifications');
    }
};
