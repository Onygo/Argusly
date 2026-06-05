<?php

namespace App\Services\AgenticMarketing;

use App\Enums\SupportedLanguage;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Workspace;
use App\Support\TitleSanitizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AgenticContentSafetyService
{
    public const STATUS_PASS = 'pass';
    public const STATUS_WARNING = 'warning';
    public const STATUS_BLOCK = 'block';

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function evaluate(?Content $content, ?ClientSite $site, ?Workspace $workspace, array $context = []): array
    {
        $issues = [];

        if (! $workspace) {
            $issues[] = $this->issue('workspace_missing', self::STATUS_BLOCK, 'A workspace is required before publication safety can be evaluated.');
        }

        if (! $content) {
            $issues[] = $this->issue('content_missing', self::STATUS_BLOCK, 'Publishable content could not be found.');

            return $this->result($issues);
        }

        $content->loadMissing(['currentRevision', 'currentVersion', 'answerBlocks']);

        $issues = array_merge($issues, [
            ...$this->validateOwnership($content, $site, $workspace),
            ...$this->validateTitle($content),
            ...$this->validateBody($content),
            ...$this->validateLocale($content),
            ...$this->validateCanonicalRules($content, $site),
            ...$this->validateInternalLinks($content, $site),
            ...$this->validateAnswerBlocks($content),
            ...$this->validatePlaceholders($content),
            ...$this->validatePublishingSiteConnector($site, $workspace),
            ...$this->validateSchemaType($content),
            ...$this->validateDuplicateSlugRisk($content, $site),
        ]);

        return $this->result($issues, [
            'content_id' => (string) $content->id,
            'site_id' => $site ? (string) $site->id : null,
            'workspace_id' => $workspace ? (string) $workspace->id : null,
            'execution_mode' => (string) ($context['execution_mode'] ?? ''),
        ]);
    }

    /**
     * @return list<array<string,string>>
     */
    private function validateOwnership(Content $content, ?ClientSite $site, ?Workspace $workspace): array
    {
        $issues = [];

        if ($workspace && (string) $content->workspace_id !== (string) $workspace->id) {
            $issues[] = $this->issue('content_workspace_mismatch', self::STATUS_BLOCK, 'Content does not belong to the evaluated workspace.');
        }

        if ($site && $workspace && (string) $site->workspace_id !== (string) $workspace->id) {
            $issues[] = $this->issue('site_workspace_mismatch', self::STATUS_BLOCK, 'Publishing site does not belong to the evaluated workspace.');
        }

        if ($site && $content->client_site_id && (string) $content->client_site_id !== (string) $site->id) {
            $issues[] = $this->issue('content_site_mismatch', self::STATUS_BLOCK, 'Content is assigned to a different publishing site.');
        }

        return $issues;
    }

    /**
     * @return list<array<string,string>>
     */
    private function validateTitle(Content $content): array
    {
        $title = trim((string) $content->title);

        return $title === '' || strcasecmp($title, TitleSanitizer::FALLBACK_TITLE) === 0
            ? [$this->issue('title_missing', self::STATUS_BLOCK, 'Content title is required before publication.')]
            : [];
    }

    /**
     * @return list<array<string,string>>
     */
    private function validateBody(Content $content): array
    {
        $body = $this->bodyFor($content);

        if (trim(strip_tags($body)) === '' && trim($body) === '') {
            return [$this->issue('body_missing', self::STATUS_BLOCK, 'Content body is empty. Generate or attach a draft before publication.')];
        }

        return [];
    }

    /**
     * @return list<array<string,string>>
     */
    private function validateLocale(Content $content): array
    {
        $locale = SupportedLanguage::normalizeLocale((string) $content->getRawOriginal('language'));

        if (! $locale || ! in_array($locale, SupportedLanguage::values(), true)) {
            return [$this->issue('locale_invalid', self::STATUS_BLOCK, 'Content locale is missing or unsupported.')];
        }

        return [];
    }

    /**
     * @return list<array<string,string>>
     */
    private function validateCanonicalRules(Content $content, ?ClientSite $site): array
    {
        $canonical = trim((string) $content->seo_canonical);
        if ($canonical === '') {
            return [$this->issue('canonical_missing', self::STATUS_WARNING, 'Canonical URL is empty; verify the destination will set the intended canonical.')];
        }

        if (! filter_var($canonical, FILTER_VALIDATE_URL)) {
            return [$this->issue('canonical_invalid', self::STATUS_BLOCK, 'Canonical URL is not valid.')];
        }

        $host = strtolower((string) parse_url($canonical, PHP_URL_HOST));
        if ($host === '') {
            return [$this->issue('canonical_host_missing', self::STATUS_BLOCK, 'Canonical URL must include a host.')];
        }

        if ($site && $this->allowedDomains($site) !== [] && ! $this->hostAllowed($host, $this->allowedDomains($site))) {
            return [$this->issue('canonical_domain_not_allowed', self::STATUS_BLOCK, 'Canonical URL is outside the allowed publishing domains.')];
        }

        return [];
    }

    /**
     * @return list<array<string,string>>
     */
    private function validateInternalLinks(Content $content, ?ClientSite $site): array
    {
        $meta = (array) ($content->internal_links_meta ?? []);
        if ($meta === []) {
            return [$this->issue('internal_links_missing', self::STATUS_WARNING, 'No internal link metadata is present.')];
        }

        $links = collect([
            ...Arr::wrap(data_get($meta, 'applied_suggestions', [])),
            ...Arr::wrap(data_get($meta, 'inline_links', [])),
            ...Arr::wrap(data_get($meta, 'supplemental_links', [])),
        ])->filter(fn ($link): bool => is_array($link))->values();

        $issues = [];
        foreach ($links as $link) {
            $url = trim((string) (data_get($link, 'url') ?: data_get($link, 'target_url') ?: data_get($link, 'href')));
            if ($url === '') {
                continue;
            }

            if ($this->containsPlaceholder($url)) {
                $issues[] = $this->issue('internal_link_placeholder', self::STATUS_BLOCK, 'An internal link still contains an unresolved placeholder.');
                break;
            }

            if (Str::startsWith($url, ['/', '#'])) {
                continue;
            }

            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
                $issues[] = $this->issue('internal_link_invalid', self::STATUS_BLOCK, 'An internal link URL is invalid.');
                break;
            }

            if ($site && $this->allowedDomains($site) !== [] && ! $this->hostAllowed($host, $this->allowedDomains($site))) {
                $issues[] = $this->issue('internal_link_external', self::STATUS_WARNING, 'An internal link points outside the allowed publishing domains.');
                break;
            }
        }

        return $issues;
    }

    /**
     * @return list<array<string,string>>
     */
    private function validateAnswerBlocks(Content $content): array
    {
        $mode = (string) $content->answer_block_render_mode;
        $visible = (string) $content->answer_block_visibility;
        $status = (string) $content->answer_block_generation_status;

        if ($status === Content::ANSWER_BLOCK_STATUS_FAILED) {
            return [$this->issue('answer_blocks_failed', self::STATUS_BLOCK, 'Answer block generation failed and must be repaired before publication.')];
        }

        if ($content->answerBlockGenerationIsActive()) {
            return [$this->issue('answer_blocks_pending', self::STATUS_WARNING, 'Answer block generation is still running.')];
        }

        if ($mode !== '' && $mode !== Content::ANSWER_BLOCK_RENDER_MODE_DISABLED && $visible !== Content::ANSWER_BLOCK_VISIBILITY_HIDDEN) {
            $count = max((int) $content->answer_block_generation_persisted_count, $content->answerBlocks->count());
            if ($count <= 0) {
                return [$this->issue('answer_blocks_empty', self::STATUS_BLOCK, 'Answer blocks are enabled but no answer blocks are available.')];
            }
        }

        return [];
    }

    /**
     * @return list<array<string,string>>
     */
    private function validatePlaceholders(Content $content): array
    {
        $haystack = implode("\n", array_filter([
            (string) $content->title,
            (string) $content->seo_title,
            (string) $content->seo_h1,
            (string) $content->seo_meta_description,
            (string) $content->seo_canonical,
            (string) $content->schema_type,
            $this->bodyFor($content),
        ]));

        return $this->containsPlaceholder($haystack)
            ? [$this->issue('unresolved_placeholder', self::STATUS_BLOCK, 'Content contains unresolved placeholders.')]
            : [];
    }

    /**
     * @return list<array<string,string>>
     */
    private function validatePublishingSiteConnector(?ClientSite $site, ?Workspace $workspace): array
    {
        if (! $site) {
            return [$this->issue('publishing_site_missing', self::STATUS_BLOCK, 'A publishing site connector is required before publication.')];
        }

        $issues = [];

        if ($workspace && (string) $site->workspace_id !== (string) $workspace->id) {
            $issues[] = $this->issue('publishing_site_tenant_mismatch', self::STATUS_BLOCK, 'Publishing site is outside the current workspace.');
        }

        if (! $site->is_active || in_array((string) $site->status, ['disabled', 'error'], true)) {
            $issues[] = $this->issue('publishing_site_inactive', self::STATUS_BLOCK, 'Publishing site connector is inactive or in an error state.');
        }

        if (! in_array((string) $site->status, ['connected', 'active'], true)) {
            $issues[] = $this->issue('publishing_site_not_connected', self::STATUS_BLOCK, 'Publishing site connector is not connected.');
        }

        if ($site->last_heartbeat_at && $site->heartbeat_status === 'offline') {
            $issues[] = $this->issue('publishing_site_offline', self::STATUS_BLOCK, 'Publishing site connector heartbeat is offline.');
        }

        if (! $site->last_heartbeat_at) {
            $issues[] = $this->issue('publishing_site_heartbeat_missing', self::STATUS_WARNING, 'Publishing site connector has not reported a heartbeat yet.');
        }

        return $issues;
    }

    /**
     * @return list<array<string,string>>
     */
    private function validateSchemaType(Content $content): array
    {
        $schemaType = trim((string) $content->schema_type);
        if ($schemaType === '') {
            return [$this->issue('schema_type_missing', self::STATUS_BLOCK, 'Schema type is required before publication.')];
        }

        $allowed = [
            'article',
            'blogposting',
            'collectionpage',
            'faqpage',
            'howto',
            'organization',
            'product',
            'softwareapplication',
            'webpage',
        ];

        $normalized = strtolower(str_replace([' ', '_', '-'], '', $schemaType));

        return in_array($normalized, $allowed, true)
            ? []
            : [$this->issue('schema_type_invalid', self::STATUS_BLOCK, 'Schema type is not supported for autonomous publication.')];
    }

    /**
     * @return list<array<string,string>>
     */
    private function validateDuplicateSlugRisk(Content $content, ?ClientSite $site): array
    {
        $slug = trim((string) ($content->publish_url_key ?: $content->canonical_url_key));
        if ($slug === '') {
            return [$this->issue('slug_missing', self::STATUS_WARNING, 'No normalized publish slug is available for duplicate checks.')];
        }

        $locale = SupportedLanguage::normalizeLocale((string) $content->getRawOriginal('language'));
        if (! $locale || ! in_array($locale, SupportedLanguage::values(), true)) {
            return [];
        }

        $query = Content::query()
            ->where('workspace_id', (string) $content->workspace_id)
            ->whereKeyNot($content->id)
            ->where('language', $locale)
            ->where(function ($query) use ($slug): void {
                $query->where('publish_url_key', $slug)
                    ->orWhere('canonical_url_key', $slug);
            });

        if ($site) {
            $query->where('client_site_id', (string) $site->id);
        }

        return $query->exists()
            ? [$this->issue('duplicate_slug_risk', self::STATUS_BLOCK, 'Another content item already uses this publishing slug for the same site and locale.')]
            : [];
    }

    private function bodyFor(Content $content): string
    {
        return trim((string) (
            $content->currentRevision?->content_html
            ?: $content->currentVersion?->body
            ?: data_get($content->internal_links_meta, 'content_html')
            ?: ''
        ));
    }

    private function containsPlaceholder(string $value): bool
    {
        if (trim($value) === '') {
            return false;
        }

        return preg_match('/({{[^}]+}}|%%[^%]+%%|\[[A-Z0-9 _-]*(TODO|PLACEHOLDER|TBD)[A-Z0-9 _-]*\]|TODO:|TBD:|lorem ipsum)/i', $value) === 1;
    }

    /**
     * @return list<string>
     */
    private function allowedDomains(ClientSite $site): array
    {
        $domains = array_filter(array_map('strtolower', (array) ($site->allowed_domains ?? [])));
        $siteHost = strtolower((string) parse_url((string) ($site->base_url ?: $site->site_url), PHP_URL_HOST));

        if ($siteHost !== '') {
            $domains[] = $siteHost;
        }

        return array_values(array_unique($domains));
    }

    /**
     * @param  list<string>  $domains
     */
    private function hostAllowed(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            $domain = ltrim(strtolower($domain), '.');
            if ($host === $domain || Str::endsWith($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,string>
     */
    private function issue(string $key, string $severity, string $message): array
    {
        return compact('key', 'severity', 'message');
    }

    /**
     * @param  list<array<string,string>>  $issues
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function result(array $issues, array $meta = []): array
    {
        $hasBlock = collect($issues)->contains(fn (array $issue): bool => $issue['severity'] === self::STATUS_BLOCK);
        $hasWarning = collect($issues)->contains(fn (array $issue): bool => $issue['severity'] === self::STATUS_WARNING);
        $status = $hasBlock ? self::STATUS_BLOCK : ($hasWarning ? self::STATUS_WARNING : self::STATUS_PASS);

        return [
            'status' => $status,
            'pass' => $status === self::STATUS_PASS,
            'warning' => $status === self::STATUS_WARNING,
            'block' => $status === self::STATUS_BLOCK,
            'issues' => array_values($issues),
            'recommended_fix' => $this->recommendedFix($issues),
            'meta' => $meta,
        ];
    }

    /**
     * @param  list<array<string,string>>  $issues
     */
    private function recommendedFix(array $issues): ?string
    {
        if ($issues === []) {
            return null;
        }

        $firstBlock = collect($issues)->first(fn (array $issue): bool => $issue['severity'] === self::STATUS_BLOCK);
        $issue = $firstBlock ?: $issues[0];

        return match ($issue['key']) {
            'title_missing' => 'Add a clear publication title.',
            'body_missing' => 'Generate, restore, or attach the current content body.',
            'locale_invalid' => 'Set a supported locale: '.implode(', ', SupportedLanguage::values()).'.',
            'canonical_invalid', 'canonical_host_missing', 'canonical_domain_not_allowed' => 'Fix the canonical URL or choose an allowed publishing destination.',
            'publishing_site_missing', 'publishing_site_not_connected', 'publishing_site_inactive', 'publishing_site_offline' => 'Select and reconnect an active publishing site.',
            'schema_type_missing', 'schema_type_invalid' => 'Set a supported schema type such as Article, BlogPosting, FAQPage, or WebPage.',
            'duplicate_slug_risk' => 'Choose a unique slug for this site and locale.',
            'content_workspace_mismatch', 'site_workspace_mismatch', 'content_site_mismatch', 'publishing_site_tenant_mismatch' => 'Verify the content and publishing site belong to the same workspace.',
            'unresolved_placeholder', 'internal_link_placeholder' => 'Replace placeholders with final copy or remove them before publication.',
            'answer_blocks_failed', 'answer_blocks_empty' => 'Regenerate or disable answer blocks before publication.',
            default => $issue['message'],
        };
    }
}
