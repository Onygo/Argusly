<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_tracking_queries', function (Blueprint $table): void {
            if (! Schema::hasColumn('llm_tracking_queries', 'frequency')) {
                $table->string('frequency', 16)->default('daily')->after('locale');
            }
        });

        Schema::table('llm_tracking_query_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('llm_tracking_query_runs', 'detected_brands')) {
                $table->json('detected_brands')->nullable()->after('competitor_hits');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'detected_competitors')) {
                $table->json('detected_competitors')->nullable()->after('detected_brands');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'entity_presence')) {
                $table->json('entity_presence')->nullable()->after('detected_competitors');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'presence_score')) {
                $table->decimal('presence_score', 6, 4)->nullable()->after('competitors_mentioned');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'position_score')) {
                $table->decimal('position_score', 6, 4)->nullable()->after('presence_score');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'sentiment_score')) {
                $table->decimal('sentiment_score', 6, 4)->nullable()->after('position_score');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'sentiment_label')) {
                $table->string('sentiment_label', 24)->nullable()->after('sentiment_score');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'competitive_score')) {
                $table->decimal('competitive_score', 6, 4)->nullable()->after('sentiment_label');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'ai_visibility_score')) {
                $table->decimal('ai_visibility_score', 6, 4)->nullable()->after('competitive_score');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'visibility_breakdown')) {
                $table->json('visibility_breakdown')->nullable()->after('ai_visibility_score');
            }
        });
    }

    public function down(): void
    {
        Schema::table('llm_tracking_query_runs', function (Blueprint $table): void {
            foreach ([
                'detected_brands',
                'detected_competitors',
                'entity_presence',
                'presence_score',
                'position_score',
                'sentiment_score',
                'sentiment_label',
                'competitive_score',
                'ai_visibility_score',
                'visibility_breakdown',
            ] as $column) {
                if (Schema::hasColumn('llm_tracking_query_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('llm_tracking_queries', function (Blueprint $table): void {
            if (Schema::hasColumn('llm_tracking_queries', 'frequency')) {
                $table->dropColumn('frequency');
            }
        });
    }
};
