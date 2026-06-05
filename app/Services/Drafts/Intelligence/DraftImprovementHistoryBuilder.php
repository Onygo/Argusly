<?php

namespace App\Services\Drafts\Intelligence;

use App\Enums\DraftImprovementAction;
use App\Models\Draft;
use App\Models\DraftAnalysis;
use App\Models\DraftImprovementResult;

class DraftImprovementHistoryBuilder
{
    public function queue(Draft $draft, DraftImprovementAction $action, ?string $userId, string $operationKey): DraftImprovementResult
    {
        return DraftImprovementResult::query()->updateOrCreate(
            [
                'draft_id' => (string) $draft->id,
                'operation_key' => $operationKey,
            ],
            [
                'before_analysis_id' => $draft->analysis?->id,
                'action' => $action->value,
                'status' => 'queued',
                'requested_by_user_id' => $userId,
                'before_content_hash' => $this->contentHash($draft),
                'affected_sections' => $this->affectedSections($action),
                'summary' => null,
                'change_notes' => [],
                'fully_applied' => false,
                'error' => null,
            ],
        );
    }

    public function markProcessing(Draft $draft, DraftImprovementAction $action, string $operationKey, ?string $userId): DraftImprovementResult
    {
        $result = $this->queue($draft, $action, $userId, $operationKey);
        $result->forceFill([
            'status' => 'processing',
            'started_at' => $result->started_at ?: now(),
            'error' => null,
        ])->save();

        return $result;
    }

    /**
     * @param array<string,mixed> $resultPayload
     */
    public function markCompleted(
        Draft $draft,
        DraftImprovementAction $action,
        string $operationKey,
        ?string $userId,
        array $resultPayload,
    ): DraftImprovementResult {
        $result = $this->queue($draft, $action, $userId, $operationKey);
        $beforeHash = (string) ($result->before_content_hash ?: $this->contentHash($draft));
        $afterHash = $this->hashForPayload($draft, $resultPayload, $action);

        $result->forceFill([
            'status' => 'completed',
            'requested_by_user_id' => $userId,
            'provider' => $resultPayload['provider'] ?? null,
            'model_used' => $resultPayload['model_used'] ?? null,
            'request_id' => $resultPayload['request_id'] ?? null,
            'prompt_version' => $resultPayload['prompt_version'] ?? null,
            'tokens_used' => (int) ($resultPayload['tokens_used'] ?? 0),
            'summary' => $resultPayload['change_summary'] ?? $this->summarizeChangeNotes((array) ($resultPayload['change_notes'] ?? [])),
            'change_notes' => $resultPayload['change_notes'] ?? [],
            'affected_sections' => $this->affectedSections($action),
            'after_content_hash' => $afterHash,
            'fully_applied' => $beforeHash !== $afterHash,
            'completed_at' => now(),
            'error' => null,
        ])->save();

        return $result;
    }

    public function markFailed(
        Draft $draft,
        DraftImprovementAction $action,
        string $operationKey,
        ?string $userId,
        string $message,
    ): DraftImprovementResult {
        $result = $this->queue($draft, $action, $userId, $operationKey);
        $result->forceFill([
            'status' => 'failed',
            'started_at' => $result->started_at ?: now(),
            'failed_at' => now(),
            'error' => $message,
        ])->save();

        return $result;
    }

    /**
     * @param array<string,mixed> $deltaSnapshot
     */
    public function attachAnalysis(DraftImprovementResult $result, DraftAnalysis $analysis, array $deltaSnapshot): void
    {
        $result->forceFill([
            'after_analysis_id' => (string) $analysis->id,
            'score_delta_snapshot' => $deltaSnapshot,
        ])->save();
    }

    public function pendingForOperation(Draft $draft, string $operationKey): ?DraftImprovementResult
    {
        return DraftImprovementResult::query()
            ->where('draft_id', (string) $draft->id)
            ->where('operation_key', $operationKey)
            ->whereIn('status', ['completed', 'processing'])
            ->whereNull('after_analysis_id')
            ->first();
    }

    /**
     * @return array<int,string>
     */
    private function affectedSections(DraftImprovementAction $action): array
    {
        return match ($action) {
            DraftImprovementAction::FULL_DRAFT => ['seo', 'readability', 'cta', 'headings', 'llm_visibility', 'brand_voice_fit', 'conversion_fit', 'trust_evidence', 'publish_readiness'],
            DraftImprovementAction::SEO => ['seo'],
            DraftImprovementAction::READABILITY => ['readability'],
            DraftImprovementAction::CTA => ['cta'],
            DraftImprovementAction::HEADINGS => ['headings'],
        };
    }

    private function contentHash(Draft $draft): string
    {
        return sha1(implode('|', [
            (string) $draft->title,
            (string) $draft->seo_title,
            (string) $draft->seo_meta_description,
            (string) $draft->seo_h1,
            (string) $draft->content_html,
        ]));
    }

    /**
     * @param array<int,mixed> $changeNotes
     */
    private function summarizeChangeNotes(array $changeNotes): ?string
    {
        $notes = collect($changeNotes)
            ->map(fn (mixed $note): string => trim((string) $note))
            ->filter()
            ->values();

        if ($notes->isEmpty()) {
            return null;
        }

        return (string) $notes->take(2)->implode(' ');
    }

    /**
     * @param array<string,mixed> $resultPayload
     */
    private function hashForPayload(Draft $draft, array $resultPayload, DraftImprovementAction $action): string
    {
        $title = $action->allowsSeoFieldUpdates() ? (string) ($resultPayload['title'] ?? $draft->title) : (string) $draft->title;
        $seoTitle = $action->allowsSeoFieldUpdates() ? (string) ($resultPayload['seo_title'] ?? $draft->seo_title) : (string) $draft->seo_title;
        $seoMetaDescription = $action->allowsSeoFieldUpdates() ? (string) ($resultPayload['seo_meta_description'] ?? $draft->seo_meta_description) : (string) $draft->seo_meta_description;
        $seoH1 = $action->allowsSeoFieldUpdates() ? (string) ($resultPayload['seo_h1'] ?? $draft->seo_h1) : (string) $draft->seo_h1;

        return sha1(implode('|', [
            $title,
            $seoTitle,
            $seoMetaDescription,
            $seoH1,
            (string) ($resultPayload['content_html'] ?? $draft->content_html),
        ]));
    }
}
