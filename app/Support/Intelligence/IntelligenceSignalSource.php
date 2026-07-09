<?php

namespace App\Support\Intelligence;

use App\Support\MarketingMetadataRedactor;

class IntelligenceSignalSource
{
    public readonly string $provider;

    public readonly ?string $system;

    public readonly ?string $dataset;

    public readonly ?string $key;

    public readonly ?string $label;

    /**
     * @var array<string, mixed>
     */
    public readonly array $metadata;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        string $provider,
        ?string $system = null,
        ?string $dataset = null,
        string|int|null $key = null,
        ?string $label = null,
        array $metadata = [],
    ) {
        $this->provider = self::normalizePart($provider, 'unknown');
        $this->system = self::nullablePart($system);
        $this->dataset = self::nullablePart($dataset);
        $this->key = $key !== null && trim((string) $key) !== '' ? trim((string) $key) : null;
        $this->label = $label !== null && trim($label) !== '' ? trim($label) : null;
        $this->metadata = MarketingMetadataRedactor::redact($metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function provider(
        string $provider,
        ?string $dataset = null,
        string|int|null $key = null,
        ?string $label = null,
        array $metadata = [],
    ): self {
        return new self(
            provider: $provider,
            dataset: $dataset,
            key: $key,
            label: $label,
            metadata: $metadata,
        );
    }

    public function signature(): string
    {
        return implode(':', array_filter([
            $this->provider,
            $this->system,
            $this->dataset,
            $this->key,
        ], fn (?string $value): bool => $value !== null && $value !== ''));
    }

    /**
     * @return array{provider:string,system:?string,dataset:?string,key:?string,label:?string,metadata:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'system' => $this->system,
            'dataset' => $this->dataset,
            'key' => $this->key,
            'label' => $this->label,
            'metadata' => $this->metadata,
        ];
    }

    private static function nullablePart(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::normalizePart($value, 'unknown');
    }

    private static function normalizePart(string $value, string $fallback): string
    {
        $normalized = str($value)->lower()->trim()->slug('_')->toString();

        return $normalized !== '' ? $normalized : $fallback;
    }
}
