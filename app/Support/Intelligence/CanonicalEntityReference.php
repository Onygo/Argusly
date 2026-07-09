<?php

namespace App\Support\Intelligence;

use Illuminate\Support\Str;

class CanonicalEntityReference
{
    /**
     * @param  array<int, string>  $aliases
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly string $key,
        public readonly array $aliases = [],
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @param  array<int, string>  $aliases
     * @param  array<string, mixed>  $metadata
     */
    public static function fromName(
        CanonicalEntityType|string $type,
        string $name,
        ?string $key = null,
        array $aliases = [],
        array $metadata = [],
    ): self {
        $typeValue = $type instanceof CanonicalEntityType ? $type->value : trim($type);

        return new self(
            type: $typeValue,
            name: trim($name),
            key: $key ?: self::keyForName($name),
            aliases: self::unique($aliases),
            metadata: $metadata,
        );
    }

    public static function keyForName(string $name): string
    {
        $normalized = trim(Str::lower($name));
        $key = Str::slug($normalized);

        return $key !== '' ? $key : hash('sha256', $name);
    }

    /**
     * @return array{type:string,name:string,key:string,aliases:array<int,string>,metadata:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'key' => $this->key,
            'aliases' => $this->aliases,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param  array<int, string>  $aliases
     * @return array<int, string>
     */
    private static function unique(array $aliases): array
    {
        return collect($aliases)
            ->map(fn (string $alias): string => trim($alias))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
