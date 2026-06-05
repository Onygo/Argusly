<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_ai_seo_scores')) {
            return;
        }

        Schema::table('content_ai_seo_scores', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_ai_seo_scores', 'analytics_site_id')) {
                $table->uuid('analytics_site_id')->nullable()->after('id');
            }

            if (! Schema::hasColumn('content_ai_seo_scores', 'url_key')) {
                $table->string('url_key', 512)->nullable()->after('url');
            }

            if (! Schema::hasColumn('content_ai_seo_scores', 'content_metrics_updated_at')) {
                $table->timestamp('content_metrics_updated_at')->nullable()->after('calculated_at');
            }

            if (! Schema::hasColumn('content_ai_seo_scores', 'ai_visibility_updated_at')) {
                $table->timestamp('ai_visibility_updated_at')->nullable()->after('content_metrics_updated_at');
            }

            if (! Schema::hasColumn('content_ai_seo_scores', 'formula_version')) {
                $table->string('formula_version', 32)->nullable()->after('weights_json');
            }

            if (! Schema::hasColumn('content_ai_seo_scores', 'inputs_json')) {
                $table->json('inputs_json')->nullable()->after('formula_version');
            }
        });

        Schema::table('content_ai_seo_scores', function (Blueprint $table): void {
            $table->index(['analytics_site_id', 'url_key'], 'content_ai_seo_scores_site_url_key_idx');
            $table->index(['analytics_site_id', 'calculated_at'], 'content_ai_seo_scores_site_calculated_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('content_ai_seo_scores')) {
            return;
        }

        Schema::table('content_ai_seo_scores', function (Blueprint $table): void {
            $table->dropIndex('content_ai_seo_scores_site_url_key_idx');
            $table->dropIndex('content_ai_seo_scores_site_calculated_idx');

            if (Schema::hasColumn('content_ai_seo_scores', 'inputs_json')) {
                $table->dropColumn('inputs_json');
            }

            if (Schema::hasColumn('content_ai_seo_scores', 'formula_version')) {
                $table->dropColumn('formula_version');
            }

            if (Schema::hasColumn('content_ai_seo_scores', 'ai_visibility_updated_at')) {
                $table->dropColumn('ai_visibility_updated_at');
            }

            if (Schema::hasColumn('content_ai_seo_scores', 'content_metrics_updated_at')) {
                $table->dropColumn('content_metrics_updated_at');
            }

            if (Schema::hasColumn('content_ai_seo_scores', 'url_key')) {
                $table->dropColumn('url_key');
            }

            if (Schema::hasColumn('content_ai_seo_scores', 'analytics_site_id')) {
                $table->dropColumn('analytics_site_id');
            }
        });
    }
};
