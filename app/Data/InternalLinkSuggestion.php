<?php

namespace App\Data;

class InternalLinkSuggestion
{
    public function __construct(
        public readonly string $targetContentId,
        public readonly string $targetUrl,
        public readonly string $anchorText,
        public readonly string $reason,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            targetContentId: trim((string) ($payload['target_content_id'] ?? '')),
            targetUrl: trim((string) ($payload['target_url'] ?? '')),
            anchorText: trim((string) ($payload['anchor_text'] ?? '')),
            reason: trim((string) ($payload['reason'] ?? '')),
        );
    }

    /**
     * @return array<string,string>
     */
    public function toArray(): array
    {
        return [
            'target_content_id' => $this->targetContentId,
            'target_url' => $this->targetUrl,
            'anchor_text' => $this->anchorText,
            'reason' => $this->reason,
        ];
    }
}
