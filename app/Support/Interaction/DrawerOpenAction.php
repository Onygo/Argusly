<?php

namespace App\Support\Interaction;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final class DrawerOpenAction implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly DrawerTarget $target,
        public readonly string $href,
        public readonly ?string $icon = null,
        public readonly bool $authorized = true,
        public readonly bool $visible = true,
        public readonly bool $disabled = false,
        public readonly ?string $disabledReason = null,
        public readonly array $history = [],
        public readonly array $ai = [],
        public readonly array $metadata = [],
    ) {
    }

    public static function fromDescriptor(DrawerDescriptor $descriptor, ?string $label = null): self
    {
        return new self(
            key: $descriptor->target->actionKey ?? 'drawer.open.'.$descriptor->target->target,
            label: $label ?? $descriptor->title ?? 'Open detail',
            target: $descriptor->target,
            href: $descriptor->href,
            icon: $descriptor->icon,
            authorized: (bool) ($descriptor->permissions['view'] ?? $descriptor->permissions['open'] ?? true),
            history: $descriptor->history,
            ai: $descriptor->ai,
            metadata: ['descriptor' => $descriptor->toArray()],
        );
    }

    public static function fromAction(array $action, DrawerDescriptor $descriptor): self
    {
        return new self(
            key: (string) $action['key'],
            label: (string) ($action['label'] ?? $descriptor->title ?? 'Open detail'),
            target: $descriptor->target,
            href: $descriptor->href,
            icon: $action['icon'] ?? $descriptor->icon,
            authorized: (bool) ($action['authorized'] ?? true),
            visible: (bool) ($action['visible'] ?? true),
            disabled: (bool) ($action['disabled'] ?? false),
            disabledReason: $action['disabled_reason'] ?? null,
            history: array_replace_recursive($descriptor->history, $action['history'] ?? []),
            ai: array_replace_recursive($descriptor->ai, $action['ai'] ?? []),
            metadata: array_replace_recursive($action['metadata'] ?? [], ['descriptor' => $descriptor->toArray()]),
        );
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'verb' => 'open',
            'icon' => $this->icon,
            'execution_mode' => Action::EXECUTION_DRAWER,
            'method' => 'GET',
            'url' => $this->href,
            'drawer' => [
                'target' => $this->target->target,
                'mode' => $this->target->mode,
                'width' => $this->target->width,
                'modal' => $this->target->modal,
            ],
            'resource' => [
                'type' => $this->target->resourceType,
                'id' => $this->target->resourceId,
                'key' => $this->target->resourceKey,
            ],
            'authorized' => $this->authorized,
            'visible' => $this->visible,
            'disabled' => $this->disabled,
            'disabled_reason' => $this->disabledReason,
            'history' => $this->history,
            'ai' => $this->ai,
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
