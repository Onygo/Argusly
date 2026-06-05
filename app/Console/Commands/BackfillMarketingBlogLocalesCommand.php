<?php

namespace App\Console\Commands;

use App\Services\Marketing\MarketingBlogLocaleBackfillService;
use Illuminate\Console\Command;

class BackfillMarketingBlogLocalesCommand extends Command
{
    protected $signature = 'marketing:backfill-blog-locales
        {--dry-run : Preview changes only}
        {--only-misplaced-en : Only process articles that are Dutch content on the EN surface}
        {--generate-en : Generate a real EN translation variant from the NL source}
        {--publish-en : Publish the generated EN variant instead of saving it as draft}
        {--limit= : Limit the number of articles to inspect}
        {--article-id= : Only process a single content UUID}
        {--force : Apply low-confidence locale repairs instead of sending them to review}
        {--queue : Queue EN translation generation jobs}
        {--skip-if-en-exists : Skip EN generation when an EN variant already exists}
        {--refresh-existing-en : Regenerate and update an existing EN variant}';

    protected $description = 'Backfill localized marketing blog content, repair legacy EN routing, and optionally generate EN translations.';

    public function handle(MarketingBlogLocaleBackfillService $service): int
    {
        $options = [
            'dry_run' => (bool) $this->option('dry-run'),
            'only_misplaced_en' => (bool) $this->option('only-misplaced-en'),
            'generate_en' => (bool) $this->option('generate-en'),
            'publish_en' => (bool) $this->option('publish-en'),
            'limit' => $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null,
            'article_id' => $this->option('article-id') ? trim((string) $this->option('article-id')) : null,
            'force' => (bool) $this->option('force'),
            'queue' => (bool) $this->option('queue'),
            'skip_if_en_exists' => (bool) $this->option('skip-if-en-exists'),
            'refresh_existing_en' => (bool) $this->option('refresh-existing-en'),
        ];

        try {
            $report = $service->run($options);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($report['articles'] as $article) {
            $line = sprintf(
                '[%s] %s (%s) %s',
                strtoupper((string) ($article['status'] ?? 'info')),
                (string) ($article['slug'] ?? ''),
                (string) ($article['content_id'] ?? ''),
                (string) ($article['message'] ?? '')
            );

            if (($article['status'] ?? '') === 'failed') {
                $this->error($line);
            } elseif (($article['status'] ?? '') === 'review') {
                $this->warn($line);
            } else {
                $this->line($line);
            }
        }

        $this->newLine();
        $this->table(['metric', 'count'], [
            ['mode', $report['mode']],
            ['scope mode', $report['scope']['mode']],
            ['scope id', $report['scope']['id']],
            ['found', $report['found']],
            ['misplaced_en_detected', $report['misplaced_en_detected']],
            ['processed', $report['processed']],
            ['normalized_to_nl', $report['normalized_to_nl']],
            ['redirects_created', $report['redirects_created']],
            ['en_generated', $report['en_generated']],
            ['en_published', $report['en_published']],
            ['skipped', $report['skipped']],
            ['needs_review', $report['needs_review']],
            ['failed', $report['failed']],
        ]);

        if ($report['review_items'] !== []) {
            $this->newLine();
            $this->warn('Needs review');
            $this->table(
                ['content_id', 'stored', 'route', 'detected', 'confidence', 'slug', 'reason'],
                collect($report['review_items'])
                    ->map(fn (array $item): array => [
                        (string) ($item['content_id'] ?? ''),
                        (string) ($item['stored_locale'] ?? ''),
                        (string) ($item['route_locale'] ?? ''),
                        (string) ($item['detected_locale'] ?? ''),
                        (string) ($item['confidence'] ?? ''),
                        (string) ($item['slug'] ?? ''),
                        (string) ($item['reason'] ?? ''),
                    ])
                    ->all()
            );
        }

        return $report['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
