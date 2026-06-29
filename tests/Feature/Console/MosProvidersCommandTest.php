<?php

use App\Services\Mos\MosProviderRegistry;
use Illuminate\Support\Facades\Artisan;

it('lists every registered mos provider with diagnostics fields', function (): void {
    $registry = app(MosProviderRegistry::class);

    $exitCode = Artisan::call('mos:providers');
    $output = Artisan::output();

    foreach ($registry->diagnostics() as $provider) {
        expect($output)
            ->toContain($provider['key'])
            ->toContain($provider['domain'])
            ->toContain($provider['capabilities_list'])
            ->toContain((string) $provider['priority'])
            ->toContain($provider['class']);
    }

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('MOS opportunity provider readiness')
        ->and($output)->toContain('legacy-content-opportunities')
        ->and($output)->toContain('ContentOpportunity')
        ->and($output)->toContain('high_value_with_existing_canonical_links')
        ->and($output)->toContain('legacy-link-opportunities')
        ->and($output)->toContain('tactical_projection_not_strategic_opportunity')
        ->and($output)->toContain('Duplicate warnings: none detected.');
});
