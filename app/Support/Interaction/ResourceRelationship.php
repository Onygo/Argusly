<?php

namespace App\Support\Interaction;

use InvalidArgumentException;

final class ResourceRelationship
{
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $resourceType,
        public readonly string|int|null $resourceId = null,
        public readonly ?string $resourceKey = null,
        public readonly ?string $title = null,
        public readonly array $metadata = [],
    ) {
        if ($key === '' || $type === '' || $resourceType === '') {
            throw new InvalidArgumentException('Resource relationships require a non-empty key, type, and resource type.');
        }
    }

    public static function make(string $key, string $type, string $resourceType): self
    {
        return new self($key, $type, $resourceType);
    }

    public function resourceId(string|int|null $resourceId): self
    {
        return new self(
            $this->key,
            $this->type,
            $this->resourceType,
            $resourceId,
            $this->resourceKey,
            $this->title,
            $this->metadata,
        );
    }

    public function resourceKey(?string $resourceKey): self
    {
        return new self(
            $this->key,
            $this->type,
            $this->resourceType,
            $this->resourceId,
            $resourceKey,
            $this->title,
            $this->metadata,
        );
    }

    public function title(?string $title): self
    {
        return new self(
            $this->key,
            $this->type,
            $this->resourceType,
            $this->resourceId,
            $this->resourceKey,
            $title,
            $this->metadata,
        );
    }

    public function metadata(array $metadata): self
    {
        return new self(
            $this->key,
            $this->type,
            $this->resourceType,
            $this->resourceId,
            $this->resourceKey,
            $this->title,
            array_replace_recursive($this->metadata, $metadata),
        );
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'resource_key' => $this->resourceKey,
            'title' => $this->title,
            'metadata' => $this->metadata,
        ];
    }
}
