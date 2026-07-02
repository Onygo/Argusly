<?php

namespace App\Support\Interaction;

use LogicException;

final class ActionRegistry
{
    /** @var array<string, Action> */
    private array $actions = [];

    /** @var array<string, ActionGroup> */
    private array $groups = [];

    public static function make(): self
    {
        return new self();
    }

    public function register(Action|ActionGroup $entry): self
    {
        if ($entry instanceof ActionGroup) {
            return $this->registerGroup($entry);
        }

        return $this->registerAction($entry);
    }

    public function registerAction(Action $action): self
    {
        if (isset($this->actions[$action->key])) {
            throw new LogicException(sprintf('Action [%s] is already registered.', $action->key));
        }

        $this->actions[$action->key] = $action;

        return $this;
    }

    public function registerGroup(ActionGroup $group): self
    {
        if (isset($this->groups[$group->key])) {
            throw new LogicException(sprintf('Action group [%s] is already registered.', $group->key));
        }

        foreach ($group->actions() as $action) {
            $this->registerAction($action);
        }

        $this->groups[$group->key] = $group;

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->actions[$key]);
    }

    public function get(string $key): Action
    {
        return $this->actions[$key]
            ?? throw new LogicException(sprintf('Action [%s] is not registered.', $key));
    }

    /**
     * @return array<string, Action>
     */
    public function all(): array
    {
        return $this->actions;
    }

    public function resolve(string $key, ActionContext $context, ?ActionPolicyResolver $resolver = null): array
    {
        return $this->get($key)->resolve($context, $resolver);
    }

    public function forContext(ActionContext $context, ?ActionPolicyResolver $resolver = null, bool $includeHidden = false): array
    {
        $resolver ??= new ActionPolicyResolver();

        return collect($this->actions)
            ->map(fn (Action $action): array => $action->resolve($context, $resolver))
            ->when(! $includeHidden, fn ($collection) => $collection->where('visible', true))
            ->values()
            ->all();
    }

    public function forSurface(string $surface, ActionContext $context, ?ActionPolicyResolver $resolver = null, bool $includeHidden = false): array
    {
        return $this->forContext($context->forSurface($surface), $resolver, $includeHidden);
    }

    public function bulkActions(ActionContext $context, ?ActionPolicyResolver $resolver = null, bool $includeHidden = false): array
    {
        $resolver ??= new ActionPolicyResolver();
        $context = $context->forSurface(Action::SURFACE_BULK);

        return collect($this->actions)
            ->filter(fn (Action $action): bool => $action->supportsBulk())
            ->map(fn (Action $action): array => $action->resolve($context, $resolver))
            ->when(! $includeHidden, fn ($collection) => $collection->where('visible', true))
            ->values()
            ->all();
    }

    public function drawerActions(ActionContext $context, ?ActionPolicyResolver $resolver = null, bool $includeHidden = false): array
    {
        $resolver ??= new ActionPolicyResolver();
        $context = $context->forSurface(Action::SURFACE_DRAWER);

        return collect($this->actions)
            ->filter(fn (Action $action): bool => $action->supportsDrawer() || $action->supportsSurface(Action::SURFACE_DRAWER))
            ->map(fn (Action $action): array => $action->resolve($context, $resolver))
            ->when(! $includeHidden, fn ($collection) => $collection->where('visible', true))
            ->values()
            ->all();
    }

    public function groupedForContext(ActionContext $context, ?ActionPolicyResolver $resolver = null, bool $includeHidden = false): array
    {
        $resolver ??= new ActionPolicyResolver();

        return collect($this->groups)
            ->map(fn (ActionGroup $group): array => $group->resolve($context, $resolver, $includeHidden))
            ->filter(fn (array $group): bool => $includeHidden || $group['actions'] !== [])
            ->values()
            ->all();
    }

    public function assertAllActionsMapToEndpoints(): void
    {
        foreach ($this->actions as $action) {
            if (! $action->mapsToExistingEndpoint()) {
                throw new LogicException(sprintf('Action [%s] does not map to an existing route, form, policy, URL, or drawer target.', $action->key));
            }
        }
    }
}
