<?php

namespace App\Support\Interaction;

final class DrawerResourceAdapter
{
    public function __construct(
        private readonly ?DrawerMetadataBuilder $builder = null,
    ) {
    }

    public static function make(): self
    {
        return new self();
    }

    public function descriptorFor(array $resource, array $overrides = []): DrawerDescriptor
    {
        return ($this->builder ?? DrawerMetadataBuilder::make())->forResource($resource, $overrides);
    }

    public function descriptorFromRegistry(
        ResourceRegistry $registry,
        string $resourceKey,
        ?ResourceContext $context = null,
        array $overrides = [],
        bool $includeHidden = false,
    ): ?DrawerDescriptor {
        $resource = $registry->resolve($resourceKey, $context ?? ResourceContext::make(), includeHidden: $includeHidden);

        if ($resource === null) {
            return null;
        }

        return $this->descriptorFor($resource, $overrides);
    }
}
