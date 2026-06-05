<?php

namespace App\Services\Content;

use App\Enums\DraftType;
use App\Enums\SupportedLanguage;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\ContentVersion;
use App\Models\Draft;
use App\Services\Integrations\LaravelConnectorDestinationResolver;
use App\Services\Publication\ContentPublicationStateService;
use App\Support\ContentPersistencePayloadNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class ContentLocalizationService
{
    public function __construct(
        private readonly LaravelConnectorDestinationResolver $laravelDestinationResolver,
        private readonly ContentPublicationStateService $publicationState,
    ) {}

    public function source(Content $content): Content
    {
        return $content->localizationSource();
    }

    /**
     * @return Collection<int,Content>
     */
    public function family(Content $content): Collection
    {
        return $this->source($content)->normalizedLocalizationFamily();
    }

    public function variantForLocale(Content $content, string $locale, bool $publishedOnly = false): ?Content
    {
        $resolvedLocale = SupportedLanguage::fromStringOrDefault($locale)->value;

        return $this->family($content)
            ->first(function (Content $variant) use ($resolvedLocale, $publishedOnly): bool {
                if ($variant->localeCode() !== $resolvedLocale) {
                    return false;
                }

                if (! $publishedOnly) {
                    return true;
                }

                return $this->publicationState->isPublished($variant);
            });
    }

    /**
     * @return array<int,string>
     */
    public function publishedLocales(Content $content): array
    {
        return $this->family($content)
            ->filter(fn (Content $variant): bool => $this->publicationState->isPublished($variant))
            ->map(fn (Content $variant): string => $variant->localeCode())
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function statusMatrix(Content $content): array
    {
        $source = $this->source($content);
        $source->loadMissing([
            'clientSite',
            'contentDestination',
            'currentVersion',
            'drafts' => fn ($query) => $query->latest('created_at'),
            'publications' => fn ($query) => $query->latest('last_delivered_at')->latest('updated_at'),
            'localizedVariants.clientSite',
            'localizedVariants.contentDestination',
            'localizedVariants.currentVersion',
            'localizedVariants.drafts' => fn ($query) => $query->latest('created_at'),
            'localizedVariants.publications' => fn ($query) => $query->latest('last_delivered_at')->latest('updated_at'),
        ]);

        $sourceLocale = $source->localeCode();
        $duplicateLocaleCounts = $source->localizationFamily()
            ->groupBy(fn (Content $variant): string => $variant->localeCode())
            ->map(fn (Collection $variants): int => $variants->count());

        return $source->normalizedLocalizationFamily()
            ->map(function (Content $variant) use ($duplicateLocaleCounts, $sourceLocale): array {
                $locale = SupportedLanguage::fromStringOrDefault($variant->localeCode());
                $latestDraft = $this->latestDraftForVariant($variant);
                $publication = $this->latestPublicationForVariant($variant);
                $remotePublishStatus = $this->publicationState->remotePublishStatus($variant, $publication);
                $publishStatus = $this->legacyPublishStatus($variant, $publication, $remotePublishStatus);
                $deliveryStatus = $this->publicationState->deliveryStatus($variant, $publication);
                $hasDuplicateLocaleRows = ((int) ($duplicateLocaleCounts->get($locale->value, 0))) > 1;
                $isPublished = $this->publicationState->isPublished($variant, $publication);
                $hasRenderableDraft = $latestDraft instanceof Draft
                    && trim((string) ($latestDraft->content_html ?? '')) !== '';
                $isLaravelVariant = ClientSite::normalizeType((string) ($variant->clientSite?->type ?? '')) === ClientSite::TYPE_LARAVEL;
                $hasLaravelDestination = $this->hasLaravelDestination($variant);
                $hasNewerDraft = $this->hasNewerDraft($variant, $latestDraft, $publication, $isPublished);
                $isOutdated = $variant->isTranslationOutdated();
                $syncWithSourceActive = $variant->isTranslationVariant()
                    && (bool) ($variant->sync_with_source ?? true)
                    && (bool) ($variant->auto_publish ?? true)
                    && (bool) ($source->auto_publish ?? true);
                $actionState = $this->variantActionState(
                    $publishStatus,
                    $hasDuplicateLocaleRows,
                    $isPublished,
                    $hasNewerDraft || $isOutdated
                );

                return [
                    'locale' => $locale->value,
                    'label' => $locale->englishLabel(),
                    'native_label' => $locale->label(),
                    'content' => $variant,
                    'is_source' => ! $variant->isTranslationVariant() || (bool) $variant->is_source_locale,
                    'source_badge_label' => (! $variant->isTranslationVariant() || (bool) $variant->is_source_locale)
                        ? 'Source'
                        : 'SRC ' . strtoupper($sourceLocale),
                    'is_published' => $isPublished,
                    'is_outdated' => $isOutdated,
                    'sync_with_source_active' => $syncWithSourceActive,
                    'status' => (string) $variant->status,
                    'publish_status' => $publishStatus,
                    'delivery_status' => $deliveryStatus->value,
                    'delivery_label' => $deliveryStatus->label(),
                    'latest_draft_exists' => $hasRenderableDraft,
                    'latest_draft_id' => $latestDraft?->id ? (string) $latestDraft->id : null,
                    'latest_draft_updated_at' => $latestDraft?->updated_at,
                    'has_newer_draft' => $hasNewerDraft,
                    'is_laravel_variant' => $isLaravelVariant,
                    'has_laravel_destination' => $hasLaravelDestination,
                    'has_duplicate_locale_rows' => $hasDuplicateLocaleRows,
                    'duplicate_locale_count' => (int) ($duplicateLocaleCounts->get($locale->value, 0)),
                    'action_state' => $actionState,
                    'state_label' => $this->variantStateLabel($actionState),
                    'state_color' => $this->variantStateColor($actionState),
                    'can_publish_now' => $hasLaravelDestination
                        && $hasRenderableDraft
                        && ! $hasDuplicateLocaleRows
                        && in_array($actionState, ['draft', 'published_with_update'], true),
                    'can_update_live' => $hasLaravelDestination
                        && $hasRenderableDraft
                        && ! $hasDuplicateLocaleRows
                        && $actionState === 'published_with_update',
                    'can_schedule' => $hasLaravelDestination
                        && $hasRenderableDraft
                        && ! $hasDuplicateLocaleRows
                        && ! $syncWithSourceActive
                        && $actionState === 'draft',
                    'translation_generated_at' => $variant->translation_generated_at,
                    'translation_source_updated_at' => $variant->translation_source_updated_at,
                    'scheduled_publish_at' => $variant->scheduled_publish_at,
                ];
            })
            ->values()
            ->all();
    }

    private function latestDraftForVariant(Content $variant): ?Draft
    {
        $drafts = $variant->relationLoaded('drafts')
            ? $variant->drafts
            : $variant->drafts()->latest('created_at')->get();

        return $drafts
            ->sortByDesc(fn (Draft $draft): int => (int) ($draft->created_at?->timestamp ?? 0))
            ->first();
    }

    private function latestPublicationForVariant(Content $variant): ?ContentPublication
    {
        return $this->publicationState->resolveCanonicalPublication($variant);
    }

    private function hasLaravelDestination(Content $variant): bool
    {
        if (ClientSite::normalizeType((string) ($variant->clientSite?->type ?? '')) !== ClientSite::TYPE_LARAVEL) {
            return false;
        }

        return $this->laravelDestinationResolver->resolveForContent($variant) !== null;
    }

    private function hasNewerDraft(
        Content $variant,
        ?Draft $latestDraft,
        ?ContentPublication $publication,
        bool $isPublished,
    ): bool {
        if (! $isPublished || ! $latestDraft) {
            return false;
        }

        $latestDraftTimestamp = (int) (($latestDraft->updated_at ?? $latestDraft->created_at)?->timestamp ?? 0);
        if ($latestDraftTimestamp === 0) {
            return false;
        }

        $lastLiveTimestamp = max(
            (int) ($publication?->last_delivered_at?->timestamp ?? 0),
            (int) ($variant->currentVersion?->updated_at?->timestamp ?? 0),
            (int) ($variant->currentVersion?->created_at?->timestamp ?? 0),
        );

        if ($lastLiveTimestamp === 0) {
            return true;
        }

        return $latestDraftTimestamp > $lastLiveTimestamp;
    }

    private function variantActionState(
        string $publishStatus,
        bool $hasDuplicateLocaleRows,
        bool $isPublished,
        bool $hasNewerDraft,
    ): string {
        if ($hasDuplicateLocaleRows) {
            return 'duplicate_invalid';
        }

        return match (true) {
            $publishStatus === 'publishing' => 'publishing',
            $publishStatus === 'scheduled' => 'scheduled',
            $isPublished && $hasNewerDraft => 'published_with_update',
            $isPublished => 'published',
            default => 'draft',
        };
    }

    private function variantStateLabel(string $actionState): string
    {
        return match ($actionState) {
            'duplicate_invalid' => 'Duplicate locale',
            'publishing' => 'Publishing...',
            'scheduled' => 'Scheduled',
            'published_with_update', 'published' => 'Published',
            default => 'Draft',
        };
    }

    private function variantStateColor(string $actionState): string
    {
        return match ($actionState) {
            'duplicate_invalid' => 'amber',
            'publishing', 'scheduled' => 'sky',
            'published_with_update', 'published' => 'green',
            default => 'slate',
        };
    }

    private function legacyPublishStatus(
        Content $variant,
        ?ContentPublication $publication,
        ?\App\Enums\RemotePublishStatus $remotePublishStatus,
    ): string {
        if ($publication instanceof ContentPublication) {
            return match (true) {
                $publication->deliveryStatusEnum()->isInProgress() => 'publishing',
                $remotePublishStatus === \App\Enums\RemotePublishStatus::SCHEDULED => 'scheduled',
                $remotePublishStatus?->isVisible() ?? false => 'published',
                $publication->deliveryStatusEnum()->isFailure() => 'failed',
                $publication->deliveryStatusEnum()->isSuccess() => 'published',
                default => 'draft',
            };
        }

        return (string) ($variant->publish_status ?? 'draft');
    }

    /**
     * @return array{draft:Draft,source_type:'draft'|'delivered'|'published'}
     */
    public function resolveTranslationSource(Content $content, ?int $userId = null): array
    {
        $source = $this->source($content);
        $sourceLocale = $source->localeCode();

        $drafts = Draft::query()
            ->where('content_id', $source->id)
            ->whereNotNull('content_html')
            ->where('content_html', '!=', '')
            ->whereNotIn('status', ['failed', 'cancelled'])
            ->latest('created_at')
            ->get();

        $draft = $drafts->first(function (Draft $candidate) use ($sourceLocale): bool {
            $draftLocale = SupportedLanguage::fromStringOrDefault((string) $candidate->getRawOriginal('language'))->value;

            return $draftLocale === $sourceLocale
                && $candidate->canBeTranslated()
                && in_array((string) $candidate->status, ['ready', 'delivered', 'published'], true);
        });

        if ($draft instanceof Draft) {
            $draftLocale = SupportedLanguage::fromStringOrDefault((string) $draft->getRawOriginal('language'))->value;

            if ($draftLocale !== $sourceLocale) {
                Log::warning('content.translation.source_draft_locale_mismatch', [
                    'content_id' => (string) $source->id,
                    'source_draft_id' => (string) $draft->id,
                    'source_content_locale' => $sourceLocale,
                    'source_draft_locale' => trim((string) $draft->getRawOriginal('language')),
                    'normalized_source_draft_locale' => $draftLocale,
                    'reason' => 'bootstrap_from_current_content',
                ]);

                return [
                    'draft' => $this->bootstrapSourceDraftFromCurrentContent(
                        $source,
                        $userId,
                        'translation_source_from_current_content_locale_repair',
                        $source->translationSourceLifecycle() ?? 'draft'
                    ),
                    'source_type' => $source->translationSourceLifecycle() ?? 'draft',
                ];
            }

            return [
                'draft' => $draft,
                'source_type' => 'draft',
            ];
        }

        $fallbackType = $source->translationSourceLifecycle() ?? 'draft';

        return [
            'draft' => $this->bootstrapSourceDraftFromCurrentContent(
                $source,
                $userId,
                sprintf('translation_source_from_%s_content', $fallbackType),
                $fallbackType
            ),
            'source_type' => $fallbackType,
        ];
    }

    public function resolveTranslationSourceDraft(Content $content, ?int $userId = null): Draft
    {
        return $this->resolveTranslationSource($content, $userId)['draft'];
    }

    private function bootstrapSourceDraftFromCurrentContent(
        Content $content,
        ?int $userId = null,
        string $bootstrapReason = 'translation_source_from_current_content',
        string $sourceType = 'draft'
    ): Draft
    {
        $sourceLocale = $content->localeCode();
        $content->loadMissing('brief', 'currentVersion');

        if (! $content->client_site_id) {
            throw new RuntimeException('This content is not linked to a site, so Argusly cannot build a translation source draft.');
        }

        $sourceVersion = $this->resolveRenderableSourceVersion($content);
        $body = trim((string) ($sourceVersion?->body ?? ''));
        if ($body === '') {
            throw new RuntimeException(
                'No usable translation source is available. Argusly could not find a current delivered/published content version with body content to translate.'
            );
        }

        $brief = $content->brief;
        if (! $brief) {
            $brief = Brief::query()->create(ContentPersistencePayloadNormalizer::normalizeBrief([
                'id' => (string) Str::uuid(),
                'client_site_id' => (string) $content->client_site_id,
                'content_destination_id' => $content->content_destination_id,
                'created_by_user_id' => $userId,
                'content_id' => (string) $content->id,
                'status' => 'done',
                'source' => 'content_localization',
                'progress' => 1,
                'title' => (string) $content->title,
                'language' => $sourceLocale,
                'content_type' => 'blog',
                'output_type' => 'kb_article',
                'primary_keyword' => $content->primary_keyword,
                'client_refs' => [
                    'bootstrap_reason' => $bootstrapReason,
                ],
            ]));
        } elseif (SupportedLanguage::fromStringOrDefault((string) $brief->language)->value !== $sourceLocale) {
            $brief->forceFill([
                'language' => $sourceLocale,
            ])->save();
        }

        return Draft::query()->create([
            'id' => (string) Str::uuid(),
            'brief_id' => (string) $brief->id,
            'content_id' => (string) $content->id,
            'client_site_id' => (string) $content->client_site_id,
            'content_destination_id' => $content->content_destination_id,
            'status' => 'ready',
            'title' => (string) $content->title,
            'output_type' => (string) ($brief->output_type ?: 'kb_article'),
            'language' => $sourceLocale,
            'draft_type' => DraftType::ORIGINAL->value,
            'content_html' => $body,
            'meta' => [
                'bootstrap_reason' => $bootstrapReason,
                'translation_source_type' => $sourceType,
                'source_version_id' => (string) ($sourceVersion?->id ?? $content->current_version_id ?? ''),
                'source_version_type' => (string) ($sourceVersion?->type ?? ''),
                'source_version_meta' => is_array($sourceVersion?->meta) ? $sourceVersion->meta : [],
            ],
            'delivery_status' => (string) ($content->delivery_status ?? 'pending'),
            'seo_title' => $content->seo_title,
            'seo_meta_description' => $content->seo_meta_description,
            'seo_h1' => $content->seo_h1,
            'seo_canonical' => $content->seo_canonical,
            'seo_og_title' => $content->seo_og_title,
            'seo_og_description' => $content->seo_og_description,
            'seo_og_image' => $content->seo_og_image,
            'seo_twitter_title' => $content->seo_twitter_title,
            'seo_twitter_description' => $content->seo_twitter_description,
            'robots_index' => $content->robots_index,
            'robots_follow' => $content->robots_follow,
            'schema_type' => $content->schema_type,
        ]);
    }

    private function resolveRenderableSourceVersion(Content $content): ?ContentVersion
    {
        $currentVersion = $content->currentVersion;

        if ($currentVersion instanceof ContentVersion && trim((string) $currentVersion->body) !== '') {
            return $currentVersion;
        }

        return $content->versions()
            ->whereIn('type', [
                ContentVersion::TYPE_PUBLISHED_SNAPSHOT,
                ContentVersion::TYPE_REVISION,
                ContentVersion::TYPE_DRAFT,
            ])
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->orderByRaw("CASE type WHEN 'published_snapshot' THEN 1 WHEN 'revision' THEN 2 WHEN 'draft' THEN 3 ELSE 4 END")
            ->orderByDesc('created_at')
            ->first();
    }
}
