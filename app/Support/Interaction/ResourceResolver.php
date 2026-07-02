<?php

namespace App\Support\Interaction;

use Illuminate\Contracts\Auth\Access\Gate;

class ResourceResolver
{
    public function __construct(
        private readonly ?Gate $gate = null,
    ) {
    }

    public function can(Resource $resource, ResourceContext $context): bool
    {
        if ($resource->authorizationCallback() !== null) {
            return (bool) $this->evaluate($resource->authorizationCallback(), $context, $resource);
        }

        $policy = $resource->policyDefinition();

        if ($policy === null) {
            return true;
        }

        $gate = $context->user
            ? $this->gate()->forUser($context->user)
            : $this->gate();

        $target = $this->evaluate($policy['target'] ?? $resource->modelSubject() ?? null, $context, $resource);
        $arguments = $this->evaluate($policy['arguments'] ?? [], $context, $resource);
        $arguments = is_array($arguments) ? array_values($arguments) : [$arguments];

        if ($target === null) {
            return $gate->allows($policy['ability'], $arguments === [] ? null : $arguments);
        }

        if ($arguments !== []) {
            return $gate->allows($policy['ability'], array_values([$target, ...$arguments]));
        }

        return $gate->allows($policy['ability'], $target);
    }

    public function permissions(Resource $resource, ResourceContext $context): array
    {
        $permissions = [];

        foreach ($resource->permissionDefinitions() as $key => $definition) {
            $permissions[$key] = $this->allowsPermission($definition, $context, $resource);
        }

        return $permissions;
    }

    public function evaluate(mixed $value, ResourceContext $context, ?Resource $resource = null): mixed
    {
        if (is_callable($value)) {
            return $value($context, $resource, $this);
        }

        return $value;
    }

    private function allowsPermission(mixed $definition, ResourceContext $context, Resource $resource): bool
    {
        if (is_bool($definition) || is_callable($definition)) {
            return (bool) $this->evaluate($definition, $context, $resource);
        }

        if (is_string($definition)) {
            $definition = ['ability' => $definition];
        }

        if (! is_array($definition) || ! isset($definition['ability'])) {
            return false;
        }

        $gate = $context->user
            ? $this->gate()->forUser($context->user)
            : $this->gate();

        $target = $this->evaluate($definition['target'] ?? $resource->modelSubject() ?? null, $context, $resource);
        $arguments = $this->evaluate($definition['arguments'] ?? [], $context, $resource);
        $arguments = is_array($arguments) ? array_values($arguments) : [$arguments];

        if ($target === null) {
            return $gate->allows($definition['ability'], $arguments === [] ? null : $arguments);
        }

        if ($arguments !== []) {
            return $gate->allows($definition['ability'], array_values([$target, ...$arguments]));
        }

        return $gate->allows($definition['ability'], $target);
    }

    private function gate(): Gate
    {
        return $this->gate ?? app(Gate::class);
    }
}
