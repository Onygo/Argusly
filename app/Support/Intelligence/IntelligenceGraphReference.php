<?php

namespace App\Support\Intelligence;

use App\Support\MarketingMetadataRedactor;
use InvalidArgumentException;
use Stringable;

class IntelligenceGraphReference
{
    public const TYPE_ENTITY = 'entity';
    public const TYPE_PAGE = 'page';
    public const TYPE_TOPIC = 'topic';
    public const TYPE_OBSERVATION = 'observation';
    public const TYPE_SIGNAL = 'signal';
    public const TYPE_INSIGHT = 'insight';
    public const TYPE_RECOMMENDATION = 'recommendation';
    public const TYPE_OBJECTIVE = 'objective';
    public const TYPE_INITIATIVE = 'initiative';
    public const TYPE_REPORT = 'report';
    public const TYPE_BRIEFING = 'briefing';
    public const TYPE_ACTION = 'action';
    public const TYPE_OUTCOME = 'outcome';
    public const TYPE_REFERENCE = 'reference';

    public readonly string $type;

    public readonly string $key;

    public readonly ?string $label;

    public readonly ?string $id;

    public readonly ?string $model;

    public readonly ?CanonicalEntityReference $entity;

    /**
     * @var array<string, mixed>
     */
    public readonly array $metadata;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        string $type,
        string|int|Stringable $key,
        ?string $label = null,
        string|int|null $id = null,
        ?string $model = null,
        ?CanonicalEntityReference $entity = null,
        array $metadata = [],
    ) {
        $this->type = self::normalizePart($type);
        $this->key = self::normalizeKey($key);
        $this->label = $label !== null && trim($label) !== '' ? trim($label) : null;
        $this->id = $id !== null && trim((string) $id) !== '' ? trim((string) $id) : null;
        $this->model = $model !== null && trim($model) !== '' ? trim($model) : null;
        $this->entity = $entity;
        $this->metadata = MarketingMetadataRedactor::redact($metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function make(
        string $type,
        string|int|Stringable $key,
        ?string $label = null,
        string|int|null $id = null,
        ?string $model = null,
        array $metadata = [],
    ): self {
        return new self($type, $key, $label, $id, $model, metadata: $metadata);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function entity(
        CanonicalEntityReference|array|string $entity,
        CanonicalEntityType|string $type = CanonicalEntityType::ORGANIZATION,
        array $context = [],
        ?EntityReferenceMapper $mapper = null,
        ?EntityReferenceNormalizer $normalizer = null,
    ): self {
        $resolver = new EntityReferenceResolver($normalizer ?? new EntityReferenceNormalizer(), $mapper);
        $reference = $resolver->resolve($type, $entity, $context);

        if ($reference === null) {
            throw new InvalidArgumentException('Unable to create an intelligence graph entity reference from an empty entity value.');
        }

        return new self(
            type: self::TYPE_ENTITY,
            key: $reference->type.':'.$reference->key,
            label: $reference->name,
            entity: $reference,
            metadata: $reference->metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function page(string|int|Stringable $key, ?string $label = null, array $metadata = []): self
    {
        return new self(self::TYPE_PAGE, $key, $label, metadata: $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function topic(string|int|Stringable $key, ?string $label = null, array $metadata = []): self
    {
        return new self(self::TYPE_TOPIC, $key, $label, metadata: $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function observation(string|int|Stringable $key, ?string $label = null, array $metadata = []): self
    {
        return new self(self::TYPE_OBSERVATION, $key, $label, metadata: $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function signal(string|int|Stringable $key, ?string $label = null, array $metadata = []): self
    {
        return new self(self::TYPE_SIGNAL, $key, $label, metadata: $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function insight(string|int|Stringable $key, ?string $label = null, array $metadata = []): self
    {
        return new self(self::TYPE_INSIGHT, $key, $label, metadata: $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function recommendation(string|int|Stringable $key, ?string $label = null, array $metadata = []): self
    {
        return new self(self::TYPE_RECOMMENDATION, $key, $label, metadata: $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function objective(string|int|Stringable $key, ?string $label = null, array $metadata = []): self
    {
        return new self(self::TYPE_OBJECTIVE, $key, $label, metadata: $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function initiative(string|int|Stringable $key, ?string $label = null, array $metadata = []): self
    {
        return new self(self::TYPE_INITIATIVE, $key, $label, metadata: $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function report(string|int|Stringable $key, ?string $label = null, array $metadata = []): self
    {
        return new self(self::TYPE_REPORT, $key, $label, metadata: $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function briefing(string|int|Stringable $key, ?string $label = null, array $metadata = []): self
    {
        return new self(self::TYPE_BRIEFING, $key, $label, metadata: $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function action(string|int|Stringable $key, ?string $label = null, array $metadata = []): self
    {
        return new self(self::TYPE_ACTION, $key, $label, metadata: $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function outcome(string|int|Stringable $key, ?string $label = null, array $metadata = []): self
    {
        return new self(self::TYPE_OUTCOME, $key, $label, metadata: $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function reference(string|int|Stringable $key, ?string $label = null, array $metadata = []): self
    {
        return new self(self::TYPE_REFERENCE, $key, $label, metadata: $metadata);
    }

    public function graphKey(): string
    {
        return $this->type.':'.$this->key;
    }

    /**
     * @return array{type:string,key:string,graph_key:string,label:?string,id:?string,model:?string,entity:?array<string,mixed>,metadata:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'key' => $this->key,
            'graph_key' => $this->graphKey(),
            'label' => $this->label,
            'id' => $this->id,
            'model' => $this->model,
            'entity' => $this->entity?->toArray(),
            'metadata' => $this->metadata,
        ];
    }

    private static function normalizePart(string $value): string
    {
        $normalized = str($value)->lower()->trim()->slug('_')->toString();

        return $normalized !== '' ? $normalized : self::TYPE_REFERENCE;
    }

    private static function normalizeKey(string|int|Stringable $key): string
    {
        $normalized = trim((string) $key);

        return $normalized !== '' ? $normalized : hash('sha256', (string) $key);
    }
}
