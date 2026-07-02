<?php

namespace App\Support\Interaction;

use Illuminate\Contracts\Auth\Access\Gate;

class ActionPolicyResolver
{
    public function __construct(
        private readonly ?Gate $gate = null,
    ) {
    }

    public function can(Action $action, ActionContext $context): bool
    {
        if ($action->authorizationCallback() !== null) {
            return (bool) $this->evaluate($action->authorizationCallback(), $context, $action);
        }

        $policy = $action->policyDefinition();

        if ($policy === null) {
            return true;
        }

        $gate = $context->user
            ? $this->gate()->forUser($context->user)
            : $this->gate();

        $target = $this->evaluate($policy['target'] ?? null, $context, $action);
        $arguments = $this->evaluate($policy['arguments'] ?? [], $context, $action);
        $arguments = is_array($arguments) ? array_values($arguments) : [$arguments];

        if ($target === null) {
            return $gate->allows($policy['ability'], $arguments === [] ? null : $arguments);
        }

        if ($arguments !== []) {
            return $gate->allows($policy['ability'], array_values([$target, ...$arguments]));
        }

        return $gate->allows($policy['ability'], $target);
    }

    public function evaluate(mixed $value, ActionContext $context, ?Action $action = null): mixed
    {
        if (is_callable($value)) {
            return $value($context, $action, $this);
        }

        return $value;
    }

    private function gate(): Gate
    {
        return $this->gate ?? app(Gate::class);
    }
}
