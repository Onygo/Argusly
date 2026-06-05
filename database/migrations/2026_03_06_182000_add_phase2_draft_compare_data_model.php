<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('draft_comparisons')) {
            if (! Schema::hasColumn('draft_comparisons', 'workspace_id')) {
                Schema::table('draft_comparisons', function (Blueprint $table): void {
                    $table->uuid('workspace_id')->nullable()->after('id');
                    $table->index('workspace_id', 'draft_comparisons_workspace_id_idx');
                });

                Schema::table('draft_comparisons', function (Blueprint $table): void {
                    $table->foreign('workspace_id', 'draft_comparisons_workspace_id_foreign')
                        ->references('id')
                        ->on('workspaces')
                        ->nullOnDelete();
                });
            }

            if (! Schema::hasColumn('draft_comparisons', 'source_language')) {
                Schema::table('draft_comparisons', function (Blueprint $table): void {
                    $table->string('source_language', 10)->nullable()->after('mode');
                });
            }

            if (! Schema::hasColumn('draft_comparisons', 'target_language')) {
                Schema::table('draft_comparisons', function (Blueprint $table): void {
                    $table->string('target_language', 10)->nullable()->after('source_language');
                });
            }

            if (! Schema::hasColumn('draft_comparisons', 'brand_voice_id')) {
                Schema::table('draft_comparisons', function (Blueprint $table): void {
                    $table->uuid('brand_voice_id')->nullable()->after('target_language');
                    $table->index('brand_voice_id', 'draft_comparisons_brand_voice_id_idx');
                });

                Schema::table('draft_comparisons', function (Blueprint $table): void {
                    $table->foreign('brand_voice_id', 'draft_comparisons_brand_voice_id_foreign')
                        ->references('id')
                        ->on('brand_voices')
                        ->nullOnDelete();
                });
            }

            if (! Schema::hasColumn('draft_comparisons', 'title')) {
                Schema::table('draft_comparisons', function (Blueprint $table): void {
                    $table->string('title')->nullable()->after('brand_voice_id');
                });
            }

            if (! Schema::hasColumn('draft_comparisons', 'requested_models_json')) {
                Schema::table('draft_comparisons', function (Blueprint $table): void {
                    $table->json('requested_models_json')->nullable()->after('status');
                });
            }

            if (! Schema::hasColumn('draft_comparisons', 'requested_model_count')) {
                Schema::table('draft_comparisons', function (Blueprint $table): void {
                    $table->unsignedInteger('requested_model_count')->default(0)->after('requested_models_json');
                });
            }

            if (! Schema::hasColumn('draft_comparisons', 'estimated_input_tokens')) {
                Schema::table('draft_comparisons', function (Blueprint $table): void {
                    $table->unsignedInteger('estimated_input_tokens')->nullable()->after('requested_model_count');
                });
            }

            if (! Schema::hasColumn('draft_comparisons', 'estimated_output_tokens')) {
                Schema::table('draft_comparisons', function (Blueprint $table): void {
                    $table->unsignedInteger('estimated_output_tokens')->nullable()->after('estimated_input_tokens');
                });
            }

            if (! Schema::hasColumn('draft_comparisons', 'estimated_credit_cost')) {
                Schema::table('draft_comparisons', function (Blueprint $table): void {
                    $table->unsignedInteger('estimated_credit_cost')->nullable()->after('estimated_output_tokens');
                });
            }

            if (! Schema::hasColumn('draft_comparisons', 'reserved_credit_amount')) {
                Schema::table('draft_comparisons', function (Blueprint $table): void {
                    $table->unsignedInteger('reserved_credit_amount')->nullable()->after('estimated_credit_cost');
                });
            }

            if (! Schema::hasColumn('draft_comparisons', 'final_credit_cost')) {
                Schema::table('draft_comparisons', function (Blueprint $table): void {
                    $table->unsignedInteger('final_credit_cost')->nullable()->after('reserved_credit_amount');
                });
            }

            if (! Schema::hasColumn('draft_comparisons', 'comparison_summary_json')) {
                Schema::table('draft_comparisons', function (Blueprint $table): void {
                    $table->json('comparison_summary_json')->nullable()->after('final_credit_cost');
                });
            }

            Schema::table('draft_comparisons', function (Blueprint $table): void {
                $table->index('status', 'draft_comparisons_status_idx');
            });
        }

        if (! Schema::hasTable('draft_comparison_variants')) {
            Schema::create('draft_comparison_variants', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('draft_comparison_id');
                $table->string('provider_key', 64);
                $table->string('model_key', 190);
                $table->string('display_name')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('status', 32)->default('pending');
                $table->uuid('generation_job_uuid')->nullable();
                $table->uuid('draft_id')->nullable();
                $table->json('prompt_snapshot_json')->nullable();
                $table->unsignedInteger('input_tokens')->nullable();
                $table->unsignedInteger('output_tokens')->nullable();
                $table->unsignedInteger('credit_cost')->nullable();
                $table->unsignedInteger('latency_ms')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index('draft_comparison_id', 'draft_compare_variants_comparison_idx');
                $table->index('status', 'draft_compare_variants_status_idx');
                $table->index('model_key', 'draft_compare_variants_model_idx');

                $table->foreign('draft_comparison_id', 'draft_compare_variants_comparison_foreign')
                    ->references('id')
                    ->on('draft_comparisons')
                    ->cascadeOnDelete();
                $table->foreign('draft_id', 'draft_compare_variants_draft_foreign')
                    ->references('id')
                    ->on('drafts')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasTable('draft_comparison_scores')) {
            Schema::create('draft_comparison_scores', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('draft_comparison_variant_id');
                $table->string('metric_key', 120);
                $table->string('metric_label', 255);
                $table->string('metric_group', 120)->nullable();
                $table->decimal('numeric_score', 8, 3)->nullable();
                $table->string('text_score')->nullable();
                $table->text('explanation')->nullable();
                $table->timestamps();

                $table->index('draft_comparison_variant_id', 'draft_compare_scores_variant_idx');
                $table->index('metric_key', 'draft_compare_scores_metric_key_idx');

                $table->foreign('draft_comparison_variant_id', 'draft_compare_scores_variant_foreign')
                    ->references('id')
                    ->on('draft_comparison_variants')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('draft_comparison_scores')) {
            Schema::dropIfExists('draft_comparison_scores');
        }

        if (Schema::hasTable('draft_comparison_variants')) {
            Schema::dropIfExists('draft_comparison_variants');
        }

        if (Schema::hasTable('draft_comparisons')) {
            Schema::table('draft_comparisons', function (Blueprint $table): void {
                if (Schema::hasColumn('draft_comparisons', 'comparison_summary_json')) {
                    $table->dropColumn('comparison_summary_json');
                }
                if (Schema::hasColumn('draft_comparisons', 'final_credit_cost')) {
                    $table->dropColumn('final_credit_cost');
                }
                if (Schema::hasColumn('draft_comparisons', 'reserved_credit_amount')) {
                    $table->dropColumn('reserved_credit_amount');
                }
                if (Schema::hasColumn('draft_comparisons', 'estimated_credit_cost')) {
                    $table->dropColumn('estimated_credit_cost');
                }
                if (Schema::hasColumn('draft_comparisons', 'estimated_output_tokens')) {
                    $table->dropColumn('estimated_output_tokens');
                }
                if (Schema::hasColumn('draft_comparisons', 'estimated_input_tokens')) {
                    $table->dropColumn('estimated_input_tokens');
                }
                if (Schema::hasColumn('draft_comparisons', 'requested_model_count')) {
                    $table->dropColumn('requested_model_count');
                }
                if (Schema::hasColumn('draft_comparisons', 'requested_models_json')) {
                    $table->dropColumn('requested_models_json');
                }
                if (Schema::hasColumn('draft_comparisons', 'title')) {
                    $table->dropColumn('title');
                }
                if (Schema::hasColumn('draft_comparisons', 'target_language')) {
                    $table->dropColumn('target_language');
                }
                if (Schema::hasColumn('draft_comparisons', 'source_language')) {
                    $table->dropColumn('source_language');
                }
            });

            Schema::table('draft_comparisons', function (Blueprint $table): void {
                if (Schema::hasColumn('draft_comparisons', 'brand_voice_id')) {
                    $table->dropForeign('draft_comparisons_brand_voice_id_foreign');
                    $table->dropIndex('draft_comparisons_brand_voice_id_idx');
                    $table->dropColumn('brand_voice_id');
                }

                if (Schema::hasColumn('draft_comparisons', 'workspace_id')) {
                    $table->dropForeign('draft_comparisons_workspace_id_foreign');
                    $table->dropIndex('draft_comparisons_workspace_id_idx');
                    $table->dropColumn('workspace_id');
                }

                $table->dropIndex('draft_comparisons_status_idx');
            });
        }
    }
};
