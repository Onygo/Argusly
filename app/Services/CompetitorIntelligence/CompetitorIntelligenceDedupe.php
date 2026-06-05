<?php

namespace App\Services\CompetitorIntelligence;

class CompetitorIntelligenceDedupe
{
    public function topicHash(string $topic): string
    {
        return hash('sha256', $this->normalize($topic));
    }

    public function opportunityHash(string $workspaceId, string $type, ?string $competitorId, string $topic): string
    {
        return hash('sha256', implode('|', [
            $workspaceId,
            $type,
            (string) $competitorId,
            $this->normalize($topic),
        ]));
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?: '';

        return $value;
    }
}
