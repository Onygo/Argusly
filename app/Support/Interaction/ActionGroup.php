<?php

namespace App\Support\Interaction;

use LogicException;

final class ActionGroup
{
    /** @var array<string, Action> */
    private array $actions = [];

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $description = null,
        public readonly ?string $icon = null,
    ) {
    }

    public static function make(string $key, string $label, ?string $description = null, ?string $icon = null): self
    {
        return new self($key, $label, $description, $icon);
    }

    public function add(Action $action): self
    {
        if (isset($this->actions[$action->key])) {
            throw new LogicException(sprintf('Action [%s] is already registered in group [%s].', $action->key, $this->key));
        }

        $this->actions[$action->key] = $action;

        return $this;
    }

    /**
     * @return array<string, Action>
     */
    public function actions(): array
    {
        return $this->actions;
    }

    public function resolve(ActionContext $context, ?ActionPolicyResolver $resolver = null, bool $includeHidden = false): array
    {
        $resolver ??= new ActionPolicyResolver();

        $actions = collect($this->actions)
            ->map(fn (Action $action): array => $action->resolve($context, $resolver))
            ->when(! $includeHidden, fn ($collection) => $collection->where('visible', true))
            ->values()
            ->all();

        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'icon' => $this->icon,
            'actions' => $actions,
        ];
    }
}
