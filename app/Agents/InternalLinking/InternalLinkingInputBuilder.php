<?php

namespace App\Agents\InternalLinking;

use App\Agents\Data\AgentContext;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Services\Content\ContentHealthService;
use Illuminate\Support\Str;

class InternalLinkingInputBuilder
{
    public function __construct(
        private readonly ContentHealthService $contentHealthService,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(AgentContext $context): array
    {
        if ($context->draftId !== null) {
            return $this->buildForDraftContext($context);
        }

        return $this->buildForContentContext($context);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildForDraftContext(AgentContext $context): array
    {
        $draft = Draft::query()
            ->with([
                'brief',
                'clientSite:id,name,base_url,site_url',
                'content.clientSite:id,name,base_url,site_url',
                'content.seriesArticle:id,series_id,content_id,article_number,is_pillar',
                'content.currentRevision',
                'content.currentVersion',
            ])
            ->findOrFail($context->draftId);

        $content = $draft->content;
        $site = $draft->clientSite ?: $content?->clientSite;
        $sourceHtml = trim((string) ($draft->content_html ?? ''));
        $sourceLocale = Str::lower((string) ($draft->language?->value ?? $draft->language ?? $context->sourceLocale ?? $content?->localeCode() ?? ''));
        $health = $this->contentHealthService->snapshot($content, $sourceHtml, (string) ($site?->site_url ?: $site?->base_url ?: ''));

        return $this->composePayload(
            resourceType: 'draft',
            content: $content,
            draft: $draft,
            site: $site,
            sourceTitle: trim((string) ($draft->title ?: $content?->title ?: '')),
            sourceKeyword: trim((string) ($content?->primary_keyword ?: $draft->brief?->primary_keyword ?: '')),
            sourceLocale: $sourceLocale,
            health: $health,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function buildForContentContext(AgentContext $context): array
    {
        $content = Content::query()
            ->with([
                'clientSite:id,name,base_url,site_url',
                'seriesArticle:id,series_id,content_id,article_number,is_pillar',
                'drafts' => fn ($query) => $query->latest('created_at')->limit(1),
                'currentRevision',
                'currentVersion',
            ])
            ->findOrFail($context->contentId);

        $draft = $content->drafts->first();
        $sourceHtml = trim((string) (
            $content->currentRevision?->content_html
            ?: $content->currentVersion?->body
            ?: $draft?->content_html
            ?: ''
        ));
        $health = $this->contentHealthService->snapshot($content, $sourceHtml);

        return $this->composePayload(
            resourceType: 'content',
            content: $content,
            draft: $draft,
            site: $content->clientSite,
            sourceTitle: trim((string) $content->title),
            sourceKeyword: trim((string) ($content->primary_keyword ?? '')),
            sourceLocale: Str::lower($content->localeCode()),
            health: $health,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function composePayload(
        string $resourceType,
        ?Content $content,
        ?Draft $draft,
        ?ClientSite $site,
        string $sourceTitle,
        string $sourceKeyword,
        string $sourceLocale,
        array $health,
    ): array {
        return [
            'resource_type' => $resourceType,
            'content' => $content,
            'draft' => $draft,
            'site' => $site,
            'source_title' => $sourceTitle,
            'source_keyword' => $sourceKeyword,
            'source_locale' => $sourceLocale,
            'source_html' => (string) ($health['html'] ?? ''),
            'source_text' => Str::lower((string) ($health['plain_text'] ?? '')),
            'headings' => (array) ($health['headings'] ?? []),
            'existing_link_urls' => (array) ($health['link_urls'] ?? []),
        ];
    }
}
