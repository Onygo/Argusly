<?php

use App\Enums\ResearchProjectStatus;
use App\Enums\SignalSeverity;
use App\Enums\SignalStatus;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\LlmTrackingQuery;
use App\Models\ResearchProject;
use App\Models\SeoAudit;
use App\Models\SignalDetection;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Interaction\Action;
use App\Support\Interaction\ActionContext;
use App\Support\Interaction\AppInteractionRegistry;
use App\Support\Interaction\ResourceContext;
use App\Support\Interaction\ResourceType;

function interactionAdoptionUser(int $organizationId = 100, string $role = 'owner'): User
{
    $user = new User();
    $user->forceFill([
        'id' => $organizationId,
        'organization_id' => $organizationId,
        'role' => $role,
        'is_admin' => false,
    ]);

    return $user;
}

function interactionAdoptionSubjects(int $organizationId = 100): array
{
    $workspace = new Workspace();
    $workspace->forceFill([
        'id' => 501,
        'organization_id' => $organizationId,
        'name' => 'Metadata workspace',
    ]);

    $site = new ClientSite();
    $site->forceFill([
        'id' => 'site-1',
        'workspace_id' => $workspace->id,
        'name' => 'Example site',
        'site_url' => 'https://example.test',
        'base_url' => 'https://example.test',
        'type' => ClientSite::TYPE_WORDPRESS,
        'status' => 'active',
        'is_active' => true,
    ]);
    $site->setRelation('workspace', $workspace);

    $content = new Content();
    $content->forceFill([
        'id' => 'content-1',
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Registry content',
        'status' => 'ready',
        'language' => 'en',
        'primary_keyword' => 'registry metadata',
    ]);
    $content->setRelation('workspace', $workspace);
    $content->setRelation('clientSite', $site);

    $brief = new Brief();
    $brief->forceFill([
        'id' => 'brief-1',
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'title' => 'Registry brief',
        'status' => 'draft',
        'primary_keyword' => 'brief metadata',
    ]);
    $brief->setRelation('clientSite', $site);
    $brief->setRelation('content', $content);

    $draft = new Draft();
    $draft->forceFill([
        'id' => 'draft-1',
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'title' => 'Registry draft',
        'status' => 'ready',
        'delivery_status' => 'pending',
    ]);
    $draft->setRelation('clientSite', $site);
    $draft->setRelation('brief', $brief);
    $draft->setRelation('content', $content);

    $research = new ResearchProject();
    $research->forceFill([
        'id' => 'research-1',
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'brief_id' => $brief->id,
        'name' => 'Registry research',
        'status' => ResearchProjectStatus::COMPLETED,
        'target_keywords' => ['metadata'],
    ]);
    $research->setRelation('workspace', $workspace);
    $research->setRelation('clientSite', $site);
    $research->setRelation('brief', $brief);

    $query = new LlmTrackingQuery();
    $query->forceFill([
        'id' => 701,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Registry query',
        'query_text' => 'What does Argusly do?',
        'target_brand' => 'Argusly',
        'is_active' => true,
    ]);
    $query->setRelation('workspace', $workspace);
    $query->setRelation('site', $site);

    $audit = new SeoAudit();
    $audit->forceFill([
        'id' => 801,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'status' => 'completed',
        'pages_crawled' => 12,
        'issue_counts' => ['error' => 1],
    ]);
    $audit->setRelation('workspace', $workspace);
    $audit->setRelation('site', $site);

    $signal = new SignalDetection();
    $signal->forceFill([
        'id' => 'signal-1',
        'organization_id' => $organizationId,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Registry signal',
        'status' => SignalStatus::DETECTED,
        'severity' => SignalSeverity::MEDIUM,
        'category' => SignalDetection::CATEGORY_BRAND_MONITORING,
        'priority_score' => 0.72,
    ]);
    $signal->setRelation('workspace', $workspace);
    $signal->setRelation('clientSite', $site);

    return compact('workspace', 'site', 'content', 'brief', 'draft', 'research', 'query', 'audit', 'signal');
}

it('registers first-batch route-backed action metadata against existing routes', function () {
    $registry = AppInteractionRegistry::actionRegistry();

    $expectedRoutes = [
        'app.content.open' => 'app.content.show',
        'app.content.create' => 'app.content.create',
        'app.content.open-calendar' => 'app.content.calendar',
        'app.research-project.open' => 'app.research.show',
        'app.research-project.create' => 'app.research.create',
        'app.llm-tracking-query.open' => 'app.sites.llm-tracking.show',
        'app.seo-audit.open' => 'app.sites.seo-audits.show',
        'app.signal-detection.open' => 'app.signal-intelligence.detections.show',
        'app.draft.open' => 'app.drafts.show',
        'app.brief.open' => 'app.briefs.show',
        'app.site.open' => 'app.sites.show',
    ];

    foreach ($expectedRoutes as $actionKey => $routeName) {
        expect($registry->has($actionKey))->toBeTrue();

        $resolved = $registry->get($actionKey)->resolve(ActionContext::make([
            'user' => interactionAdoptionUser(),
            'resource_id' => str_contains($routeName, 'llm-tracking') ? 701 : 'resource-1',
            'site_id' => 'site-1',
            'metadata' => [
                'site_id' => 'site-1',
                'site' => interactionAdoptionSubjects()['site'],
            ],
        ]));

        expect($resolved['route']['name'])->toBe($routeName)
            ->and($resolved['route']['exists'])->toBeTrue()
            ->and($resolved['method'])->toBe('GET')
            ->and($resolved['execution_mode'])->toBe(Action::EXECUTION_LINK);
    }

    $registry->assertAllActionsMapToEndpoints();
});

it('resolves first-batch resources to existing primary URLs and available action keys', function () {
    $subjects = interactionAdoptionSubjects();
    $user = interactionAdoptionUser();
    $registry = AppInteractionRegistry::resourceRegistryFor([
        $subjects['site'],
        $subjects['content'],
        $subjects['brief'],
        $subjects['draft'],
        $subjects['research'],
        $subjects['query'],
        $subjects['audit'],
        $subjects['signal'],
    ]);

    $actionRegistry = AppInteractionRegistry::actionRegistry();
    $registry->assertAllTypesMapToExistingReferences();
    $registry->assertAllResourcesMapToExistingReferences();
    $registry->assertAvailableActionsExist($actionRegistry);

    $context = ResourceContext::make(['user' => $user]);

    $contentResource = $registry->resolve('content:content-1', $context);

    expect($contentResource['primary_route']['name'])->toBe('app.content.show')
        ->and($contentResource['available_actions'])->toBe(['app.content.open'])
        ->and($registry->resolve('content:content-1', $context)['primary_url'])->toContain('/content/content-1')
        ->and($registry->resolve('draft:draft-1', $context)['primary_url'])->toContain('/drafts/draft-1')
        ->and($registry->resolve('brief:brief-1', $context)['primary_url'])->toContain('/briefs/brief-1')
        ->and($registry->resolve('research_project:research-1', $context)['primary_url'])->toContain('/research/research-1')
        ->and($registry->resolve('site:site-1', $context)['primary_url'])->toContain('/sites/site-1')
        ->and($registry->resolve('llm_tracking_query:701', $context)['primary_url'])->toContain('/sites/site-1/insights/llm/701')
        ->and($registry->resolve('seo_audit:801', $context)['primary_url'])->toContain('/sites/site-1/insights/audits/801')
        ->and($registry->resolve('signal_detection:signal-1', $context)['primary_url'])->toContain('/signal-intelligence/detections/signal-1');
});

it('hides unauthorized first-batch resources through policy-aware metadata', function () {
    $subjects = interactionAdoptionSubjects(100);
    $registry = AppInteractionRegistry::resourceRegistryFor([$subjects['content'], $subjects['site']]);
    $unauthorizedUser = interactionAdoptionUser(999);

    $context = ResourceContext::make(['user' => $unauthorizedUser]);

    expect($registry->resolve('content:content-1', $context))->toBeNull()
        ->and($registry->resolve('site:site-1', $context))->toBeNull()
        ->and($registry->resolve('content:content-1', $context, includeHidden: true)['authorized'])->toBeFalse()
        ->and($registry->resolve('site:site-1', $context, includeHidden: true)['authorized'])->toBeFalse();
});

it('keeps provider relationships descriptive only', function () {
    $subjects = interactionAdoptionSubjects();
    $registry = AppInteractionRegistry::resourceRegistryFor([$subjects['draft'], $subjects['query'], $subjects['audit']]);
    $context = ResourceContext::make(['user' => interactionAdoptionUser()]);

    $draftRelationships = $registry->resolve('draft:draft-1', $context)['relationships'];
    $queryRelationships = $registry->resolve('llm_tracking_query:701', $context)['relationships'];

    expect($draftRelationships)->toHaveCount(3)
        ->and($draftRelationships[0])->toHaveKeys(['key', 'type', 'resource_type', 'resource_id', 'resource_key', 'title', 'metadata'])
        ->and($queryRelationships[0])->toMatchArray([
            'key' => 'site',
            'type' => 'scoped_to',
            'resource_type' => ResourceType::SITE,
            'resource_id' => 'site-1',
            'resource_key' => 'site:site-1',
            'metadata' => ['source' => 'client_site_id'],
        ]);

    foreach ([$draftRelationships, $queryRelationships] as $relationships) {
        foreach ($relationships as $relationship) {
            expect(array_keys($relationship))->not->toContain('loader')
                ->and(array_keys($relationship))->not->toContain('mutation')
                ->and(array_keys($relationship))->not->toContain('sync');
        }
    }
});

it('keeps adoption providers out of Blade, controllers, jobs, services, and execution paths', function () {
    $providerFiles = glob(app_path('Support/Interaction/Providers/*.php'));

    expect($providerFiles)->not->toBeEmpty();

    $forbidden = [
        'App\\Http\\Controllers',
        'App\\Jobs',
        'App\\Services',
        'Illuminate\\View',
        'resources/views',
        'dispatch(',
        'dispatchSync(',
        'Bus::',
        'Artisan::call',
        'Http::',
        '->execute(',
    ];

    foreach ($providerFiles as $file) {
        $source = file_get_contents($file);

        foreach ($forbidden as $needle) {
            expect($source)->not->toContain($needle, basename($file).' should not depend on '.$needle);
        }
    }
});

it('keeps registered adoption actions as metadata-only links without business execution', function () {
    $registry = AppInteractionRegistry::actionRegistry();

    foreach ($registry->all() as $action) {
        $resolved = $action->resolve(ActionContext::make([
            'user' => interactionAdoptionUser(),
            'resource_id' => 'resource-1',
            'site_id' => 'site-1',
            'metadata' => [
                'site_id' => 'site-1',
                'site' => interactionAdoptionSubjects()['site'],
            ],
        ]));

        expect($resolved['execution_mode'])->toBe(Action::EXECUTION_LINK)
            ->and($resolved['method'])->toBe('GET')
            ->and($resolved['form'])->toBeNull()
            ->and($resolved['confirmation'])->toBeNull()
            ->and($resolved['drawer'])->toBeNull()
            ->and($resolved['route']['exists'])->toBeTrue();
    }
});
