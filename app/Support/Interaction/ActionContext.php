<?php

namespace App\Support\Interaction;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;

final class ActionContext
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
        public readonly array $selectedIds = [],
        public readonly string $selectionMode = 'none',
        public readonly array $filters = [],
        public readonly array $sort = [],
        public readonly array $permissions = [],
        public readonly array $drawer = [],
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
            selectedIds: array_values($attributes['selectedIds'] ?? $attributes['selected_ids'] ?? []),
            selectionMode: $attributes['selectionMode'] ?? $attributes['selection_mode'] ?? 'none',
            filters: $attributes['filters'] ?? [],
            sort: $attributes['sort'] ?? [],
            permissions: $attributes['permissions'] ?? [],
            drawer: $attributes['drawer'] ?? [],
            metadata: $attributes['metadata'] ?? [],
        );
    }

    public function with(array $overrides): self
    {
        return self::make(array_merge($this->toArray(includeUser: true), $overrides));
    }

    public function forSurface(string $surface): self
    {
        return $this->with(['surface' => $surface]);
    }

    public function withSelection(array $selectedIds, string $selectionMode = 'selected'): self
    {
        return $this->with([
            'selected_ids' => array_values($selectedIds),
            'selection_mode' => $selectedIds === [] ? 'none' : $selectionMode,
        ]);
    }

    public function hasSelection(): bool
    {
        return $this->selectedIds !== [];
    }

    public function selectedCount(): int
    {
        return count($this->selectedIds);
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

    public function toArray(bool $includeUser = false): array
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
            'selected_ids' => $this->selectedIds,
            'selected_count' => $this->selectedCount(),
            'selection_mode' => $this->selectionMode,
            'filters' => $this->filters,
            'sort' => $this->sort,
            'permissions' => $this->permissions,
            'drawer' => $this->drawer,
            'metadata' => $this->metadata,
        ];

        if ($includeUser) {
            $context['user'] = $this->user;
        } elseif ($this->user) {
            $context['user_id'] = $this->user->getAuthIdentifier();
        }

        return $context;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
