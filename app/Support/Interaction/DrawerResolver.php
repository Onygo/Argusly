<?php

namespace App\Support\Interaction;

use LogicException;
use Throwable;

final class DrawerResolver
{
    public function __construct(
        private readonly ?DrawerRegistry $drawers = null,
        private readonly ?ResourceRegistry $resources = null,
        private readonly ?ActionRegistry $actions = null,
    ) {
    }

    public function resolve(?string $key, DrawerContext $context): array
    {
        if (! filled($key)) {
            return $this->emptyResponse($context, 'No drawer is selected.');
        }

        $drawer = $this->drawers ?? DrawerRegistry::make();

        if ($drawer->has($key)) {
            return $this->attachResolvedMetadata($drawer->resolve($key, $context, $this), $context);
        }

        if ($resourceDrawer = $this->resolveFromResourceMetadata($key, $context)) {
            return $this->attachResolvedMetadata($resourceDrawer, $context);
        }

        return $this->errorResponse($context, sprintf('Drawer [%s] is not registered.', $key), $key);
    }

    public function evaluate(mixed $value, DrawerContext $context, ?Drawer $drawer = null): mixed
    {
        if (is_callable($value)) {
            return $value($context, $drawer, $this);
        }

        return $value;
    }

    private function attachResolvedMetadata(array $drawer, DrawerContext $context): array
    {
        $resolvedResource = $this->resolveResource($drawer, $context);
        $resolvedActions = $this->resolveActions($drawer, $context);

        return array_replace_recursive($drawer, [
            'resource_metadata' => array_replace_recursive(
                $drawer['resource_metadata'] ?? [],
                $context->resourceMetadata,
            ),
            'action_metadata' => array_replace_recursive(
                $drawer['action_metadata'] ?? [],
                $context->actionMetadata,
            ),
            'resolved_resource' => $resolvedResource,
            'resolved_actions' => $resolvedActions,
        ]);
    }

    private function resolveResource(array $drawer, DrawerContext $context): ?array
    {
        $resources = $this->resources ?? $context->resourceRegistry;
        $resourceKey = $drawer['resource_key'] ?? $context->resourceKey;

        if (! $resources || ! filled($resourceKey) || ! $resources->has($resourceKey)) {
            return $context->resourceMetadata === [] ? null : $context->resourceMetadata;
        }

        try {
            return $resources->resolve($resourceKey, $context->toResourceContext(), includeHidden: true);
        } catch (LogicException) {
            return $context->resourceMetadata === [] ? null : $context->resourceMetadata;
        }
    }

    private function resolveActions(array $drawer, DrawerContext $context): array
    {
        $actions = $this->actions ?? $context->actionRegistry;

        if (! $actions) {
            return $context->actionMetadata;
        }

        $actionKeys = collect([
            ...$context->actionKeys,
            ...collect($drawer['footer_actions'] ?? [])
                ->map(fn (mixed $action): mixed => is_array($action) ? ($action['key'] ?? null) : $action)
                ->all(),
        ])
            ->filter(fn (mixed $key): bool => is_string($key) && $actions->has($key))
            ->unique()
            ->values();

        $resolved = [];

        foreach ($actionKeys as $actionKey) {
            $resolved[$actionKey] = $actions->resolve($actionKey, $context->toActionContext());
        }

        return array_replace_recursive($resolved, $context->actionMetadata);
    }

    private function resolveFromResourceMetadata(string $key, DrawerContext $context): ?array
    {
        $resources = $this->resources ?? $context->resourceRegistry;

        if (! $resources || ! filled($context->resourceKey) || ! $resources->has($context->resourceKey)) {
            return null;
        }

        try {
            $resource = $resources->resolve($context->resourceKey, $context->toResourceContext(), includeHidden: true);
        } catch (Throwable) {
            return null;
        }

        $drawer = $resource['drawer'] ?? null;

        if (! is_array($drawer) || ($drawer['target'] ?? null) !== $key) {
            return null;
        }

        return Drawer::make($key, $drawer['mode'] ?? DrawerState::MODE_INSPECT)
            ->resource($resource['type'], $resource['id'], $resource['key'])
            ->width($drawer['width'] ?? 'md')
            ->title($resource['title'] ?? null)
            ->subtitle($resource['subtitle'] ?? null)
            ->resourceMetadata($resource)
            ->metadata($drawer['metadata'] ?? [])
            ->resolve($context, $this);
    }

    private function emptyResponse(DrawerContext $context, string $message): array
    {
        return Drawer::make('__empty_drawer')
            ->state(DrawerState::empty($context->mode ?? DrawerState::MODE_INSPECT, $message))
            ->emptyState(['title' => 'No drawer selected', 'description' => $message])
            ->resolve($context, $this);
    }

    private function errorResponse(DrawerContext $context, string $message, string $key): array
    {
        return Drawer::make($key)
            ->state(DrawerState::error($context->mode ?? DrawerState::MODE_INSPECT, $message))
            ->errorState(['title' => 'Drawer unavailable', 'description' => $message])
            ->resolve($context, $this);
    }
}
