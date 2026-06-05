<?php

namespace App\Services\Content;

use App\Models\Content;
use App\Models\ContentSeries;
use App\Models\ContentSeriesArticle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContentSeriesArticleSyncService
{
    /**
     * @return Collection<int,ContentSeriesArticle>
     */
    public function sync(ContentSeries $series): Collection
    {
        return DB::transaction(function () use ($series): Collection {
            /** @var ContentSeries $lockedSeries */
            $lockedSeries = ContentSeries::query()
                ->lockForUpdate()
                ->findOrFail($series->id);

            $strategyArticles = $this->strategyArticles($lockedSeries);
            $plannedUrls = $this->plannedUrls($lockedSeries);
            $existingRows = $lockedSeries->seriesArticles()
                ->orderBy('article_number')
                ->get()
                ->keyBy('article_number');
            $contentRows = $this->contentsByArticleNumber($lockedSeries);

            $targetNumbers = collect(range(1, max(1, (int) $lockedSeries->articles_count)))
                ->merge($strategyArticles->keys())
                ->merge($contentRows->keys())
                ->merge($existingRows->keys())
                ->map(fn ($value) => (int) $value)
                ->filter(fn (int $value) => $value > 0)
                ->unique()
                ->sort()
                ->values();

            foreach ($targetNumbers as $articleNumber) {
                $existing = $existingRows->get($articleNumber);
                $strategy = (array) ($strategyArticles->get($articleNumber) ?? []);
                /** @var Content|null $content */
                $content = $contentRows->get($articleNumber);

                $payload = [
                    'content_id' => $content?->id ?: $existing?->content_id,
                    'title' => $this->nullableString(data_get($strategy, 'title'))
                        ?: $this->nullableString($content?->title)
                        ?: $existing?->title,
                    'primary_keyword' => $this->nullableString(data_get($strategy, 'primary_keyword'))
                        ?: $this->nullableString($content?->primary_keyword)
                        ?: $existing?->primary_keyword,
                    'secondary_keywords' => $this->stringList(data_get($strategy, 'secondary_keywords', $existing?->secondary_keywords ?? [])),
                    'internal_links_to' => $this->intList(data_get($strategy, 'internal_links_to', $existing?->internal_links_to ?? []), $articleNumber),
                    'planned_url' => $this->nullableString($plannedUrls->get($articleNumber))
                        ?: $existing?->planned_url,
                    'meta' => is_array($existing?->meta) ? $existing->meta : [],
                ];

                if ($existing) {
                    $existing->fill($payload)->save();
                    continue;
                }

                ContentSeriesArticle::query()->create([
                    'series_id' => (string) $lockedSeries->id,
                    'article_number' => $articleNumber,
                    'is_pillar' => false,
                    ...$payload,
                ]);
            }

            $this->normalizePillar($lockedSeries);

            return $lockedSeries->fresh('seriesArticles.content')->seriesArticles
                ->sortBy('article_number')
                ->values();
        });
    }

    public function setPillar(ContentSeries $series, int $articleNumber): ContentSeriesArticle
    {
        return DB::transaction(function () use ($series, $articleNumber): ContentSeriesArticle {
            $rows = $this->sync($series)->keyBy('article_number');
            /** @var ContentSeriesArticle|null $target */
            $target = $rows->get($articleNumber);

            if (! $target) {
                throw ValidationException::withMessages([
                    'pillar_article_number' => 'The selected article is not part of this chain.',
                ]);
            }

            ContentSeriesArticle::query()
                ->where('series_id', (string) $series->id)
                ->where('is_pillar', true)
                ->where('id', '!=', (string) $target->id)
                ->update([
                    'is_pillar' => false,
                    'pillar_series_id' => null,
                    'updated_at' => now(),
                ]);

            $target->forceFill([
                'is_pillar' => true,
                'pillar_series_id' => (string) $series->id,
            ])->save();

            $this->syncStrategyJsonPillar($series->fresh(), $articleNumber);

            return $target->fresh(['content']);
        });
    }

    public function clearPillar(ContentSeries $series): void
    {
        DB::transaction(function () use ($series): void {
            ContentSeriesArticle::query()
                ->where('series_id', (string) $series->id)
                ->where('is_pillar', true)
                ->update([
                    'is_pillar' => false,
                    'pillar_series_id' => null,
                    'updated_at' => now(),
                ]);

            $this->syncStrategyJsonPillar($series->fresh(), null);
        });
    }

    public function syncContent(Content $content): ?ContentSeriesArticle
    {
        $seriesId = trim((string) ($content->series_id ?? ''));
        if ($seriesId === '') {
            $this->detachContent($content);

            return null;
        }

        $series = $content->series ?: ContentSeries::query()->find($seriesId);
        if (! $series) {
            return null;
        }

        $articleNumber = $this->parseArticleNumber((string) ($content->external_key ?? ''), (string) $series->id);
        if ($articleNumber < 1) {
            return null;
        }

        $rows = $this->sync($series)->keyBy('article_number');
        /** @var ContentSeriesArticle|null $row */
        $row = $rows->get($articleNumber);
        if (! $row) {
            return null;
        }

        $row->update([
            'content_id' => (string) $content->id,
            'title' => $this->nullableString($content->title) ?: $row->title,
            'primary_keyword' => $this->nullableString($content->primary_keyword) ?: $row->primary_keyword,
        ]);

        return $row->fresh(['content']);
    }

    public function detachContent(Content $content): void
    {
        $rows = ContentSeriesArticle::query()
            ->where('content_id', (string) $content->id)
            ->get();

        foreach ($rows as $row) {
            $wasPillar = (bool) $row->is_pillar;

            $row->update([
                'content_id' => null,
                'is_pillar' => false,
                'pillar_series_id' => null,
            ]);

            if ($wasPillar) {
                $this->syncStrategyJsonPillar($row->series()->firstOrFail(), null);
            }
        }
    }

    private function normalizePillar(ContentSeries $series): void
    {
        $pillars = $series->seriesArticles()
            ->where('is_pillar', true)
            ->orderBy('article_number')
            ->get();

        if ($pillars->count() <= 1) {
            return;
        }

        $keep = $pillars->shift();
        if (! $keep) {
            return;
        }

        ContentSeriesArticle::query()
            ->where('series_id', (string) $series->id)
            ->where('is_pillar', true)
            ->where('id', '!=', (string) $keep->id)
            ->update([
                'is_pillar' => false,
                'pillar_series_id' => null,
                'updated_at' => now(),
            ]);
    }

    private function syncStrategyJsonPillar(ContentSeries $series, ?int $pillarArticleNumber): void
    {
        $strategy = is_array($series->strategy_json) ? $series->strategy_json : [];
        $articles = collect((array) data_get($strategy, 'articles', []))
            ->map(function ($row, $index) use ($pillarArticleNumber): array {
                $row = is_array($row) ? $row : [];
                $articleNumber = (int) data_get($row, 'article_number', $index + 1);
                $row['article_number'] = $articleNumber;
                $row['is_pillar'] = $pillarArticleNumber !== null && $articleNumber === $pillarArticleNumber;

                return $row;
            })
            ->values()
            ->all();

        $strategy['articles'] = $articles;

        $series->forceFill(['strategy_json' => $strategy])->save();
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function strategyArticles(ContentSeries $series): Collection
    {
        return collect((array) data_get($series->strategy_json, 'articles', []))
            ->filter(fn ($row) => is_array($row))
            ->mapWithKeys(function (array $row, int $index): array {
                $articleNumber = (int) data_get($row, 'article_number', $index + 1);

                return $articleNumber > 0 ? [$articleNumber => $row] : [];
            });
    }

    /**
     * @return Collection<int,string>
     */
    private function plannedUrls(ContentSeries $series): Collection
    {
        return collect((array) data_get($series->publish_plan_json, 'articles', []))
            ->filter(fn ($row) => is_array($row))
            ->mapWithKeys(function (array $row): array {
                $articleNumber = (int) data_get($row, 'article_number', 0);
                $plannedUrl = trim((string) data_get($row, 'planned_url', ''));

                return $articleNumber > 0 && $plannedUrl !== ''
                    ? [$articleNumber => $plannedUrl]
                    : [];
            });
    }

    /**
     * @return Collection<int,Content>
     */
    private function contentsByArticleNumber(ContentSeries $series): Collection
    {
        return Content::query()
            ->where('series_id', (string) $series->id)
            ->orderBy('created_at')
            ->get()
            ->mapWithKeys(function (Content $content) use ($series): array {
                $articleNumber = $this->parseArticleNumber((string) ($content->external_key ?? ''), (string) $series->id);

                return $articleNumber > 0 ? [$articleNumber => $content] : [];
            });
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
            ->unique()
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
}
