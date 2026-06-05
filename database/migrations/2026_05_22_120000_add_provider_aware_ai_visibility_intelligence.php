<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_tracking_queries', function (Blueprint $table): void {
            if (! Schema::hasColumn('llm_tracking_queries', 'query_variants')) {
                $table->json('query_variants')->nullable()->after('query_text');
            }
        });

        Schema::table('llm_tracking_query_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('llm_tracking_query_runs', 'prompt_variant_key')) {
                $table->string('prompt_variant_key', 64)->nullable()->after('model');
            }
            if (! Schema::hasColumn('llm_tracking_query_runs', 'prompt_variant_text')) {
                $table->text('prompt_variant_text')->nullable()->after('prompt_variant_key');
            }
            if (! Schema::hasColumn('llm_tracking_query_runs', 'prompt_variant_intent')) {
                $table->string('prompt_variant_intent', 64)->nullable()->after('prompt_variant_text');
            }
            if (! Schema::hasColumn('llm_tracking_query_runs', 'provider_model_key')) {
                $table->string('provider_model_key', 190)->nullable()->after('prompt_variant_intent');
                $table->index(['provider_model_key'], 'llm_track_runs_provider_model_key_idx');
            }
            if (! Schema::hasColumn('llm_tracking_query_runs', 'authority_entities')) {
                $table->json('authority_entities')->nullable()->after('detected_competitors');
            }

            foreach ([
                'owned_visibility_score',
                'earned_visibility_score',
                'competitor_pressure_score',
                'citation_diversity_score',
                'model_confidence_score',
                'real_world_gap_score',
            ] as $column) {
                if (! Schema::hasColumn('llm_tracking_query_runs', $column)) {
                    $table->decimal($column, 6, 4)->nullable()->after('competitor_share_score');
                }
            }
        });

        if (! Schema::hasTable('llm_authority_entity_candidates')) {
            Schema::create('llm_authority_entity_candidates', function (Blueprint $table): void {
                $table->id();
                $table->uuid('workspace_id');
                $table->uuid('client_site_id');
                $table->foreignId('llm_tracking_query_id')->nullable()->constrained('llm_tracking_queries')->nullOnDelete();
                $table->foreignId('site_competitor_id')->nullable()->constrained('site_competitors')->nullOnDelete();
                $table->string('brand_name', 160);
                $table->string('normalized_name', 160);
                $table->string('entity_category', 64)->default('benchmark');
                $table->unsignedInteger('mention_count')->default(0);
                $table->decimal('average_rank', 8, 2)->nullable();
                $table->unsignedInteger('latest_rank')->nullable();
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->json('source_urls')->nullable();
                $table->json('provider_breakdown')->nullable();
                $table->json('query_breakdown')->nullable();
                $table->json('evidence')->nullable();
                $table->decimal('confidence_score', 6, 4)->nullable();
                $table->string('status', 24)->default('candidate');
                $table->timestamps();

                $table->unique(['client_site_id', 'normalized_name'], 'llm_entity_candidates_site_name_unique');
                $table->index(['workspace_id', 'client_site_id', 'status'], 'llm_entity_candidates_scope_status_idx');
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign('client_site_id')->references('id')->on('client_sites')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('llm_authority_learnings')) {
            Schema::create('llm_authority_learnings', function (Blueprint $table): void {
                $table->id();
                $table->uuid('workspace_id');
                $table->uuid('client_site_id');
                $table->foreignId('site_competitor_id')->nullable()->constrained('site_competitors')->nullOnDelete();
                $table->foreignId('llm_authority_entity_candidate_id')->nullable();
                $table->foreign('llm_authority_entity_candidate_id', 'llm_auth_learnings_candidate_fk')
                    ->references('id')
                    ->on('llm_authority_entity_candidates')
                    ->nullOnDelete();
                $table->foreignId('llm_tracking_query_id')->nullable()->constrained('llm_tracking_queries')->nullOnDelete();
                $table->string('provider', 60)->nullable();
                $table->string('learning_type', 80);
                $table->string('title', 190);
                $table->text('summary');
                $table->json('evidence')->nullable();
                $table->text('recommended_action')->nullable();
                $table->unsignedTinyInteger('priority')->default(3);
                $table->string('status', 24)->default('active');
                $table->timestamps();

                $table->index(['workspace_id', 'client_site_id', 'status'], 'llm_authority_learnings_scope_status_idx');
                $table->index(['site_competitor_id', 'learning_type'], 'llm_authority_learnings_comp_type_idx');
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign('client_site_id')->references('id')->on('client_sites')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_authority_learnings');
        Schema::dropIfExists('llm_authority_entity_candidates');

        Schema::table('llm_tracking_query_runs', function (Blueprint $table): void {
            if (Schema::hasColumn('llm_tracking_query_runs', 'provider_model_key')) {
                $table->dropIndex('llm_track_runs_provider_model_key_idx');
            }

            foreach ([
                'prompt_variant_key',
                'prompt_variant_text',
                'prompt_variant_intent',
                'provider_model_key',
                'authority_entities',
                'owned_visibility_score',
                'earned_visibility_score',
                'competitor_pressure_score',
                'citation_diversity_score',
                'model_confidence_score',
                'real_world_gap_score',
            ] as $column) {
                if (Schema::hasColumn('llm_tracking_query_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('llm_tracking_queries', function (Blueprint $table): void {
            if (Schema::hasColumn('llm_tracking_queries', 'query_variants')) {
                $table->dropColumn('query_variants');
            }
        });
    }
};
