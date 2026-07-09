<?php

use App\Models\ContentOpportunity;
use App\Services\Mos\Contracts\MosProvider;
use App\Services\Mos\MosDomain;
use App\Services\Mos\MosProviderRegistry;
use App\Services\Mos\Opportunity\Providers\ContentOpportunityProvider;
use App\Services\Mos\Providers\AgentWorkflowMosProvider;
use App\Services\Mos\Providers\MarketingOperatingSystemMosProvider;
use App\Services\Mos\Providers\OpportunityIntelligenceMosProvider;
use App\Services\Mos\Providers\SignalIntelligenceMosProvider;

it('registers mos providers by normalized key and domain', function () {
    $registry = app(MosProviderRegistry::class);

    expect($registry->has('Opportunity-Intelligence'))->toBeTrue()
        ->and($registry->has('signal-intelligence'))->toBeTrue()
        ->and($registry->has('agent-workflows'))->toBeTrue()
        ->and($registry->has('marketing-operating-system'))->toBeTrue()
        ->and($registry->has('legacy-content-opportunities'))->toBeTrue()
        ->and($registry->get('opportunity-intelligence'))->toBeInstanceOf(OpportunityIntelligenceMosProvider::class)
        ->and($registry->get('signal-intelligence'))->toBeInstanceOf(SignalIntelligenceMosProvider::class)
        ->and($registry->get('agent-workflows'))->toBeInstanceOf(AgentWorkflowMosProvider::class)
        ->and($registry->get('marketing-operating-system'))->toBeInstanceOf(MarketingOperatingSystemMosProvider::class)
        ->and($registry->get('legacy-content-opportunities'))->toBeInstanceOf(ContentOpportunityProvider::class)
        ->and($registry->forDomain(MosDomain::OPPORTUNITY))->toHaveKey('opportunity-intelligence')
        ->and($registry->forDomain(MosDomain::OPPORTUNITY))->toHaveKey('legacy-content-opportunities')
        ->and($registry->forDomain(MosDomain::SIGNAL))->toHaveKey('signal-intelligence')
        ->and($registry->forDomain(MosDomain::WORKFLOW))->toHaveKey('agent-workflows')
        ->and($registry->forDomain(MosDomain::WORKFLOW))->toHaveKey('marketing-operating-system');
});

it('exposes a capability map for migration planning', function () {
    $capabilities = app(MosProviderRegistry::class)->capabilityMap();

    expect($capabilities['opportunity-intelligence'])->toContain('generate_opportunities', 'recommend_actions')
        ->and($capabilities['signal-intelligence'])->toContain('detect_signals', 'promote_to_opportunities')
        ->and($capabilities['agent-workflows'])->toContain('trigger_workflows', 'coordinate_agents')
        ->and($capabilities['marketing-operating-system'])->toContain('orchestrate_objectives', 'link_recommendations', 'integrate_reports', 'integrate_briefings')
        ->and($capabilities['legacy-content-opportunities'])->toContain('emit_canonical_opportunity_payload');
});

it('formats provider diagnostics for internal observability', function () {
    $diagnostics = app(MosProviderRegistry::class)->diagnostics();

    expect($diagnostics)->toHaveCount(10)
        ->and($diagnostics[0])->toHaveKeys(['key', 'label', 'domain', 'capabilities', 'capabilities_list', 'priority', 'class'])
        ->and($diagnostics[0]['key'])->toBe('opportunity-intelligence')
        ->and($diagnostics[0]['domain'])->toBe(MosDomain::OPPORTUNITY)
        ->and($diagnostics[0]['capabilities_list'])->toContain('generate_opportunities')
        ->and($diagnostics[0]['priority'])->toBe(100)
        ->and($diagnostics[0]['class'])->toBe(OpportunityIntelligenceMosProvider::class)
        ->and(app(MosProviderRegistry::class)->duplicateWarnings())->toBe([]);
});

it('orders diagnostics by provider priority', function () {
    $keys = collect(app(MosProviderRegistry::class)->diagnostics())->pluck('key')->all();

    expect(array_slice($keys, 0, 3))->toBe([
        'opportunity-intelligence',
        'signal-intelligence',
        'agent-workflows',
    ]);
});

it('exposes opportunity provider readiness diagnostics', function () {
    $diagnostics = app(MosProviderRegistry::class)->opportunityDiagnostics();
    $contentProvider = collect($diagnostics)->firstWhere('provider_key', 'legacy-content-opportunities');
    $linkProvider = collect($diagnostics)->firstWhere('provider_key', 'legacy-link-opportunities');

    expect($diagnostics)->toHaveCount(6)
        ->and($contentProvider)->toMatchArray([
            'legacy_model' => ContentOpportunity::class,
            'classification' => 'consolidation_candidate',
            'readiness' => 'high_value_with_existing_canonical_links',
            'can_emit_canonical_payload' => true,
            'can_emit_signal' => true,
            'risk_level' => 'high',
            'read_only' => true,
        ])
        ->and($linkProvider['can_emit_canonical_payload'])->toBeFalse()
        ->and($linkProvider['classification'])->toBe('projection');
});

it('rejects duplicate provider keys', function () {
    $provider = new class implements MosProvider
    {
        public function key(): string
        {
            return 'duplicate';
        }

        public function domain(): string
        {
            return MosDomain::SIGNAL;
        }

        public function label(): string
        {
            return 'Duplicate';
        }

        public function capabilities(): array
        {
            return [];
        }

        public function priority(): int
        {
            return 0;
        }

        public function metadata(): array
        {
            return [];
        }
    };

    new MosProviderRegistry([$provider, $provider]);
})->throws(InvalidArgumentException::class, 'Duplicate MOS provider key [duplicate].');

it('rejects duplicate opportunity provider keys through the mos registry', function () {
    $provider = new class extends ContentOpportunityProvider
    {
        public function key(): string
        {
            return 'same-opportunity-key';
        }
    };

    new MosProviderRegistry([$provider, $provider]);
})->throws(InvalidArgumentException::class, 'Duplicate MOS provider key [same-opportunity-key].');
