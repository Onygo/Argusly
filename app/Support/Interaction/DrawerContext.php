<?php

namespace App\Support\Interaction;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;

final class DrawerContext
{
    public function __construct(
        public readonly ?Authenticatable $user = null,
        public readonly ?string $surface = null,
        public readonly ?string $pageKey = null,
        public readonly ?string $routeName = null,
        public readonly ?string $workspaceId = null,
        public readonly ?string $organizationId = null,
        public readonly ?string $siteId = null,
        public readonly ?string $resourceType = null,
        public readonly ?string $resourceKey = null,
        public readonly string|int|null $resourceId = null,
        public readonly ?string $actionKey = null,
        public readonly array $actionKeys = [],
        public readonly ?string $mode = null,
        public readonly mixed $subject = null,
        public readonly ?ResourceRegistry $resourceRegistry = null,
        public readonly ?ActionRegistry $actionRegistry = null,
        public readonly array $permissions = [],
        public readonly array $resourceMetadata = [],
        public readonly array $actionMetadata = [],
        public readonly array $metadata = [],
    ) {
    }

    public static function make(array $attributes = []): self
    {
        $actionKeys = $attributes['actionKeys'] ?? $attributes['action_keys'] ?? [];

        if (isset($attributes['actionKey']) || isset($attributes['action_key'])) {
            $actionKeys = array_values(array_unique([
                $attributes['actionKey'] ?? $attributes['action_key'],
                ...$actionKeys,
            ]));
        }

        return new self(
            user: $attributes['user'] ?? null,
            surface: $attributes['surface'] ?? null,
            pageKey: $attributes['pageKey'] ?? $attributes['page_key'] ?? null,
            routeName: $attributes['routeName'] ?? $attributes['route_name'] ?? null,
            workspaceId: self::stringOrNull($attributes['workspaceId'] ?? $attributes['workspace_id'] ?? null),
            organizationId: self::stringOrNull($attributes['organizationId'] ?? $attributes['organization_id'] ?? null),
            siteId: self::stringOrNull($attributes['siteId'] ?? $attributes['site_id'] ?? null),
            resourceType: $attributes['resourceType'] ?? $attributes['resource_type'] ?? null,
            resourceKey: $attributes['resourceKey'] ?? $attributes['resource_key'] ?? null,
            resourceId: $attributes['resourceId'] ?? $attributes['resource_id'] ?? null,
            actionKey: $attributes['actionKey'] ?? $attributes['action_key'] ?? null,
            actionKeys: array_values(array_filter($actionKeys, fn (mixed $key): bool => filled($key))),
            mode: $attributes['mode'] ?? null,
            subject: $attributes['subject'] ?? null,
            resourceRegistry: $attributes['resourceRegistry'] ?? $attributes['resource_registry'] ?? null,
            actionRegistry: $attributes['actionRegistry'] ?? $attributes['action_registry'] ?? null,
            permissions: $attributes['permissions'] ?? [],
            resourceMetadata: $attributes['resourceMetadata'] ?? $attributes['resource_metadata'] ?? [],
            actionMetadata: $attributes['actionMetadata'] ?? $attributes['action_metadata'] ?? [],
            metadata: $attributes['metadata'] ?? [],
        );
    }

    public function with(array $overrides): self
    {
        return self::make(array_merge($this->toArray(includeUser: true, includeSubject: true, includeRegistries: true), $overrides));
    }

    public function forMode(string $mode): self
    {
        return $this->with(['mode' => $mode]);
    }

    public function forSurface(string $surface): self
    {
        return $this->with(['surface' => $surface]);
    }

    public function permission(string $key, bool $default = false): bool
    {
        return (bool) Arr::get($this->permissions, $key, $default);
    }

    public function metadata(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->metadata;
        }

        return Arr::get($this->metadata, $key, $default);
    }

    public function toResourceContext(): ResourceContext
    {
        return ResourceContext::make([
            ...$this->toArray(includeUser: true, includeSubject: true),
            'surface' => $this->surface ?? Action::SURFACE_DRAWER,
        ]);
    }

    public function toActionContext(): ActionContext
    {
        return ActionContext::make([
            ...$this->toArray(includeUser: true),
            'surface' => $this->surface ?? Action::SURFACE_DRAWER,
            'drawer' => [
                'mode' => $this->mode,
                'resource_key' => $this->resourceKey,
            ],
        ]);
    }

    public function toArray(bool $includeUser = false, bool $includeSubject = false, bool $includeRegistries = false): array
    {
        $context = [
            'surface' => $this->surface,
            'page_key' => $this->pageKey,
            'route_name' => $this->routeName,
            'workspace_id' => $this->workspaceId,
            'organization_id' => $this->organizationId,
            'site_id' => $this->siteId,
            'resource_type' => $this->resourceType,
            'resource_key' => $this->resourceKey,
            'resource_id' => $this->resourceId,
            'action_key' => $this->actionKey,
            'action_keys' => $this->actionKeys,
            'mode' => $this->mode,
            'permissions' => $this->permissions,
            'resource_metadata' => $this->resourceMetadata,
            'action_metadata' => $this->actionMetadata,
            'metadata' => $this->metadata,
        ];

        if ($includeUser) {
            $context['user'] = $this->user;
        } elseif ($this->user) {
            $context['user_id'] = $this->user->getAuthIdentifier();
        }

        if ($includeSubject) {
            $context['subject'] = $this->subject;
        }

        if ($includeRegistries) {
            $context['resource_registry'] = $this->resourceRegistry;
            $context['action_registry'] = $this->actionRegistry;
        }

        return $context;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
