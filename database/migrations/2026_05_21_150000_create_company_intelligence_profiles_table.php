<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_intelligence_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('company_profile_id')->nullable()->index();
            $table->uuid('brand_voice_id')->nullable()->index();
            $table->string('brand_key', 120)->default('primary');
            $table->string('company_name');
            $table->text('company_description')->nullable();
            $table->string('market_category')->nullable();
            $table->text('positioning')->nullable();
            $table->text('uvp')->nullable();
            $table->json('products_services')->nullable();
            $table->string('pricing_model')->nullable();
            $table->json('regions')->nullable();
            $table->json('locales')->nullable();
            $table->json('icps')->nullable();
            $table->json('personas')->nullable();
            $table->json('buyer_roles')->nullable();
            $table->json('pain_points')->nullable();
            $table->json('objections')->nullable();
            $table->json('buying_triggers')->nullable();
            $table->json('funnel_stages')->nullable();
            $table->text('tone_of_voice')->nullable();
            $table->json('banned_phrases')->nullable();
            $table->json('messaging_rules')->nullable();
            $table->json('brand_differentiators')->nullable();
            $table->json('proof_points')->nullable();
            $table->json('primary_topics')->nullable();
            $table->json('authority_areas')->nullable();
            $table->json('target_entities')->nullable();
            $table->json('strategic_keywords')->nullable();
            $table->json('query_intents')->nullable();
            $table->json('direct_competitors')->nullable();
            $table->json('indirect_competitors')->nullable();
            $table->json('aspirational_competitors')->nullable();
            $table->json('normalized_payload')->nullable();
            $table->char('normalized_payload_hash', 64)->nullable()->index();
            $table->unsignedTinyInteger('completeness_score')->default(0)->index();
            $table->json('completeness_breakdown')->nullable();
            $table->string('embedding_status', 32)->default('not_ready')->index();
            $table->char('embedding_payload_hash', 64)->nullable()->index();
            $table->text('embedding_vector')->nullable();
            $table->string('source_type', 64)->default('manual')->index();
            $table->boolean('is_default')->default(false)->index();
            $table->string('status', 32)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('company_profile_id')->references('id')->on('company_profiles')->nullOnDelete();
            $table->foreign('brand_voice_id')->references('id')->on('brand_voices')->nullOnDelete();
            $table->unique(['workspace_id', 'brand_key'], 'company_intel_workspace_brand_key_unique');
            $table->index(['organization_id', 'status', 'is_default'], 'company_intel_org_status_default_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_intelligence_profiles');
    }
};
