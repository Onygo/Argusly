<?php

namespace App\Services\AiTransparency;

use App\Models\AiAuditReport;
use App\Models\AiFactCheck;
use App\Models\AiHumanReview;
use App\Models\AiModelRun;
use App\Models\AiProvenanceEvent;
use App\Models\AiPromptVersion;
use App\Models\AiSourceTrace;
use App\Models\AiTransparencyRecord;
use App\Models\Content;
use App\Models\Draft;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AiTransparencyService
{
    public function ensureForContent(Content $content): AiTransparencyRecord
    {
        $latestDraft = $this->latestDraftForContent($content);
        $contentHash = $this->contentHash($content, $latestDraft);
        $origin = $this->inferOrigin($content, $latestDraft);

        $record = AiTransparencyRecord::query()->firstOrCreate(
            [
                'asset_type' => 'content',
                'asset_id' => $content->id,
            ],
            [
                'organization_id' => $content->workspace?->organization_id ?? $content->clientSite?->workspace?->organization_id,
                'workspace_id' => $content->workspace_id ?? $content->clientSite?->workspace_id,
                'content_id' => $content->id,
                'draft_id' => $latestDraft?->id,
                'origin' => $origin,
                'ai_badge' => $this->badgeForOrigin($origin),
                'disclosure_label' => $this->labelForOrigin($origin),
                'content_hash' => $contentHash,
            ]
        );

        $record->fill([
            'organization_id' => $record->organization_id ?? $content->workspace?->organization_id ?? $content->clientSite?->workspace?->organization_id,
            'workspace_id' => $record->workspace_id ?? $content->workspace_id ?? $content->clientSite?->workspace_id,
            'content_id' => $content->id,
            'draft_id' => $record->draft_id ?? $latestDraft?->id,
            'origin' => $record->origin === AiTransparencyRecord::ORIGIN_UNKNOWN ? $origin : $record->origin,
            'ai_badge' => $this->badgeForOrigin($record->origin),
            'disclosure_label' => $this->labelForOrigin($record->origin),
            'content_hash' => $contentHash,
        ]);

        $this->recalculateTrustScore($record);
        $this->syncMachineMetadata($record);
        $record->save();

        if (! $record->chronologicalEvents()->exists()) {
            $this->recordEvent($record, 'asset_registered', 'system', null, 'AI transparency record created.', [
                'content_id' => $content->id,
                'draft_id' => $latestDraft?->id,
            ], outputHash: $contentHash, occurredAt: $content->created_at);
        }

        $modelUsed = $latestDraft ? $this->modelUsedForDraft($latestDraft) : '';

        if ($latestDraft && $modelUsed !== '') {
            $generationMeta = is_array(data_get($latestDraft->meta, 'generation'))
                ? data_get($latestDraft->meta, 'generation')
                : [];

            $modelRun = $record->modelRuns()->where('draft_id', $latestDraft->id)->first();

            if (! $modelRun) {
                $modelRun = $this->recordModelRun($record, [
                    'draft_id' => $latestDraft->id,
                    'provider' => data_get($generationMeta, 'provider') ?: data_get($latestDraft->meta, 'provider'),
                    'model' => $modelUsed,
                    'model_version' => data_get($generationMeta, 'model_version') ?: data_get($latestDraft->meta, 'model_version'),
                    'run_id' => data_get($generationMeta, 'request_id') ?: data_get($latestDraft->meta, 'request_id'),
                    'settings' => data_get($generationMeta, 'settings') ?: data_get($latestDraft->meta, 'generation_settings'),
                    'usage' => data_get($generationMeta, 'usage') ?: array_filter([
                        'input_tokens' => data_get($generationMeta, 'input_tokens'),
                        'output_tokens' => data_get($generationMeta, 'output_tokens'),
                        'total_tokens' => data_get($generationMeta, 'tokens'),
                    ], fn ($value) => $value !== null),
                    'output_hash' => $this->hashNullable($latestDraft->content_html),
                    'ran_at' => $latestDraft->created_at,
                ]);
            }

            if (! $record->promptVersions()->where('ai_model_run_id', $modelRun->id)->exists()) {
                $this->recordPromptHistoryForDraft($record, $modelRun, $latestDraft, $generationMeta);
            }
        }

        return $record->fresh([
            'content',
            'draft',
            'chronologicalEvents.actor',
            'modelRuns',
            'promptVersions',
            'sourceTraces',
            'factChecks.reviewer',
            'humanReviews.reviewer',
        ]);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function recordEvent(
        AiTransparencyRecord $record,
        string $eventType,
        string $actorType = 'system',
        ?User $actor = null,
        ?string $summary = null,
        array $data = [],
        ?string $inputHash = null,
        ?string $outputHash = null,
        ?Carbon $occurredAt = null
    ): AiProvenanceEvent {
        return $record->chronologicalEvents()->create([
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_id' => $actor?->id,
            'actor_label' => $actor?->name,
            'summary' => $summary,
            'input_hash' => $inputHash,
            'output_hash' => $outputHash,
            'payload' => $data,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function recordModelRun(AiTransparencyRecord $record, array $attributes): AiModelRun
    {
        $run = $record->modelRuns()->create([
            'draft_id' => $attributes['draft_id'] ?? null,
            'provider' => $attributes['provider'] ?? null,
            'model' => $attributes['model'] ?? 'unknown',
            'model_version' => $attributes['model_version'] ?? null,
            'run_id' => $attributes['run_id'] ?? null,
            'settings' => $attributes['settings'] ?? null,
            'usage' => $attributes['usage'] ?? null,
            'input_hash' => $attributes['input_hash'] ?? null,
            'output_hash' => $attributes['output_hash'] ?? null,
            'ran_at' => $attributes['ran_at'] ?? now(),
        ]);

        $record->origin = AiTransparencyRecord::ORIGIN_AI_GENERATED;
        $record->ai_badge = $this->badgeForOrigin($record->origin);
        $record->disclosure_label = $this->labelForOrigin($record->origin);
        $this->recalculateTrustScore($record);
        $this->syncMachineMetadata($record);
        $record->save();

        $this->recordEvent($record, 'model_run', 'system', null, 'AI model run recorded.', [
            'provider' => $run->provider,
            'model' => $run->model,
            'model_version' => $run->model_version,
            'run_id' => $run->run_id,
        ], inputHash: $run->input_hash, outputHash: $run->output_hash, occurredAt: $run->ran_at);

        return $run;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function recordPromptVersion(AiTransparencyRecord $record, array $attributes): AiPromptVersion
    {
        $promptText = is_string($attributes['prompt_text'] ?? null) ? (string) $attributes['prompt_text'] : null;
        $promptHash = is_string($attributes['prompt_hash'] ?? null) ? (string) $attributes['prompt_hash'] : null;

        return $record->promptVersions()->create([
            'ai_model_run_id' => $attributes['ai_model_run_id'] ?? null,
            'version' => $attributes['version'] ?? ($record->promptVersions()->max('version') + 1),
            'prompt_type' => $attributes['prompt_type'] ?? 'generation',
            'prompt_text' => $promptText,
            'redacted_prompt_summary' => $attributes['redacted_prompt_summary'] ?? ($promptText ? Str::limit($promptText, 500, '') : null),
            'prompt_hash' => $promptHash ?: $this->hashNullable($promptText),
            'contains_redactions' => (bool) ($attributes['contains_redactions'] ?? false),
            'captured_at' => $attributes['captured_at'] ?? now(),
        ]);
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function recordSourceTrace(AiTransparencyRecord $record, array $attributes): AiSourceTrace
    {
        $source = $record->sourceTraces()->create([
            'source_type' => $attributes['source_type'] ?? 'url',
            'url' => $attributes['url'] ?? null,
            'title' => $attributes['title'] ?? null,
            'retrieval_status' => $attributes['retrieval_status'] ?? 'available',
            'retrieved_at' => $attributes['retrieved_at'] ?? now(),
            'content_hash' => $attributes['content_hash'] ?? null,
            'reliability_score' => $attributes['reliability_score'] ?? null,
            'used_for_sections' => $attributes['used_for_sections'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
        ]);

        $this->recalculateTrustScore($record);
        $this->syncMachineMetadata($record);
        $record->save();

        $this->recordEvent($record, 'source_trace', 'system', null, 'Source trace recorded.', [
            'source_type' => $source->source_type,
            'url' => $source->url,
            'title' => Str::limit((string) $source->title, 240, ''),
            'retrieval_status' => $source->retrieval_status,
        ]);

        return $source;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function recordFactCheck(AiTransparencyRecord $record, array $attributes, ?User $reviewer = null): AiFactCheck
    {
        $factCheck = $record->factChecks()->create([
            'claim' => $attributes['claim'] ?? '',
            'status' => $attributes['status'] ?? AiTransparencyRecord::FACT_UNCHECKED,
            'confidence' => $attributes['confidence'] ?? null,
            'evidence' => $attributes['evidence'] ?? null,
            'notes' => $attributes['notes'] ?? null,
            'reviewed_by' => $reviewer?->id,
            'reviewed_at' => $attributes['reviewed_at'] ?? now(),
        ]);

        $record->fact_check_status = $this->aggregateFactCheckStatus($record->fresh('factChecks'));
        $record->last_fact_checked_at = now();
        $this->recalculateTrustScore($record);
        $this->syncMachineMetadata($record);
        $record->save();

        $this->recordEvent($record, 'fact_check', $reviewer ? 'human' : 'system', $reviewer, 'Fact-check status updated.', [
            'claim' => Str::limit((string) $factCheck->claim, 240, ''),
            'status' => $factCheck->status,
        ]);

        return $factCheck;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function recordHumanReview(AiTransparencyRecord $record, array $attributes, ?User $reviewer = null): AiHumanReview
    {
        $review = $record->humanReviews()->create([
            'reviewer_id' => $reviewer?->id,
            'status' => $attributes['status'] ?? AiTransparencyRecord::REVIEW_REVIEWED,
            'checklist' => $attributes['checklist'] ?? null,
            'notes' => $attributes['notes'] ?? null,
            'reviewed_at' => $attributes['reviewed_at'] ?? now(),
        ]);

        $record->human_review_status = $review->status;
        $record->last_reviewed_at = $review->reviewed_at;
        $this->recalculateTrustScore($record);
        $this->syncMachineMetadata($record);
        $record->save();

        $this->recordEvent($record, 'human_review', 'human', $reviewer, 'Human review status updated.', [
            'status' => $review->status,
        ], occurredAt: $review->reviewed_at);

        return $review;
    }

    /**
     * @return array<string,mixed>
     */
    public function disclosurePayload(AiTransparencyRecord $record): array
    {
        $record->loadMissing(['content', 'draft', 'modelRuns', 'sourceTraces', 'factChecks', 'humanReviews']);
        $content = $record->content;

        return [
            'asset_id' => $record->asset_id,
            'asset_type' => $record->asset_type,
            'content_id' => $record->content_id,
            'title' => $content?->title,
            'ai_origin' => $record->origin,
            'ai_badge' => $record->ai_badge,
            'disclosure_label' => $record->disclosure_label,
            'human_review_status' => $record->human_review_status,
            'fact_check_status' => $record->fact_check_status,
            'trust_score' => $record->trust_score,
            'content_hash' => $record->content_hash,
            'metadata_standard' => $record->metadata_standard,
            'machine_metadata' => $record->machine_metadata ?? [],
            'counts' => [
                'model_runs' => $record->modelRuns->count(),
                'prompt_versions' => $record->promptVersions()->count(),
                'source_traces' => $record->sourceTraces->count(),
                'fact_checks' => $record->factChecks->count(),
                'human_reviews' => $record->humanReviews->count(),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function provenancePayload(AiTransparencyRecord $record): array
    {
        $record->loadMissing([
            'content',
            'draft',
            'chronologicalEvents.actor',
            'modelRuns',
            'promptVersions',
            'sourceTraces',
            'factChecks.reviewer',
            'humanReviews.reviewer',
        ]);

        return [
            'record' => $this->disclosurePayload($record),
            'timeline' => $record->chronologicalEvents->map(fn (AiProvenanceEvent $event): array => [
                'id' => $event->id,
                'type' => $event->event_type,
                'summary' => $event->summary,
                'actor' => [
                    'type' => $event->actor_type,
                    'id' => $event->actor_id,
                    'label' => $event->actor_label,
                ],
                'input_hash' => $event->input_hash,
                'output_hash' => $event->output_hash,
                'payload' => $event->payload ?? [],
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])->values()->all(),
            'model_history' => $record->modelRuns->map(fn (AiModelRun $run): array => [
                'id' => $run->id,
                'provider' => $run->provider,
                'model' => $run->model,
                'model_version' => $run->model_version,
                'run_id' => $run->run_id,
                'settings' => $run->settings ?? [],
                'usage' => $run->usage ?? [],
                'input_hash' => $run->input_hash,
                'output_hash' => $run->output_hash,
                'ran_at' => $run->ran_at?->toIso8601String(),
            ])->values()->all(),
            'prompt_history' => $record->promptVersions->map(fn (AiPromptVersion $prompt): array => [
                'id' => $prompt->id,
                'version' => $prompt->version,
                'prompt_type' => $prompt->prompt_type,
                'prompt_hash' => $prompt->prompt_hash,
                'summary' => $prompt->redacted_prompt_summary,
                'contains_redactions' => $prompt->contains_redactions,
                'captured_at' => $prompt->captured_at?->toIso8601String(),
            ])->values()->all(),
            'source_trace' => $record->sourceTraces->map(fn (AiSourceTrace $source): array => [
                'id' => $source->id,
                'source_type' => $source->source_type,
                'url' => $source->url,
                'title' => $source->title,
                'retrieval_status' => $source->retrieval_status,
                'retrieved_at' => $source->retrieved_at?->toIso8601String(),
                'content_hash' => $source->content_hash,
                'reliability_score' => $source->reliability_score,
                'used_for_sections' => $source->used_for_sections ?? [],
            ])->values()->all(),
            'fact_checks' => $record->factChecks->map(fn (AiFactCheck $factCheck): array => [
                'id' => $factCheck->id,
                'claim' => $factCheck->claim,
                'status' => $factCheck->status,
                'confidence' => $factCheck->confidence,
                'evidence' => $factCheck->evidence ?? [],
                'reviewer' => $factCheck->reviewer?->name,
                'reviewed_at' => $factCheck->reviewed_at?->toIso8601String(),
            ])->values()->all(),
            'human_reviews' => $record->humanReviews->map(fn (AiHumanReview $review): array => [
                'id' => $review->id,
                'status' => $review->status,
                'reviewer' => $review->reviewer?->name,
                'checklist' => $review->checklist ?? [],
                'notes' => $review->notes,
                'reviewed_at' => $review->reviewed_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    public function renderAuditReportPdf(AiTransparencyRecord $record, ?User $generatedBy = null): string
    {
        $payload = $this->provenancePayload($record);

        Pdf::setOption([
            'isHtml5ParserEnabled' => true,
            'dpi' => 96,
            'defaultFont' => 'Arial',
        ]);

        $pdf = Pdf::loadView('pdf.ai-audit-report', [
            'record' => $record,
            'payload' => $payload,
            'generatedBy' => $generatedBy,
            'generatedAt' => now(),
        ]);
        $pdf->setPaper('a4');

        return $pdf->output();
    }

    public function generateAuditReport(AiTransparencyRecord $record, ?User $generatedBy = null): AiAuditReport
    {
        $pdfBytes = $this->renderAuditReportPdf($record, $generatedBy);
        $path = sprintf(
            'ai-audit-reports/%s/%s.pdf',
            now()->format('Y/m'),
            $record->id
        );

        Storage::disk('local')->put($path, $pdfBytes);

        return $record->auditReports()->create([
            'format' => 'pdf',
            'status' => 'generated',
            'path' => $path,
            'checksum' => hash('sha256', $pdfBytes),
            'snapshot' => $this->provenancePayload($record),
            'generated_by' => $generatedBy?->id,
            'generated_at' => now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function exportMachineMetadataForContent(Content $content, bool $markExported = false): array
    {
        $record = $this->ensureForContent($content);
        $metadata = $record->machine_metadata ?? [];

        if ($markExported) {
            $record->metadata_exported_at = now();
            $this->syncMachineMetadata($record);
            $record->save();

            $this->recordEvent($record, 'metadata_exported', 'system', null, 'Machine-readable AI metadata exported.', [
                'metadata_standard' => $record->metadata_standard,
            ]);
        }

        return is_array($metadata) ? $metadata : [];
    }

    public function recordPublication(Content $content, mixed $publication = null, ?Draft $draft = null): void
    {
        $record = $this->ensureForContent($content->fresh(['workspace', 'clientSite.workspace', 'drafts']) ?? $content);
        $record->metadata_exported_at = now();
        $this->syncMachineMetadata($record);
        $record->save();

        $this->recordEvent($record, 'published', 'system', null, 'Content published with AI disclosure metadata.', [
            'publication_id' => is_object($publication) && isset($publication->id) ? (string) $publication->id : null,
            'provider' => is_object($publication) && isset($publication->provider) ? (string) $publication->provider : null,
            'remote_id' => is_object($publication) && isset($publication->remote_id) ? (string) $publication->remote_id : null,
            'remote_url' => is_object($publication) && isset($publication->remote_url) ? (string) $publication->remote_url : null,
            'draft_id' => $draft?->id,
        ]);
    }

    public function recalculateTrustScore(AiTransparencyRecord $record): void
    {
        $record->loadMissing(['modelRuns', 'sourceTraces', 'factChecks', 'humanReviews', 'chronologicalEvents']);

        $breakdown = [
            'provenance' => $record->chronologicalEvents->isNotEmpty() ? 20 : 5,
            'machine_metadata' => $record->content_hash ? 15 : 5,
            'model_history' => $record->modelRuns->isNotEmpty() || $record->origin === AiTransparencyRecord::ORIGIN_HUMAN ? 15 : 5,
            'human_review' => match ($record->human_review_status) {
                AiTransparencyRecord::REVIEW_APPROVED => 25,
                AiTransparencyRecord::REVIEW_REVIEWED => 18,
                AiTransparencyRecord::REVIEW_NEEDS_CHANGES => 8,
                AiTransparencyRecord::REVIEW_REJECTED => 0,
                default => 5,
            },
            'fact_check' => match ($record->fact_check_status) {
                AiTransparencyRecord::FACT_SUPPORTED => 15,
                AiTransparencyRecord::FACT_PARTIAL => 10,
                AiTransparencyRecord::FACT_NEEDS_REVIEW => 5,
                AiTransparencyRecord::FACT_CONFLICTING => 0,
                default => $record->factChecks->isEmpty() ? 5 : 8,
            },
            'source_trace' => $record->sourceTraces->isNotEmpty() ? 10 : 3,
        ];

        $record->score_breakdown = $breakdown;
        $record->trust_score = min(100, array_sum($breakdown));
    }

    private function latestDraftForContent(Content $content): ?Draft
    {
        if ($content->relationLoaded('drafts')) {
            return $content->drafts->sortByDesc('created_at')->first();
        }

        return Draft::query()
            ->where('content_id', $content->id)
            ->latest('created_at')
            ->first();
    }

    private function inferOrigin(Content $content, ?Draft $draft): string
    {
        $origin = (string) ($content->origin_type?->value ?? $content->origin_type ?? '');

        if (str_contains($origin, 'ai') || str_contains($origin, 'automation')) {
            return AiTransparencyRecord::ORIGIN_AI_GENERATED;
        }

        if ($draft && $this->modelUsedForDraft($draft) !== '') {
            return AiTransparencyRecord::ORIGIN_AI_GENERATED;
        }

        if ($draft && data_get($draft->meta, 'ai_assisted')) {
            return AiTransparencyRecord::ORIGIN_AI_ASSISTED;
        }

        return AiTransparencyRecord::ORIGIN_UNKNOWN;
    }

    private function badgeForOrigin(string $origin): string
    {
        return match ($origin) {
            AiTransparencyRecord::ORIGIN_HUMAN => 'Human',
            AiTransparencyRecord::ORIGIN_AI_ASSISTED => 'AI-assisted',
            AiTransparencyRecord::ORIGIN_AI_GENERATED => 'AI-generated',
            AiTransparencyRecord::ORIGIN_AI_EDITED => 'AI-edited',
            default => 'AI status unknown',
        };
    }

    private function labelForOrigin(string $origin): string
    {
        return match ($origin) {
            AiTransparencyRecord::ORIGIN_HUMAN => 'Created without recorded AI generation.',
            AiTransparencyRecord::ORIGIN_AI_ASSISTED => 'Created with AI assistance and human editorial input.',
            AiTransparencyRecord::ORIGIN_AI_GENERATED => 'Generated or substantially transformed by an AI system.',
            AiTransparencyRecord::ORIGIN_AI_EDITED => 'Human-created content was edited or transformed by AI.',
            default => 'No AI provenance decision has been recorded yet.',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function machineMetadata(AiTransparencyRecord $record, Content $content, ?Draft $draft): array
    {
        return [
            '@context' => [
                'schema' => 'https://schema.org/',
                'argusly' => 'https://argusly.com/ns/ai-transparency#',
            ],
            '@type' => 'argusly:AiContentDisclosure',
            'standard' => $record->metadata_standard,
            'asset_id' => $record->asset_id,
            'asset_type' => $record->asset_type,
            'content_id' => $content->id,
            'draft_id' => $draft?->id,
            'title' => $content->title,
            'ai_origin' => $record->origin,
            'ai_badge' => $record->ai_badge,
            'human_review_status' => $record->human_review_status,
            'fact_check_status' => $record->fact_check_status,
            'trust_score' => $record->trust_score,
            'content_hash' => $record->content_hash,
            'model' => $draft ? $this->modelUsedForDraft($draft) : null,
            'generated_at' => $draft?->created_at?->toIso8601String(),
            'provenance_url' => route('api.v1.content.ai-provenance', ['id' => $content->id], false),
            'disclosure_url' => route('api.v1.content.ai-disclosure', ['id' => $content->id], false),
        ];
    }

    private function modelUsedForDraft(Draft $draft): string
    {
        return trim((string) ($draft->model_used ?: data_get($draft->meta, 'generation.model_used') ?: data_get($draft->meta, 'generation.model')));
    }

    /**
     * @param array<string,mixed> $generationMeta
     */
    private function recordPromptHistoryForDraft(AiTransparencyRecord $record, AiModelRun $modelRun, Draft $draft, array $generationMeta): void
    {
        $snapshot = is_array(data_get($generationMeta, 'prompt_snapshot'))
            ? data_get($generationMeta, 'prompt_snapshot')
            : [];

        foreach (['system', 'user'] as $type) {
            $prompt = is_array($snapshot[$type] ?? null) ? $snapshot[$type] : [];
            $hash = is_string($prompt['hash'] ?? null) ? (string) $prompt['hash'] : null;
            $summary = is_string($prompt['summary'] ?? null) ? (string) $prompt['summary'] : null;

            if ($hash === null && trim((string) $summary) === '') {
                continue;
            }

            $this->recordPromptVersion($record, [
                'ai_model_run_id' => $modelRun->id,
                'prompt_type' => $type,
                'prompt_hash' => $hash,
                'redacted_prompt_summary' => $summary,
                'contains_redactions' => (bool) ($prompt['contains_redactions'] ?? true),
                'captured_at' => $draft->created_at,
            ]);
        }

        if ($snapshot !== []) {
            return;
        }

        $promptText = data_get($draft->meta, 'prompt') ?: data_get($draft->meta, 'generation_prompt');

        if (is_string($promptText) && trim($promptText) !== '') {
            $this->recordPromptVersion($record, [
                'ai_model_run_id' => $modelRun->id,
                'prompt_type' => 'generation',
                'prompt_text' => $promptText,
                'redacted_prompt_summary' => Str::limit($promptText, 500, ''),
                'captured_at' => $draft->created_at,
            ]);
        }
    }

    private function syncMachineMetadata(AiTransparencyRecord $record): void
    {
        $record->loadMissing([
            'content.workspace',
            'content.clientSite.workspace',
            'draft',
        ]);

        if (! $record->content) {
            return;
        }

        $draft = $record->draft ?: $this->latestDraftForContent($record->content);
        $record->machine_metadata = $this->machineMetadata($record, $record->content, $draft);
    }

    private function contentHash(Content $content, ?Draft $draft): string
    {
        return hash('sha256', json_encode([
            'content_id' => $content->id,
            'title' => $content->title,
            'language' => (string) ($content->language?->value ?? $content->language ?? ''),
            'status' => $content->status,
            'draft_id' => $draft?->id,
            'draft_title' => $draft?->title,
            'draft_content_html' => $draft?->content_html,
            'published_url' => $content->published_url,
            'updated_at' => $content->updated_at?->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function hashNullable(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return 'sha256:' . hash('sha256', (string) $value);
    }

    private function aggregateFactCheckStatus(AiTransparencyRecord $record): string
    {
        $statuses = $record->factChecks->pluck('status')->all();

        if ($statuses === []) {
            return AiTransparencyRecord::FACT_UNCHECKED;
        }

        if (in_array(AiTransparencyRecord::FACT_CONFLICTING, $statuses, true)) {
            return AiTransparencyRecord::FACT_CONFLICTING;
        }

        if (in_array(AiTransparencyRecord::FACT_NEEDS_REVIEW, $statuses, true)) {
            return AiTransparencyRecord::FACT_NEEDS_REVIEW;
        }

        return collect($statuses)->every(fn (string $status): bool => $status === AiTransparencyRecord::FACT_SUPPORTED)
            ? AiTransparencyRecord::FACT_SUPPORTED
            : AiTransparencyRecord::FACT_PARTIAL;
    }
}
