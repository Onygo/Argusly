<?php

namespace App\Support\Interaction;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;

final class ResourceContext
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
        public readonly string|int|null $resourceId = null,
        public readonly mixed $subject = null,
        public readonly array $permissions = [],
        public readonly array $metadata = [],
    ) {
    }

    public static function make(array $attributes = []): self
    {
        return new self(
            user: $attributes['user'] ?? null,
            surface: $attributes['surface'] ?? null,
            pageKey: $attributes['pageKey'] ?? $attributes['page_key'] ?? null,
            routeName: $attributes['routeName'] ?? $attributes['route_name'] ?? null,
            workspaceId: self::stringOrNull($attributes['workspaceId'] ?? $attributes['workspace_id'] ?? null),
            organizationId: self::stringOrNull($attributes['organizationId'] ?? $attributes['organization_id'] ?? null),
            siteId: self::stringOrNull($attributes['siteId'] ?? $attributes['site_id'] ?? null),
            resourceType: $attributes['resourceType'] ?? $attributes['resource_type'] ?? null,
            resourceId: $attributes['resourceId'] ?? $attributes['resource_id'] ?? null,
            subject: $attributes['subject'] ?? null,
            permissions: $attributes['permissions'] ?? [],
            metadata: $attributes['metadata'] ?? [],
        );
    }

    public function with(array $overrides): self
    {
        return self::make(array_merge($this->toArray(includeUser: true, includeSubject: true), $overrides));
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

    public function toActionContext(): ActionContext
    {
        return ActionContext::make($this->toArray(includeUser: true));
    }

    public function toArray(bool $includeUser = false, bool $includeSubject = false): array
    {
        $context = [
            'surface' => $this->surface,
            'page_key' => $this->pageKey,
            'route_name' => $this->routeName,
            'workspace_id' => $this->workspaceId,
            'organization_id' => $this->organizationId,
            'site_id' => $this->siteId,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'permissions' => $this->permissions,
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

        return $context;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
