<?php

namespace App\Jobs\Faq;

use App\Enums\FaqWorkflowStatus;
use App\Models\FaqOpportunityAudit;
use App\Services\Faq\FaqSchemaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ValidateFaqSchemaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $auditId) {}

    public function handle(FaqSchemaService $schemaService): void
    {
        $audit = FaqOpportunityAudit::query()->find($this->auditId);

        if (! $audit) {
            return;
        }

        try {
            $generated = collect((array) $audit->generated_faqs)
                ->map(fn (array $faq): \App\Models\FaqQuestion => new \App\Models\FaqQuestion([
                    'question' => (string) ($faq['question'] ?? ''),
                    'answer' => (string) ($faq['answer'] ?? ''),
                ]));

            $errors = $schemaService->validate($schemaService->forQuestions($generated));

            $audit->update([
                'status' => $errors === [] ? FaqWorkflowStatus::GENERATED->value : FaqWorkflowStatus::FAILED->value,
                'error_message' => $errors === [] ? null : implode(' ', $errors),
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
