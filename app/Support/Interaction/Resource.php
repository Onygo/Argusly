<?php

namespace App\Support\Interaction;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

final class Resource
{
    private mixed $title = null;
    private mixed $subtitle = null;
    private mixed $status = null;
    private mixed $icon = null;
    private ?array $primaryRoute = null;
    private mixed $primaryUrl = null;
    private ?array $drawer = null;
    private ?array $policy = null;
    private mixed $authorizationCallback = null;
    private mixed $visibilityCallback = null;
    private array $availableActions = [];
    private array $permissions = [];
    private array $relationships = [];
    private array $preview = [];
    private array $history = [];
    private array $ai = [];
    private array $search = [];
    private array $notification = [];
    private array $metadata = [];
    private ?string $modelClass = null;
    private mixed $modelSubject = null;

    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string|int|null $id = null,
    ) {
        if ($key === '' || $type === '') {
            throw new InvalidArgumentException('Resources require a non-empty key and type.');
        }
    }

    public static function make(string $key, string $type, string|int|null $id = null): self
    {
        return new self($key, $type, $id);
    }

    public static function forModel(string $key, string $type, Model $model): self
    {
        return self::make($key, $type, $model->getKey())->model($model);
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

    public function status(string|array|callable|null $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function icon(string|callable|null $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function primaryRoute(string $name, array|callable $parameters = []): self
    {
        $this->primaryRoute = [
            'name' => $name,
            'parameters' => $parameters,
        ];

        return $this;
    }

    public function primaryUrl(string|callable $url): self
    {
        $this->primaryUrl = $url;

        return $this;
    }

    public function drawer(string $target, string $mode = 'inspect', string $width = 'md', array $metadata = []): self
    {
        $this->drawer = [
            'target' => $target,
            'mode' => $mode,
            'width' => $width,
            'metadata' => $metadata,
        ];

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

    public function actions(string ...$actionKeys): self
    {
        $this->availableActions = array_values(array_unique([...$this->availableActions, ...$actionKeys]));

        return $this;
    }

    public function permission(string $key, bool|string|array|callable $definition): self
    {
        $this->permissions[$key] = $definition;

        return $this;
    }

    public function permissions(array $permissions): self
    {
        foreach ($permissions as $key => $definition) {
            $this->permissions[(string) $key] = $definition;
        }

        return $this;
    }

    public function relationship(ResourceRelationship $relationship): self
    {
        $this->relationships[$relationship->key] = $relationship;

        return $this;
    }

    public function relationships(ResourceRelationship ...$relationships): self
    {
        foreach ($relationships as $relationship) {
            $this->relationship($relationship);
        }

        return $this;
    }

    public function preview(array $metadata): self
    {
        $this->preview = array_replace_recursive($this->preview, $metadata);

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

    public function search(array $metadata): self
    {
        $this->search = array_replace_recursive($this->search, $metadata);

        return $this;
    }

    public function notification(array $metadata): self
    {
        $this->notification = array_replace_recursive($this->notification, $metadata);

        return $this;
    }

    public function metadata(array $metadata): self
    {
        $this->metadata = array_replace_recursive($this->metadata, $metadata);

        return $this;
    }

    public function model(string|Model|null $model): self
    {
        if ($model instanceof Model) {
            $this->modelClass = $model::class;
            $this->modelSubject = $model;

            return $this;
        }

        $this->modelClass = $model;

        return $this;
    }

    public function resolve(ResourceContext $context, ?ResourceResolver $resolver = null): array
    {
        $resolver ??= new ResourceResolver();

        $authorized = $resolver->can($this, $context);
        $visible = $authorized && $this->passesVisibility($context, $resolver);

        return [
            'key' => $this->key,
            'type' => $this->type,
            'id' => $this->id,
            'title' => $this->resolveString($this->title, $context, $resolver),
            'subtitle' => $this->resolveString($this->subtitle, $context, $resolver),
            'status' => $resolver->evaluate($this->status, $context, $this),
            'icon' => $this->resolveString($this->icon, $context, $resolver),
            'primary_route' => $this->resolvePrimaryRoute($context, $resolver),
            'primary_url' => $this->resolvePrimaryUrl($context, $resolver),
            'drawer' => $this->drawer,
            'available_actions' => $this->availableActions,
            'permissions' => $authorized ? $resolver->permissions($this, $context) : [],
            'relationships' => array_map(
                fn (ResourceRelationship $relationship): array => $relationship->toArray(),
                array_values($this->relationships),
            ),
            'preview' => $this->preview,
            'history' => $this->history,
            'ai' => $this->ai,
            'search' => $this->search,
            'notification' => $this->notification,
            'model' => $this->modelClass,
            'authorized' => $authorized,
            'visible' => $visible,
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

    public function permissionDefinitions(): array
    {
        return $this->permissions;
    }

    public function actionKeys(): array
    {
        return $this->availableActions;
    }

    public function modelSubject(): mixed
    {
        return $this->modelSubject;
    }

    public function routeExists(): bool
    {
        return $this->primaryRoute === null || Route::has($this->primaryRoute['name']);
    }

    public function modelExists(): bool
    {
        return $this->modelClass === null || class_exists($this->modelClass);
    }

    public function mapsToExistingReference(): bool
    {
        return $this->routeExists()
            && $this->modelExists()
            && ($this->modelClass !== null || $this->primaryRoute !== null || $this->primaryUrl !== null || $this->policy !== null);
    }

    private function passesVisibility(ResourceContext $context, ResourceResolver $resolver): bool
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

    private function resolvePrimaryRoute(ResourceContext $context, ResourceResolver $resolver): ?array
    {
        if ($this->primaryRoute === null) {
            return null;
        }

        $parameters = $resolver->evaluate($this->primaryRoute['parameters'], $context, $this);
        $parameters = is_array($parameters) ? $parameters : [$parameters];

        return [
            'name' => $this->primaryRoute['name'],
            'parameters' => $parameters,
            'exists' => Route::has($this->primaryRoute['name']),
        ];
    }

    private function resolvePrimaryUrl(ResourceContext $context, ResourceResolver $resolver): ?string
    {
        if ($this->primaryUrl !== null) {
            return (string) $resolver->evaluate($this->primaryUrl, $context, $this);
        }

        if ($this->primaryRoute === null || ! Route::has($this->primaryRoute['name'])) {
            return null;
        }

        $parameters = $resolver->evaluate($this->primaryRoute['parameters'], $context, $this);

        return route($this->primaryRoute['name'], is_array($parameters) ? $parameters : [$parameters]);
    }

    private function resolveString(mixed $value, ResourceContext $context, ResourceResolver $resolver): ?string
    {
        $resolved = $resolver->evaluate($value, $context, $this);

        return $resolved === null ? null : (string) $resolved;
    }
}
