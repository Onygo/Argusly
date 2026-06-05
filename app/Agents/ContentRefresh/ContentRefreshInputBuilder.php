<?php

namespace App\Agents\ContentRefresh;

use App\Agents\Data\AgentContext;
use App\Models\Content;
use App\Services\Content\ContentDeduplicationService;
use App\Services\Content\ContentHealthService;
use App\Services\Content\ContentRelationService;
use App\Services\Content\LocaleContentMapService;

class ContentRefreshInputBuilder
{
    public function __construct(
        private readonly ContentHealthService $contentHealthService,
        private readonly ContentRelationService $contentRelationService,
        private readonly LocaleContentMapService $localeContentMapService,
        private readonly ContentDeduplicationService $contentDeduplicationService,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(AgentContext $context): array
    {
        $content = Content::query()
            ->with([
                'clientSite:id,name,base_url,site_url',
                'drafts' => fn ($query) => $query->latest('created_at')->limit(5),
                'currentRevision',
                'currentVersion',
            ])
            ->findOrFail($context->contentId);
        $health = $this->contentHealthService->snapshot(
            $content,
            $content->drafts->first()?->content_html
                ?: $content->currentRevision?->content_html
                ?: $content->currentVersion?->body
                ?: null,
        );

        return [
            'content' => $content,
            'site' => $content->clientSite,
            'locale' => $content->localeCode(),
            'html' => (string) ($health['html'] ?? ''),
            'plain_text' => (string) ($health['plain_text'] ?? ''),
            'word_count' => (int) ($health['word_count'] ?? 0),
            'heading_count' => (int) ($health['heading_count'] ?? 0),
            'headings' => (array) ($health['headings'] ?? []),
            'internal_link_count' => (int) ($health['internal_link_count'] ?? 0),
            'body_years' => (array) ($health['body_years'] ?? []),
            'has_faq' => (bool) ($health['has_faq'] ?? false),
            'latest_content_reference_at' => $health['latest_reference_at'] ?? null,
            'outdated_locales' => $this->localeContentMapService->outdatedLocales($content, uppercase: true),
            'newer_chain_article_count' => $this->contentRelationService->newerChainArticleCount($content, $health['latest_reference_at'] ?? null),
            'missing_seo_fields' => (array) ($health['missing_seo_fields'] ?? []),
            'title_h1_mismatch' => (bool) ($health['title_h1_mismatch'] ?? false),
            'duplicate_title_risks' => $this->contentDeduplicationService->titleSimilarityRisks($content, limit: 5),
            'target_word_count' => (int) ($health['target_word_count'] ?? 0),
            'latest_draft' => $content->drafts->first(),
        ];
    }
}
