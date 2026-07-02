<?php

namespace App\Support\Interaction;

use InvalidArgumentException;

final class Drawer
{
    private ?string $resourceType = null;
    private ?string $resourceKey = null;
    private string|int|null $resourceId = null;
    private bool $modal = false;
    private string $width = 'md';
    private mixed $title = null;
    private mixed $subtitle = null;
    private mixed $description = null;
    private array $tabs = [];
    private array $sections = [];
    private array $footerActions = [];
    private array $loadingState = [];
    private array $emptyState = [];
    private array $errorState = [];
    private ?string $focusReturnTarget = null;
    private array $keyboardEscape = [
        'enabled' => true,
        'closes_drawer' => true,
        'strategy' => 'close',
    ];
    private array $deepLink = [];
    private array $history = [];
    private array $ai = [];
    private array $resourceMetadata = [];
    private array $actionMetadata = [];
    private array $metadata = [];
    private DrawerState $state;

    public function __construct(
        private readonly string $key,
        private string $mode = DrawerState::MODE_INSPECT,
    ) {
        if ($key === '') {
            throw new InvalidArgumentException('Drawers require a non-empty key.');
        }

        $this->state = DrawerState::closed($mode);
    }

    public static function make(string $key, string $mode = DrawerState::MODE_INSPECT): self
    {
        return new self($key, $mode);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function resourceType(): ?string
    {
        return $this->resourceType;
    }

    public function resourceKey(): ?string
    {
        return $this->resourceKey;
    }

    public function resourceId(): string|int|null
    {
        return $this->resourceId;
    }

    public function mode(string $mode): self
    {
        $this->assertMode($mode);
        $this->mode = $mode;
        $this->state = $this->state->withMode($mode);

        return $this;
    }

    public function resource(string $type, string|int|null $id = null, ?string $key = null): self
    {
        $this->resourceType = $type;
        $this->resourceId = $id;
        $this->resourceKey = $key;

        return $this;
    }

    public function modal(bool $modal = true): self
    {
        $this->modal = $modal;

        return $this;
    }

    public function width(string $width): self
    {
        $this->width = $width;

        return $this;
    }

    public function title(string|callable|null $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function subtitle(string|callable|null $subtitle): self
    {
        $this->subtitle = $subtitle;

        return $this;
    }

    public function description(string|callable|null $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function tabs(array $tabs): self
    {
        $this->tabs = array_values($tabs);

        return $this;
    }

    public function sections(array $sections): self
    {
        $this->sections = array_values($sections);

        return $this;
    }

    public function footerActions(array $actions): self
    {
        $this->footerActions = array_values($actions);

        return $this;
    }

    public function loadingState(array $metadata): self
    {
        $this->loadingState = array_replace_recursive($this->loadingState, $metadata);

        return $this;
    }

    public function emptyState(array $metadata): self
    {
        $this->emptyState = array_replace_recursive($this->emptyState, $metadata);

        return $this;
    }

    public function errorState(array $metadata): self
    {
        $this->errorState = array_replace_recursive($this->errorState, $metadata);

        return $this;
    }

    public function focusReturnTarget(?string $target): self
    {
        $this->focusReturnTarget = $target;

        return $this;
    }

    public function keyboardEscape(bool $enabled = true, bool $closesDrawer = true, string $strategy = 'close'): self
    {
        $this->keyboardEscape = [
            'enabled' => $enabled,
            'closes_drawer' => $closesDrawer,
            'strategy' => $strategy,
        ];

        return $this;
    }

    public function deepLink(array $metadata): self
    {
        $this->deepLink = array_replace_recursive($this->deepLink, $metadata);

        return $this;
    }

    public function history(array $metadata): self
    {
        $this->history = array_replace_recursive($this->history, $metadata);

        return $this;
    }

    public function ai(array $metadata): self
    {
        $this->ai = array_replace_recursive($this->ai, $metadata);

        return $this;
    }

    public function resourceMetadata(array $metadata): self
    {
        $this->resourceMetadata = array_replace_recursive($this->resourceMetadata, $metadata);

        return $this;
    }

    public function actionMetadata(array $metadata): self
    {
        $this->actionMetadata = array_replace_recursive($this->actionMetadata, $metadata);

        return $this;
    }

    public function metadata(array $metadata): self
    {
        $this->metadata = array_replace_recursive($this->metadata, $metadata);

        return $this;
    }

    public function state(DrawerState $state): self
    {
        $this->state = $state;
        $this->mode = $state->mode;

        return $this;
    }

    public function resolve(DrawerContext $context, ?DrawerResolver $resolver = null): array
    {
        $resolver ??= new DrawerResolver();
        $mode = $context->mode ?? $this->mode;
        $state = $this->state->withMode($mode);

        return [
            'key' => $this->key,
            'resource_type' => $this->resourceType ?? $context->resourceType,
            'resource_key' => $this->resourceKey ?? $context->resourceKey,
            'resource_id' => $this->resourceId ?? $context->resourceId,
            'mode' => $mode,
            'modal' => $this->modal,
            'width' => $this->width,
            'title' => $this->resolveString($this->title, $context, $resolver),
            'subtitle' => $this->resolveString($this->subtitle, $context, $resolver),
            'description' => $this->resolveString($this->description, $context, $resolver),
            'tabs' => $this->tabs,
            'sections' => $this->sections,
            'footer_actions' => $this->footerActions,
            'loading_state' => $this->loadingState,
            'empty_state' => $this->emptyState,
            'error_state' => $this->errorState,
            'focus_return_target' => $this->focusReturnTarget,
            'keyboard_escape' => $this->keyboardEscape,
            'deep_link' => $this->deepLink,
            'history' => $this->history,
            'ai' => $this->ai,
            'resource_metadata' => array_replace_recursive($this->resourceMetadata, $context->resourceMetadata),
            'action_metadata' => array_replace_recursive($this->actionMetadata, $context->actionMetadata),
            'resolved_resource' => null,
            'resolved_actions' => [],
            'state' => $state->toArray(),
            'metadata' => $this->metadata,
        ];
    }

    private function resolveString(mixed $value, DrawerContext $context, DrawerResolver $resolver): ?string
    {
        $resolved = $resolver->evaluate($value, $context, $this);

        return $resolved === null ? null : (string) $resolved;
    }

    private function assertMode(string $mode): void
    {
        if (! in_array($mode, DrawerState::MODES, true)) {
            throw new InvalidArgumentException(sprintf('Drawer mode [%s] is not supported.', $mode));
        }
    }
}
