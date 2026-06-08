<?php

namespace App\Services\SeoAudit;

use App\Enums\PublicationDeliveryStatus;
use App\Enums\SeoAuditSuggestionState;
use App\Models\Brief;
use App\Models\Content;
use App\Models\ContentSeo;
use App\Models\ContentVersion;
use App\Models\Draft;
use App\Models\Event;
use App\Models\SeoApplyLog;
use App\Models\SeoAudit;
use App\Models\SeoAuditFixSuggestion;
use App\Models\SeoAuditIssue;
use App\Models\SeoAuditPage;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\LlmManager;
use App\Support\SeoMetadata;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SeoAuditAiFixService
{
    public const ISSUE_META_DESCRIPTION_MISSING = 'meta_description_missing';
    public const ISSUE_TITLE_LONG = 'title_long';
    public const ISSUE_TITLE_MISSING = 'title_missing';
    public const ISSUE_H1_MISSING = 'h1_missing';
    public const ISSUE_CANONICAL_MISSING = 'canonical_missing';
    public const ISSUE_INTERNAL_LINKS_LOW = 'internal_links_low';

    /**
     * @var array<int,string>
     */
    private const SUPPORTED_ISSUE_CODES = [
        self::ISSUE_META_DESCRIPTION_MISSING,
        self::ISSUE_TITLE_LONG,
        self::ISSUE_TITLE_MISSING,
        self::ISSUE_H1_MISSING,
        self::ISSUE_CANONICAL_MISSING,
        self::ISSUE_INTERNAL_LINKS_LOW,
    ];

    /**
     * @var array<int,string>
     */
    private const APPLY_LOG_FIELDS = [
        'title',
        'seo_title',
        'seo_meta_description',
        'seo_h1',
        'seo_canonical',
        'seo_og_title',
        'seo_og_description',
        'seo_og_image',
        'seo_twitter_title',
        'seo_twitter_description',
        'robots_index',
        'robots_follow',
        'schema_type',
    ];

    public function __construct(
        private readonly LlmManager $llmManager,
    ) {}

    /**
     * @return array<int,string>
     */
    public function supportedIssueCodes(): array
    {
        return self::SUPPORTED_ISSUE_CODES;
    }

    public function isSupportedIssueCode(string $issueCode): bool
    {
        return in_array(trim($issueCode), self::SUPPORTED_ISSUE_CODES, true);
    }

    public function creditCostPerSuggestion(): int
    {
        return max(1, (int) config('argusly.ai.seo_fix.credit_cost', 2));
    }

    public function estimateCreditsForIssueCount(int $count): int
    {
        return max(0, $count) * $this->creditCostPerSuggestion();
    }

    /**
     * @return array{input_snapshot:array<string,mixed>,suggestion:array<string,mixed>,token_usage:array<string,mixed>,provider:string,model:string,request_id:?string}
     */
    public function generateSuggestion(
        SeoAudit $audit,
        SeoAuditPage $page,
        SeoAuditIssue $issue,
        ?Content $content,
        int $userId
    ): array {
        $snapshot = $this->buildInputSnapshot($page, $issue, $content);
        $response = $this->llmManager->generateJson(
            new LlmRequest(
                messages: [
                    new LlmMessage('system', $this->systemPrompt()),
                    new LlmMessage('user', $this->buildUserPrompt($snapshot, $issue->code)),
                ],
                model: (string) config('llm.providers.openai.reasoning_model', config('llm.providers.openai.default_model')),
                temperature: 0.2,
                maxTokens: 1200,
                responseFormat: 'json',
                metadata: [
                    'feature' => 'seo_optimization',
                    'modality' => 'text',
                    'workspaceId' => (string) $audit->workspace_id,
                    'siteId' => (string) $audit->client_site_id,
                    'userId' => $userId,
                    'seoAuditId' => (string) $audit->id,
                    'seoAuditPageId' => (string) $page->id,
                    'issueCode' => (string) $issue->code,
                    'credits' => $this->creditCostPerSuggestion(),
                    'trigger' => 'seo_audit_ai_fix',
                ],
            ),
            $this->outputSchemaDescription(),
        );

        $payload = $response->json;
        if (! is_array($payload)) {
            $decoded = json_decode((string) $response->text, true);
            if (! is_array($decoded)) {
                throw new RuntimeException('AI response was not valid JSON.');
            }

            $payload = $decoded;
        }

        $suggestion = $this->normalizeSuggestion($payload);

        return [
            'input_snapshot' => $snapshot,
            'suggestion' => $suggestion,
            'token_usage' => $response->usage->toArray(),
            'provider' => (string) $response->providerName,
            'model' => (string) $response->modelUsed,
            'request_id' => $response->requestId,
        ];
    }

    public function applySuggestionToDraft(SeoAuditFixSuggestion $suggestion, Content $content, int $userId): Draft
    {
        $editableDraft = $this->ensureEditableDraftForContent($content, $userId);

        if (
            $suggestion->status === SeoAuditFixSuggestion::STATUS_APPLIED
            && $this->normalizeSuggestionState($suggestion) !== SeoAuditSuggestionState::SUGGESTED
        ) {
            return $editableDraft;
        }

        $payload = is_array($suggestion->suggestion) ? $suggestion->suggestion : [];
        $seoFields = SeoMetadata::merge([
            'seo_title' => $payload['title'] ?? $payload['recommended_title'] ?? null,
            'seo_meta_description' => $payload['meta_description'] ?? $payload['recommended_meta_description'] ?? null,
            'seo_h1' => $payload['h1'] ?? $payload['recommended_h1'] ?? null,
            'seo_canonical' => $payload['canonical'] ?? $payload['recommended_canonical'] ?? null,
            'seo_og_title' => $payload['og_title'] ?? $payload['recommended_og_title'] ?? null,
            'seo_og_description' => $payload['og_description'] ?? $payload['recommended_og_description'] ?? null,
            'seo_og_image' => $payload['og_image'] ?? $payload['recommended_og_image'] ?? null,
            'seo_twitter_title' => $payload['twitter_title'] ?? $payload['recommended_twitter_title'] ?? null,
            'seo_twitter_description' => $payload['twitter_description'] ?? $payload['recommended_twitter_description'] ?? null,
            'robots_index' => $payload['robots_index'] ?? $payload['recommended_robots_index'] ?? null,
            'robots_follow' => $payload['robots_follow'] ?? $payload['recommended_robots_follow'] ?? null,
            'schema_type' => $payload['schema_type'] ?? $payload['recommended_schema_type'] ?? null,
        ]);

        $recommendedTitle = trim((string) ($seoFields['seo_title'] ?? ''));
        $recommendedMetaDescription = trim((string) ($seoFields['seo_meta_description'] ?? ''));
        $recommendedH1 = trim((string) ($seoFields['seo_h1'] ?? ''));
        $recommendedCanonical = trim((string) ($seoFields['seo_canonical'] ?? ''));
        $recommendedSchemaType = trim((string) ($seoFields['schema_type'] ?? ''));
        $recommendedRobotsIndex = $seoFields['robots_index'];
        $recommendedRobotsFollow = $seoFields['robots_follow'];

        if (
            $recommendedTitle === ''
            && $recommendedMetaDescription === ''
            && $recommendedH1 === ''
            && $recommendedCanonical === ''
            && $recommendedSchemaType === ''
            && $recommendedRobotsIndex === null
            && $recommendedRobotsFollow === null
        ) {
            throw new RuntimeException('Suggestion has no draft-applicable SEO fields.');
        }

        $content->loadMissing(['currentVersion', 'draftVersion', 'seo', 'clientSite']);
        $currentSeo = SeoMetadata::resolveForContentContext($content);

        DB::transaction(function () use ($suggestion, $content, $editableDraft, $userId, $recommendedTitle, $recommendedMetaDescription, $recommendedH1, $recommendedCanonical, $recommendedSchemaType, $recommendedRobotsIndex, $recommendedRobotsFollow, $seoFields, $currentSeo): void {
            $contentBefore = $this->captureApplyLogState($content);

            ContentSeo::query()->updateOrCreate(
                ['content_id' => $content->id],
                [
                    'meta_title' => $recommendedTitle !== '' ? Str::limit($recommendedTitle, 255, '') : ($currentSeo['seo_title'] ?: null),
                    'meta_description' => $recommendedMetaDescription !== '' ? Str::limit($recommendedMetaDescription, 320, '') : ($currentSeo['seo_meta_description'] ?: null),
                    'primary_keyword' => $currentSeo['primary_keyword'] ?: null,
                    'robots_index' => $recommendedRobotsIndex ?? $currentSeo['robots_index'],
                    'robots_follow' => $recommendedRobotsFollow ?? $currentSeo['robots_follow'],
                    'schema_type' => $recommendedSchemaType !== '' ? Str::limit($recommendedSchemaType, 120, '') : ($currentSeo['schema_type'] ?: null),
                ],
            );

            $currentBody = (string) ($content->currentVersion?->body ?: $content->draftVersion?->body ?: '');
            $latestDraft = Draft::query()->lockForUpdate()->findOrFail($editableDraft->id);
            $draftBefore = $this->captureApplyLogState($latestDraft);
            $draftMeta = is_array($latestDraft->meta) ? $latestDraft->meta : [];
            $draftMeta = array_replace_recursive($draftMeta, array_filter([
                'meta_description' => $recommendedMetaDescription !== '' ? Str::limit($recommendedMetaDescription, 320, '') : null,
                'canonical_url' => $recommendedCanonical !== '' ? Str::limit($recommendedCanonical, 2048, '') : null,
                'robots_index' => $recommendedRobotsIndex,
                'robots_follow' => $recommendedRobotsFollow,
                'schema_type' => $recommendedSchemaType !== '' ? Str::limit($recommendedSchemaType, 120, '') : null,
            ], static fn ($value) => $value !== null && (is_bool($value) || trim((string) $value) !== '')));

            $latestDraft->update([
                'status' => 'generated',
                'title' => $recommendedTitle !== '' ? Str::limit($recommendedTitle, 255, '') : $latestDraft->title,
                'seo_title' => $recommendedTitle !== '' ? Str::limit($recommendedTitle, 255, '') : $latestDraft->seo_title,
                'seo_meta_description' => $recommendedMetaDescription !== '' ? Str::limit($recommendedMetaDescription, 320, '') : $latestDraft->seo_meta_description,
                'seo_h1' => $recommendedH1 !== '' ? Str::limit($recommendedH1, 255, '') : $latestDraft->seo_h1,
                'seo_canonical' => $recommendedCanonical !== '' ? Str::limit($recommendedCanonical, 2048, '') : $latestDraft->seo_canonical,
                'seo_og_title' => trim((string) ($seoFields['seo_og_title'] ?? '')) !== '' ? Str::limit((string) $seoFields['seo_og_title'], 255, '') : $latestDraft->seo_og_title,
                'seo_og_description' => trim((string) ($seoFields['seo_og_description'] ?? '')) !== '' ? Str::limit((string) $seoFields['seo_og_description'], 500, '') : $latestDraft->seo_og_description,
                'seo_og_image' => trim((string) ($seoFields['seo_og_image'] ?? '')) !== '' ? Str::limit((string) $seoFields['seo_og_image'], 2048, '') : $latestDraft->seo_og_image,
                'seo_twitter_title' => trim((string) ($seoFields['seo_twitter_title'] ?? '')) !== '' ? Str::limit((string) $seoFields['seo_twitter_title'], 255, '') : $latestDraft->seo_twitter_title,
                'seo_twitter_description' => trim((string) ($seoFields['seo_twitter_description'] ?? '')) !== '' ? Str::limit((string) $seoFields['seo_twitter_description'], 500, '') : $latestDraft->seo_twitter_description,
                'robots_index' => $recommendedRobotsIndex ?? $latestDraft->robots_index,
                'robots_follow' => $recommendedRobotsFollow ?? $latestDraft->robots_follow,
                'schema_type' => $recommendedSchemaType !== '' ? Str::limit($recommendedSchemaType, 120, '') : $latestDraft->schema_type,
                'delivery_status' => $this->markDeliveryStatusOutOfSync((string) ($latestDraft->delivery_status ?? '')),
                'delivery_last_error' => null,
                'meta' => $draftMeta,
            ]);

            $version = ContentVersion::query()->create([
                'id' => (string) Str::uuid(),
                'content_id' => $content->id,
                'type' => 'revision',
                'parent_version_id' => $content->current_version_id,
                'body' => $currentBody,
                'meta' => [
                    'source' => 'seo_audit_ai_fix',
                    'seo_audit_id' => (int) $suggestion->seo_audit_id,
                    'seo_audit_fix_suggestion_id' => (int) $suggestion->id,
                    'issue_code' => (string) $suggestion->issue_code,
                    'seo' => array_filter([
                        'title' => $recommendedTitle !== '' ? Str::limit($recommendedTitle, 255, '') : null,
                        'meta_description' => $recommendedMetaDescription !== '' ? Str::limit($recommendedMetaDescription, 320, '') : null,
                        'h1' => $recommendedH1 !== '' ? Str::limit($recommendedH1, 255, '') : null,
                        'canonical' => $recommendedCanonical !== '' ? Str::limit($recommendedCanonical, 2048, '') : null,
                        'robots_index' => $recommendedRobotsIndex,
                        'robots_follow' => $recommendedRobotsFollow,
                        'schema_type' => $recommendedSchemaType !== '' ? Str::limit($recommendedSchemaType, 120, '') : null,
                    ], static fn ($value) => $value !== null && (is_bool($value) || trim((string) $value) !== '')),
                ],
                'source' => 'pl',
                'created_by' => $userId,
            ]);

            $content->update([
                'title' => $recommendedTitle !== '' ? Str::limit($recommendedTitle, 255, '') : $content->title,
                'seo_title' => $recommendedTitle !== '' ? Str::limit($recommendedTitle, 255, '') : $content->seo_title,
                'seo_meta_description' => $recommendedMetaDescription !== '' ? Str::limit($recommendedMetaDescription, 320, '') : $content->seo_meta_description,
                'seo_h1' => $recommendedH1 !== '' ? Str::limit($recommendedH1, 255, '') : $content->seo_h1,
                'seo_canonical' => $recommendedCanonical !== '' ? Str::limit($recommendedCanonical, 2048, '') : $content->seo_canonical,
                'seo_og_title' => trim((string) ($seoFields['seo_og_title'] ?? '')) !== '' ? Str::limit((string) $seoFields['seo_og_title'], 255, '') : $content->seo_og_title,
                'seo_og_description' => trim((string) ($seoFields['seo_og_description'] ?? '')) !== '' ? Str::limit((string) $seoFields['seo_og_description'], 500, '') : $content->seo_og_description,
                'seo_og_image' => trim((string) ($seoFields['seo_og_image'] ?? '')) !== '' ? Str::limit((string) $seoFields['seo_og_image'], 2048, '') : $content->seo_og_image,
                'seo_twitter_title' => trim((string) ($seoFields['seo_twitter_title'] ?? '')) !== '' ? Str::limit((string) $seoFields['seo_twitter_title'], 255, '') : $content->seo_twitter_title,
                'seo_twitter_description' => trim((string) ($seoFields['seo_twitter_description'] ?? '')) !== '' ? Str::limit((string) $seoFields['seo_twitter_description'], 500, '') : $content->seo_twitter_description,
                'robots_index' => $recommendedRobotsIndex ?? $content->robots_index,
                'robots_follow' => $recommendedRobotsFollow ?? $content->robots_follow,
                'schema_type' => $recommendedSchemaType !== '' ? Str::limit($recommendedSchemaType, 120, '') : $content->schema_type,
                'current_version_id' => $version->id,
                'status' => 'draft',
                'delivery_status' => $this->markDeliveryStatusOutOfSync((string) ($content->delivery_status ?? '')),
                'updated_by' => $userId,
            ]);

            $suggestion->update([
                'status' => SeoAuditFixSuggestion::STATUS_APPLIED,
                'suggestion_state' => SeoAuditSuggestionState::APPLIED_LOCAL,
                'applied_by' => $userId,
            ]);

            $content->refresh();
            $latestDraft->refresh();

            $changeSet = $this->buildApplyLogChangeSet(
                $contentBefore,
                $this->captureApplyLogState($content),
                $draftBefore,
                $this->captureApplyLogState($latestDraft),
            );

            SeoApplyLog::query()->create([
                'suggestion_id' => $suggestion->id,
                'seo_audit_id' => $suggestion->seo_audit_id,
                'seo_audit_page_id' => $suggestion->seo_audit_page_id,
                'issue_code' => $suggestion->issue_code,
                'content_id' => $content->id,
                'draft_id' => $latestDraft->id,
                'applied_by' => $userId,
                'apply_target' => 'content_and_latest_draft',
                'changed_fields' => $changeSet['changed_fields'],
                'old_values' => $changeSet['old_values'],
                'new_values' => $changeSet['new_values'],
                'apply_status' => SeoApplyLog::STATUS_APPLIED,
                'applied_at' => now(),
            ]);

            Event::query()->create([
                'id' => (string) Str::uuid(),
                'client_site_id' => $content->client_site_id,
                'type' => 'seo.audit.ai_fix.applied',
                'occurred_at' => now(),
                'data' => [
                    'content_id' => (string) $content->id,
                    'seo_audit_id' => (int) $suggestion->seo_audit_id,
                    'seo_audit_fix_suggestion_id' => (int) $suggestion->id,
                    'issue_code' => (string) $suggestion->issue_code,
                    'applied_by' => $userId,
                ],
            ]);
        });

        return $editableDraft->fresh();
    }

    public function ensureSuggestionState(SeoAuditFixSuggestion $suggestion, ?Content $content = null): SeoAuditSuggestionState
    {
        $currentState = $this->normalizeSuggestionState($suggestion);

        if ($currentState === SeoAuditSuggestionState::SUGGESTED && $suggestion->status !== SeoAuditFixSuggestion::STATUS_APPLIED) {
            return $currentState;
        }

        $content ??= $suggestion->page?->arguslyArticle;
        if (! $content) {
            if ($currentState !== SeoAuditSuggestionState::APPLIED_LOCAL) {
                $suggestion->forceFill([
                    'suggestion_state' => SeoAuditSuggestionState::APPLIED_LOCAL,
                ])->save();
            }

            return SeoAuditSuggestionState::APPLIED_LOCAL;
        }

        $content->loadMissing('drafts');
        $latestDraft = $content->drafts
            ->sortByDesc(fn (Draft $draft): int => $draft->updated_at?->getTimestamp() ?? 0)
            ->first();

        $appliedAt = $suggestion->applyLog?->applied_at ?? $suggestion->updated_at;
        $deliveryStatus = $latestDraft
            ? PublicationDeliveryStatus::fromLegacyStatus((string) ($latestDraft->delivery_status ?? 'pending'))
            : PublicationDeliveryStatus::PENDING;

        $resolvedState = $deliveryStatus->isSuccess()
            && $latestDraft?->delivered_at !== null
            && $appliedAt !== null
            && $latestDraft->delivered_at->greaterThanOrEqualTo($appliedAt)
                ? SeoAuditSuggestionState::SYNCED_EXTERNAL
                : SeoAuditSuggestionState::APPLIED_LOCAL;

        if ($currentState !== $resolvedState) {
            $suggestion->forceFill([
                'suggestion_state' => $resolvedState,
            ])->save();
        }

        return $resolvedState;
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
                    'source' => 'seo_audit_ai_fix',
                    'progress' => 0,
                    'title' => (string) ($lockedContent->title ?: 'Untitled content'),
                    'language' => $lockedContent->language?->value ?? 'en',
                    'primary_keyword' => (string) ($lockedContent->primary_keyword ?: '') ?: null,
                    'output_type' => 'kb_article',
                    'client_refs' => [
                        'source' => 'seo_audit_ai_fix',
                        'auto_created_from_content' => true,
                        'wp_post_id' => $lockedContent->wp_post_id,
                    ],
                    'wp_post_id' => $lockedContent->wp_post_id,
                    'wp_site_id' => (string) $lockedContent->client_site_id,
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
                'delivery_status' => $this->markDeliveryStatusOutOfSync((string) ($lockedContent->delivery_status ?? '')),
                'meta' => [
                    'source' => 'seo_audit_ai_fix',
                    'auto_created_from_content' => true,
                    'client_refs' => [
                        'wp_post_id' => $lockedContent->wp_post_id,
                    ],
                ],
            ]);
        });
    }

    private function normalizeSuggestionState(SeoAuditFixSuggestion $suggestion): SeoAuditSuggestionState
    {
        $raw = $suggestion->suggestion_state;

        if ($raw instanceof SeoAuditSuggestionState) {
            return $raw;
        }

        return SeoAuditSuggestionState::tryFrom((string) $raw)
            ?? ($suggestion->status === SeoAuditFixSuggestion::STATUS_APPLIED
                ? SeoAuditSuggestionState::APPLIED_LOCAL
                : SeoAuditSuggestionState::SUGGESTED);
    }

    private function resolveDraftBodyFromContent(Content $content): ?string
    {
        $revisionBody = trim((string) ($content->currentRevision?->content_html ?? ''));
        if ($revisionBody !== '') {
            return $revisionBody;
        }

        $versionBody = trim((string) ($content->currentVersion?->body ?? ''));

        return $versionBody !== '' ? $versionBody : null;
    }

    private function markDeliveryStatusOutOfSync(string $status): string
    {
        $normalized = trim($status);

        if ($normalized === '') {
            return PublicationDeliveryStatus::PENDING->value;
        }

        $deliveryStatus = PublicationDeliveryStatus::fromLegacyStatus($normalized);

        return $deliveryStatus->isSuccess()
            ? PublicationDeliveryStatus::OUT_OF_SYNC->value
            : $deliveryStatus->value;
    }

    /**
     * @return array<string,mixed>
     */
    private function captureApplyLogState(mixed $record): array
    {
        $state = [];
        foreach (self::APPLY_LOG_FIELDS as $field) {
            $state[$field] = data_get($record, $field);
        }

        return $state;
    }

    /**
     * @param array<string,mixed> $contentBefore
     * @param array<string,mixed> $contentAfter
     * @param array<string,mixed>|null $draftBefore
     * @param array<string,mixed>|null $draftAfter
     * @return array{changed_fields:array<string,array<int,string>>,old_values:array<string,array<string,mixed>>,new_values:array<string,array<string,mixed>>}
     */
    private function buildApplyLogChangeSet(
        array $contentBefore,
        array $contentAfter,
        ?array $draftBefore,
        ?array $draftAfter
    ): array {
        $contentChanged = $this->diffApplyLogFields($contentBefore, $contentAfter);
        $draftChanged = ($draftBefore !== null && $draftAfter !== null)
            ? $this->diffApplyLogFields($draftBefore, $draftAfter)
            : [];

        $changedFields = [];
        $oldValues = [];
        $newValues = [];

        if ($contentChanged !== []) {
            $changedFields['content'] = $contentChanged;
            $oldValues['content'] = $this->sliceApplyLogState($contentBefore, $contentChanged);
            $newValues['content'] = $this->sliceApplyLogState($contentAfter, $contentChanged);
        }

        if ($draftChanged !== []) {
            $changedFields['draft'] = $draftChanged;
            $oldValues['draft'] = $this->sliceApplyLogState($draftBefore ?? [], $draftChanged);
            $newValues['draft'] = $this->sliceApplyLogState($draftAfter ?? [], $draftChanged);
        }

        return [
            'changed_fields' => $changedFields,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ];
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<int,string>
     */
    private function diffApplyLogFields(array $before, array $after): array
    {
        $changed = [];

        foreach (self::APPLY_LOG_FIELDS as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * @param array<string,mixed> $values
     * @param array<int,string> $keys
     * @return array<string,mixed>
     */
    private function sliceApplyLogState(array $values, array $keys): array
    {
        $slice = [];
        foreach ($keys as $key) {
            $slice[$key] = $values[$key] ?? null;
        }

        return $slice;
    }

    /**
     * @return array<string,mixed>
     */
    public function buildInputSnapshot(SeoAuditPage $page, SeoAuditIssue $issue, ?Content $content): array
    {
        $contentSeo = $content ? SeoMetadata::resolveForContentContext($content) : [];
        $topHeadings = [];
        $h1 = trim((string) ($page->h1 ?? ''));
        if ($h1 !== '') {
            $topHeadings[] = $h1;
        }

        return [
            'page_url' => (string) $page->url,
            'title' => (string) ($page->title ?? ''),
            'meta_description' => (string) ($page->meta_description ?? ''),
            'h1_list' => $topHeadings,
            'canonical_url' => (string) ($page->canonical_url ?? ''),
            'top_headings_summary' => implode(' | ', $topHeadings),
            'internal_links_count' => (int) ($page->internal_links_count ?? 0),
            'issue' => [
                'code' => (string) $issue->code,
                'title' => (string) $issue->title,
                'description' => (string) ($issue->description ?? ''),
                'recommendation' => (string) ($issue->recommendation ?? ''),
            ],
            'argusly_content_id' => $content?->id,
            'argusly_article_title' => (string) ($content?->title ?? ''),
            'argusly_article_meta_title' => (string) ($contentSeo['seo_title'] ?? ''),
            'argusly_article_meta_description' => (string) ($contentSeo['seo_meta_description'] ?? ''),
            'argusly_article_h1' => (string) ($contentSeo['seo_h1'] ?? ''),
            'argusly_article_canonical' => (string) ($contentSeo['seo_canonical'] ?? ''),
        ];
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'You are a senior technical SEO editor.',
            'Return strict JSON only.',
            'Keep the page language consistent with the input.',
            'Avoid keyword stuffing and unsafe language.',
            'Do not invent product claims or facts.',
            'Title recommendation should target 50-60 characters.',
            'Meta description recommendation should target 120-160 characters.',
        ]);
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function buildUserPrompt(array $snapshot, string $issueCode): string
    {
        return implode("\n", [
            'Create SEO fix suggestions for this issue and page context.',
            'Issue code: ' . $issueCode,
            'Output contract:',
            $this->outputSchemaDescription(),
            'Input snapshot JSON:',
            json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        ]);
    }

    private function outputSchemaDescription(): string
    {
        return <<<'JSON'
{
  "title": "string",
  "meta_description": "string",
  "h1": "string",
  "canonical": "string",
  "internal_links": [
    {"url":"string","anchor":"string","reason":"string"}
  ],
  "notes": ["string"]
}
JSON;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalizeSuggestion(array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? $payload['recommended_title'] ?? ''));
        $metaDescription = trim((string) ($payload['meta_description'] ?? $payload['recommended_meta_description'] ?? ''));
        $h1 = trim((string) ($payload['h1'] ?? $payload['recommended_h1'] ?? ''));
        $canonical = trim((string) ($payload['canonical'] ?? $payload['recommended_canonical'] ?? ''));

        $internalLinksRaw = $payload['internal_links'] ?? $payload['internal_link_suggestions'] ?? [];
        $internalLinks = [];
        if (is_array($internalLinksRaw)) {
            foreach ($internalLinksRaw as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $url = trim((string) ($row['url'] ?? ''));
                $anchor = trim((string) ($row['anchor'] ?? ''));
                $reason = trim((string) ($row['reason'] ?? ''));

                if ($url === '' && $anchor === '' && $reason === '') {
                    continue;
                }

                $internalLinks[] = [
                    'url' => Str::limit($url, 2048, ''),
                    'anchor' => Str::limit($anchor, 255, ''),
                    'reason' => Str::limit($reason, 500, ''),
                ];

                if (count($internalLinks) >= 8) {
                    break;
                }
            }
        }

        $notesRaw = $payload['notes'] ?? [];
        $notes = [];
        if (is_array($notesRaw)) {
            foreach ($notesRaw as $note) {
                $value = trim((string) $note);
                if ($value === '') {
                    continue;
                }

                $notes[] = Str::limit($value, 500, '');
                if (count($notes) >= 8) {
                    break;
                }
            }
        }

        if ($title === '' && $metaDescription === '' && $h1 === '' && $canonical === '' && $internalLinks === []) {
            throw new RuntimeException('AI response did not include usable SEO suggestions.');
        }

        return [
            'title' => Str::limit($title, 255, ''),
            'meta_description' => Str::limit($metaDescription, 320, ''),
            'h1' => Str::limit($h1, 255, ''),
            'canonical' => Str::limit($canonical, 2048, ''),
            'internal_links' => $internalLinks,
            'notes' => $notes,
            'recommended_title' => Str::limit($title, 255, ''),
            'recommended_meta_description' => Str::limit($metaDescription, 320, ''),
            'recommended_h1' => Str::limit($h1, 255, ''),
            'recommended_canonical' => Str::limit($canonical, 2048, ''),
            'internal_link_suggestions' => $internalLinks,
        ];
    }
}
