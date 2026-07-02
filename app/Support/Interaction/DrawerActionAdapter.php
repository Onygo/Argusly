<?php

namespace App\Support\Interaction;

final class DrawerActionAdapter
{
    public function __construct(
        private readonly ?DrawerMetadataBuilder $builder = null,
    ) {
    }

    public static function make(): self
    {
        return new self();
    }

    public function descriptorFor(array $action, array $overrides = []): DrawerDescriptor
    {
        return ($this->builder ?? DrawerMetadataBuilder::make())->forAction($action, $overrides);
    }

    public function openActionFor(array $action, array $overrides = []): DrawerOpenAction
    {
        return DrawerOpenAction::fromAction($action, $this->descriptorFor($action, $overrides));
    }

    public function descriptorFromRegistry(
        ActionRegistry $registry,
        string $actionKey,
        ?ActionContext $context = null,
        array $overrides = [],
        bool $includeHidden = true,
    ): ?DrawerDescriptor {
        $action = $registry->resolve(
            $actionKey,
            ($context ?? ActionContext::make())->forSurface(Action::SURFACE_DRAWER),
        );

        if (! $includeHidden && ! ($action['visible'] ?? false)) {
            return null;
        }

        return $this->descriptorFor($action, $overrides);
    }
}
