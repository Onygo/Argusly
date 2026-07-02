<?php

namespace App\Support\Interaction;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use JsonSerializable;

final class DrawerTarget implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $target,
        public readonly string $mode = DrawerState::MODE_INSPECT,
        public readonly string $width = 'md',
        public readonly bool $modal = false,
        public readonly ?string $resourceType = null,
        public readonly ?string $resourceKey = null,
        public readonly string|int|null $resourceId = null,
        public readonly ?string $actionKey = null,
        public readonly ?string $source = null,
        public readonly ?string $href = null,
        public readonly ?string $routeName = null,
        public readonly array $routeParameters = [],
        public readonly array $metadata = [],
    ) {
        if ($target === '') {
            throw new InvalidArgumentException('Drawer targets require a non-empty target key.');
        }

        if (! in_array($mode, DrawerState::MODES, true)) {
            throw new InvalidArgumentException(sprintf('Drawer mode [%s] is not supported.', $mode));
        }
    }

    public static function make(
        string $target,
        string $mode = DrawerState::MODE_INSPECT,
        string $width = 'md',
        bool $modal = false,
    ): self {
        return new self(target: $target, mode: $mode, width: $width, modal: $modal);
    }

    public static function fromResource(array $resource, ?string $href = null): self
    {
        $drawer = $resource['drawer'] ?? [];

        return new self(
            target: (string) ($drawer['target'] ?? $resource['key']),
            mode: (string) ($drawer['mode'] ?? DrawerState::MODE_INSPECT),
            width: (string) ($drawer['width'] ?? 'md'),
            modal: (bool) ($drawer['modal'] ?? false),
            resourceType: $resource['type'] ?? null,
            resourceKey: $resource['key'] ?? null,
            resourceId: $resource['id'] ?? null,
            source: 'resource',
            href: $href ?? $resource['primary_url'] ?? null,
            metadata: $drawer['metadata'] ?? [],
        );
    }

    public static function fromAction(array $action, ?string $href = null): self
    {
        $drawer = $action['drawer'] ?? [];
        $resource = $action['resource'] ?? [];

        return new self(
            target: (string) ($drawer['target'] ?? $action['key']),
            mode: (string) ($drawer['mode'] ?? DrawerState::MODE_INSPECT),
            width: (string) ($drawer['width'] ?? 'md'),
            modal: (bool) ($drawer['modal'] ?? false),
            resourceType: $resource['type'] ?? null,
            resourceId: $resource['id'] ?? null,
            actionKey: $action['key'] ?? null,
            source: 'action',
            href: $href ?? $action['url'] ?? null,
        );
    }

    public function forResource(?string $type, string|int|null $id = null, ?string $key = null): self
    {
        return new self(
            target: $this->target,
            mode: $this->mode,
            width: $this->width,
            modal: $this->modal,
            resourceType: $type,
            resourceKey: $key,
            resourceId: $id,
            actionKey: $this->actionKey,
            source: $this->source,
            href: $this->href,
            routeName: $this->routeName,
            routeParameters: $this->routeParameters,
            metadata: $this->metadata,
        );
    }

    public function forAction(?string $key): self
    {
        return new self(
            target: $this->target,
            mode: $this->mode,
            width: $this->width,
            modal: $this->modal,
            resourceType: $this->resourceType,
            resourceKey: $this->resourceKey,
            resourceId: $this->resourceId,
            actionKey: $key,
            source: $this->source,
            href: $this->href,
            routeName: $this->routeName,
            routeParameters: $this->routeParameters,
            metadata: $this->metadata,
        );
    }

    public function withHref(?string $href): self
    {
        return new self(
            target: $this->target,
            mode: $this->mode,
            width: $this->width,
            modal: $this->modal,
            resourceType: $this->resourceType,
            resourceKey: $this->resourceKey,
            resourceId: $this->resourceId,
            actionKey: $this->actionKey,
            source: $this->source,
            href: $href,
            routeName: $this->routeName,
            routeParameters: $this->routeParameters,
            metadata: $this->metadata,
        );
    }

    public function withRouteFallback(string $routeName, array $parameters = []): self
    {
        return new self(
            target: $this->target,
            mode: $this->mode,
            width: $this->width,
            modal: $this->modal,
            resourceType: $this->resourceType,
            resourceKey: $this->resourceKey,
            resourceId: $this->resourceId,
            actionKey: $this->actionKey,
            source: $this->source,
            href: $this->href,
            routeName: $routeName,
            routeParameters: $parameters,
            metadata: $this->metadata,
        );
    }

    public function fallbackUrl(?string $default = '#'): string
    {
        if (filled($this->href)) {
            return (string) $this->href;
        }

        if (filled($this->routeName) && Route::has($this->routeName)) {
            return route($this->routeName, $this->routeParameters);
        }

        return $default ?? '#';
    }

    public function toContext(array $overrides = []): DrawerContext
    {
        return DrawerContext::make(array_merge([
            'resource_type' => $this->resourceType,
            'resource_key' => $this->resourceKey,
            'resource_id' => $this->resourceId,
            'action_key' => $this->actionKey,
            'mode' => $this->mode,
            'metadata' => ['drawer_target' => $this->toArray()],
        ], $overrides));
    }

    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'mode' => $this->mode,
            'width' => $this->width,
            'modal' => $this->modal,
            'resource_type' => $this->resourceType,
            'resource_key' => $this->resourceKey,
            'resource_id' => $this->resourceId,
            'action_key' => $this->actionKey,
            'source' => $this->source,
            'href' => $this->href,
            'route' => $this->routeName === null ? null : [
                'name' => $this->routeName,
                'parameters' => $this->routeParameters,
                'exists' => Route::has($this->routeName),
            ],
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
