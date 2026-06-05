<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_tracking_query_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('llm_tracking_query_runs', 'answer_text')) {
                $table->longText('answer_text')->nullable()->after('parsed_payload');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'answer_json')) {
                $table->json('answer_json')->nullable()->after('answer_text');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'brand_hits')) {
                $table->json('brand_hits')->nullable()->after('answer_json');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'competitor_hits')) {
                $table->json('competitor_hits')->nullable()->after('brand_hits');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'url_hits')) {
                $table->json('url_hits')->nullable()->after('competitor_hits');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'citation_ranking')) {
                $table->json('citation_ranking')->nullable()->after('url_hits');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'sources')) {
                $table->json('sources')->nullable()->after('citation_ranking');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'share_of_voice_snapshot')) {
                $table->json('share_of_voice_snapshot')->nullable()->after('sources');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'suggestions')) {
                $table->json('suggestions')->nullable()->after('share_of_voice_snapshot');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'cached_key')) {
                $table->string('cached_key', 64)->nullable()->after('suggestions');
                $table->index(['cached_key'], 'llm_track_runs_cached_key_idx');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'is_cached')) {
                $table->boolean('is_cached')->default(false)->after('cached_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('llm_tracking_query_runs', function (Blueprint $table): void {
            if (Schema::hasColumn('llm_tracking_query_runs', 'cached_key')) {
                $table->dropIndex('llm_track_runs_cached_key_idx');
            }

            foreach ([
                'answer_text',
                'answer_json',
                'brand_hits',
                'competitor_hits',
                'url_hits',
                'citation_ranking',
                'sources',
                'share_of_voice_snapshot',
                'suggestions',
                'cached_key',
                'is_cached',
            ] as $column) {
                if (Schema::hasColumn('llm_tracking_query_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
