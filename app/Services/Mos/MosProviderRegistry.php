<?php

namespace App\Services\Mos;

use App\Services\Mos\Contracts\MosOpportunityProvider;
use App\Services\Mos\Contracts\MosProvider;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class MosProviderRegistry
{
    /**
     * @var array<string, MosProvider>
     */
    private array $providers;

    /**
     * @param  iterable<int, MosProvider>  $providers
     */
    public function __construct(iterable $providers = [])
    {
        $this->providers = $this->normalize($providers);
    }

    /**
     * @return array<string, MosProvider>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    public function has(string $key): bool
    {
        return array_key_exists($this->normalizeKey($key), $this->providers);
    }

    public function get(string $key): MosProvider
    {
        $key = $this->normalizeKey($key);

        if (! $this->has($key)) {
            throw new InvalidArgumentException("Unknown MOS provider [{$key}].");
        }

        return $this->providers[$key];
    }

    /**
     * @return array<string, MosProvider>
     */
    public function forDomain(string $domain): array
    {
        $domain = $this->normalizeKey($domain);

        return Collection::make($this->providers)
            ->filter(fn (MosProvider $provider): bool => $provider->domain() === $domain)
            ->all();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function capabilityMap(): array
    {
        return Collection::make($this->providers)
            ->mapWithKeys(fn (MosProvider $provider): array => [
                $provider->key() => $provider->capabilities(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function diagnostics(): array
    {
        return Collection::make($this->providers)
            ->map(function (MosProvider $provider): array {
                $diagnostics = [
                    'key' => $provider->key(),
                    'label' => $provider->label(),
                    'domain' => $provider->domain(),
                    'capabilities' => $provider->capabilities(),
                    'capabilities_list' => implode(', ', $provider->capabilities()),
                    'priority' => $provider->priority(),
                    'class' => $provider::class,
                ];

                if ($provider instanceof MosOpportunityProvider) {
                    $diagnostics['opportunity'] = [
                        'legacy_model' => $provider->sourceModel(),
                        'source_type' => $provider->sourceType(),
                        'classification' => $provider->classification(),
                        'readiness' => $provider->migrationReadiness(),
                        'can_emit_canonical_payload' => $provider->canEmitCanonicalOpportunities(),
                        'can_emit_signal' => $provider->canEmitSignals(),
                        'read_only' => $provider->isReadOnly(),
                        'risk_level' => $provider->riskLevel(),
                    ];
                }

                return $diagnostics;
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function opportunityDiagnostics(): array
    {
        return Collection::make($this->providers)
            ->filter(fn (MosProvider $provider): bool => $provider instanceof MosOpportunityProvider)
            ->map(fn (MosOpportunityProvider $provider): array => [
                'provider_key' => $provider->key(),
                'legacy_model' => $provider->sourceModel(),
                'classification' => $provider->classification(),
                'readiness' => $provider->migrationReadiness(),
                'can_emit_canonical_payload' => $provider->canEmitCanonicalOpportunities(),
                'can_emit_signal' => $provider->canEmitSignals(),
                'risk_level' => $provider->riskLevel(),
                'read_only' => $provider->isReadOnly(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function duplicateWarnings(): array
    {
        $keys = Collection::make($this->providers)
            ->map(fn (MosProvider $provider): string => $this->normalizeKey($provider->key()))
            ->all();

        return Collection::make(array_count_values($keys))
            ->filter(fn (int $count): bool => $count > 1)
            ->keys()
            ->map(fn (string $key): string => "Duplicate MOS provider key [{$key}].")
            ->values()
            ->all();
    }

    /**
     * @param  iterable<int, MosProvider>  $providers
     * @return array<string, MosProvider>
     */
    private function normalize(iterable $providers): array
    {
        $map = [];

        foreach ($providers as $provider) {
            if (! $provider instanceof MosProvider) {
                continue;
            }

            $key = $this->normalizeKey($provider->key());

            if ($key === '') {
                throw new InvalidArgumentException('MOS providers must expose a non-empty key.');
            }

            if (! in_array($provider->domain(), MosDomain::all(), true)) {
                throw new InvalidArgumentException(sprintf(
                    'MOS provider [%s] uses unsupported domain [%s].',
                    $key,
                    $provider->domain(),
                ));
            }

            if (array_key_exists($key, $map)) {
                throw new InvalidArgumentException("Duplicate MOS provider key [{$key}].");
            }

            $map[$key] = $provider;
        }

        uasort($map, fn (MosProvider $a, MosProvider $b): int => $b->priority() <=> $a->priority());

        return $map;
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim($key));
    }
}
