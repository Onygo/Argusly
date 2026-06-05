<?php

namespace App\Console\Commands;

use App\Services\Marketing\MarketingBlogLocaleBackfillService;
use Illuminate\Console\Command;

class NormalizeMarketingBlogLocalesCommand extends Command
{
    protected $signature = 'marketing:normalize-blog-locales
        {--dry-run : Preview changes only}
        {--limit-review=25 : Maximum review items to print}';

    protected $description = 'Normalize existing marketing blog locales, source variants, and legacy redirects.';

    public function handle(MarketingBlogLocaleBackfillService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limitReview = max(1, (int) $this->option('limit-review'));

        try {
            $report = $service->run([
                'dry_run' => $dryRun,
                'only_misplaced_en' => true,
                'generate_en' => false,
                'publish_en' => false,
                'limit' => null,
                'article_id' => null,
                'force' => false,
                'queue' => false,
                'skip_if_en_exists' => false,
                'refresh_existing_en' => false,
            ]);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['metric', 'count'], [
            ['mode', $report['mode']],
            ['scope mode', $report['scope']['mode']],
            ['scope id', $report['scope']['id']],
            ['blogs found', $report['found']],
            ['misplaced en detected', $report['misplaced_en_detected']],
            ['normalized to nl source', $report['normalized_to_nl']],
            ['legacy redirects created', $report['redirects_created']],
            ['unchanged', $report['skipped']],
            ['manual review', $report['needs_review']],
        ]);

        if ($report['review_items'] !== []) {
            $this->newLine();
            $this->warn('Manual review items');
            $this->table(
                ['content_id', 'stored', 'detected', 'confidence', 'slug', 'reason'],
                collect($report['review_items'])
                    ->take($limitReview)
                    ->map(fn (array $item): array => [
                        (string) ($item['content_id'] ?? ''),
                        (string) ($item['stored_locale'] ?? ''),
                        (string) ($item['detected_locale'] ?? ''),
                        (string) ($item['confidence'] ?? ''),
                        (string) ($item['slug'] ?? ''),
                        (string) ($item['reason'] ?? ''),
                    ])
                    ->all()
            );
        }

        $changed = collect($report['articles'])
            ->filter(fn (array $item): bool => in_array((string) ($item['status'] ?? ''), ['ok'], true))
            ->values();

        if ($changed->isNotEmpty()) {
            $this->newLine();
            $this->info($dryRun ? 'Planned changes' : 'Applied changes');
            $this->table(
                ['content_id', 'slug', 'status', 'message'],
                $changed
                    ->map(fn (array $item): array => [
                        (string) ($item['content_id'] ?? ''),
                        (string) ($item['slug'] ?? ''),
                        (string) ($item['status'] ?? ''),
                        (string) ($item['message'] ?? ''),
                    ])
                    ->all()
            );
        }

        return self::SUCCESS;
    }
}
