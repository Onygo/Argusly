<?php

namespace App\Console\Commands;

use App\Jobs\Stats\RecalculateAiSeoScoresJob;
use App\Services\Stats\AiSeoScoreCalculator;
use Illuminate\Console\Command;

class RecalculateAiSeoScoresCommand extends Command
{
    protected $signature = 'stats:recalculate-ai-seo-scores
        {--site= : Optional analytics_site_id}
        {--async : Queue the recalculation job instead of running inline}
        {--queue=default : Queue name when using --async}';

    protected $description = 'Recalculate AI SEO scores for tracked content URLs.';

    public function handle(AiSeoScoreCalculator $calculator): int
    {
        $siteId = trim((string) $this->option('site'));
        $siteId = $siteId !== '' ? $siteId : null;

        if ((bool) $this->option('async')) {
            RecalculateAiSeoScoresJob::dispatch($siteId)->onQueue((string) $this->option('queue'));
            $this->info('AI SEO score recalculation job queued.');

            return self::SUCCESS;
        }

        $summary = $calculator->recalculate($siteId);

        $this->line('urls processed: ' . (int) $summary['processed']);
        $this->line('min ai_seo_score: ' . number_format((float) $summary['min'], 2));
        $this->line('max ai_seo_score: ' . number_format((float) $summary['max'], 2));
        $this->line('avg ai_seo_score: ' . number_format((float) $summary['avg'], 2));
        $this->line('normalization p05/p95: ' . number_format((float) $summary['p05'], 2) . ' / ' . number_format((float) $summary['p95'], 2));
        $this->line('formula version: ' . (string) ($summary['formula_version'] ?? 'unknown'));
        $this->line('weights: ' . json_encode((array) ($summary['weights'] ?? []), JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
