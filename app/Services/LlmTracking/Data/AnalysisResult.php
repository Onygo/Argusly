<?php

namespace App\Services\LlmTracking\Data;

class AnalysisResult
{
    /**
     * @param array<int,array<string,mixed>> $brandHits
     * @param array<int,array<string,mixed>> $competitorHits
     * @param array<int,array<string,mixed>> $urlHits
     * @param array<string,mixed> $citationRanking
     * @param array<int,array<string,mixed>> $sources
     * @param array<int,string> $detectedDomains
     * @param array<string,mixed> $shareOfVoiceSnapshot
     * @param array<int,array<string,mixed>> $suggestions
     */
    public function __construct(
        public readonly array $brandHits,
        public readonly array $competitorHits,
        public readonly array $urlHits,
        public readonly array $citationRanking,
        public readonly array $sources,
        public readonly array $detectedDomains,
        public readonly ?int $firstMentionIndex,
        public readonly ?string $firstMentionBlock,
        public readonly ?string $firstMentionContext,
        public readonly array $shareOfVoiceSnapshot,
        public readonly array $suggestions,
    ) {
    }

    public function brandMentioned(): bool
    {
        return $this->brandHits !== [];
    }

    public function competitorsMentioned(): bool
    {
        return $this->competitorHits !== [];
    }

    public function urlsCited(): bool
    {
        return $this->urlHits !== [];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'brand_hits' => $this->brandHits,
            'competitor_hits' => $this->competitorHits,
            'url_hits' => $this->urlHits,
            'citation_ranking' => $this->citationRanking,
            'sources' => $this->sources,
            'detected_domains' => $this->detectedDomains,
            'first_mention_index' => $this->firstMentionIndex,
            'first_mention_block' => $this->firstMentionBlock,
            'first_mention_context' => $this->firstMentionContext,
            'share_of_voice_snapshot' => $this->shareOfVoiceSnapshot,
            'suggestions' => $this->suggestions,
            'brand_mentioned' => $this->brandMentioned(),
            'competitors_mentioned' => $this->competitorsMentioned(),
            'urls_cited' => $this->urlsCited(),
        ];
    }
}
