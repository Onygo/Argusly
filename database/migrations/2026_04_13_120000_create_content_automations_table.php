<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_automations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->uuid('workspace_id');
            $table->uuid('client_site_id')->nullable();
            $table->uuid('content_destination_id')->nullable();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->string('mode', 48)->default('chain');
            $table->string('publication_mode', 48)->default('draft_only');
            $table->unsignedInteger('generation_frequency_value')->default(3);
            $table->string('generation_frequency_unit', 24)->default('days');
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('chain_size')->default(5);
            $table->string('locale', 10)->default('en');
            $table->json('locales')->nullable();
            $table->text('topic_scope');
            $table->text('content_goal')->nullable();
            $table->text('company_context_override')->nullable();
            $table->uuid('use_brand_voice_id')->nullable();
            $table->unsignedBigInteger('use_team_persona_id')->nullable();
            $table->unsignedBigInteger('use_buyer_persona_id')->nullable();
            $table->boolean('include_internal_linking')->default(false);
            $table->boolean('include_translation')->default(false);
            $table->boolean('avoid_topic_overlap')->default(true);
            $table->string('funnel_stage', 64)->nullable();
            $table->string('campaign_context', 191)->nullable();
            $table->json('pillar_strategy')->nullable();
            $table->json('settings')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'is_active', 'next_run_at'], 'content_automations_due_idx');
            $table->index(['workspace_id', 'client_site_id'], 'content_automations_scope_idx');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('content_destination_id')->references('id')->on('content_destinations')->nullOnDelete();
            $table->foreign('use_brand_voice_id')->references('id')->on('brand_voices')->nullOnDelete();
            $table->foreign('use_team_persona_id')->references('id')->on('team_members')->nullOnDelete();
            $table->foreign('use_buyer_persona_id')->references('id')->on('personas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_automations');
    }
};
