<?php

namespace App\Support\Interaction;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

final class Action
{
    public const EXECUTION_LINK = 'link';
    public const EXECUTION_FORM = 'form';
    public const EXECUTION_ASYNC = 'async';
    public const EXECUTION_QUEUED = 'queued';
    public const EXECUTION_DRAWER = 'drawer';
    public const EXECUTION_LOCAL = 'local';

    public const SURFACE_TOOLBAR = 'toolbar';
    public const SURFACE_ROW = 'row';
    public const SURFACE_BULK = 'bulk';
    public const SURFACE_CONTEXT_MENU = 'context_menu';
    public const SURFACE_COMMAND_PALETTE = 'command_palette';
    public const SURFACE_DRAWER = 'drawer';
    public const SURFACE_QUICK = 'quick';
    public const SURFACE_NOTIFICATION = 'notification';
    public const SURFACE_SHORTCUT = 'shortcut';
    public const SURFACE_AI_RECOMMENDATION = 'ai_recommendation';

    private ?string $description = null;
    private ?string $icon = null;
    private ?string $shortcut = null;
    private string $executionMode = self::EXECUTION_LINK;
    private string $method = 'GET';
    private ?array $route = null;
    private mixed $url = null;
    private ?array $form = null;
    private ?array $policy = null;
    private mixed $authorizationCallback = null;
    private mixed $visibilityCallback = null;
    private mixed $disabledCallback = null;
    private ?string $disabledReason = null;
    private ?array $confirmation = null;
    private ?array $bulk = null;
    private ?array $drawer = null;
    private ?array $resource = null;
    private array $surfaces = [];
    private array $scopes = [];
    private array $history = [];
    private array $ai = [];
    private array $metadata = [];

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $verb,
    ) {
        if ($key === '' || $label === '' || $verb === '') {
            throw new InvalidArgumentException('Actions require a non-empty key, label, and verb.');
        }
    }

    public static function make(string $key, string $label, string $verb = 'navigate'): self
    {
        return new self($key, $label, $verb);
    }

    public function description(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function icon(?string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function shortcut(?string $shortcut): self
    {
        $this->shortcut = $shortcut;

        return $this;
    }

    public function executesAs(string $executionMode): self
    {
        $this->executionMode = $executionMode;

        return $this;
    }

    public function route(string $name, array|callable $parameters = [], string $method = 'GET'): self
    {
        $this->route = [
            'name' => $name,
            'parameters' => $parameters,
        ];
        $this->method = strtoupper($method);
        $this->executionMode = $this->method === 'GET'
            ? self::EXECUTION_LINK
            : self::EXECUTION_FORM;

        return $this;
    }

    public function url(string|callable $url, string $method = 'GET'): self
    {
        $this->url = $url;
        $this->method = strtoupper($method);
        $this->executionMode = $this->method === 'GET'
            ? self::EXECUTION_LINK
            : self::EXECUTION_FORM;

        return $this;
    }

    public function form(string $formId, ?string $routeName = null, array|callable $parameters = [], string $method = 'POST'): self
    {
        $this->form = [
            'id' => $formId,
        ];

        if ($routeName !== null) {
            $this->route($routeName, $parameters, $method);
        } else {
            $this->method = strtoupper($method);
            $this->executionMode = self::EXECUTION_FORM;
        }

        return $this;
    }

    public function policy(string $ability, mixed $target = null, array|callable $arguments = []): self
    {
        $this->policy = [
            'ability' => $ability,
            'target' => $target,
            'arguments' => $arguments,
        ];

        return $this;
    }

    public function authorize(callable $callback): self
    {
        $this->authorizationCallback = $callback;

        return $this;
    }

    public function visibleWhen(callable|bool $condition): self
    {
        $this->visibilityCallback = $condition;

        return $this;
    }

    public function disabledWhen(callable|bool $condition, string|callable $reason): self
    {
        $this->disabledCallback = function (ActionContext $context, self $action, ActionPolicyResolver $resolver) use ($condition, $reason): ?string {
            $isDisabled = is_callable($condition)
                ? (bool) $condition($context, $action, $resolver)
                : $condition;

            if (! $isDisabled) {
                return null;
            }

            return is_callable($reason)
                ? (string) $reason($context, $action, $resolver)
                : $reason;
        };

        return $this;
    }

    public function disabledReason(?string $reason): self
    {
        $this->disabledReason = $reason;

        return $this;
    }

    public function confirm(
        string $title,
        string $message,
        string $severity = 'medium',
        ?string $confirmLabel = null,
        ?string $typedPhrase = null,
    ): self {
        $this->confirmation = array_filter([
            'title' => $title,
            'message' => $message,
            'severity' => $severity,
            'confirm_label' => $confirmLabel,
            'typed_phrase' => $typedPhrase,
        ], fn (mixed $value): bool => $value !== null);

        return $this;
    }

    public function bulk(
        string $selectionMode = 'selected',
        ?string $resourceType = null,
        bool $supportsAllMatching = false,
        ?int $maxSelected = null,
        ?string $eligibility = null,
    ): self {
        $this->bulk = array_filter([
            'selection_mode' => $selectionMode,
            'resource_type' => $resourceType,
            'supports_all_matching' => $supportsAllMatching,
            'max_selected' => $maxSelected,
            'eligibility' => $eligibility,
        ], fn (mixed $value): bool => $value !== null);

        return $this->visibleIn(self::SURFACE_BULK);
    }

    public function drawer(string $target, string $mode = 'inspect', string $width = 'md', bool $modal = false): self
    {
        $this->drawer = [
            'target' => $target,
            'mode' => $mode,
            'width' => $width,
            'modal' => $modal,
        ];

        return $this;
    }

    public function resource(string $type, string|int|null $id = null): self
    {
        $this->resource = [
            'type' => $type,
            'id' => $id,
        ];

        return $this;
    }

    public function visibleIn(string ...$surfaces): self
    {
        $this->surfaces = array_values(array_unique([...$this->surfaces, ...$surfaces]));

        return $this;
    }

    public function scopes(string ...$scopes): self
    {
        $this->scopes = array_values(array_unique([...$this->scopes, ...$scopes]));

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

    public function metadata(array $metadata): self
    {
        $this->metadata = array_replace_recursive($this->metadata, $metadata);

        return $this;
    }

    public function resolve(ActionContext $context, ?ActionPolicyResolver $resolver = null): array
    {
        $resolver ??= new ActionPolicyResolver();

        $authorized = $resolver->can($this, $context);
        $visible = $authorized
            && $this->isVisibleOnSurface($context->surface)
            && $this->passesVisibility($context, $resolver);
        $disabledReason = $authorized ? $this->resolveDisabledReason($context, $resolver) : null;

        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'verb' => $this->verb,
            'icon' => $this->icon,
            'shortcut' => $this->shortcut,
            'execution_mode' => $this->executionMode,
            'method' => $this->method,
            'route' => $this->resolveRoute($context, $resolver),
            'url' => $this->resolveUrl($context, $resolver),
            'form' => $this->form,
            'resource' => $this->resolveResource($context, $resolver),
            'surfaces' => $this->surfaces,
            'scopes' => $this->scopes,
            'authorized' => $authorized,
            'visible' => $visible,
            'disabled' => $disabledReason !== null,
            'disabled_reason' => $disabledReason,
            'confirmation' => $this->confirmation,
            'bulk' => $this->bulk,
            'drawer' => $this->drawer,
            'history' => $this->history,
            'ai' => $this->ai,
            'metadata' => $this->metadata,
        ];
    }

    public function policyDefinition(): ?array
    {
        return $this->policy;
    }

    public function authorizationCallback(): mixed
    {
        return $this->authorizationCallback;
    }

    public function supportsBulk(): bool
    {
        return $this->bulk !== null;
    }

    public function supportsDrawer(): bool
    {
        return $this->drawer !== null;
    }

    public function supportsSurface(?string $surface): bool
    {
        return $this->isVisibleOnSurface($surface);
    }

    public function routeExists(): bool
    {
        return $this->route === null || Route::has($this->route['name']);
    }

    public function mapsToExistingEndpoint(): bool
    {
        return $this->routeExists()
            && ($this->route !== null || $this->form !== null || $this->policy !== null || $this->url !== null || $this->drawer !== null);
    }

    private function passesVisibility(ActionContext $context, ActionPolicyResolver $resolver): bool
    {
        if ($this->visibilityCallback === null) {
            return true;
        }

        if (is_callable($this->visibilityCallback)) {
            $callback = $this->visibilityCallback;

            return (bool) $callback($context, $this, $resolver);
        }

        return (bool) $this->visibilityCallback;
    }

    private function resolveDisabledReason(ActionContext $context, ActionPolicyResolver $resolver): ?string
    {
        if ($this->disabledCallback !== null) {
            $reason = $resolver->evaluate($this->disabledCallback, $context, $this);

            return $reason === null ? null : (string) $reason;
        }

        return $this->disabledReason;
    }

    private function isVisibleOnSurface(?string $surface): bool
    {
        return $surface === null || $this->surfaces === [] || in_array($surface, $this->surfaces, true);
    }

    private function resolveRoute(ActionContext $context, ActionPolicyResolver $resolver): ?array
    {
        if ($this->route === null) {
            return null;
        }

        $parameters = $resolver->evaluate($this->route['parameters'], $context, $this);
        $parameters = is_array($parameters) ? $parameters : [$parameters];

        return [
            'name' => $this->route['name'],
            'parameters' => $parameters,
            'exists' => Route::has($this->route['name']),
        ];
    }

    private function resolveUrl(ActionContext $context, ActionPolicyResolver $resolver): ?string
    {
        if ($this->url !== null) {
            return (string) $resolver->evaluate($this->url, $context, $this);
        }

        if ($this->route === null || ! Route::has($this->route['name'])) {
            return null;
        }

        $parameters = $resolver->evaluate($this->route['parameters'], $context, $this);

        return route($this->route['name'], is_array($parameters) ? $parameters : [$parameters]);
    }

    private function resolveResource(ActionContext $context, ActionPolicyResolver $resolver): ?array
    {
        if ($this->resource === null) {
            return $context->resourceType === null
                ? null
                : ['type' => $context->resourceType, 'id' => $context->resourceId];
        }

        return [
            'type' => $this->resource['type'],
            'id' => $resolver->evaluate(Arr::get($this->resource, 'id'), $context, $this),
        ];
    }
}
