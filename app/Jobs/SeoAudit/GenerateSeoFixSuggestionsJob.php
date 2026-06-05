<?php

namespace App\Jobs\SeoAudit;

use App\Models\Content;
use App\Models\SeoAudit;
use App\Models\SeoAuditFixSuggestion;
use App\Models\SeoAuditIssue;
use App\Models\User;
use App\Services\CreditReservationService;
use App\Services\SeoAudit\SeoAuditAiFixService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateSeoFixSuggestionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /**
     * @param array<int,int> $issueIds
     */
    public function __construct(
        public readonly int $auditId,
        public readonly array $issueIds,
        public readonly int $userId,
    ) {}

    public function handle(SeoAuditAiFixService $aiFixService, CreditReservationService $reservations): void
    {
        $audit = SeoAudit::query()->with(['site', 'workspace'])->find($this->auditId);
        if (! $audit || ! $audit->site) {
            return;
        }

        $user = User::query()->find($this->userId);
        if (! $user) {
            return;
        }

        $selectedIssueIds = collect($this->issueIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($selectedIssueIds === []) {
            return;
        }

        $issues = SeoAuditIssue::query()
            ->where('seo_audit_id', $audit->id)
            ->whereIn('id', $selectedIssueIds)
            ->with(['page.publishlayerArticle.seo'])
            ->get();

        foreach ($issues as $issue) {
            $page = $issue->page;
            if (! $page || ! $aiFixService->isSupportedIssueCode((string) $issue->code)) {
                continue;
            }

            $content = $page->publishlayerArticle;
            if ($content && ! $content instanceof Content) {
                $content = null;
            }

            $snapshot = $aiFixService->buildInputSnapshot($page, $issue, $content);
            $suggestion = SeoAuditFixSuggestion::query()
                ->where('seo_audit_id', $audit->id)
                ->where('seo_audit_page_id', $page->id)
                ->where('issue_code', (string) $issue->code)
                ->first();

            if ($suggestion && in_array((string) $suggestion->status, [SeoAuditFixSuggestion::STATUS_GENERATED, SeoAuditFixSuggestion::STATUS_APPLIED], true)) {
                Log::info('SEO audit AI fix skipped already-generated suggestion', [
                    'audit_id' => $audit->id,
                    'suggestion_id' => $suggestion->id,
                    'issue_code' => $issue->code,
                    'page_id' => $page->id,
                ]);

                continue;
            }

            if (! $suggestion) {
                $suggestion = SeoAuditFixSuggestion::query()->create([
                    'organization_id' => $audit->workspace?->organization_id,
                    'workspace_id' => $audit->workspace_id,
                    'client_site_id' => $audit->client_site_id,
                    'seo_audit_id' => $audit->id,
                    'seo_audit_page_id' => $page->id,
                    'issue_code' => (string) $issue->code,
                    'status' => SeoAuditFixSuggestion::STATUS_PENDING,
                    'suggestion_state' => SeoAuditFixSuggestion::STATE_SUGGESTED,
                    'input_snapshot' => $snapshot,
                    'created_by' => $this->userId,
                ]);
            } else {
                $suggestion->update([
                    'status' => SeoAuditFixSuggestion::STATUS_PENDING,
                    'suggestion_state' => SeoAuditFixSuggestion::STATE_SUGGESTED,
                    'input_snapshot' => $snapshot,
                    'token_usage' => null,
                ]);
            }

            $reservation = null;
            $creditCost = $aiFixService->creditCostPerSuggestion();

            try {
                $reservation = $reservations->reserve(
                    clientSiteId: (string) $audit->client_site_id,
                    amount: $creditCost,
                    idempotencyKey: 'seo_audit_fix:suggestion:' . $suggestion->id,
                    purpose: 'seo_audit_ai_fix',
                    context: $content,
                    options: [
                        'userId' => $this->userId,
                        'metadata' => [
                            'seo_audit_id' => (int) $audit->id,
                            'seo_audit_page_id' => (int) $page->id,
                            'seo_audit_fix_suggestion_id' => (int) $suggestion->id,
                            'issue_code' => (string) $issue->code,
                        ],
                    ],
                );

                $result = $aiFixService->generateSuggestion($audit, $page, $issue, $content, $this->userId);

                $reservations->capture($reservation, [
                    'userId' => $this->userId,
                    'metadata' => [
                        'seo_audit_fix_suggestion_id' => (int) $suggestion->id,
                        'seo_audit_id' => (int) $audit->id,
                    ],
                ]);

                $suggestion->update([
                    'status' => SeoAuditFixSuggestion::STATUS_GENERATED,
                    'suggestion_state' => SeoAuditFixSuggestion::STATE_SUGGESTED,
                    'input_snapshot' => $result['input_snapshot'],
                    'suggestion' => $result['suggestion'],
                    'token_usage' => array_merge((array) $result['token_usage'], [
                        'provider' => $result['provider'],
                        'model' => $result['model'],
                        'request_id' => $result['request_id'],
                    ]),
                    'credits_reserved' => $creditCost,
                    'credits_charged' => $creditCost,
                ]);

                Log::info('SEO audit AI fix suggestion generated', [
                    'audit_id' => $audit->id,
                    'suggestion_id' => $suggestion->id,
                    'issue_code' => $issue->code,
                    'page_id' => $page->id,
                    'credits_charged' => $creditCost,
                ]);
            } catch (\Throwable $exception) {
                if ($reservation && $reservation->isReserved()) {
                    $reservations->release($reservation, 'seo_audit_ai_fix_failed', [
                        'userId' => $this->userId,
                        'failureCode' => 'generation_failed',
                    ]);
                }

                $suggestion->update([
                    'status' => SeoAuditFixSuggestion::STATUS_FAILED,
                    'suggestion_state' => SeoAuditFixSuggestion::STATE_SUGGESTED,
                    'credits_reserved' => $creditCost,
                    'credits_charged' => 0,
                    'token_usage' => [
                        'error' => 'generation_failed',
                    ],
                ]);

                Log::warning('SEO audit AI fix suggestion failed', [
                    'audit_id' => $audit->id,
                    'suggestion_id' => $suggestion->id,
                    'issue_code' => $issue->code,
                    'page_id' => $page->id,
                    'exception_class' => get_class($exception),
                    'exception_message' => $exception->getMessage(),
                ]);
            }
        }
    }
}
