<?php

namespace App\Support\Connectors;

use App\Enums\ContentDestinationType;
use App\Contracts\Connectors\ConnectorContract;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use InvalidArgumentException;

/**
 * Registry for content publication connectors.
 *
 * Manages the mapping between connector types and their implementations.
 * Use this to resolve connectors by type or from destination configurations.
 *
 * ## Usage
 *
 * ```php
 * // Resolve by type
 * $connector = $registry->resolve('laravel');
 *
 * // Resolve from destination
 * $connector = $registry->resolveForDestination($destination);
 *
 * // Check if type is registered
 * if ($registry->has('wordpress')) { ... }
 * ```
 */
class ConnectorRegistry
{
    /** @var array<string, ConnectorContract> */
    private array $connectors = [];

    /** @var array<string, class-string<ConnectorContract>> */
    private array $deferredConnectors = [];

    /**
     * Register a connector instance.
     */
    public function register(ConnectorContract $connector): self
    {
        $this->connectors[$connector->type()] = $connector;

        return $this;
    }

    /**
     * Register a connector class for deferred instantiation.
     *
     * @param class-string<ConnectorContract> $class
     */
    public function registerDeferred(string $type, string $class): self
    {
        $this->deferredConnectors[$type] = $class;

        return $this;
    }

    /**
     * Resolve a connector by type.
     *
     * @throws InvalidArgumentException If the type is not registered
     */
    public function resolve(string $type): ConnectorContract
    {
        // Check for already-instantiated connectors
        if (isset($this->connectors[$type])) {
            return $this->connectors[$type];
        }

        // Check for deferred connectors
        if (isset($this->deferredConnectors[$type])) {
            $class = $this->deferredConnectors[$type];
            $this->connectors[$type] = app($class);

            return $this->connectors[$type];
        }

        throw new InvalidArgumentException(
            "No connector registered for type: {$type}. Available types: " . implode(', ', $this->types())
        );
    }

    /**
     * Resolve a connector for a content destination.
     *
     * @throws InvalidArgumentException If no connector matches the destination type
     */
    public function resolveForDestination(ContentDestination $destination): ConnectorContract
    {
        $type = $this->mapDestinationToType($destination);

        return $this->resolve($type);
    }

    /**
     * Resolve a connector from a publication record.
     *
     * @throws InvalidArgumentException If no connector matches the publication provider
     */
    public function resolveForPublication(ContentPublication $publication): ConnectorContract
    {
        $type = trim((string) $publication->provider);
        if ($type === '') {
            throw new InvalidArgumentException('Publication provider is missing.');
        }

        return $this->resolve($type);
    }

    /**
     * Check if a connector type is registered.
     */
    public function has(string $type): bool
    {
        return isset($this->connectors[$type]) || isset($this->deferredConnectors[$type]);
    }

    /**
     * Get all registered connector types.
     *
     * @return array<string>
     */
    public function types(): array
    {
        return array_unique([
            ...array_keys($this->connectors),
            ...array_keys($this->deferredConnectors),
        ]);
    }

    /**
     * Get capabilities for all registered connectors.
     *
     * @return array<string, ConnectorCapabilities>
     */
    public function allCapabilities(): array
    {
        $capabilities = [];

        foreach ($this->types() as $type) {
            $capabilities[$type] = $this->resolve($type)->capabilities();
        }

        return $capabilities;
    }

    /**
     * Map a destination to its connector type.
     */
    private function mapDestinationToType(ContentDestination $destination): string
    {
        $rawType = $destination->rawTypeValue();
        $destinationType = ContentDestinationType::normalize($rawType);

        return match ($destinationType) {
            ContentDestinationType::LARAVEL->value => ContentPublication::PROVIDER_LARAVEL,
            ContentDestinationType::WORDPRESS->value => ContentPublication::PROVIDER_WORDPRESS,
            ContentDestinationType::API->value => ContentPublication::PROVIDER_API,
            default => throw new InvalidArgumentException(sprintf(
                'Unknown destination type: %s',
                $rawType ?? 'missing'
            )),
        };
    }
}
