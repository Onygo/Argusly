<?php

namespace App\Support\Interaction;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class DrawerMetadataBuilder
{
    public function __construct(
        private readonly ?DrawerHistoryAdapter $history = null,
    ) {
    }

    public static function make(): self
    {
        return new self();
    }

    public function forResource(array $resource, array $overrides = []): DrawerDescriptor
    {
        return $this->build(DrawerTarget::fromResource($resource), array_merge($overrides, [
            'resource' => $resource,
        ]));
    }

    public function forAction(array $action, array $overrides = []): DrawerDescriptor
    {
        return $this->build(DrawerTarget::fromAction($action), array_merge($overrides, [
            'action' => $action,
        ]));
    }

    public function build(DrawerTarget $target, array $options = []): DrawerDescriptor
    {
        $resource = $options['resource'] ?? [];
        $action = $options['action'] ?? [];
        $drawer = $options['drawer'] ?? [];
        $history = ($this->history ?? new DrawerHistoryAdapter())->metadata($target, $options['history'] ?? []);

        return new DrawerDescriptor(
            target: $target,
            href: $history['fallback_url'],
            drawerUrl: $history['drawer_url'],
            title: $this->stringValue($options['title'] ?? $drawer['title'] ?? $resource['title'] ?? $action['label'] ?? null)
                ?? Str::headline(str_replace(['.', '-', '_'], ' ', $target->target)),
            subtitle: $this->stringValue($options['subtitle'] ?? $drawer['subtitle'] ?? $resource['subtitle'] ?? $action['description'] ?? $target->resourceType),
            icon: $this->stringValue($options['icon'] ?? $resource['icon'] ?? $action['icon'] ?? 'panel-right-open'),
            status: $options['status'] ?? $resource['status'] ?? null,
            badges: $this->badges($options, $resource),
            tabs: $options['tabs'] ?? $drawer['tabs'] ?? $this->defaultTabs($resource),
            sections: $options['sections'] ?? $drawer['sections'] ?? $this->defaultSections($resource, $action),
            footerActions: $options['footer_actions'] ?? $drawer['footer_actions'] ?? $this->defaultFooterActions($target, $action),
            permissions: $this->permissions($options, $resource, $action),
            preview: array_replace_recursive($resource['preview'] ?? [], $options['preview'] ?? []),
            ai: array_replace_recursive($resource['ai'] ?? [], $action['ai'] ?? [], $drawer['ai'] ?? [], $options['ai'] ?? []),
            relationships: $options['relationships'] ?? $resource['relationships'] ?? [],
            history: $history,
            loading: $options['loading'] ?? $drawer['loading_state'] ?? $this->defaultLoading($target),
            empty: $options['empty'] ?? $drawer['empty_state'] ?? $this->defaultEmpty($target),
            errors: $options['errors'] ?? $drawer['error_state'] ?? $this->defaultErrors($target),
            resource: $resource,
            action: $action,
            drawer: $drawer,
            metadata: array_replace_recursive($target->metadata, $drawer['metadata'] ?? [], $options['metadata'] ?? []),
        );
    }

    private function defaultTabs(array $resource): array
    {
        $tabs = [
            ['key' => 'overview', 'label' => 'Overview'],
        ];

        if (($resource['relationships'] ?? []) !== []) {
            $tabs[] = ['key' => 'relationships', 'label' => 'Relationships'];
        }

        if (($resource['history'] ?? []) !== []) {
            $tabs[] = ['key' => 'history', 'label' => 'History'];
        }

        return $tabs;
    }

    private function defaultSections(array $resource, array $action): array
    {
        $items = array_values(array_filter([
            ['label' => 'Type', 'value' => $resource['type'] ?? Arr::get($action, 'resource.type')],
            ['label' => 'Status', 'value' => $this->statusLabel($resource['status'] ?? null)],
        ], fn (array $item): bool => filled($item['value'])));

        return [[
            'key' => 'overview',
            'title' => 'Overview',
            'items' => $items,
        ]];
    }

    private function defaultFooterActions(DrawerTarget $target, array $action): array
    {
        $label = $action['label'] ?? 'Open full page';

        return [[
            'key' => $target->actionKey ?? 'drawer.fallback.open',
            'label' => $label,
            'href' => $target->fallbackUrl(),
            'execution_mode' => Action::EXECUTION_LINK,
            'method' => 'GET',
        ]];
    }

    private function permissions(array $options, array $resource, array $action): array
    {
        return array_replace_recursive(
            $resource['permissions'] ?? [],
            array_filter([
                'authorized' => $action === [] ? null : (bool) ($action['authorized'] ?? true),
                'visible' => $action === [] ? null : (bool) ($action['visible'] ?? true),
                'disabled' => $action === [] ? null : (bool) ($action['disabled'] ?? false),
                'disabled_reason' => $action['disabled_reason'] ?? null,
            ], fn (mixed $value): bool => $value !== null),
            $options['permissions'] ?? [],
        );
    }

    private function badges(array $options, array $resource): array
    {
        if (isset($options['badges'])) {
            return $options['badges'];
        }

        $status = $resource['status'] ?? null;

        if (! is_array($status) || ! filled($status['label'] ?? null)) {
            return [];
        }

        return [[
            'label' => $status['label'],
            'tone' => $status['tone'] ?? 'neutral',
        ]];
    }

    private function defaultLoading(DrawerTarget $target): array
    {
        return [
            'title' => 'Loading detail',
            'description' => sprintf('Loading %s metadata.', $target->resourceType ?? 'drawer'),
        ];
    }

    private function defaultEmpty(DrawerTarget $target): array
    {
        return [
            'title' => 'No detail selected',
            'description' => sprintf('Select a %s to inspect it here.', $target->resourceType ?? 'resource'),
        ];
    }

    private function defaultErrors(DrawerTarget $target): array
    {
        return [
            'title' => 'Detail unavailable',
            'description' => sprintf('The %s drawer could not be prepared.', $target->target),
        ];
    }

    private function statusLabel(mixed $status): ?string
    {
        if (is_array($status)) {
            return $status['label'] ?? null;
        }

        return $status === null ? null : (string) $status;
    }

    private function stringValue(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
