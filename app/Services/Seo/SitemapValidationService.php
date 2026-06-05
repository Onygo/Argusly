<?php

namespace App\Services\Seo;

use App\Models\Content;
use App\Services\Content\LocaleIntegrityValidationService;
use App\Services\Publication\ContentPublicationStateService;

class SitemapValidationService
{
    public function __construct(
        private readonly CanonicalUrlService $canonicals,
        private readonly ContentPublicationStateService $publicationState,
        private readonly LocaleIntegrityValidationService $localeIntegrity,
    ) {
    }

    /**
     * @return array{
     *   canonical_url:?string,
     *   included:bool,
     *   indexable:bool,
     *   redirect_issue:bool,
     *   canonical_match:bool,
     *   issues:array<int,array{code:string,severity:string,message:string}>
     * }
     */
    public function validateContent(Content $content): array
    {
        $expectedCanonical = $this->canonicals->expectedCanonicalForContent($content);
        $storedCanonical = $this->canonicals->normalize((string) ($content->seo_canonical ?? $content->published_url ?? ''));
        $published = $this->publicationState->isPublished($content);
        $indexable = (bool) ($content->robots_index ?? true);

        $issues = [];

        if (! $indexable) {
            $issues[] = [
                'code' => 'noindex_detected',
                'severity' => 'warning',
                'message' => 'The content is marked noindex and should not appear in public sitemaps.',
            ];
        }

        $canonicalMatch = $expectedCanonical !== null
            && $storedCanonical !== null
            && $this->canonicals->equivalent($expectedCanonical, $storedCanonical);

        if ($expectedCanonical !== null && $storedCanonical !== null && ! $canonicalMatch) {
            $issues[] = [
                'code' => 'canonical_mismatch',
                'severity' => 'high',
                'message' => 'Stored canonical does not match the public resolved route.',
            ];
        }

        foreach ($this->localeIntegrity->validate($content)['issues'] as $issue) {
            $issues[] = [
                'code' => (string) $issue['code'],
                'severity' => (string) $issue['severity'],
                'message' => (string) $issue['message'],
            ];
        }

        $redirectIssue = collect($issues)->contains(fn (array $issue): bool => in_array($issue['code'], [
            'canonical_mismatch',
            'cross_locale_or_stale_canonical',
        ], true));

        return [
            'canonical_url' => $expectedCanonical,
            'included' => $published && $indexable && $expectedCanonical !== null && ! $redirectIssue,
            'indexable' => $indexable,
            'redirect_issue' => $redirectIssue,
            'canonical_match' => $canonicalMatch || ($storedCanonical === null && $expectedCanonical !== null),
            'issues' => $issues,
        ];
    }
}
