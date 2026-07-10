<?php

namespace App\Providers;

use App\Billing\Providers\MolliePaymentProvider;
use App\Billing\Providers\PaymentProviderRegistry;
use App\Contracts\ConnectorPublicBlogSource;
use App\Contracts\Connectors\Intelligence\CampaignIntelligenceFeed;
use App\Contracts\Connectors\Intelligence\LeadIntelligenceFeed;
use App\Contracts\Connectors\Intelligence\MarketingIntelligenceFeed;
use App\Contracts\Connectors\Intelligence\SalesIntelligenceFeed;
use App\Contracts\LinkIntelligence\AnchorTextService;
use App\Contracts\LinkIntelligence\EmbeddingService;
use App\Contracts\LinkIntelligence\EntityExtractionService;
use App\Contracts\LinkIntelligence\LinkApplyService;
use App\Contracts\LinkIntelligence\LinkRelevanceService;
use App\Contracts\LinkIntelligence\LinkSuggestionService;
use App\Contracts\PageIntelligence\ScheduledBriefingContract;
use App\Contracts\PdfRenderer;
use App\Contracts\PublicBlogSource;
use App\Domain\AccessOverrides\AccessOverrideResolver;
use App\Events\Agents\ContentPublished;
use App\Events\Agents\TranslationCompleted;
use App\Events\Connectors\ConnectorRawRecordsWritten;
use App\Events\Connectors\ConnectorSyncCompletedForTransformation;
use App\Events\LinkIntelligence\ArticleSignalsRequested;
use App\Events\Notifications\DraftDeliveryFailed;
use App\Events\Notifications\SiteVerified;
use App\Events\Onboarding\BriefCreated;
use App\Events\Onboarding\ContentPushedToWordPress;
use App\Events\Onboarding\DraftGenerated;
use App\Events\Onboarding\UserEmailVerified;
use App\Events\Onboarding\UserFirstLogin;
use App\Events\Onboarding\UserRegistered;
use App\Listeners\Agents\RunInternalLinkingAfterDraftGenerated;
use App\Listeners\Agents\RunLocalizationChecksAfterTranslation;
use App\Listeners\Agents\RunRefreshAnalysisAfterPublish;
use App\Listeners\Content\SyncLinkedLocalePublishingAfterTranslation;
use App\Listeners\ContentAutomation\SyncAutomationTranslationResult;
use App\Listeners\Connectors\QueueConnectorRawRecordNormalization;
use App\Listeners\Connectors\QueueConnectorSyncNormalization;
use App\Listeners\LinkIntelligence\QueueBuildArticleSignals;
use App\Listeners\Marketing\InvalidateCrossLocaleRedirectsOnPublish;
use App\Listeners\Notifications\SendDraftDeliveredNotification;
use App\Listeners\Notifications\SendDraftDeliveryFailedNotification;
use App\Listeners\Notifications\SendDraftReadyNotification;
use App\Listeners\Notifications\SendSiteVerifiedNotification;
use App\Listeners\Onboarding\SyncOnboardingStateOnBriefCreated;
use App\Listeners\Onboarding\SyncOnboardingStateOnContentPushed;
use App\Listeners\Onboarding\SyncOnboardingStateOnDraftGenerated;
use App\Listeners\Onboarding\SyncOnboardingStateOnEmailVerified;
use App\Listeners\Onboarding\SyncOnboardingStateOnFirstLogin;
use App\Listeners\Onboarding\SyncOnboardingStateOnRegistered;
use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\AgenticMarketingRun;
use App\Models\ApiKey;
use App\Models\ApiWebhook;
use App\Models\BrandVoice;
use App\Models\Brief;
use App\Models\CompanyProfile;
use App\Models\Content;
use App\Models\ContentAutomation;
use App\Models\ContentDestination;
use App\Models\ContentImage;
use App\Models\ContentPublication;
use App\Models\ContentRevision;
use App\Models\ContentSeo;
use App\Models\ContentSeries;
use App\Models\ContentTranslation;
use App\Models\ContentVersion;
use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorSyncRun;
use App\Models\CrossLinkPermission;
use App\Models\Draft;
use App\Models\DraftComparison;
use App\Models\FaqQuestion;
use App\Models\GrowthAsset;
use App\Models\GrowthProgram;
use App\Models\GrowthRun;
use App\Models\LinkSuggestion;
use App\Models\AlertRule;
use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\Notification as WorkspaceNotification;
use App\Models\Organization;
use App\Models\PageAlert;
use App\Models\PageIntelligenceReport;
use App\Models\ProgrammaticBriefBlueprint;
use App\Models\ProgrammaticCluster;
use App\Models\ProgrammaticDraftRequest;
use App\Models\ProgrammaticDraftReview;
use App\Models\ProgrammaticOpportunity;
use App\Models\ProgrammaticPublicationPlan;
use App\Models\ProgrammaticPublicationReadiness;
use App\Models\ResearchProject;
use App\Models\ScheduledPageIntelligenceBriefing;
use App\Models\SignalDetection;
use App\Models\SignalDetectionLink;
use App\Models\SignalEntity;
use App\Models\SignalEvent;
use App\Models\SignalFeedItem;
use App\Models\SignalMention;
use App\Models\SignalProcessingRun;
use App\Models\SignalScore;
use App\Models\SignalSource;
use App\Models\SocialPost;
use App\Models\StructuredAnswerBlock;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WriterProfile;
use App\Observers\ContentImageObserver;
use App\Observers\ContentObserver;
use App\Observers\ContentPublicationObserver;
use App\Observers\ContentRevisionObserver;
use App\Observers\ContentSeoObserver;
use App\Observers\ContentTranslationObserver;
use App\Observers\ContentVersionObserver;
use App\Observers\DraftObserver;
use App\Observers\StructuredAnswerBlockObserver;
use App\Observers\UserObserver;
use App\Policies\AdminNotificationPolicy;
use App\Policies\AgenticActionRunPolicy;
use App\Policies\AgenticMarketingPolicy;
use App\Policies\AlertRulePolicy;
use App\Policies\ApiKeyPolicy;
use App\Policies\ApiWebhookPolicy;
use App\Policies\BrandVoicePolicy;
use App\Policies\BriefPolicy;
use App\Policies\CompanyProfilePolicy;
use App\Policies\ContentAutomationPolicy;
use App\Policies\ContentDestinationPolicy;
use App\Policies\ContentPolicy;
use App\Policies\ContentSeriesPolicy;
use App\Policies\ConnectorAccountPolicy;
use App\Policies\ConnectorDatasetPolicy;
use App\Policies\ConnectorSyncRunPolicy;
use App\Policies\CrossLinkPermissionPolicy;
use App\Policies\DraftComparisonPolicy;
use App\Policies\DraftPolicy;
use App\Policies\FaqQuestionPolicy;
use App\Policies\GrowthAssetPolicy;
use App\Policies\GrowthProgramPolicy;
use App\Policies\GrowthRunPolicy;
use App\Policies\LinkSuggestionPolicy;
use App\Policies\MonitoredPagePolicy;
use App\Policies\MonitoredSourcePolicy;
use App\Policies\NotificationPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\PageAlertPolicy;
use App\Policies\PageIntelligenceReportPolicy;
use App\Policies\ProgrammaticBriefBlueprintPolicy;
use App\Policies\ProgrammaticClusterPolicy;
use App\Policies\ProgrammaticDraftRequestPolicy;
use App\Policies\ProgrammaticDraftReviewPolicy;
use App\Policies\ProgrammaticOpportunityPolicy;
use App\Policies\ProgrammaticPublicationPlanPolicy;
use App\Policies\ProgrammaticPublicationReadinessPolicy;
use App\Policies\ResearchProjectPolicy;
use App\Policies\ScheduledPageIntelligenceBriefingPolicy;
use App\Policies\SignalDetectionLinkPolicy;
use App\Policies\SignalDetectionPolicy;
use App\Policies\SignalEntityPolicy;
use App\Policies\SignalEventPolicy;
use App\Policies\SignalFeedItemPolicy;
use App\Policies\SignalMentionPolicy;
use App\Policies\SignalProcessingRunPolicy;
use App\Policies\SignalScorePolicy;
use App\Policies\SignalSourcePolicy;
use App\Policies\SocialPostPolicy;
use App\Policies\TeamMemberPolicy;
use App\Policies\WorkspacePolicy;
use App\Policies\WriterProfilePolicy;
use App\Services\Credits\CreditWarningService;
use App\Services\DataConnectors\ConnectorOAuthTokenClient;
use App\Services\DataConnectors\DataConnectorRegistry;
use App\Services\DataConnectors\HttpConnectorOAuthTokenClient;
use App\Services\DataConnectors\Intelligence\NormalizedCampaignIntelligenceFeed;
use App\Services\DataConnectors\Intelligence\NormalizedLeadIntelligenceFeed;
use App\Services\DataConnectors\Intelligence\NormalizedMarketingIntelligenceFeed;
use App\Services\DataConnectors\Intelligence\NormalizedSalesIntelligenceFeed;
use App\Services\DompdfInvoicePdfRenderer;
use App\Services\FakeInvoicePdfRenderer;
use App\Services\Integrations\ApiCapabilityService;
use App\Services\LinkIntelligence\DefaultAnchorTextService;
use App\Services\LinkIntelligence\DefaultLinkApplyService;
use App\Services\LinkIntelligence\DefaultLinkRelevanceService;
use App\Services\LinkIntelligence\DefaultLinkSuggestionService;
use App\Services\Mos\MosProviderRegistry;
use App\Services\Mos\Opportunity\Providers\AgenticMarketingOpportunityProvider;
use App\Services\Mos\Opportunity\Providers\CompetitorContentOpportunityProvider;
use App\Services\Mos\Opportunity\Providers\ContentOpportunityProvider;
use App\Services\Mos\Opportunity\Providers\FaqOpportunityAuditProvider;
use App\Services\Mos\Opportunity\Providers\LinkOpportunityProvider;
use App\Services\Mos\Opportunity\Providers\ProgrammaticOpportunityProvider;
use App\Services\Mos\Providers\AgentWorkflowMosProvider;
use App\Services\Mos\Providers\MarketingOperatingSystemMosProvider;
use App\Services\Mos\Providers\OpportunityIntelligenceMosProvider;
use App\Services\Mos\Providers\SignalIntelligenceMosProvider;
use App\Services\Notifications\NotificationService;
use App\Services\PageIntelligence\Geo\AnswerEngineAdapter;
use App\Services\PageIntelligence\Geo\AnswerEngineProviderRegistry;
use App\Services\PageIntelligence\Reports\ReportBuilder;
use App\Services\PageIntelligence\Serp\SerpProviderAdapter;
use App\Services\PageIntelligence\Serp\SerpProviderRegistry;
use App\Services\PublicBlog\ArguslyConnectorBlogSource;
use App\Services\PublicBlog\ConnectorFirstBlogSource;
use App\Services\Sitemap\SitemapManifestBuilder;
use App\Services\Sitemap\Sources\MarketingPagesSitemapSource;
use App\Services\Sitemap\Sources\PublishedArticleSitemapSource;
use App\Services\Sitemap\Sources\StaticPagesSitemapSource;
use App\Services\Support\SupportContext;
use App\Support\MarketingRouteSegments;
use App\Support\QueueWorkerHeartbeat;
use App\Support\SecurityResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    private static bool $eventListenersRegistered = false;

    /**
     * Required environment variables for production.
     * Missing keys will throw RuntimeException on boot.
     */
    private const PRODUCTION_REQUIRED_SECRETS = [
        'services.openai.key' => 'OPENAI_API_KEY',
        'services.mailgun.secret' => 'MAILGUN_SECRET',
        'billing.mollie.key' => 'MOLLIE_KEY',
        'argusly.admin_key' => 'ARGUSLY_ADMIN_KEY',
    ];

    public function register(): void
    {
        $this->app->scoped(SupportContext::class, fn () => new SupportContext);

        $this->app->singleton(PaymentProviderRegistry::class, function () {
            return new PaymentProviderRegistry([
                'mollie' => fn () => new MolliePaymentProvider,
            ]);
        });

        $this->app->tag([
            OpportunityIntelligenceMosProvider::class,
            SignalIntelligenceMosProvider::class,
            AgentWorkflowMosProvider::class,
            MarketingOperatingSystemMosProvider::class,
            ContentOpportunityProvider::class,
            AgenticMarketingOpportunityProvider::class,
            CompetitorContentOpportunityProvider::class,
            FaqOpportunityAuditProvider::class,
            ProgrammaticOpportunityProvider::class,
            LinkOpportunityProvider::class,
        ], 'mos.providers');

        $this->app->singleton(MosProviderRegistry::class, fn ($app) => new MosProviderRegistry(
            $app->tagged('mos.providers')
        ));

        $this->app->singleton(DataConnectorRegistry::class, fn ($app) => new DataConnectorRegistry(
            (array) config('data_connectors.providers', []),
            $app
        ));

        $this->app->bind(MarketingIntelligenceFeed::class, NormalizedMarketingIntelligenceFeed::class);
        $this->app->bind(CampaignIntelligenceFeed::class, NormalizedCampaignIntelligenceFeed::class);
        $this->app->bind(LeadIntelligenceFeed::class, NormalizedLeadIntelligenceFeed::class);
        $this->app->bind(SalesIntelligenceFeed::class, NormalizedSalesIntelligenceFeed::class);

        $this->app->bind(ConnectorOAuthTokenClient::class, HttpConnectorOAuthTokenClient::class);

        $this->app->singleton(SerpProviderRegistry::class, function ($app) {
            $providers = [];

            foreach ((array) config('page_intelligence.providers.serp', []) as $key => $provider) {
                $adapter = is_string($provider) ? $app->make($provider) : $provider;

                if (! $adapter instanceof SerpProviderAdapter) {
                    throw new RuntimeException('Invalid SERP provider configured for key: '.$key);
                }

                $providers[(string) $key] = $adapter;
            }

            return new SerpProviderRegistry($providers);
        });

        $this->app->singleton(AnswerEngineProviderRegistry::class, function ($app) {
            $providers = [];

            foreach ((array) config('page_intelligence.providers.answer_engines', []) as $key => $provider) {
                $adapter = is_string($provider) ? $app->make($provider) : $provider;

                if (! $adapter instanceof AnswerEngineAdapter) {
                    throw new RuntimeException('Invalid answer engine provider configured for key: '.$key);
                }

                $providers[(string) $key] = $adapter;
            }

            return new AnswerEngineProviderRegistry($providers);
        });

        $this->app->bind(EmbeddingService::class, function ($app) {
            $service = (string) config('link_intelligence.embedding.service');

            return $app->make($service);
        });

        $this->app->bind(EntityExtractionService::class, function ($app) {
            $service = (string) config('link_intelligence.entity_extraction.service');

            return $app->make($service);
        });

        $this->app->bind(LinkRelevanceService::class, DefaultLinkRelevanceService::class);
        $this->app->bind(AnchorTextService::class, DefaultAnchorTextService::class);
        $this->app->bind(LinkSuggestionService::class, DefaultLinkSuggestionService::class);
        $this->app->bind(LinkApplyService::class, DefaultLinkApplyService::class);
        $this->app->bind(ScheduledBriefingContract::class, ReportBuilder::class);
        $this->app->bind(ConnectorPublicBlogSource::class, ArguslyConnectorBlogSource::class);
        $this->app->bind(PublicBlogSource::class, ConnectorFirstBlogSource::class);
        $this->app->tag([
            PublishedArticleSitemapSource::class,
            StaticPagesSitemapSource::class,
            MarketingPagesSitemapSource::class,
        ], 'sitemap.sources');
        $this->app->when(SitemapManifestBuilder::class)
            ->needs('$sources')
            ->giveTagged('sitemap.sources');

        $this->app->bind(PdfRenderer::class, $this->app->environment('testing')
            ? FakeInvoicePdfRenderer::class
            : DompdfInvoicePdfRenderer::class);
    }

    public function boot(): void
    {
        $this->ensureRuntimeDirectories();

        if (app()->environment(['local', 'testing'])) {
            app(MarketingRouteSegments::class)->assertConfigured();
        }

        $this->validateProductionSecrets();

        RateLimiter::for('analytics-events', function (Request $request): array {
            $perMinute = max(1, (int) config('security.rate_limits.analytics_events_per_minute', 120));
            $siteKey = $this->extractAnalyticsSiteKeyFromRequest($request);
            $ip = (string) ($request->ip() ?? 'unknown');

            return [
                $this->limit($request, $perMinute, 'analytics-site:'.($siteKey !== '' ? $siteKey : 'unknown')),
                $this->limit($request, $perMinute * 2, 'analytics-ip:'.$ip),
            ];
        });

        RateLimiter::for('integration-api', function (Request $request): array {
            $workspace = $request->attributes->get('workspace');
            $apiKey = $request->attributes->get('apiKey');
            $siteToken = $request->attributes->get('siteToken');
            $ip = (string) ($request->ip() ?? 'unknown');

            $workspaceId = trim((string) ($workspace?->id ?? 'unknown'));
            $apiKeyId = trim((string) ($apiKey?->id ?? 'none'));
            $siteTokenId = trim((string) ($siteToken?->id ?? 'none'));

            $limitPerMinute = (int) config('security.rate_limits.integration_api_per_minute', 120);
            if ($workspace) {
                $limitPerMinute = app(ApiCapabilityService::class)->apiRateLimitPerMinute($workspace);
            }

            return [
                $this->limit($request, max(10, $limitPerMinute), 'integration-workspace:'.$workspaceId),
                $this->limit($request, max(10, (int) round($limitPerMinute * 1.5)), 'integration-key:'.($apiKeyId !== '' ? $apiKeyId : $siteTokenId)),
                $this->limit($request, max(20, (int) round($limitPerMinute * 2)), 'integration-ip:'.$ip),
            ];
        });

        RateLimiter::for('web', fn (Request $request) => [
            $this->limit($request, (int) config('security.rate_limits.web_per_minute', 120), 'web:'.$this->requestSignature($request)),
        ]);

        RateLimiter::for('api', fn (Request $request) => [
            $this->limit($request, (int) config('security.rate_limits.api_per_minute', 60), 'api:'.$this->requestSignature($request)),
        ]);

        RateLimiter::for('login', fn (Request $request) => [
            $this->limit($request, (int) config('security.rate_limits.login_per_minute', 5), 'login:'.$this->guestRequestSignature($request)),
        ]);

        RateLimiter::for('password-reset', fn (Request $request) => [
            $this->limit($request, (int) config('security.rate_limits.password_reset_per_minute', 5), 'password-reset:'.$this->guestRequestSignature($request)),
        ]);

        RateLimiter::for('contact', fn (Request $request) => [
            $this->limit($request, (int) config('security.rate_limits.contact_per_minute', 5), 'contact:'.$this->guestRequestSignature($request)),
        ]);

        RateLimiter::for('organization-register', function (Request $request): array {
            $ip = (string) ($request->ip() ?? 'unknown');
            $domain = $this->emailDomain((string) $request->input('email', ''));

            return [
                Limit::perHour(max(1, (int) config('security.rate_limits.organization_register_per_hour', 3)))
                    ->by('organization-register:ip-hour:'.$ip),
                Limit::perDay(max(1, (int) config('security.rate_limits.organization_register_per_day', 10)))
                    ->by('organization-register:ip-day:'.$ip),
                Limit::perHour(max(1, (int) config('security.rate_limits.organization_register_domain_per_hour', 10)))
                    ->by('organization-register:domain-hour:'.$domain),
                Limit::perDay(max(1, (int) config('security.rate_limits.organization_register_domain_per_day', 25)))
                    ->by('organization-register:domain-day:'.$domain),
            ];
        });

        // Backwards compatible aliases for older route definitions.
        RateLimiter::for('contact-form', fn (Request $request) => [
            $this->limit($request, (int) config('security.rate_limits.contact_per_minute', 5), 'contact-form:'.$this->guestRequestSignature($request)),
        ]);

        RateLimiter::for('public-form-submission', fn (Request $request) => [
            $this->limit($request, (int) config('security.rate_limits.contact_per_minute', 5), 'public-form-submission:'.$this->guestRequestSignature($request)),
        ]);

        RateLimiter::for('heavy', fn (Request $request) => [
            $this->limit(
                $request,
                (int) config('security.rate_limits.heavy_per_minute', 10),
                'heavy:'.$this->requestSignature($request).'|'.$this->routeFingerprint($request)
            ),
        ]);

        RateLimiter::for('heavy-actions', fn (Request $request) => [
            $this->limit(
                $request,
                (int) config('security.rate_limits.heavy_per_minute', 10),
                'heavy:'.$this->requestSignature($request).'|'.$this->routeFingerprint($request)
            ),
        ]);

        RateLimiter::for('webhook-public', fn (Request $request) => [
            $this->limit(
                $request,
                (int) config('security.rate_limits.webhook_per_minute', 60),
                'webhook:'.$this->guestRequestSignature($request).'|'.$this->routeFingerprint($request)
            ),
        ]);

        Queue::looping(function (Looping $event): void {
            unset($event);
            QueueWorkerHeartbeat::touch();
        });

        Queue::before(function (JobProcessing $event): void {
            unset($event);
            QueueWorkerHeartbeat::touch();
        });

        Gate::policy(LinkSuggestion::class, LinkSuggestionPolicy::class);
        Gate::policy(AgenticActionRun::class, AgenticActionRunPolicy::class);
        Gate::policy(AgenticMarketingAction::class, AgenticMarketingPolicy::class);
        Gate::policy(AgenticMarketingObjective::class, AgenticMarketingPolicy::class);
        Gate::policy(AgenticMarketingOpportunity::class, AgenticMarketingPolicy::class);
        Gate::policy(AgenticMarketingRun::class, AgenticMarketingPolicy::class);
        Gate::policy(ContentDestination::class, ContentDestinationPolicy::class);
        Gate::policy(ConnectorAccount::class, ConnectorAccountPolicy::class);
        Gate::policy(ConnectorDataset::class, ConnectorDatasetPolicy::class);
        Gate::policy(ConnectorSyncRun::class, ConnectorSyncRunPolicy::class);
        Gate::policy(ContentAutomation::class, ContentAutomationPolicy::class);
        Gate::policy(ApiKey::class, ApiKeyPolicy::class);
        Gate::policy(ApiWebhook::class, ApiWebhookPolicy::class);
        Gate::policy(CrossLinkPermission::class, CrossLinkPermissionPolicy::class);
        Gate::policy(Brief::class, BriefPolicy::class);
        Gate::policy(Draft::class, DraftPolicy::class);
        Gate::policy(DraftComparison::class, DraftComparisonPolicy::class);
        Gate::policy(GrowthProgram::class, GrowthProgramPolicy::class);
        Gate::policy(GrowthAsset::class, GrowthAssetPolicy::class);
        Gate::policy(GrowthRun::class, GrowthRunPolicy::class);
        Gate::policy(ProgrammaticBriefBlueprint::class, ProgrammaticBriefBlueprintPolicy::class);
        Gate::policy(ProgrammaticDraftRequest::class, ProgrammaticDraftRequestPolicy::class);
        Gate::policy(ProgrammaticDraftReview::class, ProgrammaticDraftReviewPolicy::class);
        Gate::policy(ProgrammaticPublicationPlan::class, ProgrammaticPublicationPlanPolicy::class);
        Gate::policy(ProgrammaticPublicationReadiness::class, ProgrammaticPublicationReadinessPolicy::class);
        Gate::policy(ProgrammaticOpportunity::class, ProgrammaticOpportunityPolicy::class);
        Gate::policy(ProgrammaticCluster::class, ProgrammaticClusterPolicy::class);
        Gate::policy(CompanyProfile::class, CompanyProfilePolicy::class);
        Gate::policy(BrandVoice::class, BrandVoicePolicy::class);
        Gate::policy(TeamMember::class, TeamMemberPolicy::class);
        Gate::policy(Content::class, ContentPolicy::class);
        Gate::policy(FaqQuestion::class, FaqQuestionPolicy::class);
        Gate::policy(ContentSeries::class, ContentSeriesPolicy::class);
        Gate::policy(Workspace::class, WorkspacePolicy::class);
        Gate::policy(WriterProfile::class, WriterProfilePolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(ResearchProject::class, ResearchProjectPolicy::class);
        Gate::policy(WorkspaceNotification::class, NotificationPolicy::class);
        Gate::policy(SocialPost::class, SocialPostPolicy::class);
        Gate::policy(SignalEntity::class, SignalEntityPolicy::class);
        Gate::policy(SignalSource::class, SignalSourcePolicy::class);
        Gate::policy(SignalFeedItem::class, SignalFeedItemPolicy::class);
        Gate::policy(SignalMention::class, SignalMentionPolicy::class);
        Gate::policy(SignalEvent::class, SignalEventPolicy::class);
        Gate::policy(SignalDetection::class, SignalDetectionPolicy::class);
        Gate::policy(SignalDetectionLink::class, SignalDetectionLinkPolicy::class);
        Gate::policy(SignalScore::class, SignalScorePolicy::class);
        Gate::policy(SignalProcessingRun::class, SignalProcessingRunPolicy::class);
        Gate::policy(MonitoredPage::class, MonitoredPagePolicy::class);
        Gate::policy(MonitoredSource::class, MonitoredSourcePolicy::class);
        Gate::policy(AlertRule::class, AlertRulePolicy::class);
        Gate::policy(PageAlert::class, PageAlertPolicy::class);
        Gate::policy(PageIntelligenceReport::class, PageIntelligenceReportPolicy::class);
        Gate::policy(ScheduledPageIntelligenceBriefing::class, ScheduledPageIntelligenceBriefingPolicy::class);

        Draft::observe(DraftObserver::class);
        Content::observe(ContentObserver::class);
        ContentImage::observe(ContentImageObserver::class);
        ContentSeo::observe(ContentSeoObserver::class);
        ContentVersion::observe(ContentVersionObserver::class);
        ContentRevision::observe(ContentRevisionObserver::class);
        ContentPublication::observe(ContentPublicationObserver::class);
        ContentTranslation::observe(ContentTranslationObserver::class);
        StructuredAnswerBlock::observe(StructuredAnswerBlockObserver::class);
        User::observe(UserObserver::class);

        if (! self::$eventListenersRegistered) {
            Event::listen(
                ArticleSignalsRequested::class,
                QueueBuildArticleSignals::class
            );
            Event::listen(UserRegistered::class, SyncOnboardingStateOnRegistered::class);
            Event::listen(UserEmailVerified::class, SyncOnboardingStateOnEmailVerified::class);
            Event::listen(UserFirstLogin::class, SyncOnboardingStateOnFirstLogin::class);
            Event::listen(BriefCreated::class, SyncOnboardingStateOnBriefCreated::class);
            Event::listen(DraftGenerated::class, SyncOnboardingStateOnDraftGenerated::class);
            Event::listen(ContentPushedToWordPress::class, SyncOnboardingStateOnContentPushed::class);
            Event::listen(DraftGenerated::class, SendDraftReadyNotification::class);
            Event::listen(ContentPushedToWordPress::class, SendDraftDeliveredNotification::class);
            Event::listen(DraftGenerated::class, RunInternalLinkingAfterDraftGenerated::class);
            Event::listen(TranslationCompleted::class, RunLocalizationChecksAfterTranslation::class);
            Event::listen(TranslationCompleted::class, SyncAutomationTranslationResult::class);
            Event::listen(TranslationCompleted::class, SyncLinkedLocalePublishingAfterTranslation::class);
            Event::listen(ContentPublished::class, RunRefreshAnalysisAfterPublish::class);
            Event::listen(ContentPublished::class, InvalidateCrossLocaleRedirectsOnPublish::class);
            Event::listen(DraftDeliveryFailed::class, SendDraftDeliveryFailedNotification::class);
            Event::listen(SiteVerified::class, SendSiteVerifiedNotification::class);
            Event::listen(ConnectorRawRecordsWritten::class, QueueConnectorRawRecordNormalization::class);
            Event::listen(ConnectorSyncCompletedForTransformation::class, QueueConnectorSyncNormalization::class);

            self::$eventListenersRegistered = true;
        }

        Gate::define('manage-organization', function (User $user): bool {
            $support = app(SupportContext::class);
            if ($support->isEnabled() && $support->targetUser()) {
                return in_array((string) $support->targetUser()->role, ['owner', 'admin'], true);
            }

            if ($user->is_admin) {
                return true;
            }

            return in_array($user->role, ['owner', 'admin'], true);
        });

        Gate::define('view-organization', function (User $user, Organization $organization): bool {
            if ($user->is_admin) {
                return true;
            }

            return $user->organization_id === $organization->id;
        });

        Gate::define('manage-cross-link-permissions', function (User $user): bool {
            $support = app(SupportContext::class);
            if ($support->isEnabled() && $support->targetUser()) {
                return in_array((string) $support->targetUser()->role, ['owner', 'admin'], true);
            }

            if ($user->is_admin) {
                return true;
            }

            return in_array($user->role, ['owner', 'admin'], true);
        });

        Gate::define('view_llm_monitor', function (User $user): bool {
            return $user->isAdminAreaUser();
        });

        Gate::define('viewAgentRuns', function (User $user): bool {
            return $user->isAdminAreaUser();
        });

        Gate::define('manage_llm_settings', function (User $user): bool {
            return $user->isSuperadmin();
        });

        Gate::define('admin-area-access', function (User $user): bool {
            return $user->isAdminAreaUser();
        });

        Gate::define('admin-area-superadmin', function (User $user): bool {
            return $user->isSuperadmin();
        });

        Gate::define('admin-area-manage-approvals', function (User $user): bool {
            return $user->isAdminAreaUser();
        });

        Gate::define('viewQueues', function (User $user): bool {
            return $user->isAdminAreaUser();
        });

        Gate::define('manageQueues', function (User $user): bool {
            return $user->isAdminAreaUser();
        });

        Gate::define('admin-area-view-sites', function (User $user): bool {
            return $user->isAdminAreaUser();
        });

        Gate::define('admin-area-manage-billing', function (User $user): bool {
            return $user->isSuperadmin();
        });

        Gate::define('admin-notifications.view-any', function (User $user): bool {
            return app(AdminNotificationPolicy::class)->viewAny($user);
        });

        Gate::define('admin-notifications.view', function (User $user, WorkspaceNotification $notification): bool {
            return app(AdminNotificationPolicy::class)->view($user, $notification);
        });

        Gate::define('admin-notifications.update', function (User $user, WorkspaceNotification $notification): bool {
            return app(AdminNotificationPolicy::class)->update($user, $notification);
        });

        Gate::define('admin-notifications.create-announcement', function (User $user): bool {
            return app(AdminNotificationPolicy::class)->createAnnouncement($user);
        });

        View::composer('layouts.app', function ($view): void {
            $lowCreditThreshold = max(1, (int) config('credits.warnings.absolute_threshold', 10));
            $creditNav = [
                'available' => null,
                'is_low' => false,
                'minimum' => $lowCreditThreshold,
                'show_upgrade' => false,
            ];
            $notificationBell = [
                'workspace_id' => null,
                'unread_count' => 0,
                'recent' => collect(),
            ];
            $accessOverrideBanner = null;
            $lowCreditBanner = null;

            if (! auth()->check()) {
                $view->with('appCreditNav', $creditNav);
                $view->with('appNotificationBell', $notificationBell);
                $view->with('appAccessOverrideBanner', $accessOverrideBanner);
                $view->with('appLowCreditBanner', $lowCreditBanner);

                return;
            }

            $user = auth()->user();
            if (
                ! $user
                || ! $user->organization_id
                || (! Schema::hasTable('workspace_credit_wallets') && ! Schema::hasTable('site_credit_allocations'))
                || ! Schema::hasTable('client_sites')
                || ! Schema::hasTable('workspaces')
                || ! Schema::hasColumn('workspaces', 'organization_id')
            ) {
                $view->with('appCreditNav', $creditNav);
                $view->with('appNotificationBell', $notificationBell);
                $view->with('appAccessOverrideBanner', $accessOverrideBanner);
                $view->with('appLowCreditBanner', $lowCreditBanner);

                return;
            }

            $available = Schema::hasTable('workspace_credit_wallets')
                ? (int) DB::table('workspace_credit_wallets')
                    ->where('organization_id', $user->organization_id)
                    ->selectRaw('COALESCE(SUM(balance_cached - reserved_cached), 0) as available')
                    ->value('available')
                : (int) DB::table('site_credit_allocations')
                    ->join('client_sites', 'site_credit_allocations.client_site_id', '=', 'client_sites.id')
                    ->join('workspaces', 'client_sites.workspace_id', '=', 'workspaces.id')
                    ->where('workspaces.organization_id', $user->organization_id)
                    ->selectRaw('COALESCE(SUM(site_credit_allocations.allocated_credits - site_credit_allocations.reserved_cached), 0) as available')
                    ->value('available');

            $creditNav['available'] = max(0, $available);
            $creditNav['is_low'] = $creditNav['available'] < $lowCreditThreshold;
            $creditNav['show_upgrade'] = $creditNav['is_low'] && Gate::forUser($user)->allows('manage-organization');

            if (
                ! $user->is_admin
                && Schema::hasTable('notifications')
                && Schema::hasColumn('notifications', 'target_scope')
                && Schema::hasColumn('notifications', 'is_admin_only')
            ) {
                /** @var NotificationService $notificationService */
                $notificationService = app(NotificationService::class);

                $requestedWorkspaceId = trim((string) request()->query('workspace_id', ''));
                try {
                    $notificationBell = $notificationService->appBellDataForUser(
                        actor: $user,
                        workspaceId: $requestedWorkspaceId !== '' ? $requestedWorkspaceId : null
                    );
                } catch (\Throwable) {
                    $notificationBell = $notificationService->appBellDataForUser($user);
                }
            }

            if (($user->is_admin || session()->has('admin_impersonator_id')) && Schema::hasTable('access_overrides')) {
                $override = app(AccessOverrideResolver::class)->getActiveOverrideForUser($user);

                if ($override) {
                    $accessOverrideBanner = [
                        'label' => $override->type->label(),
                        'message' => $override->uiMessage(),
                        'status' => $override->effectiveStatus(),
                    ];
                }
            }

            if (
                ! $user->is_admin
                && Schema::hasColumn('workspaces', 'low_credit_warning_state')
                && Schema::hasTable('content_automations')
                && config('credits.warnings.enabled', true)
            ) {
                try {
                    /** @var CreditWarningService $creditWarnings */
                    $creditWarnings = app(CreditWarningService::class);
                    $lowCreditBanner = $creditWarnings->mostUrgentForOrganization((int) $user->organization_id);
                } catch (\Throwable) {
                    $lowCreditBanner = null;
                }
            }

            if ($lowCreditBanner !== null) {
                $creditNav['available'] = max(0, (int) ($lowCreditBanner['available_credits'] ?? $creditNav['available']));
                $creditNav['is_low'] = true;
                $creditNav['show_upgrade'] = Gate::forUser($user)->allows('manage-organization');
                $creditNav['top_up_url'] = route('app.billing.index').'#buy-credit-packs';
            }

            $view->with('appCreditNav', $creditNav);
            $view->with('appNotificationBell', $notificationBell);
            $view->with('appAccessOverrideBanner', $accessOverrideBanner);
            $view->with('appLowCreditBanner', $lowCreditBanner);
        });

        View::composer('layouts.admin', function ($view): void {
            $notificationBell = [
                'unread_count' => 0,
                'recent' => collect(),
            ];

            if (
                ! auth()->check()
                || ! Schema::hasTable('notifications')
                || ! Schema::hasColumn('notifications', 'target_scope')
                || ! Schema::hasColumn('notifications', 'is_admin_only')
            ) {
                $view->with('adminNotificationBell', $notificationBell);

                return;
            }

            $user = auth()->user();
            if (! $user || ! $user->isAdminAreaUser()) {
                $view->with('adminNotificationBell', $notificationBell);

                return;
            }

            /** @var NotificationService $notificationService */
            $notificationService = app(NotificationService::class);
            $notificationBell = $notificationService->adminBellDataForUser($user);

            $view->with('adminNotificationBell', $notificationBell);
        });
    }

    private function ensureRuntimeDirectories(): void
    {
        foreach ([
            storage_path('framework/cache'),
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
            storage_path('logs/schedule'),
        ] as $path) {
            try {
                File::ensureDirectoryExists($path, 0775, true);
            } catch (\Throwable $exception) {
                Log::warning('runtime.directory_unavailable', [
                    'path' => $path,
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function extractAnalyticsSiteKeyFromRequest(Request $request): string
    {
        $siteKey = trim((string) $request->input('site_key', $request->input('site', '')));

        if ($siteKey !== '') {
            return $siteKey;
        }

        $raw = trim((string) $request->getContent());
        if ($raw === '') {
            return '';
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return '';
        }

        return trim((string) ($decoded['site_key'] ?? $decoded['site'] ?? ''));
    }

    /**
     * Validate that all required secrets are configured in production.
     *
     * @throws RuntimeException If a required secret is missing in production.
     */
    private function validateProductionSecrets(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        $missing = [];

        foreach (self::PRODUCTION_REQUIRED_SECRETS as $configKey => $envName) {
            $value = config($configKey);

            if ($value === null || $value === '') {
                $missing[] = $envName;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Missing required environment variables for production: '.implode(', ', $missing).'. '
                .'Please configure these secrets in your environment (NOT in .env files committed to git).'
            );
        }

        // Additional security check: ensure admin key is sufficiently strong
        $adminKey = (string) config('argusly.admin_key');
        if (strlen($adminKey) < 32) {
            throw new RuntimeException(
                'ARGUSLY_ADMIN_KEY must be at least 32 characters in production. '
                .'Generate with: openssl rand -hex 32'
            );
        }
    }

    private function limit(Request $request, int $perMinute, string $key): Limit
    {
        return Limit::perMinute(max(1, $perMinute))
            ->by($key)
            ->response(function (Request $request, array $headers) {
                $retryAfter = $headers['Retry-After'] ?? null;

                return SecurityResponse::tooManyRequests(
                    $request,
                    $retryAfter !== null ? (int) $retryAfter : null
                );
            });
    }

    private function requestSignature(Request $request): string
    {
        $userId = $request->user()?->getAuthIdentifier();
        $ip = (string) ($request->ip() ?? 'unknown');

        if ($userId !== null) {
            return 'user:'.$userId.'|ip:'.$ip;
        }

        return 'ip:'.$ip;
    }

    private function guestRequestSignature(Request $request): string
    {
        return 'ip:'.(string) ($request->ip() ?? 'unknown');
    }

    private function emailDomain(string $email): string
    {
        $domain = strtolower(trim((string) strrchr($email, '@'), '@'));

        return $domain !== '' ? $domain : 'unknown';
    }

    private function routeFingerprint(Request $request): string
    {
        return (string) ($request->route()?->getName() ?: $request->path());
    }
}
