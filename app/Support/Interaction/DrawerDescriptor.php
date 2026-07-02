<?php

namespace App\Support\Interaction;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final class DrawerDescriptor implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly DrawerTarget $target,
        public readonly string $href,
        public readonly string $drawerUrl,
        public readonly ?string $title = null,
        public readonly ?string $subtitle = null,
        public readonly ?string $icon = null,
        public readonly mixed $status = null,
        public readonly array $badges = [],
        public readonly array $tabs = [],
        public readonly array $sections = [],
        public readonly array $footerActions = [],
        public readonly array $permissions = [],
        public readonly array $preview = [],
        public readonly array $ai = [],
        public readonly array $relationships = [],
        public readonly array $history = [],
        public readonly array $loading = [],
        public readonly array $empty = [],
        public readonly array $errors = [],
        public readonly array $resource = [],
        public readonly array $action = [],
        public readonly array $drawer = [],
        public readonly array $metadata = [],
    ) {
    }

    public function openAction(?string $label = null): DrawerOpenAction
    {
        return DrawerOpenAction::fromDescriptor($this, $label);
    }

    public function dataAttributes(): array
    {
        return array_filter([
            'data-drawer-trigger-ready' => 'true',
            'data-drawer-target' => $this->target->target,
            'data-drawer-mode' => $this->target->mode,
            'data-drawer-width' => $this->target->width,
            'data-drawer-modal' => $this->target->modal ? 'true' : 'false',
            'data-drawer-url' => $this->drawerUrl,
            'data-drawer-resource-type' => $this->target->resourceType,
            'data-drawer-resource-key' => $this->target->resourceKey,
            'data-drawer-resource-id' => $this->target->resourceId === null ? null : (string) $this->target->resourceId,
            'data-drawer-action-key' => $this->target->actionKey,
            'data-progressive-enhancement' => 'true',
            'data-command-palette-ready' => 'true',
            'data-context-menu-ready' => 'true',
            'data-hover-preview-ready' => 'true',
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function toArray(): array
    {
        return [
            'target' => $this->target->toArray(),
            'href' => $this->href,
            'drawer_url' => $this->drawerUrl,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'icon' => $this->icon,
            'status' => $this->status,
            'badges' => $this->badges,
            'tabs' => $this->tabs,
            'sections' => $this->sections,
            'footer_actions' => $this->footerActions,
            'permissions' => $this->permissions,
            'preview' => $this->preview,
            'ai' => $this->ai,
            'relationships' => $this->relationships,
            'history' => $this->history,
            'loading' => $this->loading,
            'empty' => $this->empty,
            'errors' => $this->errors,
            'resource' => $this->resource,
            'action' => $this->action,
            'drawer' => $this->drawer,
            'metadata' => $this->metadata,
            'data_attributes' => $this->dataAttributes(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
