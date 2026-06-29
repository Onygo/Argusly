<?php

namespace App\Console\Commands;

use App\Services\Mos\MosProviderRegistry;
use Illuminate\Console\Command;

class MosProvidersCommand extends Command
{
    protected $signature = 'mos:providers';

    protected $description = 'Inspect registered MOS providers and their capabilities.';

    public function handle(MosProviderRegistry $registry): int
    {
        $this->table(
            ['key', 'domain', 'capabilities', 'priority', 'class'],
            collect($registry->diagnostics())
                ->map(fn (array $provider): array => [
                    $provider['key'],
                    $provider['domain'],
                    $provider['capabilities_list'],
                    (string) $provider['priority'],
                    $provider['class'],
                ])
                ->all()
        );

        $opportunityDiagnostics = $registry->opportunityDiagnostics();

        if ($opportunityDiagnostics !== []) {
            $this->newLine();
            $this->components->info('MOS opportunity provider readiness');
            $this->table(
                ['provider key', 'legacy model', 'classification', 'readiness', 'canonical', 'signal', 'risk'],
                collect($opportunityDiagnostics)
                    ->map(fn (array $provider): array => [
                        $provider['provider_key'],
                        class_basename((string) $provider['legacy_model']),
                        $provider['classification'],
                        $provider['readiness'],
                        $provider['can_emit_canonical_payload'] ? 'yes' : 'no',
                        $provider['can_emit_signal'] ? 'yes' : 'no',
                        $provider['risk_level'],
                    ])
                    ->all()
            );
        }

        $warnings = $registry->duplicateWarnings();

        if ($warnings === []) {
            $this->info('Duplicate warnings: none detected.');

            return self::SUCCESS;
        }

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
