<?php

namespace App\Services\InternalLinking;

use App\Data\InternalLinkSuggestion;
use App\Models\Content;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\LlmManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class InternalLinkSuggestionService
{
    /**
     * @var array<int,string>
     */
    private array $genericAnchors = [
        'click here',
        'read more',
        'learn more',
        'here',
        'this article',
        'this guide',
        'guide',
        'article',
        'post',
        'more',
    ];

    public function __construct(
        private readonly InternalLinkCandidateService $candidateService,
        private readonly LlmManager $llmManager,
    ) {
    }

    /**
     * @return Collection<int,InternalLinkSuggestion>
     */
    public function suggestFor(Content $source, ?string $sourceHtml = null): Collection
    {
        $sourceHtml ??= $this->candidateService->sourceHtml($source);
        if (trim($sourceHtml) === '') {
            return collect();
        }

        $candidates = $this->candidateService->candidatesFor($source, $sourceHtml);
        if ($candidates->isEmpty()) {
            return collect();
        }

        $suggestions = $this->shouldUseLlm($source)
            ? $this->suggestWithLlm($source, $sourceHtml, $candidates)
            : collect();

        if ($suggestions->isEmpty()) {
            $suggestions = $this->fallbackSuggestions($candidates);
        }

        return $this->normalizeSuggestions($suggestions, $candidates);
    }

    private function shouldUseLlm(Content $source): bool
    {
        if (app()->environment('testing')) {
            return false;
        }

        if (! config('internal_linking.enabled', true)) {
            return false;
        }

        return trim((string) ($source->workspace_id ?? '')) !== '';
    }

    /**
     * @param Collection<int,array<string,mixed>> $candidates
     * @return Collection<int,InternalLinkSuggestion>
     */
    private function suggestWithLlm(Content $source, string $sourceHtml, Collection $candidates): Collection
    {
        try {
            $response = $this->llmManager->generateJson(
                new LlmRequest(
                    messages: [
                        new LlmMessage('system', 'You create safe internal link suggestions for HTML articles. Return strict JSON only.'),
                        new LlmMessage('user', $this->buildPrompt($source, $sourceHtml, $candidates)),
                    ],
                    model: (string) config('llm.providers.openai.reasoning_model', config('llm.providers.openai.default_model')),
                    temperature: 0.1,
                    maxTokens: 1600,
                    responseFormat: 'json',
                    metadata: [
                        'feature' => 'internal_linking',
                        'modality' => 'text',
                        'workspaceId' => (string) $source->workspace_id,
                        'siteId' => (string) $source->client_site_id,
                        'contentId' => (string) $source->id,
                    ],
                ),
                '{"suggestions":[{"target_content_id":"...","target_url":"...","anchor_text":"...","reason":"..."}]}'
            );

            $rows = collect((array) data_get($response->json, 'suggestions', []))
                ->filter(fn ($row) => is_array($row))
                ->map(fn (array $row): InternalLinkSuggestion => InternalLinkSuggestion::fromArray($row));

            return $rows->values();
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * @param Collection<int,array<string,mixed>> $candidates
     */
    private function buildPrompt(Content $source, string $sourceHtml, Collection $candidates): string
    {
        $payload = [
            'source' => [
                'id' => (string) $source->id,
                'title' => (string) $source->title,
                'primary_keyword' => (string) ($source->primary_keyword ?? ''),
                'excerpt' => Str::limit(trim(preg_replace('/\s+/u', ' ', strip_tags($sourceHtml)) ?? ''), 900, ''),
            ],
            'candidates' => $candidates->map(function (array $candidate): array {
                /** @var Content $content */
                $content = $candidate['content'];

                return [
                    'target_content_id' => (string) $content->id,
                    'target_url' => (string) $candidate['target_url'],
                    'title' => (string) $content->title,
                    'primary_keyword' => (string) ($content->primary_keyword ?? ''),
                    'relationship' => (string) $candidate['relationship'],
                    'similarity_score' => (float) $candidate['similarity_score'],
                    'allowed_anchor_options' => array_values((array) ($candidate['anchor_options'] ?? [])),
                ];
            })->values()->all(),
        ];

        return implode("\n", [
            'Suggest up to 4 contextual internal links for this article.',
            'Rules:',
            '- Use only candidates provided below.',
            '- Use only anchor_text values from allowed_anchor_options.',
            '- Do not repeat anchor_text values.',
            '- Avoid generic anchors.',
            '- Prefer same-chain and pillar relationships when relevant.',
            '- Return JSON: {"suggestions":[{"target_content_id":"...","target_url":"...","anchor_text":"...","reason":"..."}]}',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @param Collection<int,array<string,mixed>> $candidates
     * @return Collection<int,InternalLinkSuggestion>
     */
    private function fallbackSuggestions(Collection $candidates): Collection
    {
        $usedAnchors = [];
        $limit = min(4, max(1, (int) config('internal_linking.max_links_per_article', 4)));
        $suggestions = collect();

        foreach ($candidates as $candidate) {
            /** @var Content $content */
            $content = $candidate['content'];

            $anchor = collect((array) ($candidate['anchor_options'] ?? []))
                ->first(function (string $anchor) use ($usedAnchors): bool {
                    return ! in_array(Str::lower($anchor), $usedAnchors, true) && ! $this->isGenericAnchor($anchor);
                });

            if (! is_string($anchor) || trim($anchor) === '') {
                continue;
            }

            $usedAnchors[] = Str::lower($anchor);
            $suggestions->push(new InternalLinkSuggestion(
                targetContentId: (string) $content->id,
                targetUrl: (string) $candidate['target_url'],
                anchorText: $anchor,
                reason: $this->fallbackReason((string) $candidate['relationship'], $content),
            ));

            if ($suggestions->count() >= $limit) {
                break;
            }
        }

        return $suggestions->values();
    }

    private function fallbackReason(string $relationship, Content $content): string
    {
        return match ($relationship) {
            'same_chain_pillar' => 'Relevant same-chain pillar article that should act as the hub for this topic cluster.',
            'same_chain_supporting' => 'Relevant same-chain supporting article that expands the pillar topic with a narrower angle.',
            'same_chain_related' => 'Relevant same-chain article covering a closely related supporting topic.',
            default => 'Relevant topic-related article in the same workspace that strengthens internal topic coverage.',
        };
    }

    /**
     * @param Collection<int,InternalLinkSuggestion> $suggestions
     * @param Collection<int,array<string,mixed>> $candidates
     * @return Collection<int,InternalLinkSuggestion>
     */
    private function normalizeSuggestions(Collection $suggestions, Collection $candidates): Collection
    {
        $candidateMap = $candidates->mapWithKeys(function (array $candidate): array {
            /** @var Content $content */
            $content = $candidate['content'];

            return [(string) $content->id => $candidate];
        });

        $usedAnchors = [];
        $limit = min(4, max(1, (int) config('internal_linking.max_links_per_article', 4)));

        return $suggestions
            ->map(function (InternalLinkSuggestion $suggestion) use ($candidateMap): ?InternalLinkSuggestion {
                $candidate = $candidateMap->get($suggestion->targetContentId);
                if (! is_array($candidate)) {
                    return null;
                }

                return new InternalLinkSuggestion(
                    targetContentId: $suggestion->targetContentId,
                    targetUrl: (string) $candidate['target_url'],
                    anchorText: $suggestion->anchorText,
                    reason: $suggestion->reason,
                );
            })
            ->filter()
            ->filter(function (InternalLinkSuggestion $suggestion) use ($candidateMap, &$usedAnchors): bool {
                $candidate = $candidateMap->get($suggestion->targetContentId);
                if (! is_array($candidate)) {
                    return false;
                }

                $anchor = trim($suggestion->anchorText);
                if ($anchor === '' || $this->isGenericAnchor($anchor)) {
                    return false;
                }

                if (! in_array($anchor, (array) ($candidate['anchor_options'] ?? []), true)) {
                    return false;
                }

                $normalized = Str::lower($anchor);
                if (in_array($normalized, $usedAnchors, true)) {
                    return false;
                }

                $usedAnchors[] = $normalized;

                return true;
            })
            ->take($limit)
            ->values();
    }

    private function isGenericAnchor(string $anchor): bool
    {
        $normalized = Str::lower(trim($anchor));

        return $normalized === ''
            || mb_strlen($normalized) < 4
            || in_array($normalized, $this->genericAnchors, true);
    }
}
