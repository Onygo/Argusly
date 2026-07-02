<?php

namespace App\Support\Interaction;

use LogicException;

final class ResourceRegistry
{
    /** @var array<string, Resource> */
    private array $resources = [];

    /** @var array<string, ResourceType> */
    private array $types = [];

    public static function make(): self
    {
        return new self();
    }

    public static function withInitialTypes(): self
    {
        $registry = self::make();

        foreach (ResourceType::initialTypes() as $type) {
            $registry->registerType($type);
        }

        return $registry;
    }

    public function register(Resource|ResourceType $entry): self
    {
        if ($entry instanceof ResourceType) {
            return $this->registerType($entry);
        }

        return $this->registerResource($entry);
    }

    public function registerType(ResourceType $type): self
    {
        if (isset($this->types[$type->key])) {
            throw new LogicException(sprintf('Resource type [%s] is already registered.', $type->key));
        }

        $this->types[$type->key] = $type;

        return $this;
    }

    public function registerResource(Resource $resource): self
    {
        if (isset($this->resources[$resource->key])) {
            throw new LogicException(sprintf('Resource [%s] is already registered.', $resource->key));
        }

        if (! isset($this->types[$resource->type])) {
            throw new LogicException(sprintf('Resource type [%s] is not registered.', $resource->type));
        }

        $this->resources[$resource->key] = $resource;

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->resources[$key]);
    }

    public function hasType(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function get(string $key): Resource
    {
        return $this->resources[$key]
            ?? throw new LogicException(sprintf('Resource [%s] is not registered.', $key));
    }

    public function type(string $key): ResourceType
    {
        return $this->types[$key]
            ?? throw new LogicException(sprintf('Resource type [%s] is not registered.', $key));
    }

    /**
     * @return array<string, Resource>
     */
    public function all(): array
    {
        return $this->resources;
    }

    /**
     * @return array<string, ResourceType>
     */
    public function types(): array
    {
        return $this->types;
    }

    public function resolve(string $key, ResourceContext $context, ?ResourceResolver $resolver = null, bool $includeHidden = false): ?array
    {
        $resource = $this->get($key)->resolve($context, $resolver);

        if (! $includeHidden && ! $resource['visible']) {
            return null;
        }

        return $resource;
    }

    public function forContext(ResourceContext $context, ?ResourceResolver $resolver = null, bool $includeHidden = false): array
    {
        $resolver ??= new ResourceResolver();

        return collect($this->resources)
            ->map(fn (Resource $resource): array => $resource->resolve($context, $resolver))
            ->when(! $includeHidden, fn ($collection) => $collection->where('visible', true))
            ->values()
            ->all();
    }

    public function forType(string $type, ResourceContext $context, ?ResourceResolver $resolver = null, bool $includeHidden = false): array
    {
        $resolver ??= new ResourceResolver();

        return collect($this->resources)
            ->filter(fn (Resource $resource): bool => $resource->type === $type)
            ->map(fn (Resource $resource): array => $resource->resolve($context, $resolver))
            ->when(! $includeHidden, fn ($collection) => $collection->where('visible', true))
            ->values()
            ->all();
    }

    public function assertAllTypesMapToExistingReferences(): void
    {
        foreach ($this->types as $type) {
            if (! $type->mapsToExistingReference()) {
                throw new LogicException(sprintf('Resource type [%s] does not map to an existing model, route, policy, or URL.', $type->key));
            }
        }
    }

    public function assertAllResourcesMapToExistingReferences(): void
    {
        foreach ($this->resources as $resource) {
            if (! $resource->mapsToExistingReference()) {
                throw new LogicException(sprintf('Resource [%s] does not map to an existing model, route, policy, or URL.', $resource->key));
            }
        }
    }

    public function assertAvailableActionsExist(ActionRegistry $actions): void
    {
        foreach ($this->resources as $resource) {
            foreach ($resource->actionKeys() as $actionKey) {
                if (! $actions->has($actionKey)) {
                    throw new LogicException(sprintf('Resource [%s] references missing action [%s].', $resource->key, $actionKey));
                }
            }
        }
    }
}
