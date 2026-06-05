<?php

namespace App\Agents\InternalLinking;

use App\Agents\Contracts\AgentInterface;
use App\Agents\Data\AgentContext;
use App\Agents\Data\AgentResult;
use App\Models\Content;

class InternalLinkingAgent implements AgentInterface
{
    public const KEY = 'content.internal_linking';

    public function __construct(
        private readonly InternalLinkingInputBuilder $inputBuilder,
        private readonly InternalLinkOpportunityFinder $opportunityFinder,
        private readonly InternalLinkingFormatter $formatter,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function supports(AgentContext $context): bool
    {
        return $context->draftId !== null || $context->contentId !== null;
    }

    public function run(AgentContext $context): AgentResult
    {
        $input = $this->inputBuilder->build($context);
        $formatted = $this->formatter->format($input, []);
        $content = $input['content'] ?? null;

        if (! $content instanceof Content) {
            return AgentResult::warning(
                agentKey: self::KEY,
                summary: 'Internal linking needs a content item with site and locale context.',
                actions: $formatted['actions'],
                warnings: [[
                    'title' => 'Missing content context',
                    'description' => 'This draft is not linked to a content record yet, so related same-site articles could not be evaluated.',
                ]],
                rawPayload: [
                    'resource_type' => $input['resource_type'] ?? null,
                ],
            );
        }

        $suggestions = $this->opportunityFinder->find($input);
        $formatted = $this->formatter->format($input, $suggestions);
        $draft = $input['draft'] ?? null;
        $site = $input['site'] ?? null;
        $metrics = [
            'suggestion_count' => count($suggestions),
            'existing_link_count' => count((array) ($input['existing_link_urls'] ?? [])),
            'heading_count' => count((array) ($input['headings'] ?? [])),
            'locale' => (string) ($input['source_locale'] ?? $content->localeCode()),
            'resource_type' => (string) ($input['resource_type'] ?? 'content'),
        ];
        $rawPayload = [
            'content_id' => (string) $content->id,
            'draft_id' => $draft instanceof \App\Models\Draft ? (string) $draft->id : '',
            'site_id' => $site instanceof \App\Models\ClientSite ? (string) $site->id : '',
            'target_content_ids' => collect($suggestions)->pluck('target_content_id')->values()->all(),
        ];

        if ($suggestions === []) {
            return AgentResult::warning(
                agentKey: self::KEY,
                summary: $formatted['summary'],
                actions: $formatted['actions'],
                warnings: $formatted['warnings'],
                metrics: $metrics,
                rawPayload: $rawPayload,
            );
        }

        return AgentResult::success(
            agentKey: self::KEY,
            summary: $formatted['summary'],
            suggestions: $suggestions,
            actions: $formatted['actions'],
            warnings: $formatted['warnings'],
            metrics: $metrics,
            rawPayload: $rawPayload,
        );
    }
}
