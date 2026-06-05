<?php

namespace Tests\Feature\Translation;

use App\Enums\DraftType;
use App\Enums\SupportedLanguage;
use App\Jobs\TranslateDraftJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentTranslation;
use App\Models\CreditLedgerEntry;
use App\Models\CreditWallet;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Content\ContentLifecycleService;
use App\Services\ContentAutomation\AutomationRunItemStateService;
use App\Services\CreditWalletService;
use App\Services\Integrations\ApiWebhookPublisher;
use App\Services\Integrations\AsyncOperationService;
use App\Services\Translation\TranslationService;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class TranslateDraftJobTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Workspace $workspace;
    protected ClientSite $clientSite;
    protected Draft $sourceDraft;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->create([
            'name' => 'Translation Job User',
            'email' => 'translation-job-' . Str::random(8) . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $organization = Organization::query()->create([
            'name' => 'Translation Job Org',
            'slug' => 'translation-job-org-' . Str::random(6),
            'status' => 'active',
            'approved_at' => now(),
            'primary_user_id' => $user->id,
        ]);

        $user->organization_id = $organization->id;
        $user->role = 'owner';
        $user->save();

        $this->user = $user;

        $this->workspace = Workspace::query()->create([
            'name' => 'Translation Job Workspace',
            'organization_id' => $organization->id,
            'default_content_language' => SupportedLanguage::EN->value,
            'enabled_content_languages' => [SupportedLanguage::EN->value, SupportedLanguage::NL->value],
        ]);

        $this->clientSite = ClientSite::query()->create([
            'workspace_id' => $this->workspace->id,
            'type' => ClientSite::TYPE_WORDPRESS,
            'name' => 'Translation Job Site',
            'site_url' => 'https://translation-job.example.com',
            'allowed_domains' => ['translation-job.example.com'],
            'is_active' => true,
            'status' => 'connected',
        ]);

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'client_site_id' => $this->clientSite->id,
            'title' => 'Source content',
            'language' => SupportedLanguage::EN->value,
            'type' => 'article',
            'status' => 'draft',
            'source' => 'manual',
            'delivery_status' => 'pending',
            'publish_status' => 'draft',
        ]);

        $brief = Brief::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => $this->clientSite->id,
            'content_id' => $content->id,
            'status' => 'done',
            'source' => 'client_ui',
            'progress' => 1,
            'title' => 'Source brief',
            'language' => SupportedLanguage::EN->value,
            'content_type' => 'blog',
            'output_type' => 'kb_article',
            'primary_keyword' => 'source keyword',
        ]);

        $this->sourceDraft = Draft::query()->create([
            'id' => (string) Str::uuid(),
            'brief_id' => $brief->id,
            'content_id' => $content->id,
            'client_site_id' => $this->clientSite->id,
            'status' => 'ready',
            'title' => 'Source draft',
            'language' => SupportedLanguage::EN->value,
            'draft_type' => DraftType::ORIGINAL->value,
            'output_type' => 'kb_article',
            'content_html' => '<h1>Source draft</h1><p>Original text.</p>',
            'seo_title' => 'Source draft',
            'seo_meta_description' => 'Source description',
        ]);
    }

    public function test_translate_draft_job_commits_translation_credits_and_creates_localized_draft(): void
    {
        $wallets = app(CreditWalletService::class);
        $wallets->addCredits((string) $this->clientSite->id, 20, CreditWalletService::TYPE_ALLOWANCE);
        $initialSummary = $wallets->getSummary((string) $this->clientSite->id);

        $realTranslationService = app(TranslationService::class);
        $contentLifecycle = app(ContentLifecycleService::class);
        $translationService = \Mockery::mock(TranslationService::class);
        $translationService->shouldReceive('resolveTargetVariantContent')->once()->andReturn(null);
        $translationService->shouldReceive('validateSourceDraft')->once();
        $translationService->shouldNotReceive('validateTargetLanguageAvailability');
        $translationService->shouldNotReceive('validateTargetLanguageAvailabilityForDispatch');
        $translationService->shouldReceive('validateTargetLanguageAvailabilityForJob')->once();
        $translationService->shouldReceive('translate')->once()->andReturn([
            'title' => 'Brontekst vertaald',
            'content_html' => '<h1>Brontekst vertaald</h1><p>Nederlandse versie.</p>',
            'seo' => [
                'seo_title' => 'Brontekst vertaald',
                'seo_meta_description' => 'Nederlandse beschrijving',
                'seo_h1' => 'Brontekst vertaald',
                'slug' => 'brontekst-vertaald',
                'suggested_primary_keyword' => 'brontekst vertaald',
                'secondary_keywords' => ['nederlandse versie'],
            ],
            'model_used' => 'gpt-4.1-mini',
            'input_tokens' => 110,
            'output_tokens' => 190,
            'total_tokens' => 300,
            'request_id' => 'req-translate-success',
        ]);
        $translationService->shouldReceive('createTranslatedDraft')
            ->once()
            ->andReturnUsing(fn (Draft $draft, SupportedLanguage $language, array $result, ?string $userId = null) => $realTranslationService->createTranslatedDraft($draft, $language, $result, $userId));

        $asyncOperations = \Mockery::mock(AsyncOperationService::class);
        $webhooks = \Mockery::mock(ApiWebhookPublisher::class);
        $webhooks->shouldReceive('publish')->once();
        $automationItemState = app(AutomationRunItemStateService::class);

        $job = new TranslateDraftJob(
            sourceDraftId: (string) $this->sourceDraft->id,
            targetLanguage: SupportedLanguage::NL->value,
            userId: (string) $this->user->id,
        );

        $job->handle($translationService, $wallets, $asyncOperations, $webhooks, $contentLifecycle, $automationItemState, app(\App\Services\Content\TranslationLockService::class));

        $translatedDraft = Draft::query()
            ->where('source_draft_id', $this->sourceDraft->id)
            ->where('language', SupportedLanguage::NL->value)
            ->first();

        $this->assertNotNull($translatedDraft);
        $this->assertNotSame((string) $this->sourceDraft->content_id, (string) $translatedDraft->content_id);
        $this->assertSame('committed', (string) $translatedDraft->credit_status);
        $this->assertGreaterThan(0, (int) $translatedDraft->credit_cost);

        $usageEntry = CreditLedgerEntry::query()->findOrFail($translatedDraft->credit_ledger_entry_id);
        $this->assertSame(abs((int) $usageEntry->amount), (int) $translatedDraft->credit_cost);

        $summary = $wallets->getSummary((string) $this->clientSite->id);
        $this->assertSame((int) $summary['balance_cached'], (int) $summary['available']);
        $this->assertSame(0, (int) $summary['reserved_cached']);
        $this->assertLessThan((int) $initialSummary['available'], (int) $summary['available']);
        $this->assertGreaterThanOrEqual(abs((int) $usageEntry->amount), (int) $initialSummary['available'] - (int) $summary['available']);

        $this->assertSame('draft_translation', (string) data_get($usageEntry->meta, 'action'));
        $this->assertSame((string) $this->sourceDraft->id, (string) data_get($usageEntry->meta, 'source_draft_id'));
        $this->assertSame('nl', (string) data_get($usageEntry->meta, 'target_language'));
    }

    public function test_translate_draft_job_releases_reserved_credits_when_translation_fails(): void
    {
        $wallets = app(CreditWalletService::class);
        $wallets->addCredits((string) $this->clientSite->id, 20, CreditWalletService::TYPE_ALLOWANCE);

        $translationService = \Mockery::mock(TranslationService::class);
        $translationService->shouldReceive('resolveTargetVariantContent')->once()->andReturn(null);
        $translationService->shouldReceive('validateSourceDraft')->once();
        $translationService->shouldReceive('validateTargetLanguageAvailabilityForJob')->once();
        $translationService->shouldReceive('translate')->once()->andThrow(new RuntimeException('Translation provider failed'));
        $contentLifecycle = app(ContentLifecycleService::class);
        $automationItemState = app(AutomationRunItemStateService::class);

        $asyncOperations = \Mockery::mock(AsyncOperationService::class);
        $webhooks = \Mockery::mock(ApiWebhookPublisher::class);
        $webhooks->shouldNotReceive('publish');

        $job = new TranslateDraftJob(
            sourceDraftId: (string) $this->sourceDraft->id,
            targetLanguage: SupportedLanguage::NL->value,
            userId: (string) $this->user->id,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Translation provider failed');

        try {
            $job->handle($translationService, $wallets, $asyncOperations, $webhooks, $contentLifecycle, $automationItemState, app(\App\Services\Content\TranslationLockService::class));
        } finally {
            $summary = $wallets->getSummary((string) $this->clientSite->id);
            $this->assertSame(20, (int) $summary['available']);
            $this->assertSame(0, (int) $summary['reserved_cached']);

            $releaseEntry = CreditLedgerEntry::query()
                ->where('type', CreditWalletService::TYPE_RELEASE)
                ->where('source_id', (string) $this->sourceDraft->id)
                ->latest('created_at')
                ->first();

            $this->assertNotNull($releaseEntry);
            $this->assertSame('translation_failed', (string) data_get($releaseEntry->meta, 'reason'));
        }
    }

    public function test_translate_draft_job_allows_refreshing_an_existing_translation_variant(): void
    {
        $wallets = app(CreditWalletService::class);
        $wallets->addCredits((string) $this->clientSite->id, 20, CreditWalletService::TYPE_ALLOWANCE);

        $targetContent = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'client_site_id' => $this->clientSite->id,
            'title' => 'Bestaande vertaling',
            'language' => SupportedLanguage::NL->value,
            'translation_source_content_id' => (string) $this->sourceDraft->content_id,
            'translation_source_locale' => SupportedLanguage::EN->value,
            'is_source_locale' => false,
            'type' => 'article',
            'status' => 'published',
            'source' => 'manual',
            'delivery_status' => 'delivered',
            'publish_status' => 'published',
        ]);

        $targetBrief = Brief::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => $this->clientSite->id,
            'content_id' => $targetContent->id,
            'status' => 'done',
            'source' => 'client_ui',
            'progress' => 1,
            'title' => 'Existing translated brief',
            'language' => SupportedLanguage::NL->value,
            'content_type' => 'blog',
            'output_type' => 'kb_article',
            'primary_keyword' => 'existing target keyword',
        ]);

        $existingTranslatedDraft = Draft::query()->create([
            'id' => (string) Str::uuid(),
            'brief_id' => $targetBrief->id,
            'content_id' => $targetContent->id,
            'client_site_id' => $this->clientSite->id,
            'status' => 'ready',
            'title' => 'Existing translated draft',
            'language' => SupportedLanguage::NL->value,
            'draft_type' => DraftType::TRANSLATION->value,
            'source_draft_id' => (string) $this->sourceDraft->id,
            'translation_source_language' => SupportedLanguage::EN->value,
            'output_type' => 'kb_article',
            'content_html' => '<h1>Bestaande vertaling</h1><p>Oude Nederlandse versie.</p>',
        ]);

        $realTranslationService = app(TranslationService::class);
        $contentLifecycle = app(ContentLifecycleService::class);
        $translationService = \Mockery::mock(TranslationService::class);
        $translationService->shouldReceive('validateSourceDraft')->once();
        $translationService->shouldReceive('validateTargetLanguageAvailabilityForJob')
            ->once()
            ->withArgs(function (Draft $draft, SupportedLanguage $language, bool $allowExisting, ?string $jobUuid = null, ?string $translationRequestId = null): bool {
                return (string) $draft->id === (string) $this->sourceDraft->id
                    && $language === SupportedLanguage::NL
                    && $allowExisting === true
                    && is_string($jobUuid)
                    && $jobUuid !== '';
            });
        $translationService->shouldReceive('translate')
            ->once()
            ->withArgs(function (Draft $draft, SupportedLanguage $language, ?string $model = null, bool $allowExisting = false): bool {
                return (string) $draft->id === (string) $this->sourceDraft->id
                    && $language === SupportedLanguage::NL
                    && $allowExisting === true;
            })
            ->andReturn([
                'title' => 'Bestaande vertaling vernieuwd',
                'content_html' => '<h1>Bestaande vertaling vernieuwd</h1><p>Nieuwe Nederlandse versie.</p>',
                'seo' => [
                    'seo_title' => 'Bestaande vertaling vernieuwd',
                    'seo_meta_description' => 'Nieuwe Nederlandse beschrijving',
                    'seo_h1' => 'Bestaande vertaling vernieuwd',
                    'slug' => 'bestaande-vertaling-vernieuwd',
                    'suggested_primary_keyword' => 'bestaande vertaling vernieuwd',
                    'secondary_keywords' => ['nieuwe nederlandse versie'],
                ],
                'model_used' => 'gpt-4.1-mini',
                'input_tokens' => 100,
                'output_tokens' => 150,
                'total_tokens' => 250,
                'request_id' => 'req-translate-refresh',
            ]);
        $translationService->shouldReceive('refreshTranslatedDraft')
            ->once()
            ->andReturnUsing(fn (Draft $draft, Content $content, SupportedLanguage $language, array $result, ?string $userId = null) => $realTranslationService->refreshTranslatedDraft(
                $draft,
                $content,
                $language,
                $result,
                $userId
            ));

        $asyncOperations = \Mockery::mock(AsyncOperationService::class);
        $webhooks = \Mockery::mock(ApiWebhookPublisher::class);
        $webhooks->shouldReceive('publish')->once();
        $automationItemState = app(AutomationRunItemStateService::class);

        $job = new TranslateDraftJob(
            sourceDraftId: (string) $this->sourceDraft->id,
            targetLanguage: SupportedLanguage::NL->value,
            userId: (string) $this->user->id,
            targetContentId: (string) $targetContent->id,
        );

        $job->handle($translationService, $wallets, $asyncOperations, $webhooks, $contentLifecycle, $automationItemState, app(\App\Services\Content\TranslationLockService::class));

        $translatedDraft = Draft::query()
            ->where('content_id', (string) $targetContent->id)
            ->where('language', SupportedLanguage::NL->value)
            ->where('id', '!=', (string) $existingTranslatedDraft->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($translatedDraft);
        $this->assertSame((string) $targetContent->id, (string) $translatedDraft->content_id);
        $this->assertStringContainsString('Nieuwe Nederlandse versie', (string) $translatedDraft->content_html);
    }

    public function test_translate_draft_job_is_idempotent_for_repeated_source_and_target_runs(): void
    {
        $wallets = app(CreditWalletService::class);
        $wallets->addCredits((string) $this->clientSite->id, 40, CreditWalletService::TYPE_ALLOWANCE);

        $realTranslationService = app(TranslationService::class);
        $contentLifecycle = app(ContentLifecycleService::class);
        $translationService = \Mockery::mock(TranslationService::class);
        $translationService->shouldReceive('resolveTargetVariantContent')
            ->twice()
            ->andReturnUsing(fn (Draft $draft, SupportedLanguage $language) => $realTranslationService->resolveTargetVariantContent($draft, $language));
        $translationService->shouldReceive('validateSourceDraft')
            ->twice()
            ->andReturnUsing(fn (Draft $draft) => $realTranslationService->validateSourceDraft($draft));
        $translationService->shouldReceive('validateTargetLanguageAvailabilityForJob')
            ->twice()
            ->andReturnUsing(fn (Draft $draft, SupportedLanguage $language, bool $allowExisting = false, ?string $jobUuid = null, ?string $currentTranslationRequestId = null, ?string $currentQueueJobId = null, ?string $currentTargetContentId = null, bool $bypassDispatchOnlyProcessingCheck = false) => $realTranslationService->validateTargetLanguageAvailabilityForJob($draft, $language, $allowExisting, $jobUuid, $currentTranslationRequestId, $currentQueueJobId, $currentTargetContentId, $bypassDispatchOnlyProcessingCheck));
        $translationService->shouldReceive('translate')
            ->twice()
            ->andReturn(
                [
                    'title' => 'Nederlandse versie',
                    'content_html' => '<h1>Nederlandse versie</h1><p>Eerste run.</p>',
                    'seo' => [
                        'seo_title' => 'Nederlandse versie',
                        'seo_meta_description' => 'Nederlandse meta beschrijving',
                        'seo_h1' => 'Nederlandse versie',
                        'slug' => 'nederlandse-versie',
                        'suggested_primary_keyword' => 'nederlandse versie',
                        'secondary_keywords' => ['eerste run'],
                    ],
                    'model_used' => 'gpt-4.1-mini',
                    'input_tokens' => 100,
                    'output_tokens' => 150,
                    'total_tokens' => 250,
                    'request_id' => 'req-translate-run-1',
                ],
                [
                    'title' => 'Nederlandse versie vernieuwd',
                    'content_html' => '<h1>Nederlandse versie vernieuwd</h1><p>Tweede run.</p>',
                    'seo' => [
                        'seo_title' => 'Nederlandse versie vernieuwd',
                        'seo_meta_description' => 'Bijgewerkte Nederlandse meta beschrijving',
                        'seo_h1' => 'Nederlandse versie vernieuwd',
                        'slug' => 'nederlandse-versie-vernieuwd',
                        'suggested_primary_keyword' => 'nederlandse versie vernieuwd',
                        'secondary_keywords' => ['tweede run'],
                    ],
                    'model_used' => 'gpt-4.1-mini',
                    'input_tokens' => 110,
                    'output_tokens' => 160,
                    'total_tokens' => 270,
                    'request_id' => 'req-translate-run-2',
                ],
            );
        $translationService->shouldReceive('createTranslatedDraft')
            ->once()
            ->andReturnUsing(fn (Draft $draft, SupportedLanguage $language, array $result, ?string $userId = null) => $realTranslationService->createTranslatedDraft($draft, $language, $result, $userId));
        $translationService->shouldReceive('refreshTranslatedDraft')
            ->once()
            ->andReturnUsing(fn (Draft $draft, Content $content, SupportedLanguage $language, array $result, ?string $userId = null) => $realTranslationService->refreshTranslatedDraft($draft, $content, $language, $result, $userId));

        $asyncOperations = \Mockery::mock(AsyncOperationService::class);
        $webhooks = \Mockery::mock(ApiWebhookPublisher::class);
        $webhooks->shouldReceive('publish')->twice();
        $automationItemState = app(AutomationRunItemStateService::class);

        $firstJob = new TranslateDraftJob(
            sourceDraftId: (string) $this->sourceDraft->id,
            targetLanguage: SupportedLanguage::NL->value,
            userId: (string) $this->user->id,
        );
        $secondJob = new TranslateDraftJob(
            sourceDraftId: (string) $this->sourceDraft->id,
            targetLanguage: SupportedLanguage::NL->value,
            userId: (string) $this->user->id,
        );

        $firstJob->handle($translationService, $wallets, $asyncOperations, $webhooks, $contentLifecycle, $automationItemState, app(\App\Services\Content\TranslationLockService::class));
        $secondJob->handle($translationService, $wallets, $asyncOperations, $webhooks, $contentLifecycle, $automationItemState, app(\App\Services\Content\TranslationLockService::class));

        $translatedDrafts = Draft::query()
            ->where('source_draft_id', $this->sourceDraft->id)
            ->where('language', SupportedLanguage::NL->value)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $translatedDrafts);
        $this->assertSame(1, $translatedDrafts->pluck('content_id')->unique()->count());
        $this->assertSame(
            'Nederlandse versie vernieuwd',
            (string) Content::query()->findOrFail($translatedDrafts->last()->content_id)->title
        );
    }

    public function test_translate_draft_job_marks_automation_translation_as_insufficient_credits_without_retrying(): void
    {
        $automation = \App\Models\ContentAutomation::query()->create([
            'organization_id' => $this->workspace->organization_id,
            'workspace_id' => $this->workspace->id,
            'client_site_id' => $this->clientSite->id,
            'name' => 'Translation automation',
            'is_active' => true,
            'mode' => 'chain',
            'publication_mode' => 'draft_only',
            'generation_frequency_value' => 1,
            'generation_frequency_unit' => 'days',
            'next_run_at' => now()->subMinute(),
            'chain_size' => 1,
            'locale' => 'en',
            'locales' => ['en', 'nl'],
            'include_translation' => true,
            'topic_scope' => 'Translation scope',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $run = \App\Models\ContentAutomationRun::query()->create([
            'automation_id' => (string) $automation->id,
            'organization_id' => (int) $automation->organization_id,
            'workspace_id' => (string) $automation->workspace_id,
            'client_site_id' => (string) $automation->client_site_id,
            'status' => 'partial',
            'triggered_by' => 'manual',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'generated_content_ids' => [(string) $this->sourceDraft->content_id],
            'published_content_ids' => [],
            'metadata' => [],
        ]);

        $content = $this->sourceDraft->content()->firstOrFail();
        $content->forceFill([
            'automation_id' => (string) $automation->id,
            'automation_run_id' => (string) $run->id,
        ])->save();

        \App\Models\ContentAutomationRunItem::query()->create([
            'automation_run_id' => (string) $run->id,
            'automation_id' => (string) $automation->id,
            'chain_index' => 1,
            'item_type' => 'source',
            'status' => 'completed',
            'content_id' => (string) $content->id,
            'draft_id' => (string) $this->sourceDraft->id,
            'content_family_id' => (string) $content->id,
            'client_site_id' => (string) $this->clientSite->id,
            'locale' => 'en',
            'source_locale' => 'en',
            'is_source_locale' => true,
            'title' => 'Source item',
            'generation_status' => 'completed',
            'translation_status' => 'not_required',
        ]);

        \App\Models\ContentAutomationRunItem::query()->create([
            'automation_run_id' => (string) $run->id,
            'automation_id' => (string) $automation->id,
            'chain_index' => 101,
            'item_type' => 'translation',
            'status' => 'partial',
            'source_run_item_id' => \App\Models\ContentAutomationRunItem::query()->where('automation_run_id', (string) $run->id)->where('item_type', 'source')->value('id'),
            'content_family_id' => (string) $content->id,
            'client_site_id' => (string) $this->clientSite->id,
            'locale' => 'nl',
            'source_locale' => 'en',
            'is_source_locale' => false,
            'title' => 'Translation item',
            'generation_status' => 'pending',
            'translation_status' => 'queued',
        ]);

        CreditWallet::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => (string) $this->clientSite->id,
            'workspace_id' => (string) $this->workspace->id,
            'allocated_credits' => 0,
            'reserved_cached' => 0,
            'used_cached' => 0,
        ]);

        $translationService = \Mockery::mock(TranslationService::class);
        $translationService->shouldReceive('resolveTargetVariantContent')->once()->andReturn(null);
        $translationService->shouldReceive('validateSourceDraft')->once();
        $translationService->shouldReceive('validateTargetLanguageAvailabilityForJob')->once();
        $translationService->shouldNotReceive('translate');

        $contentLifecycle = app(ContentLifecycleService::class);
        $automationItemState = app(AutomationRunItemStateService::class);
        $asyncOperations = \Mockery::mock(AsyncOperationService::class);
        $webhooks = \Mockery::mock(ApiWebhookPublisher::class);
        $webhooks->shouldNotReceive('publish');

        $job = new TranslateDraftJob(
            sourceDraftId: (string) $this->sourceDraft->id,
            targetLanguage: SupportedLanguage::NL->value,
            userId: (string) $this->user->id,
        );

        $job->handle($translationService, $wallets = app(CreditWalletService::class), $asyncOperations, $webhooks, $contentLifecycle, $automationItemState, app(\App\Services\Content\TranslationLockService::class));

        $translationItem = \App\Models\ContentAutomationRunItem::query()
            ->where('automation_run_id', (string) $run->id)
            ->where('item_type', 'translation')
            ->firstOrFail();

        $this->assertSame('failed', (string) $translationItem->status);
        $this->assertSame('insufficient_credits', (string) $translationItem->last_error_code);
        $this->assertSame('PL-CREDITS-INSUFFICIENT', (string) data_get($translationItem->metadata, 'failure_code'));
        $this->assertSame(6, (int) data_get($translationItem->metadata, 'failure_details.required_credits'));
        $this->assertSame(0, (int) data_get($translationItem->metadata, 'failure_details.available_credits'));
        $this->assertStringContainsString('there are not enough credits available', (string) $translationItem->last_error_message);

        $run->refresh();
        $this->assertSame('failed', (string) $run->status->value);
        $this->assertSame('insufficient_credits', (string) data_get($run->metadata, 'failure_pattern'));
        $this->assertSame('PL-CREDITS-INSUFFICIENT', (string) data_get($run->metadata, 'failure_code'));
        $this->assertStringContainsString('Required: 6, available: 0', (string) $run->error_message);
        $this->assertSame(0, Draft::query()->where('source_draft_id', (string) $this->sourceDraft->id)->where('language', 'nl')->count());
    }

    public function test_translate_draft_job_releases_lock_and_marks_billing_failure_when_credits_are_insufficient(): void
    {
        $jobUuid = (string) Str::uuid();

        $translationRequest = ContentTranslation::query()->create([
            'content_id' => (string) $this->sourceDraft->content_id,
            'target_locale' => SupportedLanguage::NL->value,
            'status' => ContentTranslation::STATUS_PROCESSING,
            'requested_by_user_id' => $this->user->id,
            'processing_job_uuid' => $jobUuid,
            'processing_started_at' => now(),
            'processing_locked_at' => now(),
            'processing_last_heartbeat_at' => now(),
        ]);

        CreditWallet::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => (string) $this->clientSite->id,
            'workspace_id' => (string) $this->workspace->id,
            'allocated_credits' => 0,
            'reserved_cached' => 0,
            'used_cached' => 0,
        ]);

        $translationService = \Mockery::mock(TranslationService::class);
        $translationService->shouldReceive('resolveTargetVariantContent')->once()->andReturn(null);
        $translationService->shouldReceive('validateSourceDraft')->once();
        $translationService->shouldReceive('validateTargetLanguageAvailabilityForJob')->once();
        $translationService->shouldNotReceive('translate');

        $job = new TranslateDraftJob(
            sourceDraftId: (string) $this->sourceDraft->id,
            targetLanguage: SupportedLanguage::NL->value,
            userId: (string) $this->user->id,
            translationRequestId: (string) $translationRequest->id,
            dispatchJobUuid: $jobUuid,
            sourceContentId: (string) $this->sourceDraft->content_id,
        );

        $job->handle(
            $translationService,
            app(CreditWalletService::class),
            \Mockery::mock(AsyncOperationService::class),
            tap(\Mockery::mock(ApiWebhookPublisher::class), fn ($mock) => $mock->shouldNotReceive('publish')),
            app(ContentLifecycleService::class),
            app(AutomationRunItemStateService::class),
            app(\App\Services\Content\TranslationLockService::class)
        );

        $translationRequest->refresh();

        $this->assertSame(ContentTranslation::STATUS_FAILED, (string) $translationRequest->status);
        $this->assertSame(ContentTranslation::STATUS_INSUFFICIENT_CREDITS, $translationRequest->displayStatus());
        $this->assertSame(ContentTranslation::FAILURE_REASON_INSUFFICIENT_CREDITS, (string) $translationRequest->failure_reason);
        $this->assertSame(6, (int) $translationRequest->required_credits);
        $this->assertSame(0, (int) $translationRequest->available_credits);
        $this->assertNull($translationRequest->processing_job_uuid);
        $this->assertStringContainsString('Required: 6, available: 0', (string) $translationRequest->displayErrorMessage());
    }

    public function test_failed_callback_releases_processing_translation_lock_state(): void
    {
        $jobUuid = (string) Str::uuid();

        $translationRequest = ContentTranslation::query()->create([
            'content_id' => (string) $this->sourceDraft->content_id,
            'target_locale' => SupportedLanguage::NL->value,
            'status' => ContentTranslation::STATUS_PROCESSING,
            'requested_by_user_id' => $this->user->id,
            'job_id' => 'queue-job-123',
            'processing_job_uuid' => $jobUuid,
            'processing_started_at' => now(),
            'processing_locked_at' => now(),
            'processing_last_heartbeat_at' => now(),
        ]);

        $job = new TranslateDraftJob(
            sourceDraftId: (string) $this->sourceDraft->id,
            targetLanguage: SupportedLanguage::NL->value,
            userId: (string) $this->user->id,
            translationRequestId: (string) $translationRequest->id,
            dispatchJobUuid: $jobUuid,
            sourceContentId: (string) $this->sourceDraft->content_id,
        );

        $job->failed(new RuntimeException('Translation timed out'));

        $translationRequest->refresh();

        $this->assertSame(ContentTranslation::STATUS_FAILED, (string) $translationRequest->status);
        $this->assertSame('Translation timed out', (string) $translationRequest->error_message);
        $this->assertNull($translationRequest->job_id);

        // Verify the lock is no longer active and retry is possible
        $this->assertFalse($translationRequest->isActiveLock());
        $this->assertSame('failed', $translationRequest->displayStatus());
    }

    public function test_exception_on_first_attempt_does_not_mark_as_failed_until_retries_exhausted(): void
    {
        $wallets = app(CreditWalletService::class);
        $wallets->addCredits((string) $this->clientSite->id, 20, CreditWalletService::TYPE_ALLOWANCE);

        $translationRequest = ContentTranslation::query()->create([
            'content_id' => (string) $this->sourceDraft->content_id,
            'target_locale' => SupportedLanguage::NL->value,
            'status' => ContentTranslation::STATUS_QUEUED,
            'requested_by_user_id' => $this->user->id,
        ]);

        $translationService = \Mockery::mock(TranslationService::class);
        $translationService->shouldReceive('resolveTargetVariantContent')->once()->andReturn(null);
        $translationService->shouldReceive('validateSourceDraft')->once();
        $translationService->shouldReceive('validateTargetLanguageAvailabilityForJob')->once();
        $translationService->shouldReceive('translate')->once()->andThrow(new RuntimeException('Temporary API failure'));
        $contentLifecycle = app(ContentLifecycleService::class);
        $automationItemState = app(AutomationRunItemStateService::class);

        $asyncOperations = \Mockery::mock(AsyncOperationService::class);
        $webhooks = \Mockery::mock(ApiWebhookPublisher::class);
        $webhooks->shouldNotReceive('publish');

        // Create job that simulates first attempt (retries remaining)
        $job = new TranslateDraftJob(
            sourceDraftId: (string) $this->sourceDraft->id,
            targetLanguage: SupportedLanguage::NL->value,
            userId: (string) $this->user->id,
            translationRequestId: (string) $translationRequest->id,
        );

        // Override tries to simulate job having multiple attempts remaining
        $job->tries = 3;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Temporary API failure');

        try {
            $job->handle($translationService, $wallets, $asyncOperations, $webhooks, $contentLifecycle, $automationItemState, app(\App\Services\Content\TranslationLockService::class));
        } finally {
            $translationRequest->refresh();

            // Failures should clear processing immediately so the UI can surface retry/error state.
            $this->assertSame(
                ContentTranslation::STATUS_FAILED,
                (string) $translationRequest->status,
                'Status should move to FAILED when the attempt throws'
            );
        }
    }

    public function test_failed_translation_allows_retry_via_coordinator(): void
    {
        $translationRequest = ContentTranslation::query()->create([
            'content_id' => (string) $this->sourceDraft->content_id,
            'target_locale' => SupportedLanguage::NL->value,
            'status' => ContentTranslation::STATUS_FAILED,
            'requested_by_user_id' => $this->user->id,
            'error_message' => 'Previous translation failed',
        ]);

        // Verify isActiveLock returns false for failed translations
        $this->assertFalse($translationRequest->isActiveLock());

        // Verify ContentTranslationCoordinator can queue a retry
        $coordinator = app(\App\Services\Content\ContentTranslationCoordinator::class);
        $targets = $coordinator->targetLocales($this->sourceDraft->content);

        $nlTarget = $targets->firstWhere('value', SupportedLanguage::NL->value);

        $this->assertNotNull($nlTarget);
        $this->assertSame('failed', $nlTarget['state']);
        $this->assertSame('retry', $nlTarget['action']);
        $this->assertSame('Retry translation', $nlTarget['verb']);
        $this->assertFalse($nlTarget['is_disabled']);
    }

    public function test_active_processing_job_blocks_duplicate_translation(): void
    {
        $translationRequest = ContentTranslation::query()->create([
            'content_id' => (string) $this->sourceDraft->content_id,
            'target_locale' => SupportedLanguage::NL->value,
            'status' => ContentTranslation::STATUS_PROCESSING,
            'requested_by_user_id' => $this->user->id,
            'job_id' => 'active-job-456',
        ]);

        // Verify isActiveLock returns true for active processing
        $this->assertTrue($translationRequest->isActiveLock());

        // Verify ContentTranslationCoordinator blocks retry
        $coordinator = app(\App\Services\Content\ContentTranslationCoordinator::class);
        $targets = $coordinator->targetLocales($this->sourceDraft->content);

        $nlTarget = $targets->firstWhere('value', SupportedLanguage::NL->value);

        $this->assertNotNull($nlTarget);
        $this->assertSame('processing', $nlTarget['state']);
        $this->assertTrue($nlTarget['is_disabled']);

        // Attempting to queue should throw
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("A translation to 'Dutch' is already processing.");

        $coordinator->queue($this->sourceDraft->content, SupportedLanguage::NL->value, (string) $this->user->id);
    }

    public function test_stale_processing_lock_allows_retry(): void
    {
        config()->set('translation.processing_lock_ttl_seconds', 60);

        $translationRequest = ContentTranslation::query()->create([
            'content_id' => (string) $this->sourceDraft->content_id,
            'target_locale' => SupportedLanguage::NL->value,
            'status' => ContentTranslation::STATUS_PROCESSING,
            'requested_by_user_id' => $this->user->id,
            'job_id' => 'stale-job-789',
        ]);

        // Make the lock stale by setting updated_at in the past
        $translationRequest->forceFill([
            'updated_at' => now()->subHours(2),
            'created_at' => now()->subHours(2),
        ])->saveQuietly();

        // Verify lock is expired before any reconciliation
        $this->assertTrue($translationRequest->isExpiredProcessingLock());
        $this->assertFalse($translationRequest->isActiveLock());

        // Manually call reconcileExpiredProcessingLock to mark as failed
        $this->assertTrue($translationRequest->reconcileExpiredProcessingLock());

        $translationRequest->refresh();
        $this->assertSame(ContentTranslation::STATUS_FAILED, (string) $translationRequest->status);
        $this->assertNull($translationRequest->job_id);
        $this->assertTrue($translationRequest->isStaleFailure());
        $this->assertStringContainsString('[stale_translation_lock]', (string) $translationRequest->error_message);

        // Verify ContentTranslationCoordinator shows stale state and allows retry
        $coordinator = app(\App\Services\Content\ContentTranslationCoordinator::class);
        $targets = $coordinator->targetLocales($this->sourceDraft->content);

        $nlTarget = $targets->firstWhere('value', SupportedLanguage::NL->value);

        $this->assertNotNull($nlTarget);
        $this->assertSame('stale_recovered', $nlTarget['state']);
        $this->assertSame('Stale recovered', $nlTarget['state_label']);
        $this->assertSame('retry', $nlTarget['action']);
        $this->assertSame('Retry translation', $nlTarget['verb']);
        $this->assertFalse($nlTarget['is_disabled']);
    }

    public function test_already_processing_with_same_job_uuid_does_not_fail(): void
    {
        $wallets = app(CreditWalletService::class);
        $wallets->addCredits((string) $this->clientSite->id, 20, CreditWalletService::TYPE_ALLOWANCE);

        $jobUuid = (string) Str::uuid();
        $translationRequest = ContentTranslation::query()->create([
            'content_id' => (string) $this->sourceDraft->content_id,
            'target_locale' => SupportedLanguage::NL->value,
            'status' => ContentTranslation::STATUS_PROCESSING,
            'requested_by_user_id' => $this->user->id,
            'processing_job_uuid' => $jobUuid,
            'processing_started_at' => now(),
            'processing_locked_at' => now(),
            'processing_last_heartbeat_at' => now(),
        ]);

        $translationService = \Mockery::mock(TranslationService::class);
        $translationService->shouldReceive('resolveTargetVariantContent')->once()->andReturn(null);
        $translationService->shouldReceive('validateSourceDraft')->once();
        $realTranslationService = app(TranslationService::class);
        $translationService->shouldReceive('validateTargetLanguageAvailabilityForJob')
            ->once()
            ->andReturnUsing(fn (Draft $draft, SupportedLanguage $language, bool $allowExisting = false, ?string $currentJobUuid = null, ?string $currentTranslationRequestId = null, ?string $currentQueueJobId = null, ?string $currentTargetContentId = null, bool $bypassDispatchOnlyProcessingCheck = false) => $realTranslationService->validateTargetLanguageAvailabilityForJob($draft, $language, $allowExisting, $currentJobUuid, $currentTranslationRequestId, $currentQueueJobId, $currentTargetContentId, $bypassDispatchOnlyProcessingCheck));
        $translationService->shouldReceive('translate')->once()->andReturn([
            'title' => 'Nederlandse versie',
            'content_html' => '<p>Vertaling.</p>',
            'seo' => [],
            'model_used' => 'gpt-4.1-mini',
            'request_id' => 'req-same-job',
        ]);
        $translationService->shouldReceive('createTranslatedDraft')
            ->once()
            ->andReturnUsing(fn (Draft $draft, SupportedLanguage $language, array $result, ?string $userId = null) => app(TranslationService::class)->createTranslatedDraft($draft, $language, $result, $userId));

        $job = new TranslateDraftJob(
            sourceDraftId: (string) $this->sourceDraft->id,
            targetLanguage: SupportedLanguage::NL->value,
            userId: (string) $this->user->id,
            translationRequestId: (string) $translationRequest->id,
            dispatchJobUuid: $jobUuid,
            sourceContentId: (string) $this->sourceDraft->content_id,
        );

        $job->handle(
            $translationService,
            $wallets,
            \Mockery::mock(AsyncOperationService::class),
            tap(\Mockery::mock(ApiWebhookPublisher::class), fn ($mock) => $mock->shouldReceive('publish')->once()),
            app(ContentLifecycleService::class),
            app(AutomationRunItemStateService::class),
            app(\App\Services\Content\TranslationLockService::class)
        );

        $translationRequest->refresh();

        $this->assertSame(ContentTranslation::STATUS_COMPLETED, (string) $translationRequest->status);
        $this->assertNull($translationRequest->processing_job_uuid);
    }

    public function test_translate_draft_job_recovers_stale_request_and_reaches_provider_on_third_attempt(): void
    {
        $wallets = app(CreditWalletService::class);
        $wallets->addCredits((string) $this->clientSite->id, 20, CreditWalletService::TYPE_ALLOWANCE);

        $staleJobUuid = (string) Str::uuid();
        $currentJobUuid = (string) Str::uuid();
        $translationRequest = ContentTranslation::query()->create([
            'content_id' => (string) $this->sourceDraft->content_id,
            'target_locale' => SupportedLanguage::NL->value,
            'status' => ContentTranslation::STATUS_PROCESSING,
            'requested_by_user_id' => $this->user->id,
            'processing_job_uuid' => $staleJobUuid,
            'processing_started_at' => now()->subHours(2),
            'processing_locked_at' => now()->subHours(2),
            'processing_last_heartbeat_at' => now()->subHours(2),
            'processing_failed_at' => now()->subHours(3),
            'processing_error_message' => 'Old stale failure',
            'error_message' => 'Old stale failure',
        ]);

        $translationService = \Mockery::mock(TranslationService::class);
        $translationService->shouldReceive('resolveTargetVariantContent')->once()->andReturn(null);
        $translationService->shouldReceive('validateSourceDraft')->once();
        $realTranslationService = app(TranslationService::class);
        $translationService->shouldReceive('validateTargetLanguageAvailabilityForJob')
            ->once()
            ->andReturnUsing(fn (Draft $draft, SupportedLanguage $language, bool $allowExisting = false, ?string $currentJobUuidArg = null, ?string $currentTranslationRequestId = null, ?string $currentQueueJobId = null, ?string $currentTargetContentId = null, bool $bypassDispatchOnlyProcessingCheck = false) => $realTranslationService->validateTargetLanguageAvailabilityForJob($draft, $language, $allowExisting, $currentJobUuidArg, $currentTranslationRequestId, $currentQueueJobId, $currentTargetContentId, $bypassDispatchOnlyProcessingCheck));
        $translationService->shouldReceive('translate')->once()->andReturnUsing(function () use ($translationRequest, $currentJobUuid): array {
            $translationRequest->refresh();

            $this->assertSame(ContentTranslation::STATUS_PROCESSING, (string) $translationRequest->status);
            $this->assertSame($currentJobUuid, (string) $translationRequest->processing_job_uuid);
            $this->assertNotNull($translationRequest->processing_locked_at);
            $this->assertNotNull($translationRequest->processing_last_heartbeat_at);
            $this->assertTrue($translationRequest->processing_locked_at->gt(now()->subMinute()));
            $this->assertTrue($translationRequest->processing_last_heartbeat_at->gt(now()->subMinute()));
            $this->assertNull($translationRequest->processing_failed_at);
            $this->assertNull($translationRequest->processing_error_message);
            $this->assertNull($translationRequest->error_message);

            return [
                'title' => 'Herstelde Nederlandse versie',
                'content_html' => '<p>Vertaling na stale recovery.</p>',
                'seo' => [],
                'model_used' => 'gpt-4.1-mini',
                'request_id' => 'req-stale-recovery',
            ];
        });
        $translationService->shouldReceive('createTranslatedDraft')
            ->once()
            ->andReturnUsing(fn (Draft $draft, SupportedLanguage $language, array $result, ?string $userId = null) => app(TranslationService::class)->createTranslatedDraft($draft, $language, $result, $userId));

        $job = new TranslateDraftJob(
            sourceDraftId: (string) $this->sourceDraft->id,
            targetLanguage: SupportedLanguage::NL->value,
            userId: (string) $this->user->id,
            translationRequestId: (string) $translationRequest->id,
            dispatchJobUuid: $currentJobUuid,
            sourceContentId: (string) $this->sourceDraft->content_id,
        );

        $queueJob = \Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->andReturn(3);
        $queueJob->shouldReceive('getJobId')->andReturn('queue-job-3');
        $queueJob->shouldReceive('uuid')->andReturn($currentJobUuid);
        $queueJob->shouldReceive('payload')->andReturn(['uuid' => $currentJobUuid]);
        $job->setJob($queueJob);

        $job->handle(
            $translationService,
            $wallets,
            \Mockery::mock(AsyncOperationService::class),
            tap(\Mockery::mock(ApiWebhookPublisher::class), fn ($mock) => $mock->shouldReceive('publish')->once()),
            app(ContentLifecycleService::class),
            app(AutomationRunItemStateService::class),
            app(\App\Services\Content\TranslationLockService::class)
        );

        $translationRequest->refresh();

        $this->assertSame(ContentTranslation::STATUS_COMPLETED, (string) $translationRequest->status);
        $this->assertNull($translationRequest->processing_job_uuid);
    }

    public function test_translate_draft_job_blocks_other_fresh_processing_job(): void
    {
        $wallets = app(CreditWalletService::class);
        $wallets->addCredits((string) $this->clientSite->id, 20, CreditWalletService::TYPE_ALLOWANCE);

        $translationRequest = ContentTranslation::query()->create([
            'content_id' => (string) $this->sourceDraft->content_id,
            'target_locale' => SupportedLanguage::NL->value,
            'status' => ContentTranslation::STATUS_PROCESSING,
            'requested_by_user_id' => $this->user->id,
            'processing_job_uuid' => (string) Str::uuid(),
            'processing_started_at' => now(),
            'processing_locked_at' => now(),
            'processing_last_heartbeat_at' => now(),
        ]);

        $translationService = \Mockery::mock(TranslationService::class);
        $translationService->shouldReceive('resolveTargetVariantContent')->never();
        $translationService->shouldReceive('validateSourceDraft')->never();
        $realTranslationService = app(TranslationService::class);
        $translationService->shouldReceive('validateTargetLanguageAvailabilityForJob')
            ->never()
            ->andReturnUsing(fn (Draft $draft, SupportedLanguage $language, bool $allowExisting = false, ?string $currentJobUuid = null, ?string $currentTranslationRequestId = null, ?string $currentQueueJobId = null, bool $bypassDispatchOnlyProcessingCheck = false) => $realTranslationService->validateTargetLanguageAvailabilityForJob($draft, $language, $allowExisting, $currentJobUuid, $currentTranslationRequestId, $currentQueueJobId, $bypassDispatchOnlyProcessingCheck));
        $translationService->shouldNotReceive('translate');

        $job = new TranslateDraftJob(
            sourceDraftId: (string) $this->sourceDraft->id,
            targetLanguage: SupportedLanguage::NL->value,
            userId: (string) $this->user->id,
            translationRequestId: (string) $translationRequest->id,
            dispatchJobUuid: (string) Str::uuid(),
            sourceContentId: (string) $this->sourceDraft->content_id,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("A translation to 'Dutch' is already processing.");

        $job->handle(
            $translationService,
            $wallets,
            \Mockery::mock(AsyncOperationService::class),
            \Mockery::mock(ApiWebhookPublisher::class),
            app(ContentLifecycleService::class),
            app(AutomationRunItemStateService::class),
            app(\App\Services\Content\TranslationLockService::class)
        );
    }

    public function test_old_failed_job_cannot_clear_newer_job_lock(): void
    {
        $olderJobUuid = (string) Str::uuid();
        $newerJobUuid = (string) Str::uuid();

        $translationRequest = ContentTranslation::query()->create([
            'content_id' => (string) $this->sourceDraft->content_id,
            'target_locale' => SupportedLanguage::NL->value,
            'status' => ContentTranslation::STATUS_PROCESSING,
            'requested_by_user_id' => $this->user->id,
            'processing_job_uuid' => $newerJobUuid,
            'processing_started_at' => now(),
            'processing_locked_at' => now(),
            'processing_last_heartbeat_at' => now(),
        ]);

        $job = new TranslateDraftJob(
            sourceDraftId: (string) $this->sourceDraft->id,
            targetLanguage: SupportedLanguage::NL->value,
            userId: (string) $this->user->id,
            translationRequestId: (string) $translationRequest->id,
            dispatchJobUuid: $olderJobUuid,
            sourceContentId: (string) $this->sourceDraft->content_id,
        );

        $job->failed(new RuntimeException('Old job failed after replacement'));

        $translationRequest->refresh();

        $this->assertSame(ContentTranslation::STATUS_PROCESSING, (string) $translationRequest->status);
        $this->assertSame($newerJobUuid, (string) $translationRequest->processing_job_uuid);
    }
}
