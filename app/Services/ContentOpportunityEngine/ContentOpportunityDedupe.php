<?php

namespace App\Services\ContentOpportunityEngine;

class ContentOpportunityDedupe
{
    public function hash(string $workspaceId, string $type, string $title, string $topic): string
    {
        return hash('sha256', implode('|', [
            $workspaceId,
            strtolower(trim($type)),
            $this->normalize($title),
            $this->normalize($topic),
        ]));
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\s\-]/', ' ', $value) ?: '';
        $value = preg_replace('/\s+/', ' ', $value) ?: '';

        return trim($value);
    }
}
