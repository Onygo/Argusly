<?php

namespace App\DTO\QueryIntent;

use Illuminate\Database\Eloquent\Model;

class QueryIntentInput
{
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $query = null,
        public readonly ?string $text = null,
        public readonly ?string $locale = null,
        public readonly ?string $sourceType = null,
        public readonly ?string $sourceKey = null,
        public readonly ?string $workspaceId = null,
        public readonly ?string $clientSiteId = null,
        public readonly ?int $organizationId = null,
        public readonly ?Model $classifiable = null,
        public readonly array $context = [],
    ) {}

    public function combinedText(): string
    {
        return trim(implode(' ', array_filter([
            $this->title,
            $this->query,
            $this->text,
            implode(' ', array_map(
                fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                $this->context
            )),
        ])));
    }

    public function payloadHash(): string
    {
        return hash('sha256', json_encode([
            'title' => $this->title,
            'query' => $this->query,
            'text' => $this->text,
            'locale' => $this->locale,
            'source_type' => $this->sourceType,
            'source_key' => $this->sourceKey,
            'classifiable_type' => $this->classifiable?->getMorphClass(),
            'classifiable_id' => $this->classifiable?->getKey(),
            'context' => $this->context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
