<?php

namespace App\Jobs\Faq;

use App\Data\Faq\FaqPageInput;
use App\Enums\FaqWorkflowStatus;
use App\Models\FaqOpportunityAudit;
use App\Services\Faq\FaqOpportunityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class AnalyzeFaqPageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        public readonly array $payload,
        public readonly ?int $userId = null,
    ) {}

    public function handle(FaqOpportunityService $service): void
    {
        $audit = FaqOpportunityAudit::query()->create([
            'page_type' => (string) ($this->payload['page_type'] ?? 'resource'),
            'page_slug' => (string) ($this->payload['page_slug'] ?? 'unknown'),
            'locale' => strtolower((string) ($this->payload['locale'] ?? app()->getLocale())),
            'page_title' => (string) ($this->payload['page_title'] ?? ''),
            'sector' => (string) ($this->payload['sector'] ?? ''),
            'solution_type' => (string) ($this->payload['solution_type'] ?? ''),
            'status' => FaqWorkflowStatus::ANALYZING->value,
            'created_by' => $this->userId,
        ]);

        try {
            $result = $service->analyze(FaqPageInput::fromArray($this->payload), $this->userId, persist: false);

            $audit->update([
                'status' => FaqWorkflowStatus::REVIEW_REQUIRED->value,
                'faq_coverage_score' => $result['scores']['faq_coverage_score']['score'],
                'faq_opportunity_score' => $result['scores']['faq_opportunity_score']['score'],
                'ai_visibility_impact_score' => $result['scores']['ai_visibility_impact_score']['score'],
                'seo_impact_score' => $result['scores']['seo_impact_score']['score'],
                'conversion_impact_score' => $result['scores']['conversion_impact_score']['score'],
                'score_rationale' => $result['scores'],
                'missing_questions' => $result['detected_gaps'],
                'generated_faqs' => $result['recommended_faqs'],
                'suggested_internal_links' => $result['internal_link_opportunities'],
                'suggested_ctas' => $result['suggested_ctas'],
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $audit->update([
                'status' => FaqWorkflowStatus::FAILED->value,
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }
}
