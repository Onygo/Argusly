<?php

namespace App\Jobs;

use App\Models\ContentAudit;
use App\Services\ContentAuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunContentAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $contentAuditId) {}

    public function handle(ContentAuditService $audits): void
    {
        $audit = ContentAudit::query()->findOrFail($this->contentAuditId);

        $audits->run($audit);
    }

    public function failed(Throwable $exception): void
    {
        $audit = ContentAudit::query()->find($this->contentAuditId);

        if (! $audit) {
            return;
        }

        $audit->forceFill([
            'status' => 'failed',
            'issues' => ['Audit failed: '.$exception->getMessage()],
            'recommendations' => ['Retry the audit after reviewing the content asset.'],
            'summary' => 'Content audit failed before deterministic scoring completed.',
        ])->save();
    }
}
