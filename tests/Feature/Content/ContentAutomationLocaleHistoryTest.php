<?php

use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentAutomation;
use App\Models\ContentAutomationRun;
use App\Models\ContentAutomationRunItem;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContentAutomation\AutomationRunItemStateService;
use App\Services\ContentAutomation\ContentAutomationArticleService;
use App\Services\ContentAutomation\ContentAutomationOrchestrator;
use App\Services\ContentAutomation\ContentAutomationPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('tracks a single source locale automation as one completed locale item', function () {
    [$user, $automation] = makeAutomationLocaleHistoryContext([
        'locale' => 'nl',
        'locales' => ['nl'],
        'include_translation' => false,
    ]);

    $planner = \Mockery::mock(ContentAutomationPlanner::class);
    $planner->shouldReceive('plan')->once()->andReturn([
        'chain_title' => 'NL chain',
        'chain_theme' => 'NL chain',
        'source_locale' => 'nl',
        'locales' => ['nl'],
        'articles' => [
            ['sequence' => 1, 'title' => 'NL bronartikel', 'target_locale' => 'nl'],
        ],
    ]);
    $this->app->instance(ContentAutomationPlanner::class, $planner);

    $articleService = \Mockery::mock(ContentAutomationArticleService::class);
    $articleService->shouldReceive('execute')->once()->andReturnUsing(function (ContentAutomation $automation, ContentAutomationRun $run, array $plan) use ($user) {
        [$content, $brief, $draft] = createAutomationContentBundle($automation, (string) $run->id, (string) $plan['title'], 'nl', $user->id);

        return [
            'content_id' => (string) $content->id,
            'brief_id' => (string) $brief->id,
            'draft_id' => (string) $draft->id,
            'published_content_ids' => [],
        ];
    });
    $this->app->instance(ContentAutomationArticleService::class, $articleService);

    $run = app(ContentAutomationOrchestrator::class)->run($automation, \App\Enums\ContentAutomationTriggerType::MANUAL, $user->id);

    $items = $run->fresh('items')->items;

    expect($run->status->value)->toBe('completed')
        ->and($items)->toHaveCount(1)
        ->and($items->first()->locale)->toBe('nl')
        ->and($items->first()->item_type)->toBe('source')
        ->and($items->first()->generation_status)->toBe('completed')
        ->and($items->first()->translation_status)->toBe('not_required');
});

it('uses the workspace default source locale instead of a hardcoded en fallback', function () {
    [, $automation] = makeAutomationLocaleHistoryContext([
        'locale' => 'nl',
        'locales' => ['nl', 'de'],
    ]);

    $workspace = $automation->workspace;
    $workspace->forceFill([
        'default_content_language' => 'nl',
        'enabled_content_languages' => ['nl', 'de'],
    ])->save();

    $probe = new ContentAutomation([
        'locale' => null,
        'locales' => ['de'],
    ]);
    $probe->setRelation('workspace', $workspace);

    expect($probe->sourceLocale())->toBe('nl')
        ->and($probe->configuredLocales())->toBe(['nl', 'de'])
        ->and($probe->targetLocales())->toBe(['de']);
});

it('creates a pending EN translation result item instead of marking it completed before content exists', function () {
    [$user, $automation] = makeAutomationLocaleHistoryContext([
        'locale' => 'nl',
        'locales' => ['nl', 'en'],
        'include_translation' => true,
    ]);

    $planner = \Mockery::mock(ContentAutomationPlanner::class);
    $planner->shouldReceive('plan')->once()->andReturn([
        'chain_title' => 'NL EN chain',
        'chain_theme' => 'NL EN chain',
        'source_locale' => 'nl',
        'locales' => ['nl', 'en'],
        'articles' => [
            ['sequence' => 1, 'title' => 'NL bronartikel', 'target_locale' => 'nl'],
        ],
    ]);
    $this->app->instance(ContentAutomationPlanner::class, $planner);

    $articleService = \Mockery::mock(ContentAutomationArticleService::class);
    $articleService->shouldReceive('execute')->once()->andReturnUsing(function (ContentAutomation $automation, ContentAutomationRun $run, array $plan) use ($user) {
        [$content, $brief, $draft] = createAutomationContentBundle($automation, (string) $run->id, (string) $plan['title'], 'nl', $user->id);

        return [
            'content_id' => (string) $content->id,
            'brief_id' => (string) $brief->id,
            'draft_id' => (string) $draft->id,
            'published_content_ids' => [],
            'queued_translation_locales' => ['en'],
            'translation_queue_results' => [
                [
                    'locale' => 'en',
                    'mode' => 'translate',
                    'existing_variant_id' => null,
                    'source_content_id' => (string) $content->id,
                ],
            ],
        ];
    });
    $this->app->instance(ContentAutomationArticleService::class, $articleService);

    $run = app(ContentAutomationOrchestrator::class)->run($automation, \App\Enums\ContentAutomationTriggerType::MANUAL, $user->id);

    $items = $run->fresh('items')->items->keyBy(fn (ContentAutomationRunItem $item): string => $item->locale);

    expect($run->status->value)->toBe('partial')
        ->and($items->keys()->all())->toBe(['nl', 'en'])
        ->and($items['nl']->status)->toBe('completed')
        ->and($items['en']->item_type)->toBe('translation')
        ->and($items['en']->status)->toBe('partial')
        ->and($items['en']->translation_status)->toBe('queued')
        ->and($items['en']->content_id)->toBeNull();
});

it('syncs a completed EN translation back into run history and recent content from the real content record', function () {
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    [$user, $automation] = makeAutomationLocaleHistoryContext([
        'locale' => 'nl',
        'locales' => ['nl', 'en'],
        'include_translation' => true,
    ]);

    $planner = \Mockery::mock(ContentAutomationPlanner::class);
    $planner->shouldReceive('plan')->once()->andReturn([
        'chain_title' => 'NL EN chain',
        'chain_theme' => 'NL EN chain',
        'source_locale' => 'nl',
        'locales' => ['nl', 'en'],
        'articles' => [
            ['sequence' => 1, 'title' => 'NL bronartikel', 'target_locale' => 'nl'],
        ],
    ]);
    $this->app->instance(ContentAutomationPlanner::class, $planner);

    $articleService = \Mockery::mock(ContentAutomationArticleService::class);
    $articleService->shouldReceive('execute')->once()->andReturnUsing(function (ContentAutomation $automation, ContentAutomationRun $run, array $plan) use ($user) {
        [$content, $brief, $draft] = createAutomationContentBundle($automation, (string) $run->id, (string) $plan['title'], 'nl', $user->id);

        return [
            'content_id' => (string) $content->id,
            'brief_id' => (string) $brief->id,
            'draft_id' => (string) $draft->id,
            'published_content_ids' => [],
            'queued_translation_locales' => ['en'],
            'translation_queue_results' => [
                [
                    'locale' => 'en',
                    'mode' => 'translate',
                    'existing_variant_id' => null,
                    'source_content_id' => (string) $content->id,
                ],
            ],
        ];
    });
    $this->app->instance(ContentAutomationArticleService::class, $articleService);

    $run = app(ContentAutomationOrchestrator::class)->run($automation, \App\Enums\ContentAutomationTriggerType::MANUAL, $user->id);
    $sourceContent = Content::query()->where('automation_run_id', (string) $run->id)->where('language', 'nl')->firstOrFail();
    $staleFailureItem = $run->fresh('items')->items->firstWhere('locale', 'en');
    $staleFailureItem->forceFill([
        'failure_stage' => 'translation_queue',
        'last_error_code' => 'translation_queue_failed',
        'last_error_message' => "A translation to 'English' is already processing.",
    ])->save();
    $run->forceFill([
        'error_message' => "A translation to 'English' is already processing.",
    ])->save();
    $automation->forceFill([
        'last_failure_message' => "A translation to 'English' is already processing.",
        'last_failure_code' => 'translation_queue_failed',
        'last_failure_run_id' => (string) $run->id,
        'last_failure_at' => now(),
    ])->save();

    [$translatedContent] = createAutomationContentBundle(
        $automation,
        (string) $run->id,
        'EN translation article',
        'en',
        $user->id,
        [
            'translation_source_content_id' => (string) $sourceContent->id,
            'translation_source_locale' => 'nl',
            'is_source_locale' => false,
        ],
    );

    app(AutomationRunItemStateService::class)->syncFromContent($translatedContent->fresh(['drafts', 'publications']) ?? $translatedContent);

    $run->refresh();
    $items = $run->fresh('items')->items->keyBy(fn (ContentAutomationRunItem $item): string => $item->locale);

    expect($run->status->value)->toBe('completed')
        ->and($run->generated_content_ids)->toHaveCount(2)
        ->and($items['en']->status)->toBe('completed')
        ->and($items['en']->translation_status)->toBe('completed')
        ->and($items['en']->failure_stage)->toBeNull()
        ->and($items['en']->last_error_code)->toBeNull()
        ->and($items['en']->last_error_message)->toBeNull()
        ->and($run->error_message)->toBeNull()
        ->and($automation->fresh()->last_failure_message)->toBeNull()
        ->and($automation->fresh()->last_failure_code)->toBeNull()
        ->and($automation->fresh()->last_failure_run_id)->toBeNull()
        ->and($items['en']->content_id)->toBe((string) $translatedContent->id);

    $this->actingAs($user)
        ->get(route('app.content.automations.show', $automation))
        ->assertOk()
        ->assertSee('NL bronartikel')
        ->assertSee('EN translation article')
        ->assertSee('Translation')
        ->assertSee('Generated NL source')
        ->assertSee('Queued EN translation')
        ->assertSee('EN translation completed');
});

it('marks the EN locale item as failed when translation processing fails after source generation', function () {
    [$user, $automation] = makeAutomationLocaleHistoryContext([
        'locale' => 'nl',
        'locales' => ['nl', 'en'],
        'include_translation' => true,
    ]);

    $planner = \Mockery::mock(ContentAutomationPlanner::class);
    $planner->shouldReceive('plan')->once()->andReturn([
        'chain_title' => 'NL EN chain',
        'chain_theme' => 'NL EN chain',
        'source_locale' => 'nl',
        'locales' => ['nl', 'en'],
        'articles' => [
            ['sequence' => 1, 'title' => 'NL bronartikel', 'target_locale' => 'nl'],
        ],
    ]);
    $this->app->instance(ContentAutomationPlanner::class, $planner);

    $sourceDraft = null;

    $articleService = \Mockery::mock(ContentAutomationArticleService::class);
    $articleService->shouldReceive('execute')->once()->andReturnUsing(function (ContentAutomation $automation, ContentAutomationRun $run, array $plan) use ($user, &$sourceDraft) {
        [$content, $brief, $draft] = createAutomationContentBundle($automation, (string) $run->id, (string) $plan['title'], 'nl', $user->id);
        $sourceDraft = $draft;

        return [
            'content_id' => (string) $content->id,
            'brief_id' => (string) $brief->id,
            'draft_id' => (string) $draft->id,
            'published_content_ids' => [],
            'queued_translation_locales' => ['en'],
            'translation_queue_results' => [
                [
                    'locale' => 'en',
                    'mode' => 'translate',
                    'existing_variant_id' => null,
                    'source_content_id' => (string) $content->id,
                ],
            ],
        ];
    });
    $this->app->instance(ContentAutomationArticleService::class, $articleService);

    $run = app(ContentAutomationOrchestrator::class)->run($automation, \App\Enums\ContentAutomationTriggerType::MANUAL, $user->id);

    app(AutomationRunItemStateService::class)->markTranslationFailure(
        $sourceDraft,
        'en',
        new RuntimeException('Translation provider failed.'),
    );

    $translationItem = $run->fresh('items')->items->firstWhere('locale', 'en');

    expect($translationItem->status)->toBe('failed')
        ->and($translationItem->translation_status)->toBe('failed')
        ->and($translationItem->last_error_message)->toBe('Translation provider failed.')
        ->and($run->fresh()->status->value)->toBe('partial');
});

it('marks reused translation content from a previous run as completed on the current run item', function () {
    [$user, $automation] = makeAutomationLocaleHistoryContext([
        'locale' => 'en',
        'locales' => ['en', 'nl'],
        'include_translation' => true,
    ]);

    $previousRun = ContentAutomationRun::query()->create([
        'automation_id' => (string) $automation->id,
        'organization_id' => (int) $automation->organization_id,
        'workspace_id' => (string) $automation->workspace_id,
        'client_site_id' => (string) $automation->client_site_id,
        'status' => 'completed',
        'triggered_by' => 'scheduled',
        'started_at' => now()->subDay(),
        'finished_at' => now()->subDay(),
        'metadata' => [],
    ]);

    [$sourceContent, , $sourceDraft] = createAutomationContentBundle(
        $automation,
        (string) $previousRun->id,
        'Existing EN source',
        'en',
        $user->id,
        [
            'is_source_locale' => true,
            'publish_status' => 'published',
            'delivery_status' => 'delivered',
        ],
    );
    [$translatedContent] = createAutomationContentBundle(
        $automation,
        (string) $previousRun->id,
        'Existing NL translation',
        'nl',
        $user->id,
        [
            'translation_source_content_id' => (string) $sourceContent->id,
            'translation_source_locale' => 'en',
            'is_source_locale' => false,
            'publish_status' => 'published',
            'delivery_status' => 'delivered',
        ],
    );

    $run = ContentAutomationRun::query()->create([
        'automation_id' => (string) $automation->id,
        'organization_id' => (int) $automation->organization_id,
        'workspace_id' => (string) $automation->workspace_id,
        'client_site_id' => (string) $automation->client_site_id,
        'status' => 'running',
        'triggered_by' => 'manual',
        'started_at' => now(),
        'metadata' => [],
    ]);
    $sourceItem = ContentAutomationRunItem::query()->create([
        'automation_run_id' => (string) $run->id,
        'automation_id' => (string) $automation->id,
        'chain_index' => 1,
        'item_type' => ContentAutomationRunItem::TYPE_SOURCE,
        'status' => ContentAutomationRunItem::STATUS_COMPLETED,
        'content_id' => (string) $sourceContent->id,
        'draft_id' => (string) $sourceDraft->id,
        'client_site_id' => (string) $automation->client_site_id,
        'locale' => 'en',
        'source_locale' => 'en',
        'is_source_locale' => true,
        'generation_status' => 'completed',
        'translation_status' => ContentAutomationRunItem::TRANSLATION_STATUS_NOT_REQUIRED,
    ]);
    $translationItem = ContentAutomationRunItem::query()->create([
        'automation_run_id' => (string) $run->id,
        'automation_id' => (string) $automation->id,
        'source_run_item_id' => (string) $sourceItem->id,
        'chain_index' => 101,
        'item_type' => ContentAutomationRunItem::TYPE_TRANSLATION,
        'status' => ContentAutomationRunItem::STATUS_PLANNED,
        'client_site_id' => (string) $automation->client_site_id,
        'locale' => 'nl',
        'source_locale' => 'en',
        'is_source_locale' => false,
        'generation_status' => 'pending',
        'translation_status' => 'pending',
    ]);

    app(AutomationRunItemStateService::class)->recordSourceResult($automation, $run, $sourceItem, [
        'content_id' => (string) $sourceContent->id,
        'draft_id' => (string) $sourceDraft->id,
        'published_content_ids' => [],
        'translation_queue_results' => [
            [
                'locale' => 'nl',
                'mode' => 'existing_reused',
                'existing_variant_id' => (string) $translatedContent->id,
                'source_content_id' => (string) $sourceContent->id,
            ],
        ],
    ]);

    $translationItem->refresh();

    expect($translationItem->content_id)->toBe((string) $translatedContent->id)
        ->and($translationItem->status)->toBe(ContentAutomationRunItem::STATUS_COMPLETED)
        ->and($translationItem->translation_status)->toBe('completed')
        ->and($translationItem->delivery_status)->toBe('delivered')
        ->and($translationItem->publication_status)->toBe('published')
        ->and($translationItem->last_error_message)->toBeNull();
});

it('recovers an existing translation variant when the queue reports a duplicate target', function () {
    [$user, $automation] = makeAutomationLocaleHistoryContext([
        'locale' => 'en',
        'locales' => ['en', 'nl'],
        'include_translation' => true,
    ]);
    $run = ContentAutomationRun::query()->create([
        'automation_id' => (string) $automation->id,
        'organization_id' => (int) $automation->organization_id,
        'workspace_id' => (string) $automation->workspace_id,
        'client_site_id' => (string) $automation->client_site_id,
        'status' => 'running',
        'triggered_by' => 'manual',
        'started_at' => now(),
        'metadata' => [],
    ]);

    [$sourceContent, , $sourceDraft] = createAutomationContentBundle(
        $automation,
        (string) $run->id,
        'Existing EN source',
        'en',
        $user->id,
        [
            'is_source_locale' => true,
            'publish_status' => 'published',
            'delivery_status' => 'delivered',
        ],
    );
    [$translatedContent] = createAutomationContentBundle(
        $automation,
        (string) $run->id,
        'Existing NL translation',
        'nl',
        $user->id,
        [
            'translation_source_content_id' => (string) $sourceContent->id,
            'translation_source_locale' => 'en',
            'is_source_locale' => false,
            'publish_status' => 'published',
            'delivery_status' => 'delivered',
        ],
    );

    $translationService = \Mockery::mock(\App\Services\Translation\TranslationService::class);
    $translationService
        ->shouldReceive('resolveExistingTargetVariantForRefresh')
        ->once()
        ->andThrow(new \RuntimeException("A translation to 'Dutch' already exists for this draft."));
    $this->app->instance(\App\Services\Translation\TranslationService::class, $translationService);

    $articleService = app(ContentAutomationArticleService::class);
    $method = new ReflectionMethod($articleService, 'existingTranslationVariantForQueueFailure');
    $method->setAccessible(true);

    $existingVariant = $method->invoke(
        $articleService,
        $sourceDraft,
        'nl',
        new \RuntimeException("A translation to 'Dutch' already exists for this draft.")
    );

    expect($existingVariant)->toBeInstanceOf(Content::class)
        ->and((string) $existingVariant->id)->toBe((string) $translatedContent->id);
});

it('repairs duplicate translation automation failures from existing locale variants', function () {
    [$user, $automation] = makeAutomationLocaleHistoryContext([
        'locale' => 'en',
        'locales' => ['en', 'nl'],
        'include_translation' => true,
    ]);
    $run = ContentAutomationRun::query()->create([
        'automation_id' => (string) $automation->id,
        'organization_id' => (int) $automation->organization_id,
        'workspace_id' => (string) $automation->workspace_id,
        'client_site_id' => (string) $automation->client_site_id,
        'status' => 'failed',
        'triggered_by' => 'manual',
        'started_at' => now()->subHour(),
        'finished_at' => now()->subMinutes(30),
        'error_message' => "A translation to 'Dutch' already exists for this draft.",
        'metadata' => [
            'last_error_code' => 'translation_queue_failed',
            'last_error_message' => "A translation to 'Dutch' already exists for this draft.",
        ],
    ]);

    [$sourceContent, , $sourceDraft] = createAutomationContentBundle(
        $automation,
        (string) $run->id,
        'Existing EN source',
        'en',
        $user->id,
        [
            'is_source_locale' => true,
            'publish_status' => 'published',
            'delivery_status' => 'delivered',
        ],
    );
    [$translatedContent] = createAutomationContentBundle(
        $automation,
        (string) $run->id,
        'Existing NL translation',
        'nl',
        $user->id,
        [
            'translation_source_content_id' => (string) $sourceContent->id,
            'translation_source_locale' => 'en',
            'is_source_locale' => false,
            'publish_status' => 'published',
            'delivery_status' => 'delivered',
        ],
    );

    $sourceItem = ContentAutomationRunItem::query()->create([
        'automation_run_id' => (string) $run->id,
        'automation_id' => (string) $automation->id,
        'chain_index' => 1,
        'item_type' => ContentAutomationRunItem::TYPE_SOURCE,
        'status' => ContentAutomationRunItem::STATUS_COMPLETED,
        'content_id' => (string) $sourceContent->id,
        'draft_id' => (string) $sourceDraft->id,
        'client_site_id' => (string) $automation->client_site_id,
        'locale' => 'en',
        'source_locale' => 'en',
        'is_source_locale' => true,
        'generation_status' => 'completed',
        'translation_status' => ContentAutomationRunItem::TRANSLATION_STATUS_NOT_REQUIRED,
    ]);
    $translationItem = ContentAutomationRunItem::query()->create([
        'automation_run_id' => (string) $run->id,
        'automation_id' => (string) $automation->id,
        'source_run_item_id' => (string) $sourceItem->id,
        'chain_index' => 101,
        'item_type' => ContentAutomationRunItem::TYPE_TRANSLATION,
        'status' => ContentAutomationRunItem::STATUS_FAILED,
        'failure_stage' => 'translation_queue',
        'last_error_code' => 'translation_queue_failed',
        'last_error_message' => "A translation to 'Dutch' already exists for this draft.",
        'client_site_id' => (string) $automation->client_site_id,
        'locale' => 'nl',
        'source_locale' => 'en',
        'is_source_locale' => false,
        'generation_status' => 'pending',
        'translation_status' => 'failed',
    ]);

    $automation->forceFill([
        'last_failure_message' => "A translation to 'Dutch' already exists for this draft.",
        'last_failure_code' => 'translation_queue_failed',
        'last_failure_run_id' => (string) $run->id,
        'last_failure_at' => now(),
    ])->save();

    $this->artisan('automations:repair-duplicate-translation-failures', [
        '--run-id' => (string) $run->id,
    ])->assertSuccessful();

    expect($translationItem->fresh()->status)->toBe(ContentAutomationRunItem::STATUS_FAILED);

    $this->artisan('automations:repair-duplicate-translation-failures', [
        '--run-id' => (string) $run->id,
        '--apply' => true,
    ])->assertSuccessful();

    $translationItem->refresh();
    $run->refresh();
    $automation->refresh();

    expect($translationItem->content_id)->toBe((string) $translatedContent->id)
        ->and($translationItem->status)->toBe(ContentAutomationRunItem::STATUS_COMPLETED)
        ->and($translationItem->translation_status)->toBe('completed')
        ->and($translationItem->last_error_message)->toBeNull()
        ->and($run->status->value)->toBe('completed')
        ->and($run->error_message)->toBeNull()
        ->and($automation->last_failure_message)->toBeNull()
        ->and($automation->last_failure_run_id)->toBeNull();
});

it('repairs historical locale labels from real content records and supports dry run', function () {
    [$user, $automation] = makeAutomationLocaleHistoryContext([
        'locale' => 'nl',
        'locales' => ['nl', 'en'],
        'include_translation' => true,
    ]);

    $run = ContentAutomationRun::query()->create([
        'automation_id' => (string) $automation->id,
        'organization_id' => (int) $automation->organization_id,
        'workspace_id' => (string) $automation->workspace_id,
        'client_site_id' => (string) $automation->client_site_id,
        'status' => 'partial',
        'triggered_by' => 'manual',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'generated_content_ids' => [],
        'published_content_ids' => [],
        'metadata' => [],
    ]);

    [$sourceContent] = createAutomationContentBundle($automation, (string) $run->id, 'NL bronartikel', 'nl', $user->id);
    [$translatedContent] = createAutomationContentBundle(
        $automation,
        (string) $run->id,
        'EN article',
        'en',
        $user->id,
        [
            'translation_source_content_id' => (string) $sourceContent->id,
            'translation_source_locale' => 'nl',
            'is_source_locale' => false,
        ],
    );

    $brokenItem = ContentAutomationRunItem::query()->create([
        'automation_run_id' => (string) $run->id,
        'automation_id' => (string) $automation->id,
        'source_run_item_id' => null,
        'chain_index' => 101,
        'item_type' => 'translation',
        'status' => 'completed',
        'content_id' => (string) $translatedContent->id,
        'content_family_id' => (string) $sourceContent->id,
        'client_site_id' => (string) $automation->client_site_id,
        'locale' => 'nl',
        'source_locale' => 'nl',
        'is_source_locale' => false,
        'generation_status' => 'completed',
        'translation_status' => 'completed',
    ]);

    $this->artisan('automations:repair-locale-history', [
        '--run-id' => (string) $run->id,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect($brokenItem->fresh()->locale)->toBe('nl');

    $this->artisan('automations:repair-locale-history', [
        '--run-id' => (string) $run->id,
    ])->assertSuccessful();

    expect($brokenItem->fresh()->locale)->toBe('en')
        ->and($brokenItem->fresh()->content_id)->toBe((string) $translatedContent->id)
        ->and($run->fresh()->generated_content_ids)->toHaveCount(2);
});

function makeAutomationLocaleHistoryContext(array $automationOverrides = []): array
{
    $organization = Organization::query()->create([
        'name' => 'Automation Locale Org',
        'slug' => 'automation-locale-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Automation Locale BV',
        'billing_address_line1' => 'Locale straat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Automation Locale Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => (string) $workspace->id,
        'type' => 'wordpress',
        'name' => 'Automation Locale Site',
        'site_url' => 'https://automation-locale.example.com',
        'base_url' => 'https://automation-locale.example.com',
        'allowed_domains' => ['automation-locale.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'automation-locale-plan'],
        [
            'name' => 'Automation Locale Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $automation = ContentAutomation::query()->create(array_merge([
        'organization_id' => $organization->id,
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'name' => 'Locale automation',
        'is_active' => true,
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 3,
        'generation_frequency_unit' => 'days',
        'next_run_at' => now()->subMinute(),
        'chain_size' => 1,
        'locale' => 'nl',
        'locales' => ['nl'],
        'include_translation' => false,
        'topic_scope' => 'Locale test scope',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ], $automationOverrides));

    app(\App\Services\CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 50,
        type: \App\Services\CreditWalletService::TYPE_ADJUSTMENT,
        meta: ['source' => 'automation-locale-history-test'],
    );

    return [$user, $automation];
}

/**
 * @param  array<string, mixed>  $contentOverrides
 * @return array{0: Content, 1: Brief, 2: Draft}
 */
function createAutomationContentBundle(
    ContentAutomation $automation,
    string $runId,
    string $title,
    string $language,
    int $userId,
    array $contentOverrides = [],
): array {
    $content = Content::query()->create(array_merge([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $automation->workspace_id,
        'client_site_id' => (string) $automation->client_site_id,
        'title' => $title,
        'language' => $language,
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'automation',
        'automation_id' => (string) $automation->id,
        'automation_run_id' => $runId,
        'created_by' => $userId,
        'updated_by' => $userId,
    ], $contentOverrides));

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $automation->client_site_id,
        'created_by_user_id' => $userId,
        'status' => 'done',
        'source' => 'automation',
        'progress' => 1,
        'title' => $title,
        'language' => $language,
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $automation->client_site_id,
        'status' => 'ready',
        'title' => $title,
        'output_type' => 'kb_article',
        'language' => $language,
        'draft_type' => $content->is_source_locale ? 'original' : 'translation',
        'content_html' => '<p>Ready content</p>',
        'delivery_status' => 'pending',
    ]);

    return [$content, $brief, $draft];
}
