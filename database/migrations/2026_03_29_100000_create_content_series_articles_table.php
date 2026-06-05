<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_series_articles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('series_id');
            $table->uuid('content_id')->nullable();
            $table->unsignedSmallInteger('article_number');
            $table->string('title', 255)->nullable();
            $table->string('primary_keyword', 255)->nullable();
            $table->json('secondary_keywords')->nullable();
            $table->json('internal_links_to')->nullable();
            $table->string('planned_url', 2048)->nullable();
            $table->boolean('is_pillar')->default(false);
            $table->uuid('pillar_series_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['series_id', 'article_number'], 'content_series_articles_series_article_unq');
            $table->unique('content_id', 'content_series_articles_content_unq');
            $table->unique('pillar_series_id', 'content_series_articles_pillar_series_unq');
            $table->index(['series_id', 'is_pillar'], 'content_series_articles_series_pillar_idx');

            $table->foreign('series_id', 'content_series_articles_series_fk')
                ->references('id')
                ->on('content_series')
                ->cascadeOnDelete();

            $table->foreign('content_id', 'content_series_articles_content_fk')
                ->references('id')
                ->on('contents')
                ->nullOnDelete();
        });

        $this->backfillSeriesArticles();
    }

    public function down(): void
    {
        Schema::dropIfExists('content_series_articles');
    }

    private function backfillSeriesArticles(): void
    {
        $contentsBySeries = DB::table('contents')
            ->whereNotNull('series_id')
            ->orderBy('created_at')
            ->get(['id', 'series_id', 'external_key', 'title', 'primary_keyword'])
            ->groupBy('series_id');

        $seriesRows = DB::table('content_series')
            ->orderBy('created_at')
            ->get(['id', 'articles_count', 'strategy_json', 'publish_plan_json', 'created_at', 'updated_at']);

        foreach ($seriesRows as $series) {
            $strategyArticles = array_values($this->decodeJsonArray($series->strategy_json));

            $publishPlanArticles = collect((array) data_get($this->decodeJsonArray($series->publish_plan_json), 'articles', []))
                ->filter(fn ($row) => is_array($row))
                ->mapWithKeys(function (array $row): array {
                    $articleNumber = (int) data_get($row, 'article_number', 0);

                    return $articleNumber > 0
                        ? [$articleNumber => trim((string) data_get($row, 'planned_url', ''))]
                        : [];
                });

            /** @var Collection<int,object> $seriesContents */
            $seriesContents = $contentsBySeries->get($series->id, collect());
            $contentRows = [];

            foreach ($seriesContents as $content) {
                $articleNumber = $this->parseArticleNumber((string) ($content->external_key ?? ''), (string) $series->id);

                if ($articleNumber < 1) {
                    continue;
                }

                $contentRows[$articleNumber] = $content;
            }

            $targetNumbers = collect(range(1, max(1, (int) $series->articles_count)))
                ->merge(
                    collect($strategyArticles)
                        ->map(fn ($row, $index) => (int) data_get($row, 'article_number', ((int) $index) + 1))
                )
                ->merge(array_keys($contentRows))
                ->map(fn ($value) => (int) $value)
                ->filter(fn (int $value) => $value > 0)
                ->unique()
                ->sort()
                ->values();

            foreach ($targetNumbers as $articleNumber) {
                $strategy = $this->strategyRowForNumber($strategyArticles, $articleNumber);
                $content = $contentRows[$articleNumber] ?? null;

                DB::table('content_series_articles')->insert([
                    'id' => (string) Str::uuid(),
                    'series_id' => (string) $series->id,
                    'content_id' => $content?->id,
                    'article_number' => $articleNumber,
                    'title' => $this->nullableString(data_get($strategy, 'title')) ?: $this->nullableString($content?->title),
                    'primary_keyword' => $this->nullableString(data_get($strategy, 'primary_keyword')) ?: $this->nullableString($content?->primary_keyword),
                    'secondary_keywords' => json_encode($this->stringList((array) data_get($strategy, 'secondary_keywords', []))),
                    'internal_links_to' => json_encode($this->intList((array) data_get($strategy, 'internal_links_to', []), $articleNumber)),
                    'planned_url' => $this->nullableString($publishPlanArticles->get($articleNumber)),
                    'is_pillar' => false,
                    'pillar_series_id' => null,
                    'meta' => json_encode([]),
                    'created_at' => $series->created_at,
                    'updated_at' => $series->updated_at,
                ]);
            }
        }
    }

    /**
     * @return array<int,mixed>
     */
    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int,mixed> $strategyArticles
     * @return array<string,mixed>
     */
    private function strategyRowForNumber(array $strategyArticles, int $articleNumber): array
    {
        foreach (array_values($strategyArticles) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            if ((int) data_get($row, 'article_number', ((int) $index) + 1) === $articleNumber) {
                return $row;
            }
        }

        return [];
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,string>
     */
    private function stringList(array $values): array
    {
        return collect($values)
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,int>
     */
    private function intList(array $values, int $articleNumber): array
    {
        return collect($values)
            ->map(function ($value): int {
                if (is_numeric($value)) {
                    return (int) $value;
                }

                preg_match('/\d+/', (string) $value, $matches);

                return isset($matches[0]) ? (int) $matches[0] : 0;
            })
            ->filter(fn (int $value) => $value > 0 && $value !== $articleNumber)
            ->unique()
            ->values()
            ->all();
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function parseArticleNumber(string $externalKey, string $seriesId): int
    {
        $pattern = '/^series-' . preg_quote($seriesId, '/') . '-article-(\d+)$/';

        if (! preg_match($pattern, $externalKey, $matches)) {
            return 0;
        }

        return isset($matches[1]) ? max(0, (int) $matches[1]) : 0;
    }
};
