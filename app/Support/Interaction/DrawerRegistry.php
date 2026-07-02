<?php

namespace App\Support\Interaction;

use LogicException;

final class DrawerRegistry
{
    /** @var array<string, Drawer> */
    private array $drawers = [];

    public static function make(): self
    {
        return new self();
    }

    public function register(Drawer $drawer): self
    {
        if (isset($this->drawers[$drawer->key()])) {
            throw new LogicException(sprintf('Drawer [%s] is already registered.', $drawer->key()));
        }

        $this->drawers[$drawer->key()] = $drawer;

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->drawers[$key]);
    }

    public function get(string $key): Drawer
    {
        return $this->drawers[$key]
            ?? throw new LogicException(sprintf('Drawer [%s] is not registered.', $key));
    }

    /**
     * @return array<string, Drawer>
     */
    public function all(): array
    {
        return $this->drawers;
    }

    public function resolve(string $key, DrawerContext $context, ?DrawerResolver $resolver = null): array
    {
        return $this->get($key)->resolve($context, $resolver);
    }
}
