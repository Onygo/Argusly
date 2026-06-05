<?php

namespace App\Billing\Providers;

use RuntimeException;

class PaymentProviderRegistry
{
    /** @var array<array-key, PaymentProvider|callable(): PaymentProvider> */
    private array $providers;

    /** @var array<string, PaymentProvider> */
    private array $resolved = [];

    public function __construct(array $providers)
    {
        $this->providers = $providers;
    }

    public function get(?string $name = null): PaymentProvider
    {
        $name = $name ?: config('billing.default_provider', 'mollie');

        if (array_key_exists($name, $this->providers)) {
            return $this->resolve((string) $name, $this->providers[$name]);
        }

        foreach ($this->providers as $provider) {
            if (! $provider instanceof PaymentProvider) {
                continue;
            }

            if ($provider->name() === $name) {
                return $provider;
            }
        }

        throw new RuntimeException('Unknown payment provider: ' . $name);
    }

    private function resolve(string $name, PaymentProvider|callable $provider): PaymentProvider
    {
        if ($provider instanceof PaymentProvider) {
            return $provider;
        }

        if (! array_key_exists($name, $this->resolved)) {
            $resolved = $provider();

            if (! $resolved instanceof PaymentProvider) {
                throw new RuntimeException('Payment provider factory did not return a payment provider: ' . $name);
            }

            $this->resolved[$name] = $resolved;
        }

        return $this->resolved[$name];
    }
}
