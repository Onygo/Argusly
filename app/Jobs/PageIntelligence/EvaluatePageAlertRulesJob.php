<?php

namespace App\Jobs\PageIntelligence;

use App\Services\PageIntelligence\Alerts\PageAlertRuleEvaluator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class EvaluatePageAlertRulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $backoff = 60;

    public function __construct(public readonly ?string $alertRuleId = null)
    {
        $this->onQueue((string) config('page_intelligence.queues.alert', 'page_intelligence_alert'));
    }

    public function handle(PageAlertRuleEvaluator $evaluator): void
    {
        $evaluator->evaluate($this->alertRuleId);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('page-intelligence:alert:'.($this->alertRuleId ?: 'all')))->releaseAfter(60)->expireAfter(600),
        ];
    }
}
