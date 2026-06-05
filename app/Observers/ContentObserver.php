<?php

namespace App\Observers;

use App\Enums\ContentLifecycleStatus;
use App\Enums\SupportedLanguage;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Workspace;
use App\Jobs\GenerateStructuredAnswersJob;
use App\Services\Aeo\AeoScoreService;
use App\Services\Content\ContentCacheInvalidationService;
use App\Services\ContentAutomation\AutomationRunItemStateService;
use App\Services\Performance\PerformanceCacheService;
use App\Services\PublicBlog\PublicBlogPerformanceDataService;
use App\Support\Markdown\MarkdownGenerationDispatcher;
use App\Support\Analytics\AnalyticsUrlKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ContentObserver
{
    public function saving(Content $content): void
    {
        $supportsFamilyId = Content::supportsFamilyId();

        if (! filled($content->id)) {
            $content->id = (string) Str::uuid();
        }

        $resolvedLocale = SupportedLanguage::normalizeLocale(
            ($content->language instanceof SupportedLanguage ? $content->language->value : (string) $content->language)
            ?: $content->getRawOriginal('language')
            ?: $content->translationSourceContent?->localeCode()
        );

        if ($resolvedLocale === null && $content->client_site_id) {
            $site = $content->relationLoaded('clientSite')
                ? $content->clientSite
                : ClientSite::query()->with('workspace')->find((string) $content->client_site_id);

            $resolvedLocale = $site?->workspace?->defaultContentLanguageCode();
        }

        if ($resolvedLocale === null && $content->workspace_id) {
            $workspace = $content->relationLoaded('workspace')
                ? $content->workspace
                : Workspace::query()->find((string) $content->workspace_id);

            $resolvedLocale = $workspace?->defaultContentLanguageCode();
        }

        if ($resolvedLocale === null) {
            throw new RuntimeException('Locale is required');
        }

        $content->language = $resolvedLocale;

        if (! $supportsFamilyId) {
            unset($content->family_id);
        }

        if ($content->translation_source_content_id) {
            $canonicalSource = $this->resolveCanonicalSource($content);

            if ($canonicalSource instanceof Content) {
                $content->translation_source_content_id = (string) $canonicalSource->id;
                if ($supportsFamilyId) {
                    $content->family_id = (string) ($canonicalSource->family_id ?: $canonicalSource->id);
                }
                $content->translation_source_version_id = $content->translation_source_version_id ?: $canonicalSource->current_version_id;
            } elseif ($supportsFamilyId && ! filled($content->family_id)) {
                $content->family_id = (string) $content->translation_source_content_id;
            }

            $content->is_source_locale = false;

            $sourceLocale = $canonicalSource?->localeCode() ?? $content->translationSourceContent?->localeCode();
            if ($sourceLocale !== null) {
                $content->translation_source_locale = $sourceLocale;
            }
        } else {
            if ($supportsFamilyId) {
                $content->family_id = (string) ($content->id ?: $content->family_id);
            }
            $content->translation_source_content_id = null;
            $content->translation_source_locale = null;
            $content->translation_source_version_id = null;
            $content->is_source_locale = true;
        }

        $publishedUrl = trim((string) ($content->published_url ?? ''));
        $explicitPublishSlug = trim((string) ($content->publish_url_key ?? ''));
        $publishUrlKey = $this->resolvePublishSlug($explicitPublishSlug, $publishedUrl);
        $canonicalUrlKey = AnalyticsUrlKey::fromUrl($publishedUrl);

        $siteUrl = '';
        if ($content->clientSite) {
            $siteUrl = (string) ($content->clientSite->base_url ?: $content->clientSite->site_url);
        } elseif (! empty($content->client_site_id)) {
            $site = ClientSite::query()
                ->whereKey((string) $content->client_site_id)
                ->first(['base_url', 'site_url']);

            $siteUrl = (string) ($site?->base_url ?: $site?->site_url);
        }

        $siteHost = AnalyticsUrlKey::hostFromUrl($siteUrl);
        if ($siteHost !== '' && $publishedUrl !== '') {
            $keyWithSiteHost = AnalyticsUrlKey::fromUrlUsingHost($publishedUrl, $siteHost);
            if ($keyWithSiteHost !== '') {
                $canonicalUrlKey = $keyWithSiteHost;
            }
        }

        $content->publish_url_key = $publishUrlKey !== '' ? $publishUrlKey : null;
        $content->canonical_url_key = $canonicalUrlKey !== '' ? $canonicalUrlKey : null;

        // Sync lifecycle_stage to legacy status field for backwards compatibility
        $this->syncLifecycleToLegacyStatus($content);
    }

    /**
     * Sync lifecycle_stage changes to the legacy status field.
     *
     * When lifecycle_stage is changed, we update the legacy status field
     * to maintain backwards compatibility with existing code.
     */
    private function syncLifecycleToLegacyStatus(Content $content): void
    {
        // Check if lifecycle_stage column exists (for pre-migration compatibility)
        if (! Content::query()->getConnection()->getSchemaBuilder()->hasColumn('contents', 'lifecycle_stage')) {
            return;
        }

        // Only sync if lifecycle_stage was actually changed
        if (! $content->isDirty('lifecycle_stage')) {
            return;
        }

        $lifecycleStage = $content->lifecycle_stage;

        // Handle enum or string value
        $stage = $lifecycleStage instanceof ContentLifecycleStatus
            ? $lifecycleStage
            : ContentLifecycleStatus::tryFrom((string) $lifecycleStage);

        if ($stage) {
            $content->status = $stage->toLegacyStatus();
        }
    }

    private function resolvePublishSlug(string $explicitPublishSlug, string $publishedUrl): string
    {
        if ($explicitPublishSlug !== ''
            && ! str_contains($explicitPublishSlug, '/')
            && ! filter_var($explicitPublishSlug, FILTER_VALIDATE_URL)
        ) {
            return Str::slug($explicitPublishSlug);
        }

        $path = (string) parse_url($publishedUrl, PHP_URL_PATH);
        $slug = trim((string) basename($path), '/');

        return $slug !== '' ? Str::slug($slug) : '';
    }

    public function saved(Content $content): void
    {
        if ($content->wasRecentlyCreated || $content->wasChanged($this->performanceRelevantAttributes())) {
            app(PerformanceCacheService::class)->bustForContent(
                $content->loadMissing('workspace:id,organization_id')
            );
        }

        if ((bool) $content->is_source_locale) {
            app(\App\Services\Content\LocaleMismatchService::class)->enforceSingleSourceForContent($content);
        }

        if (filled($content->automation_run_id) && filled($content->automation_id)) {
            app(AutomationRunItemStateService::class)->syncFromContent(
                $content->fresh(['drafts', 'publications']) ?? $content
            );
        }

        if (! $content->wasRecentlyCreated && ! $content->wasChanged([
            'title',
            'language',
            'status',
            'type',
            'source',
            'publish_status',
            'current_revision_id',
            'current_version_id',
            'seo_title',
            'seo_meta_description',
            'seo_h1',
            'seo_canonical',
            'seo_og_title',
            'seo_og_description',
            'seo_og_image',
            'seo_twitter_title',
            'seo_twitter_description',
            'primary_keyword',
            'robots_index',
            'robots_follow',
            'schema_type',
            'team_member_id',
            'scheduled_publish_at',
            'published_url',
            'answer_block_render_mode',
            'answer_block_visibility',
            'answer_block_position',
            'answer_block_max_visible',
        ])) {
            return;
        }

        MarkdownGenerationDispatcher::dispatch((string) $content->id);
        $this->dispatchAeoRecalculation($content);
        $this->dispatchAutomationAnswerGeneration($content);

        if (! $this->shouldInvalidatePublicContentCache($content)) {
            return;
        }

        $dispatch = function () use ($content): void {
            $fresh = $content->fresh([
                'clientSite',
                'translationSourceContent',
                'localizedVariants',
                'currentVersion:id,content_id,body,meta',
                'featuredImage',
            ]);

            if (! $fresh instanceof Content) {
                return;
            }

            app(PublicBlogPerformanceDataService::class)->syncContent($fresh);
            app(ContentCacheInvalidationService::class)->invalidateContent($fresh, 'content.saved');
        };

        if (app()->runningUnitTests()) {
            $dispatch();

            return;
        }

        DB::afterCommit($dispatch);
    }

    private function shouldInvalidatePublicContentCache(Content $content): bool
    {
        if ((string) $content->type !== 'article') {
            return false;
        }

        $publicTransitionFields = [
            'status',
            'publish_status',
            'published_url',
            'publish_url_key',
            'canonical_url_key',
            'language',
            'family_id',
            'translation_source_content_id',
            'translation_source_locale',
            'is_source_locale',
            'client_site_id',
            'workspace_id',
        ];

        $currentlyPublished = (string) $content->status === 'published'
            && (string) ($content->publish_status ?? '') === 'published';

        if ($content->wasRecentlyCreated) {
            return $currentlyPublished;
        }

        if ($currentlyPublished) {
            return $content->wasChanged([
                'title',
                'language',
                'status',
                'type',
                'source',
                'publish_status',
                'current_revision_id',
                'current_version_id',
                'seo_title',
                'seo_meta_description',
                'seo_h1',
                'seo_canonical',
                'seo_og_title',
                'seo_og_description',
                'seo_og_image',
                'seo_twitter_title',
                'seo_twitter_description',
                'primary_keyword',
                'robots_index',
                'robots_follow',
                'schema_type',
                'team_member_id',
                'scheduled_publish_at',
                'published_url',
                'answer_block_render_mode',
                'answer_block_visibility',
                'answer_block_position',
                'answer_block_max_visible',
                'publish_url_key',
                'canonical_url_key',
                'family_id',
                'translation_source_content_id',
                'translation_source_locale',
                'is_source_locale',
                'client_site_id',
                'workspace_id',
            ]);
        }

        return $content->wasChanged($publicTransitionFields);
    }

    /**
     * @return list<string>
     */
    private function performanceRelevantAttributes(): array
    {
        return [
            'title',
            'status',
            'publish_status',
            'delivery_status',
            'client_site_id',
            'workspace_id',
            'series_id',
            'automation_id',
            'family_id',
            'language',
            'first_published_at',
            'scheduled_publish_at',
            'updated_at',
            'deleted_at',
            'lifecycle_stage',
            'assigned_user_id',
            'reviewer_user_id',
            'due_at',
            'content_health_score',
            'ai_visibility_score',
            'semantic_coverage_score',
            'freshness_score',
            'internal_link_score',
            'answer_block_score',
            'answer_block_render_mode',
            'answer_block_visibility',
            'answer_block_position',
            'answer_block_max_visible',
            'translation_parity_score',
            'decay_risk_level',
            'intelligence_status',
            'optimization_opportunity_score',
            'ai_optimized_at',
        ];
    }

    private function dispatchAeoRecalculation(Content $content): void
    {
        if (! $content->wasRecentlyCreated && ! $content->wasChanged([
            'title',
            'language',
            'type',
            'primary_keyword',
            'current_revision_id',
            'current_version_id',
        ])) {
            return;
        }

        $dispatch = function () use ($content): void {
            $fresh = $content->fresh(['currentRevision', 'currentVersion', 'answerBlocks']) ?? $content;
            app(AeoScoreService::class)->recalculate($fresh);
        };

        if (app()->runningUnitTests()) {
            $dispatch();

            return;
        }

        DB::afterCommit($dispatch);
    }

    private function dispatchAutomationAnswerGeneration(Content $content): void
    {
        if (! $content->wasRecentlyCreated || ! filled($content->automation_id)) {
            return;
        }

        $automation = $content->relationLoaded('automation')
            ? $content->automation
            : $content->automation()->first();

        if (! $automation || ! (bool) data_get($automation->settings, 'generate_structured_answers', false)) {
            return;
        }

        $dispatch = fn () => GenerateStructuredAnswersJob::dispatch((string) $content->id);

        if (app()->runningUnitTests()) {
            $dispatch();

            return;
        }

        DB::afterCommit($dispatch);
    }

    private function resolveCanonicalSource(Content $content): ?Content
    {
        $sourceId = trim((string) $content->translation_source_content_id);
        if ($sourceId === '') {
            return null;
        }

        $supportsFamilyId = Content::supportsFamilyId();
        $selectColumns = ['id', 'translation_source_content_id', 'language', 'current_version_id'];

        if ($supportsFamilyId) {
            $selectColumns[] = 'family_id';
        }

        $visited = [];
        $currentId = $sourceId;

        while ($currentId !== '' && ! isset($visited[$currentId])) {
            $visited[$currentId] = true;

            /** @var Content|null $current */
            $current = Content::query()
                ->select($selectColumns)
                ->find($currentId);

            if (! $current instanceof Content) {
                return null;
            }

            $familyId = $supportsFamilyId
                ? trim((string) $current->family_id)
                : '';

            if ($supportsFamilyId && $familyId !== '' && $familyId !== (string) $current->id) {
                return Content::query()
                    ->select($selectColumns)
                    ->find($familyId);
            }

            $nextId = trim((string) $current->translation_source_content_id);
            if ($nextId === '' || $nextId === (string) $current->id) {
                return $current;
            }

            $currentId = $nextId;
        }

        $candidateIds = array_keys($visited);
        if ($candidateIds === []) {
            return null;
        }

        return Content::query()
            ->select($selectColumns)
            ->whereIn('id', $candidateIds)
            ->orderByRaw(DB::getDriverName() === 'sqlite' ? 'CASE WHEN translation_source_content_id IS NULL THEN 0 ELSE 1 END' : 'CASE WHEN translation_source_content_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }
}
