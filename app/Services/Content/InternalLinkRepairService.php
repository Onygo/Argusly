<?php

namespace App\Services\Content;

use App\Jobs\RebuildContentMarkdownArtifactJob;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\ContentRevision;
use App\Models\ContentVersion;
use App\Models\Draft;
use App\Services\InternalLinking\InternalLinkingService;
use App\Services\Publication\ContentPublicationStateService;
use App\Support\Analytics\AnalyticsUrlKey;
use App\Support\SiteUrl;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InternalLinkRepairService
{
    /**
     * @var array<string,array<string,mixed>>
     */
    private array $lookupPools = [];

    public function __construct(
        private readonly ContentPublicationStateService $publicationState,
        private readonly LocaleContentMapService $localeMap,
        private readonly InternalLinkingService $internalLinking,
    ) {}

    /**
     * @param  array{
     *   content?:?string,
     *   family?:?string,
     *   site?:?string,
     *   workspace?:?string,
     *   locale?:?string
     * }  $filters
     * @return array<string,mixed>
     */
    public function repair(
        array $filters = [],
        bool $dryRun = false,
        bool $removeUnresolved = false,
        bool $rerunLinking = false,
        bool $allowCrossLocaleFallback = false,
    ): array {
        $contents = $this->scopedContentsQuery($filters)
            ->with([
                'clientSite',
                'contentDestination',
                'currentRevision',
                'currentVersion',
                'translationSourceContent.currentVersion',
                'translationSourceContent.publications',
                'localizedVariants.currentVersion',
                'localizedVariants.publications',
                'publications.destination',
            ])
            ->orderBy('created_at')
            ->get();

        $contentReports = [];
        $reportRows = [];
        $skipped = [];
        $summary = [
            'contents_scanned' => $contents->count(),
            'links_inspected' => 0,
            'replaced' => 0,
            'removed' => 0,
            'unchanged' => 0,
            'contents_changed' => 0,
            'contents_skipped' => 0,
            'rerun_requested' => $rerunLinking ? $contents->count() : 0,
            'rerun_executed' => 0,
        ];
        $reasons = [];

        foreach ($contents as $content) {
            $report = $this->repairContent(
                $content,
                dryRun: $dryRun,
                removeUnresolved: $removeUnresolved,
                rerunLinking: $rerunLinking,
                allowCrossLocaleFallback: $allowCrossLocaleFallback,
            );

            $contentReports[] = $report['content'] ?? [];
            $reportRows = array_merge($reportRows, $report['rows'] ?? []);

            if (($report['content']['skipped'] ?? false) === true) {
                $summary['contents_skipped']++;
                $skipped[] = [
                    'content_id' => (string) ($report['content']['content_id'] ?? ''),
                    'locale' => (string) ($report['content']['locale'] ?? ''),
                    'reason' => (string) ($report['content']['skip_reason'] ?? 'skipped'),
                    'body_source' => (string) ($report['content']['body_source'] ?? ''),
                ];

                continue;
            }

            $summary['links_inspected'] += (int) ($report['content']['links_inspected'] ?? 0);
            $summary['replaced'] += (int) ($report['content']['replaced'] ?? 0);
            $summary['removed'] += (int) ($report['content']['removed'] ?? 0);
            $summary['unchanged'] += (int) ($report['content']['unchanged'] ?? 0);

            if (($report['content']['changed'] ?? false) === true) {
                $summary['contents_changed']++;
            }

            if (($report['content']['rerun']['executed'] ?? false) === true) {
                $summary['rerun_executed']++;
            }

            foreach ($report['rows'] ?? [] as $row) {
                $reason = trim((string) ($row['reason'] ?? ''));
                if ($reason === '') {
                    continue;
                }

                $reasons[$reason] = (int) ($reasons[$reason] ?? 0) + 1;
            }
        }

        ksort($reasons);

        return [
            'dry_run' => $dryRun,
            'filters' => [
                'content' => $filters['content'] ?? null,
                'family' => $filters['family'] ?? null,
                'site' => $filters['site'] ?? null,
                'workspace' => $filters['workspace'] ?? null,
                'locale' => $filters['locale'] ?? null,
            ],
            'options' => [
                'remove_unresolved' => $removeUnresolved,
                'rerun_linking' => $rerunLinking,
                'allow_cross_locale_fallback' => $allowCrossLocaleFallback,
            ],
            'summary' => $summary,
            'reasons' => $reasons,
            'contents' => $contentReports,
            'skipped_contents' => $skipped,
            'report_rows' => $reportRows,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function repairContent(
        Content $content,
        bool $dryRun,
        bool $removeUnresolved,
        bool $rerunLinking,
        bool $allowCrossLocaleFallback,
    ): array {
        $body = $this->resolveBodyContext($content);

        if (($body['supported'] ?? false) !== true) {
            return [
                'content' => [
                    'content_id' => (string) $content->id,
                    'locale' => $content->localeCode(),
                    'body_source' => (string) ($body['source'] ?? ''),
                    'body_format' => (string) ($body['format'] ?? ''),
                    'skipped' => true,
                    'skip_reason' => (string) ($body['skip_reason'] ?? 'unsupported-body-format'),
                    'links_inspected' => 0,
                    'replaced' => 0,
                    'removed' => 0,
                    'unchanged' => 0,
                    'changed' => false,
                    'rerun' => [
                        'requested' => $rerunLinking,
                        'executed' => false,
                        'reason' => 'skipped-content',
                    ],
                ],
                'rows' => [],
            ];
        }

        $document = $this->loadDocument((string) $body['html']);
        $root = $document->getElementsByTagName('body')->item(0);

        if (! $root instanceof DOMElement) {
            return [
                'content' => [
                    'content_id' => (string) $content->id,
                    'locale' => $content->localeCode(),
                    'body_source' => (string) ($body['source'] ?? ''),
                    'body_format' => (string) ($body['format'] ?? ''),
                    'skipped' => true,
                    'skip_reason' => 'html-parse-failed',
                    'links_inspected' => 0,
                    'replaced' => 0,
                    'removed' => 0,
                    'unchanged' => 0,
                    'changed' => false,
                    'rerun' => [
                        'requested' => $rerunLinking,
                        'executed' => false,
                        'reason' => 'skipped-content',
                    ],
                ],
                'rows' => [],
            ];
        }

        $pool = $this->lookupPoolFor($content);
        $anchors = [];

        foreach ($root->getElementsByTagName('a') as $anchor) {
            if ($anchor instanceof DOMElement) {
                $anchors[] = $anchor;
            }
        }

        $rows = [];

        foreach ($anchors as $anchor) {
            $row = $this->inspectAnchor(
                $content,
                $anchor,
                $root,
                $pool,
                $removeUnresolved,
                $allowCrossLocaleFallback,
            );

            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        $updatedHtml = trim($this->innerHtml($root));
        $originalHtml = trim((string) $body['html']);
        $changed = $updatedHtml !== '' && $updatedHtml !== $originalHtml;
        $persisted = null;

        if ($changed && ! $dryRun) {
            $persisted = $this->persistUpdatedHtml($content, $body, $updatedHtml, $rows);
        }

        $rerun = $this->maybeRerunInternalLinking($content, $body, $persisted, $dryRun, $rerunLinking);

        return [
            'content' => [
                'content_id' => (string) $content->id,
                'locale' => $content->localeCode(),
                'body_source' => (string) ($body['source'] ?? ''),
                'body_format' => (string) ($body['format'] ?? 'html'),
                'skipped' => false,
                'skip_reason' => null,
                'links_inspected' => count($rows),
                'replaced' => collect($rows)->where('action', 'replaced')->count(),
                'removed' => collect($rows)->where('action', 'removed')->count(),
                'unchanged' => collect($rows)->where('action', 'unchanged')->count(),
                'changed' => $changed,
                'persisted' => $persisted,
                'rerun' => $rerun,
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string,mixed>  $pool
     * @return array<string,mixed>|null
     */
    private function inspectAnchor(
        Content $source,
        DOMElement $anchor,
        DOMElement $root,
        array $pool,
        bool $removeUnresolved,
        bool $allowCrossLocaleFallback,
    ): ?array {
        $href = trim((string) $anchor->getAttribute('href'));
        if ($href === '' || ! $this->isInspectableInternalHref($href, $pool)) {
            return null;
        }

        $resolution = $this->resolveTargetForHref(
            $source,
            $href,
            $pool,
            $removeUnresolved,
            $allowCrossLocaleFallback,
        );

        if (($resolution['track'] ?? false) !== true) {
            return null;
        }

        $action = (string) ($resolution['action'] ?? 'unchanged');

        if ($action === 'replaced') {
            $anchor->setAttribute('href', (string) ($resolution['replacement_url'] ?? ''));
        } elseif ($action === 'removed') {
            $this->replaceAnchorWithText($anchor, (string) $anchor->textContent, $root);
        }

        return [
            'source_content_id' => (string) $source->id,
            'source_locale' => $source->localeCode(),
            'found_url' => $href,
            'matched_target_content_id' => $resolution['matched_target_content_id'] ?? null,
            'matched_target_locale' => $resolution['matched_target_locale'] ?? null,
            'matched_publication_id' => $resolution['matched_publication_id'] ?? null,
            'canonical_target_content_id' => $resolution['canonical_target_content_id'] ?? null,
            'canonical_target_locale' => $resolution['canonical_target_locale'] ?? null,
            'canonical_publication_id' => $resolution['canonical_publication_id'] ?? null,
            'action' => $action,
            'replacement_url' => $resolution['replacement_url'] ?? null,
            'reason' => $resolution['reason'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $pool
     * @return array<string,mixed>
     */
    private function resolveTargetForHref(
        Content $source,
        string $href,
        array $pool,
        bool $removeUnresolved,
        bool $allowCrossLocaleFallback,
    ): array {
        $match = $this->matchContentForHref($source, $href, $pool);
        $normalizedPath = $this->normalizePath($href);

        if (! is_array($match)) {
            if (! $this->isKnownPublicationPath($normalizedPath, $pool)) {
                return [
                    'track' => false,
                ];
            }

            return [
                'track' => true,
                'action' => $removeUnresolved ? 'removed' : 'unchanged',
                'reason' => 'unresolved-target',
                'replacement_url' => null,
            ];
        }

        /** @var Content $matchedTarget */
        $matchedTarget = $match['content'];
        $preferredTarget = $this->preferredTargetFor(
            $source,
            $matchedTarget,
            $allowCrossLocaleFallback,
        );

        if (! $preferredTarget instanceof Content) {
            return [
                'track' => true,
                'matched_target_content_id' => (string) $matchedTarget->id,
                'matched_target_locale' => $matchedTarget->localeCode(),
                'matched_publication_id' => $match['publication_id'] ?? null,
                'action' => $removeUnresolved ? 'removed' : 'unchanged',
                'reason' => 'unresolved-target',
                'replacement_url' => null,
            ];
        }

        $replacementUrl = $this->resolveCanonicalTargetUrl($preferredTarget);

        if ($replacementUrl === '') {
            return [
                'track' => true,
                'matched_target_content_id' => (string) $matchedTarget->id,
                'matched_target_locale' => $matchedTarget->localeCode(),
                'matched_publication_id' => $match['publication_id'] ?? null,
                'canonical_target_content_id' => (string) $preferredTarget->id,
                'canonical_target_locale' => $preferredTarget->localeCode(),
                'action' => $removeUnresolved ? 'removed' : 'unchanged',
                'reason' => 'unresolved-target',
                'replacement_url' => null,
            ];
        }

        $canonicalPublication = $this->publicationState->resolveCanonicalPublication($preferredTarget);
        $existingComparable = $this->normalizeComparableUrl($href, $source);
        $replacementComparable = $this->normalizeComparableUrl($replacementUrl, $preferredTarget);
        $reason = $this->reasonForReplacement($matchedTarget, $preferredTarget, $match, $existingComparable, $replacementComparable, $canonicalPublication);

        return [
            'track' => true,
            'matched_target_content_id' => (string) $matchedTarget->id,
            'matched_target_locale' => $matchedTarget->localeCode(),
            'matched_publication_id' => $match['publication_id'] ?? null,
            'canonical_target_content_id' => (string) $preferredTarget->id,
            'canonical_target_locale' => $preferredTarget->localeCode(),
            'canonical_publication_id' => $canonicalPublication?->id,
            'action' => $existingComparable !== '' && $existingComparable === $replacementComparable
                ? 'unchanged'
                : 'replaced',
            'reason' => $reason,
            'replacement_url' => $replacementUrl,
        ];
    }

    /**
     * @param  array<string,mixed>  $match
     */
    private function reasonForReplacement(
        Content $matchedTarget,
        Content $preferredTarget,
        array $match,
        string $existingComparable,
        string $replacementComparable,
        ?ContentPublication $canonicalPublication,
    ): string {
        if ((string) $preferredTarget->id !== (string) $matchedTarget->id) {
            if ($preferredTarget->localizationRootId() === $matchedTarget->localizationRootId()) {
                return $preferredTarget->localeCode() !== $matchedTarget->localeCode()
                    ? 'wrong-locale-target'
                    : 'legacy-shadow';
            }

            return 'non-canonical-target';
        }

        if (
            filled($match['publication_id'] ?? null)
            && (string) ($match['publication_id'] ?? '') !== (string) ($canonicalPublication?->id ?? '')
        ) {
            return 'stale-publication';
        }

        if ($existingComparable !== '' && $replacementComparable !== '' && $existingComparable !== $replacementComparable) {
            return 'non-canonical-target';
        }

        return 'canonical';
    }

    private function preferredTargetFor(
        Content $source,
        Content $matchedTarget,
        bool $allowCrossLocaleFallback,
    ): ?Content {
        $sourceLocale = $source->localeCode();
        $family = $this->localeMap->family($matchedTarget)
            ->filter(fn (Content $variant): bool => $this->resolveCanonicalTargetUrl($variant) !== '')
            ->values();

        $sameLocale = $family
            ->first(fn (Content $variant): bool => $variant->localeCode() === $sourceLocale);

        if ($sameLocale instanceof Content) {
            return $sameLocale;
        }

        if ($matchedTarget->localeCode() === $sourceLocale && $this->resolveCanonicalTargetUrl($matchedTarget) !== '') {
            return $matchedTarget;
        }

        if (! $allowCrossLocaleFallback) {
            return null;
        }

        return $family->first()
            ?: ($this->resolveCanonicalTargetUrl($matchedTarget) !== '' ? $matchedTarget : null);
    }

    private function resolveCanonicalTargetUrl(Content $content): string
    {
        $content->loadMissing([
            'clientSite:id,base_url,site_url,allowed_domains',
            'contentDestination',
            'publications.destination',
        ]);

        $publication = $this->publicationState->resolveCanonicalPublication($content);

        foreach ([
            trim((string) ($content->published_url ?? '')),
            trim((string) ($publication?->remote_url ?? '')),
            trim((string) ($content->seo_canonical ?? '')),
        ] as $candidate) {
            $resolved = $this->resolveSiteScopedAbsoluteUrl($candidate, $content);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        return '';
    }

    private function resolveSiteScopedAbsoluteUrl(string $candidate, Content $content): string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return '';
        }

        $base = $this->preferredSiteBase($content);
        $hosts = $this->siteHosts($content);

        if (str_starts_with($candidate, '/')) {
            return $base !== ''
                ? $base . '/' . ltrim($candidate, '/')
                : '';
        }

        if (! preg_match('#^https?://#i', $candidate)) {
            return '';
        }

        $host = SiteUrl::hostFromUrl($candidate);
        if ($host === '' || ! in_array($host, $hosts, true)) {
            return '';
        }

        return $candidate;
    }

    private function preferredSiteBase(Content $content): string
    {
        $content->loadMissing('clientSite:id,base_url,site_url');

        return rtrim((string) ($content->clientSite?->site_url ?: $content->clientSite?->base_url ?: ''), '/');
    }

    /**
     * @return array<int,string>
     */
    private function siteHosts(Content $content): array
    {
        $content->loadMissing('clientSite:id,base_url,site_url,allowed_domains');

        return collect([
            SiteUrl::hostFromUrl((string) ($content->clientSite?->site_url ?? '')),
            SiteUrl::hostFromUrl((string) ($content->clientSite?->base_url ?? '')),
        ])
            ->merge((array) ($content->clientSite?->allowed_domains ?? []))
            ->map(fn (mixed $host): string => Str::lower(trim((string) $host)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveBodyContext(Content $content): array
    {
        $content->loadMissing('currentRevision', 'currentVersion');

        $draft = $this->latestDraft($content);
        $currentRevisionHtml = trim((string) ($content->currentRevision?->content_html ?? ''));
        $currentVersionBody = trim((string) ($content->currentVersion?->body ?? ''));
        $draftHtml = trim((string) ($draft?->content_html ?? ''));

        foreach ([
            ['source' => 'current_revision', 'html' => $currentRevisionHtml],
            ['source' => 'current_version', 'html' => $currentVersionBody],
            ['source' => 'draft', 'html' => $draftHtml],
        ] as $candidate) {
            if ($candidate['html'] === '') {
                continue;
            }

            if ($this->looksLikeHtml($candidate['html'])) {
                return [
                    'supported' => true,
                    'source' => $candidate['source'],
                    'format' => 'html',
                    'html' => $candidate['html'],
                    'draft' => $draft,
                    'draft_matches_source' => $draft instanceof Draft
                        && trim((string) $draft->content_html) === (string) $candidate['html'],
                ];
            }
        }

        $firstNonEmpty = $currentRevisionHtml !== ''
            ? $currentRevisionHtml
            : ($currentVersionBody !== '' ? $currentVersionBody : $draftHtml);

        return [
            'supported' => false,
            'source' => $currentRevisionHtml !== '' ? 'current_revision' : ($currentVersionBody !== '' ? 'current_version' : ($draftHtml !== '' ? 'draft' : 'none')),
            'format' => $this->detectBodyFormat($firstNonEmpty),
            'skip_reason' => $firstNonEmpty === '' ? 'missing-body' : 'unsupported-body-format',
            'html' => '',
            'draft' => $draft,
            'draft_matches_source' => false,
        ];
    }

    private function latestDraft(Content $content): ?Draft
    {
        return $content->drafts()
            ->latest('created_at')
            ->first();
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function persistUpdatedHtml(Content $content, array $body, string $updatedHtml, array $rows): array
    {
        /** @var Draft|null $draft */
        $draft = $body['draft'] ?? null;
        $shouldSyncDraft = $draft instanceof Draft
            && (
                (string) ($body['source'] ?? '') === 'draft'
                || ($body['draft_matches_source'] ?? false) === true
            );

        $result = DB::transaction(function () use ($content, $updatedHtml, $rows, $draft, $shouldSyncDraft): array {
            $content->refresh();
            $content->loadMissing('currentRevision', 'currentVersion');

            if ($shouldSyncDraft && $draft instanceof Draft) {
                $draft->refresh();
                $draft->update([
                    'content_html' => $updatedHtml,
                ]);
            }

            $nextRevisionNumber = (int) ContentRevision::query()
                ->where('content_id', (string) $content->id)
                ->max('revision_number') + 1;

            $revision = ContentRevision::query()->create([
                'id' => (string) Str::uuid(),
                'content_id' => (string) $content->id,
                'draft_id' => $shouldSyncDraft ? (string) ($draft?->id ?? '') ?: null : null,
                'revision_number' => $nextRevisionNumber,
                'label' => 'R' . $nextRevisionNumber,
                'content_html' => $updatedHtml,
                'meta' => [
                    'source' => 'internal_link_repair',
                    'repaired_at' => now()->toIso8601String(),
                    'summary' => $this->repairSummaryForMeta($rows),
                ],
                'is_active' => true,
                'created_by_user_id' => null,
            ]);

            ContentRevision::query()
                ->where('content_id', (string) $content->id)
                ->where('id', '!=', (string) $revision->id)
                ->update(['is_active' => false]);

            $version = ContentVersion::query()->create([
                'id' => (string) Str::uuid(),
                'content_id' => (string) $content->id,
                'type' => ContentVersion::TYPE_REVISION,
                'parent_version_id' => $content->current_version_id,
                'body' => $updatedHtml,
                'meta' => [
                    'label' => 'Internal link repair - ' . now()->format('Y-m-d H:i'),
                    'source' => 'internal_link_repair',
                    'summary' => $this->repairSummaryForMeta($rows),
                ],
                'source' => ContentVersion::SOURCE_PUBLISHLAYER,
                'created_by' => null,
            ]);

            $content->update([
                'current_revision_id' => (string) $revision->id,
                'current_version_id' => (string) $version->id,
            ]);

            return [
                'revision_id' => (string) $revision->id,
                'version_id' => (string) $version->id,
                'draft_synced' => $shouldSyncDraft,
                'draft_id' => $shouldSyncDraft ? (string) ($draft?->id ?? '') ?: null : null,
            ];
        });

        RebuildContentMarkdownArtifactJob::dispatch((string) $content->id, force: true)->afterCommit();

        return $result;
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function repairSummaryForMeta(array $rows): array
    {
        return [
            'inspected' => count($rows),
            'replaced' => collect($rows)->where('action', 'replaced')->count(),
            'removed' => collect($rows)->where('action', 'removed')->count(),
            'unchanged' => collect($rows)->where('action', 'unchanged')->count(),
            'reasons' => collect($rows)
                ->pluck('reason')
                ->filter()
                ->countBy()
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $persisted
     * @return array<string,mixed>
     */
    private function maybeRerunInternalLinking(
        Content $content,
        array $body,
        ?array $persisted,
        bool $dryRun,
        bool $rerunLinking,
    ): array {
        if (! $rerunLinking) {
            return [
                'requested' => false,
                'executed' => false,
                'reason' => null,
            ];
        }

        if ($dryRun) {
            return [
                'requested' => true,
                'executed' => false,
                'reason' => 'dry-run',
            ];
        }

        /** @var Draft|null $draft */
        $draft = $body['draft'] ?? null;

        if (! $draft instanceof Draft) {
            return [
                'requested' => true,
                'executed' => false,
                'reason' => 'missing-draft',
            ];
        }

        $draftSafeToUse = (string) ($body['source'] ?? '') === 'draft'
            || ($body['draft_matches_source'] ?? false) === true
            || (($persisted['draft_synced'] ?? false) === true);

        if (! $draftSafeToUse) {
            return [
                'requested' => true,
                'executed' => false,
                'reason' => 'draft-out-of-sync',
            ];
        }

        $result = $this->internalLinking->generateForContent($content->fresh());

        return [
            'requested' => true,
            'executed' => true,
            'reason' => null,
            'suggestion_count' => count((array) ($result['suggestions'] ?? [])),
            'applied_count' => (int) ($result['applied_count'] ?? 0),
            'updated' => (bool) ($result['updated'] ?? false),
        ];
    }

    /**
     * @param  array{
     *   content?:?string,
     *   family?:?string,
     *   site?:?string,
     *   workspace?:?string,
     *   locale?:?string
     * }  $filters
     */
    private function scopedContentsQuery(array $filters): Builder
    {
        $query = Content::query()
            ->where('type', 'article');

        if (filled($filters['content'] ?? null)) {
            $query->whereKey((string) $filters['content']);
        }

        if (filled($filters['family'] ?? null)) {
            $query->whereInLocalizationRoots([(string) $filters['family']]);
        }

        if (filled($filters['site'] ?? null)) {
            $query->where('client_site_id', (string) $filters['site']);
        }

        if (filled($filters['workspace'] ?? null)) {
            $query->where('workspace_id', (string) $filters['workspace']);
        }

        if (filled($filters['locale'] ?? null)) {
            $query->where('language', Str::lower(trim((string) $filters['locale'])));
        }

        return $query;
    }

    /**
     * @return array<string,mixed>
     */
    private function lookupPoolFor(Content $source): array
    {
        $cacheKey = filled($source->client_site_id)
            ? 'site:' . (string) $source->client_site_id
            : 'workspace:' . (string) $source->workspace_id;

        if (isset($this->lookupPools[$cacheKey])) {
            return $this->lookupPools[$cacheKey];
        }

        $query = Content::query()
            ->with([
                'clientSite:id,base_url,site_url,allowed_domains',
                'contentDestination',
                'publications.destination',
            ])
            ->where('type', 'article');

        if (filled($source->client_site_id)) {
            $query->where('client_site_id', (string) $source->client_site_id);
        } else {
            $query->where('workspace_id', (string) $source->workspace_id);
        }

        $byUrl = [];
        $byPath = [];
        $hosts = [];
        $prefixes = [];

        foreach ($query->get() as $candidate) {
            foreach ($this->aliasEntriesForContent($candidate) as $entry) {
                $normalizedUrl = (string) ($entry['normalized_url'] ?? '');
                $path = (string) ($entry['path'] ?? '');

                if ($normalizedUrl !== '') {
                    $byUrl[$normalizedUrl][] = $entry;
                    $host = SiteUrl::hostFromUrl((string) ($entry['url'] ?? ''));
                    if ($host !== '') {
                        $hosts[] = $host;
                    }
                }

                if ($path !== '') {
                    $byPath[$path][] = $entry;
                    $prefix = $this->pathPrefix($path);
                    if ($prefix !== '') {
                        $prefixes[] = $prefix;
                    }
                }
            }
        }

        $pool = [
            'by_url' => $byUrl,
            'by_path' => $byPath,
            'hosts' => collect($hosts)
                ->merge($this->siteHosts($source))
                ->map(fn (string $host): string => Str::lower(trim($host)))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'known_prefixes' => collect($prefixes)
                ->map(fn (string $prefix): string => rtrim($prefix, '/'))
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ];

        $this->lookupPools[$cacheKey] = $pool;

        return $pool;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function aliasEntriesForContent(Content $content): array
    {
        $entries = [];

        foreach ([
            ['kind' => 'content.published_url', 'url' => trim((string) ($content->published_url ?? '')), 'publication_id' => null],
            ['kind' => 'content.seo_canonical', 'url' => trim((string) ($content->seo_canonical ?? '')), 'publication_id' => null],
        ] as $candidate) {
            $alias = $this->buildAliasEntry($content, $candidate['kind'], $candidate['url'], $candidate['publication_id']);
            if ($alias !== null) {
                $entries[] = $alias;
            }
        }

        foreach ($content->publications as $publication) {
            $alias = $this->buildAliasEntry(
                $content,
                'publication.remote_url',
                trim((string) ($publication->remote_url ?? '')),
                (string) $publication->id,
            );

            if ($alias !== null) {
                $entries[] = $alias;
            }
        }

        return $entries;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildAliasEntry(
        Content $content,
        string $sourceKind,
        string $url,
        ?string $publicationId,
    ): ?array {
        $url = trim($url);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        $normalizedUrl = AnalyticsUrlKey::normalizeUrl($url);
        $path = AnalyticsUrlKey::normalizePathValue($url);

        if ($normalizedUrl === null || $path === '') {
            return null;
        }

        return [
            'content' => $content,
            'content_id' => (string) $content->id,
            'publication_id' => $publicationId,
            'source_kind' => $sourceKind,
            'url' => $url,
            'normalized_url' => $normalizedUrl,
            'path' => $path,
            'timestamp' => max(
                (int) ($content->updated_at?->timestamp ?? 0),
                (int) ($content->created_at?->timestamp ?? 0),
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $pool
     * @return array<string,mixed>|null
     */
    private function matchContentForHref(Content $source, string $href, array $pool): ?array
    {
        $normalizedUrl = $this->normalizeComparableUrl($href, $source);
        $path = $this->normalizePath($href);
        $candidates = [];

        if ($normalizedUrl !== '' && isset($pool['by_url'][$normalizedUrl])) {
            $candidates = array_merge($candidates, (array) $pool['by_url'][$normalizedUrl]);
        }

        if ($path !== '' && isset($pool['by_path'][$path])) {
            $candidates = array_merge($candidates, (array) $pool['by_path'][$path]);
        }

        if ($candidates === []) {
            return null;
        }

        return collect($candidates)
            ->unique(fn (array $candidate): string => implode(':', [
                (string) ($candidate['content_id'] ?? ''),
                (string) ($candidate['publication_id'] ?? ''),
                (string) ($candidate['source_kind'] ?? ''),
                (string) ($candidate['url'] ?? ''),
            ]))
            ->sortBy(function (array $candidate) use ($normalizedUrl): array {
                return [
                    (string) ($candidate['normalized_url'] ?? '') === $normalizedUrl ? 0 : 1,
                    (string) ($candidate['source_kind'] ?? '') === 'publication.remote_url' ? 0 : 1,
                    -1 * (int) ($candidate['timestamp'] ?? 0),
                ];
            })
            ->first();
    }

    /**
     * @param  array<string,mixed>  $pool
     */
    private function isInspectableInternalHref(string $href, array $pool): bool
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#')) {
            return false;
        }

        if (preg_match('/^(mailto|tel|sms|javascript):/i', $href) === 1) {
            return false;
        }

        if (str_starts_with($href, '/')) {
            return true;
        }

        if (! preg_match('#^https?://#i', $href)) {
            return false;
        }

        $host = SiteUrl::hostFromUrl($href);

        return $host !== '' && in_array($host, (array) ($pool['hosts'] ?? []), true);
    }

    /**
     * @param  array<string,mixed>  $pool
     */
    private function isKnownPublicationPath(string $path, array $pool): bool
    {
        if ($path === '') {
            return false;
        }

        foreach ((array) ($pool['known_prefixes'] ?? []) as $prefix) {
            $prefix = trim((string) $prefix);
            if ($prefix === '' || $prefix === '/') {
                continue;
            }

            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function normalizeComparableUrl(string $value, Content $content): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '//')) {
            $value = 'https:' . $value;
        }

        if (str_starts_with($value, '/')) {
            $base = $this->preferredSiteBase($content);

            if ($base !== '') {
                return AnalyticsUrlKey::normalizeUrl($base . $value) ?? '';
            }

            return AnalyticsUrlKey::normalizePathValue($value);
        }

        if (preg_match('#^https?://#i', $value)) {
            return AnalyticsUrlKey::normalizeUrl($value) ?? '';
        }

        return '';
    }

    private function normalizePath(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '//')) {
            $value = 'https:' . $value;
        }

        if (str_starts_with($value, '/')) {
            return AnalyticsUrlKey::normalizePathValue($value);
        }

        if (preg_match('#^https?://#i', $value)) {
            return AnalyticsUrlKey::normalizePathValue($value);
        }

        return '';
    }

    private function pathPrefix(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return '';
        }

        $directory = trim((string) dirname($path), '.');
        $directory = '/' . ltrim($directory, '/');
        $directory = rtrim($directory, '/');

        return $directory === '' ? '/' : $directory;
    }

    private function looksLikeHtml(string $body): bool
    {
        return preg_match('/<\s*[a-z][^>]*>/i', $body) === 1;
    }

    private function detectBodyFormat(string $body): string
    {
        $body = trim($body);

        if ($body === '') {
            return 'empty';
        }

        if ($this->looksLikeHtml($body)) {
            return 'html';
        }

        if (str_starts_with($body, '{') || str_starts_with($body, '[')) {
            return 'json';
        }

        if (preg_match('/^(#{1,6}\s|[-*]\s|\d+\.\s|>\s)/m', $body) === 1) {
            return 'markdown';
        }

        return 'text';
    }

    private function loadDocument(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private function innerHtml(DOMElement $element): string
    {
        $html = '';

        foreach ($element->childNodes as $child) {
            $html .= $element->ownerDocument?->saveHTML($child) ?: '';
        }

        return $html;
    }

    private function replaceAnchorWithText(DOMElement $anchor, string $text, DOMNode $root): void
    {
        $fragment = $root->ownerDocument?->createDocumentFragment();
        if (! $fragment) {
            return;
        }

        $fragment->appendChild(new DOMText($text));
        $anchor->parentNode?->replaceChild($fragment, $anchor);
    }
}
