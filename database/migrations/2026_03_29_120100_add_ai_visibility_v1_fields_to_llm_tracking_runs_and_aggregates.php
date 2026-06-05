<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_tracking_query_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('llm_tracking_query_runs', 'normalized_response')) {
                $table->longText('normalized_response')->nullable()->after('answer_text');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'detected_domains')) {
                $table->json('detected_domains')->nullable()->after('sources');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'first_mention_index')) {
                $table->unsignedInteger('first_mention_index')->nullable()->after('detected_domains');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'first_mention_block')) {
                $table->string('first_mention_block', 32)->nullable()->after('first_mention_index');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'first_mention_context')) {
                $table->text('first_mention_context')->nullable()->after('first_mention_block');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'citation_score')) {
                $table->decimal('citation_score', 6, 4)->nullable()->after('position_score');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'context_score')) {
                $table->decimal('context_score', 6, 4)->nullable()->after('citation_score');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'context_label')) {
                $table->string('context_label', 24)->nullable()->after('context_score');
            }

            if (! Schema::hasColumn('llm_tracking_query_runs', 'competitor_share_score')) {
                $table->decimal('competitor_share_score', 6, 4)->nullable()->after('competitive_score');
            }
        });

        Schema::table('llm_tracking_aggregates', function (Blueprint $table): void {
            if (! Schema::hasColumn('llm_tracking_aggregates', 'provider')) {
                $table->string('provider', 60)->default('')->after('period_start');
            }
        });

        if (! $this->indexExists('llm_tracking_aggregates', 'llm_track_aggs_query_fk_idx')) {
            Schema::table('llm_tracking_aggregates', function (Blueprint $table): void {
                $table->index(['query_id'], 'llm_track_aggs_query_fk_idx');
            });
        }

        if ($this->indexExists('llm_tracking_aggregates', 'llm_track_aggs_unique_idx')) {
            Schema::table('llm_tracking_aggregates', function (Blueprint $table): void {
                $table->dropUnique('llm_track_aggs_unique_idx');
            });
        }

        if (! $this->indexExists('llm_tracking_aggregates', 'llm_track_aggs_unique_idx')) {
            Schema::table('llm_tracking_aggregates', function (Blueprint $table): void {
                $table->unique(
                    ['query_id', 'period', 'period_start', 'provider', 'model', 'locale'],
                    'llm_track_aggs_unique_idx'
                );
            });
        }

        DB::table('llm_tracking_query_runs')
            ->select(['id', 'answer_text', 'sources', 'sentiment_score', 'sentiment_label', 'competitive_score'])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $sources = json_decode((string) ($row->sources ?? '[]'), true);
                    $detectedDomains = collect(is_array($sources) ? $sources : [])
                        ->map(fn ($source): string => strtolower(trim((string) data_get($source, 'domain'))))
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();

                    DB::table('llm_tracking_query_runs')
                        ->where('id', $row->id)
                        ->update([
                            'normalized_response' => $row->answer_text,
                            'detected_domains' => $detectedDomains === [] ? null : json_encode($detectedDomains, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'citation_score' => null,
                            'context_score' => $row->sentiment_score,
                            'context_label' => $row->sentiment_label,
                            'competitor_share_score' => $row->competitive_score,
                        ]);
                }
            });
    }

    public function down(): void
    {
        if ($this->indexExists('llm_tracking_aggregates', 'llm_track_aggs_unique_idx')) {
            Schema::table('llm_tracking_aggregates', function (Blueprint $table): void {
                $table->dropUnique('llm_track_aggs_unique_idx');
            });
        }

        if (! $this->indexExists('llm_tracking_aggregates', 'llm_track_aggs_unique_idx')) {
            Schema::table('llm_tracking_aggregates', function (Blueprint $table): void {
                $table->unique(
                    ['query_id', 'period', 'period_start', 'model', 'locale'],
                    'llm_track_aggs_unique_idx'
                );
            });
        }

        Schema::table('llm_tracking_aggregates', function (Blueprint $table): void {
            if (Schema::hasColumn('llm_tracking_aggregates', 'provider')) {
                $table->dropColumn('provider');
            }
        });

        if ($this->indexExists('llm_tracking_aggregates', 'llm_track_aggs_query_fk_idx')) {
            Schema::table('llm_tracking_aggregates', function (Blueprint $table): void {
                $table->dropIndex('llm_track_aggs_query_fk_idx');
            });
        }

        Schema::table('llm_tracking_query_runs', function (Blueprint $table): void {
            foreach ([
                'normalized_response',
                'detected_domains',
                'first_mention_index',
                'first_mention_block',
                'first_mention_context',
                'citation_score',
                'context_score',
                'context_label',
                'competitor_share_score',
            ] as $column) {
                if (Schema::hasColumn('llm_tracking_query_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();
        $database = $connection->getDatabaseName();

        if ($driver === 'sqlite') {
            $indexes = DB::select('PRAGMA index_list("' . $table . '")');

            return collect($indexes)->contains(fn ($row): bool => (string) ($row->name ?? '') === $index);
        }

        if ($driver === 'mysql') {
            return DB::table('information_schema.statistics')
                ->where('table_schema', $database)
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
        }

        return false;
    }
};
