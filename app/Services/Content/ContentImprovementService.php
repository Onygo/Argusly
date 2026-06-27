<?php

namespace App\Services\Content;

use App\Enums\DraftImprovementAction;
use App\Jobs\GenerateContentImprovementJob;
use App\Models\Brief;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\ContentImprovementRun;
use App\Models\Draft;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ContentImprovementService
{
    public function __construct(
        private readonly ContentImprovementLifecycleLogger $logger,
        private readonly ContentImprovementDiffService $diffs,
    ) {}

    /**
     * @return array<int,array{key:string,type:string,label:string,description:string,score_hint:string,recommendation:string}>
     */
    public function optionsForRecommendations(iterable $recommendations): array
    {
        return collect($recommendations)
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->values()
            ->map(function (string $recommendation, int $index): array {
                $action = $this->resolveActionForRecommendation($recommendation);
                $label = $this->resolveLabelForRecommendation($recommendation, $action);

                return [
                    'key' => $this->recommendationKey($action->value, $recommendation),
                    'fallback_key' => $action->value . '-' . $index,
                    'type' => $action->value,
                    'label' => $label,
                    'description' => $recommendation,
                    'score_hint' => $this->scoreHintForAction($action),
                    'recommendation' => $recommendation,
                ];
            })
            ->all();
    }

    public function queue(Content $content, string $type, string $recommendation, User $user): ContentImprovementRun
    {
        $action = DraftImprovementAction::fromInput($type);
        if (! $action) {
            throw new RuntimeException('Unsupported improvement type.');
        }

        $existing = ContentImprovementRun::query()
            ->where('content_id', (string) $content->id)
            ->where('type', $action->value)
            ->whereIn('status', [
                ContentImprovementRun::STATUS_QUEUED,
                ContentImprovementRun::STATUS_RUNNING,
            ])
            ->latest('created_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        $snapshot = $this->sourceSnapshot($content->loadMissing(['drafts', 'currentVersion', 'currentRevision']));

        $run = ContentImprovementRun::query()->create([
            'content_id' => (string) $content->id,
            'organization_id' => $content->workspace?->organization_id,
            'type' => $action->value,
            'recommendation_label' => $recommendation,
            'recommendation_key' => $this->recommendationKey($action->value, $recommendation),
            'status' => ContentImprovementRun::STATUS_QUEUED,
            'progress_percentage' => 5,
            'created_by' => $user->id,
            'source_content_id' => (string) $content->id,
            'source_draft_id' => $snapshot['source_draft_id'],
            'source_content_version_id' => $snapshot['source_content_version_id'],
            'source_content_revision_id' => $snapshot['source_content_revision_id'],
            'source_revision_hash' => $snapshot['source_revision_hash'],
            'diagnostics' => [
                'queue_name' => 'generation',
                'retry_count' => 0,
                'source_updated_at' => $snapshot['source_updated_at'],
            ],
        ]);

        $this->logger->record($run, 'QUEUED', 'Improvement job queued.', [
            'queue_name' => 'generation',
            'recommendation' => $recommendation,
        ]);

        GenerateContentImprovementJob::dispatch((string) $run->id)
            ->onQueue('generation')
            ->afterCommit();

        return $run;
    }

    public function accept(ContentImprovementRun $run, User $user): ContentImprovementRun
    {
        if ($run->status !== ContentImprovementRun::STATUS_COMPLETED || ! $run->hasResult()) {
            throw new RuntimeException('Improvement is not ready to apply.');
        }

        $content = $run->content()->with(['brief', 'drafts', 'currentVersion', 'currentRevision', 'clientSite'])->firstOrFail();
        $sourceDraft = $run->sourceDraft ?: $this->ensureEditableDraftForContent($content, (int) $user->id);
        $targetDraft = $run->targetDraft ?: $sourceDraft;
        $currentSnapshot = $this->sourceSnapshot($content->fresh(['drafts', 'currentVersion', 'currentRevision']));

        if ($run->source_revision_hash && $currentSnapshot['source_revision_hash'] !== $run->source_revision_hash) {
            throw new RuntimeException('The editable content changed after this improvement was generated. Regenerate before applying it.');
        }

        $updates = $this->draftUpdatesFromPayload($targetDraft, (array) ($run->result_payload ?? []), (string) $run->id);
        $sourceDraft->forceFill($updates)->saveQuietly();

        $run->forceFill([
            'draft_id' => (string) $sourceDraft->id,
            'applied_at' => now(),
            'applied_by' => $user->id,
            'diagnostics' => array_merge((array) ($run->diagnostics ?? []), [
                'applied_to_draft_id' => (string) $sourceDraft->id,
            ]),
        ])->save();

        $this->logger->record($run->fresh(), 'APPLIED', 'Generated improvement applied to editable draft.', [
            'draft_id' => (string) $sourceDraft->id,
        ]);

        return $run->fresh(['events']);
    }

    public function reject(ContentImprovementRun $run, User $user): ContentImprovementRun
    {
        $run->forceFill([
            'status' => ContentImprovementRun::STATUS_CANCELLED,
            'completed_at' => $run->completed_at ?? now(),
            'diagnostics' => array_merge((array) ($run->diagnostics ?? []), [
                'cancelled_by' => $user->id,
            ]),
        ])->save();

        $this->logger->record($run->fresh(), 'CANCELLED', 'Generated improvement rejected.', [
            'user_id' => $user->id,
        ]);

        return $run->fresh(['events']);
    }

    /**
     * @return array{active:Collection<int,ContentImprovementRun>,recent:Collection<int,ContentImprovementRun>,events:Collection<int,\App\Models\ContentImprovementEvent>,active_by_type:array<string,string>,latest_event_id:int}
     */
    public function dashboard(Content $content): array
    {
        $content = $content->fresh(['drafts', 'currentVersion', 'currentRevision']) ?? $content->loadMissing(['drafts', 'currentVersion', 'currentRevision']);
        $currentSourceHash = $this->sourceSnapshot($content)['source_revision_hash'];
        $runs = ContentImprovementRun::query()
            ->with(['events', 'sourceDraft', 'targetDraft'])
            ->where('content_id', (string) $content->id)
            ->latest('created_at')
            ->limit(20)
            ->get();

        $active = $runs->filter(fn (ContentImprovementRun $run): bool => $run->isActive())->values();
        $recent = $runs->reject(fn (ContentImprovementRun $run): bool => $run->isActive())->values();
        $latestByRecommendationKey = $runs
            ->filter(function (ContentImprovementRun $run) use ($currentSourceHash): bool {
                $sourceHash = (string) ($run->source_revision_hash ?? '');
                $outputHash = (string) ($run->output_revision_hash ?? '');

                return ($sourceHash !== '' && $sourceHash === (string) $currentSourceHash)
                    || ($outputHash !== '' && $outputHash === (string) $currentSourceHash);
            })
            ->filter(fn (ContentImprovementRun $run): bool => trim((string) ($run->recommendation_key ?? '')) !== '')
            ->groupBy(fn (ContentImprovementRun $run): string => (string) $run->recommendation_key)
            ->map(fn (Collection $group): ?ContentImprovementRun => $group->sortByDesc('created_at')->first());
        $events = $runs
            ->flatMap(fn (ContentImprovementRun $run) => $run->events)
            ->sortBy('id')
            ->values();

        return [
            'active' => $active,
            'recent' => $recent,
            'events' => $events,
            'active_by_type' => $active
                ->mapWithKeys(fn (ContentImprovementRun $run): array => [(string) $run->type => (string) $run->status])
                ->all(),
            'latest_by_recommendation_key' => $latestByRecommendationKey,
            'current_source_hash' => $currentSourceHash,
            'latest_event_id' => (int) ($events->last()->id ?? 0),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function buildResultPayload(string $beforeHtml, array $result): array
    {
        $afterHtml = (string) ($result['content_html'] ?? '');
        $diff = $this->diffs->buildPreview($beforeHtml, $afterHtml);

        return [
            'before_html' => $beforeHtml,
            'content_html' => $afterHtml,
            'diff_preview_html' => $diff['html'],
            'inserted_text' => $diff['inserted_text'],
            'removed_text' => $diff['removed_text'],
            'change_summary' => $result['change_summary'] ?? null,
            'change_notes' => $result['change_notes'] ?? [],
            'title' => $result['title'] ?? null,
            'seo_title' => $result['seo_title'] ?? null,
            'seo_meta_description' => $result['seo_meta_description'] ?? null,
            'seo_h1' => $result['seo_h1'] ?? null,
            'model_used' => $result['model_used'] ?? null,
            'provider' => $result['provider'] ?? null,
            'tokens_used' => $result['tokens_used'] ?? null,
            'request_id' => $result['request_id'] ?? null,
            'prompt_version' => $result['prompt_version'] ?? null,
        ];
    }

    /**
     * @return array{status:string,reason:string,summary:string,diff_summary:string}
     */
    public function evaluateGeneratedResult(string $beforeHtml, array $payload): array
    {
        $afterHtml = trim((string) ($payload['content_html'] ?? ''));
        $beforePlain = $this->plainText($beforeHtml);
        $afterPlain = $this->plainText($afterHtml);
        $inserted = trim((string) ($payload['inserted_text'] ?? ''));
        $removed = trim((string) ($payload['removed_text'] ?? ''));
        $summary = trim((string) ($payload['change_summary'] ?? ''));

        if ($afterPlain === '') {
            return [
                'status' => ContentImprovementRun::STATUS_NO_CHANGES,
                'reason' => 'The AI did not return editable content.',
                'summary' => $summary !== '' ? $summary : 'No useful changes generated.',
                'diff_summary' => 'Generated output was empty after normalization.',
            ];
        }

        if ($this->normalizedHash($beforeHtml) === $this->normalizedHash($afterHtml)) {
            return [
                'status' => ContentImprovementRun::STATUS_NO_CHANGES,
                'reason' => 'The generated output matched the current source content.',
                'summary' => $summary !== '' ? $summary : 'No useful changes generated.',
                'diff_summary' => 'The generated draft was identical to the source revision.',
            ];
        }

        if ($inserted === '' && $removed === '') {
            return [
                'status' => ContentImprovementRun::STATUS_NO_CHANGES,
                'reason' => 'No meaningful diff could be detected between the source and generated output.',
                'summary' => $summary !== '' ? $summary : 'No useful changes generated.',
                'diff_summary' => 'The generated output did not produce a visible diff.',
            ];
        }

        if (mb_strlen($afterPlain) < max(80, (int) floor(mb_strlen($beforePlain) * 0.2))) {
            return [
                'status' => ContentImprovementRun::STATUS_NO_CHANGES,
                'reason' => 'The generated output was too short to be a useful edited draft.',
                'summary' => $summary !== '' ? $summary : 'No useful changes generated.',
                'diff_summary' => 'The AI returned advice-like or partial output instead of a usable rewrite.',
            ];
        }

        return [
            'status' => ContentImprovementRun::STATUS_COMPLETED,
            'reason' => '',
            'summary' => $summary !== '' ? $summary : 'Generated an updated draft revision.',
            'diff_summary' => $this->buildDiffSummary($inserted, $removed),
        ];
    }

    public function ensureEditableDraftForContent(Content $content, int $userId): Draft
    {
        return DB::transaction(function () use ($content, $userId): Draft {
            /** @var Content $lockedContent */
            $lockedContent = Content::query()
                ->with(['brief', 'drafts', 'currentVersion', 'currentRevision', 'clientSite'])
                ->lockForUpdate()
                ->findOrFail($content->id);

            $existingDraft = $lockedContent->drafts
                ->sortByDesc(fn (Draft $draft): int => $draft->updated_at?->getTimestamp() ?? 0)
                ->first();

            if ($existingDraft) {
                return $existingDraft;
            }

            $brief = $lockedContent->brief;
            if (! $brief) {
                $brief = Brief::query()->create([
                    'id' => (string) Str::uuid(),
                    'client_site_id' => (string) $lockedContent->client_site_id,
                    'created_by_user_id' => $userId,
                    'content_id' => (string) $lockedContent->id,
                    'status' => 'draft',
                    'source' => 'content_improvement',
                    'progress' => 0,
                    'title' => (string) ($lockedContent->title ?: 'Untitled content'),
                    'language' => $lockedContent->language?->value ?? 'en',
                    'primary_keyword' => (string) ($lockedContent->primary_keyword ?: '') ?: null,
                    'output_type' => 'kb_article',
                    'client_refs' => [
                        'source' => 'content_improvement',
                        'auto_created_from_content' => true,
                    ],
                ]);
            }

            return Draft::query()->create([
                'id' => (string) Str::uuid(),
                'brief_id' => (string) $brief->id,
                'content_id' => (string) $lockedContent->id,
                'client_site_id' => (string) $lockedContent->client_site_id,
                'status' => 'generated',
                'attempts' => 0,
                'title' => (string) ($lockedContent->title ?: $brief->title ?: 'Untitled content'),
                'seo_title' => (string) ($lockedContent->seo_title ?: $lockedContent->title ?: $brief->title ?: 'Untitled content'),
                'seo_meta_description' => $lockedContent->seo_meta_description,
                'seo_h1' => (string) ($lockedContent->seo_h1 ?: $lockedContent->title ?: $brief->title ?: 'Untitled content'),
                'seo_canonical' => $lockedContent->seo_canonical,
                'seo_og_title' => $lockedContent->seo_og_title,
                'seo_og_description' => $lockedContent->seo_og_description,
                'seo_og_image' => $lockedContent->seo_og_image,
                'seo_twitter_title' => $lockedContent->seo_twitter_title,
                'seo_twitter_description' => $lockedContent->seo_twitter_description,
                'robots_index' => $lockedContent->robots_index,
                'robots_follow' => $lockedContent->robots_follow,
                'schema_type' => $lockedContent->schema_type,
                'output_type' => 'kb_article',
                'language' => $lockedContent->language?->value ?? 'en',
                'content_html' => $this->resolveDraftBodyFromContent($lockedContent),
                'delivery_status' => (string) ($lockedContent->delivery_status ?? 'pending'),
                'meta' => [
                    'source' => 'content_improvement',
                    'auto_created_from_content' => true,
                ],
            ]);
        });
    }

    public function ensureTargetDraftForRun(ContentImprovementRun $run, Content $content, Draft $sourceDraft, int $userId): Draft
    {
        if ($run->targetDraft) {
            return $run->targetDraft;
        }

        return DB::transaction(function () use ($run, $content, $sourceDraft, $userId): Draft {
            $freshRun = ContentImprovementRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($freshRun->target_draft_id) {
                return Draft::query()->findOrFail($freshRun->target_draft_id);
            }

            $meta = is_array($sourceDraft->meta) ? $sourceDraft->meta : [];
            data_set($meta, 'content_improvement.run_id', (string) $run->id);
            data_set($meta, 'content_improvement.recommendation_key', (string) ($run->recommendation_key ?? ''));
            data_set($meta, 'content_improvement.source_draft_id', (string) $sourceDraft->id);
            data_set($meta, 'content_improvement.source_revision_hash', (string) ($run->source_revision_hash ?? ''));
            data_set($meta, 'content_improvement.created_from_content_id', (string) $content->id);

            $targetDraft = Draft::query()->create([
                'id' => (string) Str::uuid(),
                'brief_id' => (string) $sourceDraft->brief_id,
                'content_id' => (string) $content->id,
                'client_site_id' => (string) $sourceDraft->client_site_id,
                'content_destination_id' => $sourceDraft->content_destination_id,
                'status' => 'generated',
                'attempts' => 0,
                'title' => (string) $sourceDraft->title,
                'seo_title' => $sourceDraft->seo_title,
                'seo_meta_description' => $sourceDraft->seo_meta_description,
                'seo_h1' => $sourceDraft->seo_h1,
                'seo_canonical' => $sourceDraft->seo_canonical,
                'seo_og_title' => $sourceDraft->seo_og_title,
                'seo_og_description' => $sourceDraft->seo_og_description,
                'seo_og_image' => $sourceDraft->seo_og_image,
                'seo_twitter_title' => $sourceDraft->seo_twitter_title,
                'seo_twitter_description' => $sourceDraft->seo_twitter_description,
                'robots_index' => $sourceDraft->robots_index,
                'robots_follow' => $sourceDraft->robots_follow,
                'schema_type' => $sourceDraft->schema_type,
                'output_type' => (string) $sourceDraft->output_type,
                'language' => $sourceDraft->language?->value ?? $sourceDraft->language,
                'draft_type' => $sourceDraft->draft_type?->value ?? $sourceDraft->draft_type,
                'source_draft_id' => $sourceDraft->source_draft_id ?: (string) $sourceDraft->id,
                'translation_source_language' => $sourceDraft->translation_source_language,
                'model_used' => $sourceDraft->model_used,
                'content_html' => $sourceDraft->content_html,
                'meta' => $meta,
                'links' => $sourceDraft->links,
                'delivery_status' => 'pending',
            ]);

            $freshRun->forceFill([
                'draft_id' => (string) $targetDraft->id,
                'target_draft_id' => (string) $targetDraft->id,
                'source_draft_id' => (string) $sourceDraft->id,
                'source_content_id' => (string) $content->id,
                'source_content_version_id' => $content->current_version_id ? (string) $content->current_version_id : null,
                'source_content_revision_id' => $content->current_revision_id ? (string) $content->current_revision_id : null,
            ])->save();

            return $targetDraft;
        });
    }

    /**
     * @return array{source_draft_id:?string,source_content_version_id:?string,source_content_revision_id:?string,source_revision_hash:string,source_updated_at:?string}
     */
    public function sourceSnapshot(Content $content): array
    {
        $sourceDraft = $this->latestEditableDraft($content);
        $sourceHtml = trim((string) (
            $sourceDraft?->content_html
            ?: $content->currentRevision?->content_html
            ?: $content->currentVersion?->body
            ?: ''
        ));

        return [
            'source_draft_id' => $sourceDraft?->id ? (string) $sourceDraft->id : null,
            'source_content_version_id' => $content->current_version_id ? (string) $content->current_version_id : null,
            'source_content_revision_id' => $content->current_revision_id ? (string) $content->current_revision_id : null,
            'source_revision_hash' => $this->normalizedHash($sourceHtml),
            'source_updated_at' => collect([
                $sourceDraft?->updated_at,
                $sourceDraft?->created_at,
                $content->currentRevision?->updated_at,
                $content->currentRevision?->created_at,
                $content->currentVersion?->updated_at,
                $content->currentVersion?->created_at,
                $content->updated_at,
            ])->filter()->sortDesc()->first()?->toIso8601String(),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function draftUpdatesFromPayload(Draft $draft, array $payload, string $runId): array
    {
        $updates = [
            'content_html' => (string) ($payload['content_html'] ?? $draft->content_html),
        ];

        foreach (['title', 'seo_title', 'seo_meta_description', 'seo_h1'] as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] !== null) {
                $updates[$field] = $payload[$field];
            }
        }

        $meta = is_array($draft->meta) ? $draft->meta : [];
        data_set($meta, 'content_improvements.latest_run_id', $runId);
        data_set($meta, 'content_improvements.latest_change_summary', (string) ($payload['change_summary'] ?? ''));
        data_set($meta, 'content_improvements.generated_at', now()->toIso8601String());
        $updates['meta'] = $meta;

        return $updates;
    }

    private function resolveDraftBodyFromContent(Content $content): ?string
    {
        $draft = $this->latestEditableDraft($content);

        $draftHtml = trim((string) ($draft?->content_html ?? ''));
        if ($draftHtml !== '') {
            return $draftHtml;
        }

        $revisionHtml = trim((string) ($content->currentRevision?->content_html ?? ''));
        if ($revisionHtml !== '') {
            return $revisionHtml;
        }

        $versionBody = trim((string) ($content->currentVersion?->body ?? ''));

        return $versionBody !== '' ? $versionBody : null;
    }

    private function latestEditableDraft(Content $content): ?Draft
    {
        if (! $content->relationLoaded('drafts')) {
            $content->load('drafts');
        }

        return $content->drafts
            ->sortByDesc(fn (Draft $draft): string => sprintf(
                '%010d-%s',
                max(
                    $draft->updated_at?->getTimestamp() ?? 0,
                    $draft->created_at?->getTimestamp() ?? 0,
                ),
                (string) $draft->id,
            ))
            ->first();
    }

    private function recommendationKey(string $type, string $recommendation): string
    {
        return $type . ':' . Str::of($recommendation)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/u', '-')
            ->trim('-')
            ->value();
    }

    private function normalizedHash(string $html): string
    {
        return sha1($this->plainText($html));
    }

    private function plainText(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
    }

    private function buildDiffSummary(string $inserted, string $removed): string
    {
        $parts = [];

        if ($inserted !== '') {
            $parts[] = 'Added ' . str_word_count($inserted) . ' words';
        }

        if ($removed !== '') {
            $parts[] = 'removed ' . str_word_count($removed) . ' words';
        }

        return $parts !== [] ? implode(', ', $parts) . '.' : 'Generated a visible content diff.';
    }

    private function resolveActionForRecommendation(string $recommendation): DraftImprovementAction
    {
        $normalized = Str::lower($recommendation);

        return match (true) {
            str_contains($normalized, 'cta') => DraftImprovementAction::CTA,
            str_contains($normalized, 'human content'),
            str_contains($normalized, 'human voice'),
            str_contains($normalized, 'ai fingerprint'),
            str_contains($normalized, 'editorial quality'),
            str_contains($normalized, 'originality') => DraftImprovementAction::HUMAN_CONTENT,
            str_contains($normalized, 'heading'), str_contains($normalized, 'structure') => DraftImprovementAction::HEADINGS,
            str_contains($normalized, 'readability'),
            str_contains($normalized, 'scanability'),
            str_contains($normalized, 'sentence'),
            str_contains($normalized, 'intro'),
            str_contains($normalized, 'clarity') => DraftImprovementAction::READABILITY,
            str_contains($normalized, 'entity'), str_contains($normalized, 'keyword'), str_contains($normalized, 'seo') => DraftImprovementAction::SEO,
            default => DraftImprovementAction::FULL_DRAFT,
        };
    }

    private function resolveLabelForRecommendation(string $recommendation, DraftImprovementAction $action): string
    {
        return match ($action) {
            DraftImprovementAction::CTA => 'Add CTA section',
            DraftImprovementAction::HUMAN_CONTENT => 'Apply Human Content fixes',
            DraftImprovementAction::HEADINGS => 'Improve heading structure',
            DraftImprovementAction::READABILITY => 'Improve readability',
            DraftImprovementAction::SEO => 'Strengthen semantic coverage',
            DraftImprovementAction::FULL_DRAFT => 'Apply AI improvement',
        };
    }

    private function scoreHintForAction(DraftImprovementAction $action): string
    {
        return match ($action) {
            DraftImprovementAction::CTA => '+6 estimated AEO score',
            DraftImprovementAction::HUMAN_CONTENT => '+10 estimated Human Content score',
            DraftImprovementAction::HEADINGS => '+5 estimated AEO score',
            DraftImprovementAction::READABILITY => '+4 estimated AEO score',
            DraftImprovementAction::SEO => '+7 estimated AEO score',
            DraftImprovementAction::FULL_DRAFT => '+8 estimated AEO score',
        };
    }
}
