<?php

namespace App\Jobs\Faq;

use App\Enums\FaqWorkflowStatus;
use App\Models\FaqOpportunityAudit;
use App\Repositories\FaqQuestionRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecalculateFaqCoverageScoresJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $auditId) {}

    public function handle(FaqQuestionRepository $faqs): void
    {
        $audit = FaqOpportunityAudit::query()->find($this->auditId);

        if (! $audit) {
            return;
        }

        $published = $faqs->publishedForPage($audit->page_type, $audit->page_slug, $audit->locale)->count();
        $missing = collect((array) $audit->missing_questions)->flatten()->count();
        $coverage = max(0, min(100, ($published * 12) - ($missing * 4) + 35));

        $audit->update([
            'status' => $published > 0 ? FaqWorkflowStatus::PUBLISHED->value : $audit->status,
            'faq_coverage_score' => $coverage,
            'completed_at' => now(),
        ]);
    }
}
