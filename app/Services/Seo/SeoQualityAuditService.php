<?php

namespace App\Services\Seo;

use App\Models\Content;
use App\Models\Workspace;
use App\Services\Content\ContentDeduplicationService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;

class SeoQualityAuditService
{
    public function __construct(
        private readonly ContentDeduplicationService $contentDeduplicationService,
    ) {
    }

    /**
     * @return array{items:list<array<string,mixed>>,summary:array<string,int>}
     */
    public function audit(bool $publishedOnly = false, int $limit = 500): array
    {
        return $this->auditWorkspace(null, $publishedOnly, $limit);
    }

    /**
     * @return array{items:list<array<string,mixed>>,summary:array<string,int>}
     */
    public function auditWorkspace(
        ?Workspace $workspace,
        bool $publishedOnly = false,
        int $limit = 500,
        string $contentType = 'article',
        string $locale = '',
        string $issueType = '',
        string $severity = '',
    ): array
    {
        $query = Content::query()
            ->with(['currentVersion:id,content_id,body,meta', 'workspace:id,organization_id,name,display_name', 'clientSite:id,workspace_id,name'])
            ->latest('updated_at')
            ->limit($limit);

        if ($workspace) {
            $query->where('workspace_id', $workspace->id);
        }

        if ($contentType !== '') {
            $query->where('type', $contentType);
        }

        if ($publishedOnly) {
            $query->where('status', 'published')->where('publish_status', 'published');
        }

        /** @var EloquentCollection<int,Content> $contents */
        $contents = $query->get();
        $items = $contents
            ->filter(fn (Content $content): bool => $locale === '' || $content->localeCode() === $locale)
            ->map(fn (Content $content): array => $this->auditContent($content))
            ->filter(fn (array $row): bool => $row['issue_count'] > 0)
            ->filter(fn (array $row): bool => $issueType === '' || in_array($issueType, (array) ($row['issue_types'] ?? []), true))
            ->filter(fn (array $row): bool => $severity === '' || (string) ($row['severity'] ?? '') === $severity)
            ->values()
            ->all();

        return [
            'items' => $items,
            'summary' => [
                'audited' => $contents->count(),
                'with_issues' => count($items),
                'issues' => collect($items)->sum('issue_count'),
                'easy_wins' => collect($items)->where('impact', 'easy_win')->count(),
                'high_impact' => collect($items)->where('severity', 'high')->count(),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function auditContent(Content $content): array
    {
        $html = (string) ($content->currentVersion?->body ?? '');
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?: '');
        $wordCount = str_word_count($plain);
        $issues = [];
        $issueTypes = [];

        $firstParagraph = $this->firstParagraph($html);
        if (str_word_count($firstParagraph) < 25) {
            $issues[] = 'Intro should explain the topic clearly.';
            $issueTypes[] = 'structure';
        }

        if (preg_match_all('/<h[23]\b/i', $html) < 2) {
            $issues[] = 'Content needs clearer logical sections.';
            $issueTypes[] = 'headings';
        }

        if (preg_match('/<h[23][^>]*>\s*(introduction|conclusion|misc|general)\s*<\/h[23]>/i', $html)) {
            $issues[] = 'Headings are too generic.';
            $issueTypes[] = 'headings';
        }

        if ($wordCount < 600) {
            $issues[] = 'Article is thin for a published SEO page.';
            $issueTypes[] = 'depth';
        }

        if (! preg_match('/<(strong|h2|h3)[^>]*>(summary|answer|key takeaways|in short|kort antwoord|samenvatting)/i', $html)) {
            $issues[] = 'Add a clear answer block or summary near the top.';
            $issueTypes[] = 'ai_readiness';
        }

        if (! preg_match('/href=["\']https?:\/\/([^"\']*argusly|argusly\.)/i', $html)
            && ! preg_match('/href=["\']\/(?:en|nl)\//i', $html)) {
            $issues[] = 'Add useful internal links to related Argusly pages or articles.';
            $issueTypes[] = 'links';
        }

        if ($this->looksClaimHeavy($plain) && ! preg_match('/href=["\']https?:\/\/(?![^"\']*argusly)/i', $html)) {
            $issues[] = 'Add at least one external source link for supported claims.';
            $issueTypes[] = 'sources';
        }

        if ($content->first_published_at && $content->first_published_at->lt(now()->subMonths(9)) && ! $content->reviewed_at) {
            $issues[] = 'Older article should be reviewed or marked with reviewed_at.';
            $issueTypes[] = 'freshness';
        }

        $titleRisks = $this->contentDeduplicationService->titleSimilarityRisks($content, limit: 3);
        foreach ($titleRisks as $risk) {
            $issues[] = match ((string) ($risk['match_type'] ?? '')) {
                'exact_title' => 'Duplicate title detected against another article: ' . Str::limit((string) ($risk['title'] ?? ''), 90) . '.',
                default => 'Very similar title detected (' . (int) ($risk['similarity'] ?? 0) . '% match): ' . Str::limit((string) ($risk['title'] ?? ''), 90) . '.',
            };
            $issueTypes[] = 'duplicate_titles';
        }

        if ($this->looksMostlyGenericAiText($plain)) {
            $issues[] = 'Article reads generic; add concrete examples, product context and original detail.';
            $issueTypes[] = 'ai_readiness';
        }

        $severity = match (true) {
            count($titleRisks) > 0 || in_array('ai_readiness', $issueTypes, true) && $wordCount < 600 => 'high',
            count($issues) >= 3 => 'medium',
            count($issues) >= 1 => 'low',
            default => 'none',
        };

        return [
            'id' => (string) $content->id,
            'organization_id' => (string) ($content->workspace?->organization_id ?? ''),
            'workspace_id' => (string) $content->workspace_id,
            'site_id' => (string) ($content->client_site_id ?? ''),
            'site_name' => (string) ($content->clientSite?->name ?? ''),
            'title' => (string) $content->title,
            'locale' => $content->localeCode(),
            'type' => (string) ($content->type ?? ''),
            'word_count' => $wordCount,
            'severity' => $severity,
            'impact' => in_array($severity, ['low', 'medium'], true) && count($issues) <= 2 ? 'easy_win' : 'optimization',
            'issue_count' => count($issues),
            'issue_types' => array_values(array_unique($issueTypes)),
            'issues' => $issues,
            'duplicate_title_matches' => $titleRisks,
        ];
    }

    private function firstParagraph(string $html): string
    {
        if (preg_match('/<p\b[^>]*>(.*?)<\/p>/is', $html, $matches)) {
            return trim(strip_tags((string) $matches[1]));
        }

        return Str::of(strip_tags($html))->words(60, '')->toString();
    }

    private function looksClaimHeavy(string $plain): bool
    {
        return preg_match('/\b(study|research|data|according to|report|statistics|percent|%|survey|benchmark)\b/i', $plain) === 1;
    }

    private function looksMostlyGenericAiText(string $plain): bool
    {
        $genericPhrases = ['in today\'s digital landscape', 'it is important to note', 'leverage', 'unlock the power', 'game-changer'];
        $hits = 0;
        $lower = Str::lower($plain);

        foreach ($genericPhrases as $phrase) {
            $hits += substr_count($lower, $phrase);
        }

        return $hits >= 2;
    }
}
