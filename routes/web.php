<?php

use App\Http\Controllers\Admin\AdminControlCenterController;
use App\Http\Controllers\Admin\AiRuntimeMonitorController;
use App\Http\Controllers\Admin\PlatformAlertController;
use App\Http\Controllers\Admin\PlatformFeatureFlagController;
use App\Http\Controllers\Admin\PlatformOverviewController;
use App\Http\Controllers\Admin\PlatformQueueController;
use App\Http\Controllers\Admin\PlatformWebhookController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AnswerBlockController;
use App\Http\Controllers\AudienceController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\BrandKnowledgeCenterController;
use App\Http\Controllers\BriefingController;
use App\Http\Controllers\CampaignBoardController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CompetitorController;
use App\Http\Controllers\ConnectorController;
use App\Http\Controllers\ContentAssetController;
use App\Http\Controllers\ContentOperationsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DistributionController;
use App\Http\Controllers\DomainEventController;
use App\Http\Controllers\EmailProviderController;
use App\Http\Controllers\EntityController;
use App\Http\Controllers\GoogleIntegrationController;
use App\Http\Controllers\GraphController;
use App\Http\Controllers\IntelligenceSignalController;
use App\Http\Controllers\KnowledgeGraphController;
use App\Http\Controllers\LinkedInIntegrationController;
use App\Http\Controllers\MarketingCalendarController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\MarketingOsController;
use App\Http\Controllers\MarketingTaskController;
use App\Http\Controllers\MentionController;
use App\Http\Controllers\NarrativeController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OpportunityController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\RelationshipController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SearchPerformanceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SocialPostController;
use App\Http\Controllers\SocialRepurposingController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\TenantContextController;
use App\Http\Controllers\TopicController;
use App\Http\Controllers\UserLocaleController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\VisibilityController;
use App\Http\Controllers\WorkspaceImpersonationController;
use App\Models\BillingInvoice;
use App\Models\Subscription;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

if (! function_exists('deprecated_redirect')) {
    function deprecated_redirect(string $route, array $parameters = []): RedirectResponse
    {
        $request = request();

        if ($request->user()) {
            app(ActivityLogger::class)->log(
                event: 'route.deprecated',
                description: "Deprecated route used: {$request->path()}.",
                account: current_account(),
                brand: current_brand(),
                user: $request->user(),
                properties: [
                    'from' => $request->path(),
                    'to' => $route,
                ],
            );
        }

        return redirect()->route($route, $parameters, 301);
    }
}

$marketingRoutes = function (): void {
    Route::get('/', [MarketingController::class, 'home'])->name('marketing.home');
    Route::get('/signup', [MarketingController::class, 'signup'])->name('marketing.signup');
    Route::post('/signup', [MarketingController::class, 'storeSignup'])->middleware('throttle:marketing-forms')->name('marketing.signup.store');
    Route::get('/contact', [MarketingController::class, 'contact'])->name('marketing.contact');
    Route::post('/contact', [MarketingController::class, 'storeContact'])->middleware('throttle:marketing-forms')->name('marketing.contact.store');
    Route::get('/{page}', [MarketingController::class, 'page'])
        ->whereIn('page', ['platform', 'security', 'about', 'privacy', 'terms'])
        ->name('marketing.page');
};

if (config('argusly.marketing_domain') && ! app()->runningUnitTests() && ! app()->environment('testing')) {
    Route::domain(config('argusly.marketing_domain'))->group($marketingRoutes);
} else {
    $marketingRoutes();
}

$appRoutes = function (): void {
    if (config('argusly.app_domain') && ! app()->environment('testing')) {
        Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : redirect()->route('login'))
            ->name('app.home');
    }

    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:auth-actions')->name('login.store');
        Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
        Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:auth-actions')->name('password.email');
        Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
        Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:auth-actions')->name('password.update');
    });

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
        ->middleware('auth')
        ->name('logout');

    Route::post('/impersonation/stop', [AdminControlCenterController::class, 'stopImpersonating'])
        ->middleware('auth')
        ->name('impersonation.stop');

    Route::post('/user/locale', UserLocaleController::class)
        ->middleware('auth')
        ->name('user.locale.update');

    Route::middleware(['auth', 'platform.admin', 'throttle:admin-actions'])
        ->prefix('admin')
        ->name('admin.')
        ->group(function (): void {
            Route::get('/', [AdminControlCenterController::class, 'overview'])->name('overview');
            Route::get('/accounts', [AdminControlCenterController::class, 'accounts'])->name('accounts');
            Route::post('/accounts', [AdminControlCenterController::class, 'storeAccount'])->name('accounts.store');
            Route::get('/accounts/{account}', [AdminControlCenterController::class, 'showAccount'])->name('accounts.show');
            Route::put('/accounts/{account}', [AdminControlCenterController::class, 'updateAccount'])->name('accounts.update');

            Route::get('/brands', [AdminControlCenterController::class, 'brands'])->name('brands');
            Route::post('/brands', [AdminControlCenterController::class, 'storeBrand'])->name('brands.store');
            Route::put('/brands/{brand}', [AdminControlCenterController::class, 'updateBrand'])->name('brands.update');

            Route::get('/users', [AdminControlCenterController::class, 'users'])->name('users');
            Route::post('/users/{user}/impersonate', [AdminControlCenterController::class, 'impersonate'])->name('users.impersonate');
            Route::post('/memberships/accounts', [AdminControlCenterController::class, 'assignAccountUser'])->name('memberships.accounts.store');
            Route::post('/memberships/brands', [AdminControlCenterController::class, 'assignBrandUser'])->name('memberships.brands.store');
            Route::delete('/memberships/{membership}', [AdminControlCenterController::class, 'removeMembership'])->name('memberships.destroy');

            Route::get('/modules', [AdminControlCenterController::class, 'modules'])->name('modules');
            Route::post('/modules/enable', [AdminControlCenterController::class, 'enableModule'])->name('modules.enable');
            Route::put('/plans/{plan}', [AdminControlCenterController::class, 'updatePlan'])->name('plans.update');
            Route::get('/subscriptions', [AdminControlCenterController::class, 'modules'])->name('subscriptions');

            Route::get('/billing', [AdminControlCenterController::class, 'billing'])->name('billing');
            Route::post('/billing/mollie-checkout', [AdminControlCenterController::class, 'startMollieCheckout'])->name('billing.mollie-checkout');
            Route::post('/billing/invoices', [AdminControlCenterController::class, 'createInvoice'])->name('billing.invoices.store');
            Route::post('/billing/overages', [AdminControlCenterController::class, 'recordOverage'])->name('billing.overages.store');

            Route::get('/credits', [AdminControlCenterController::class, 'credits'])->name('credits');
            Route::post('/credits/adjust', [AdminControlCenterController::class, 'adjustCredits'])->name('credits.adjust');
            Route::get('/credits/cost-catalog', [AdminControlCenterController::class, 'creditCosts'])->name('credit-costs');
            Route::put('/credits/cost-catalog/{catalog}', [AdminControlCenterController::class, 'updateCreditCost'])->name('credit-costs.update');
            Route::post('/credits/cost-catalog/overrides', [AdminControlCenterController::class, 'storeCreditCostOverride'])->name('credit-costs.overrides.store');
            Route::get('/llm-requests', [AdminControlCenterController::class, 'llmRequests'])->name('llm-requests');
            Route::get('/ai-runtime/monitor', [AiRuntimeMonitorController::class, 'index'])->name('ai-runtime.monitor');
            Route::get('/llm', [AdminControlCenterController::class, 'llm'])->name('llm');
            Route::patch('/llm', [AdminControlCenterController::class, 'updateGlobalLlm'])->name('llm.update');
            Route::get('/llm/providers', [AdminControlCenterController::class, 'llmProviders'])->name('llm.providers');
            Route::patch('/llm/providers/{provider}', [AdminControlCenterController::class, 'updateLlmProvider'])->name('llm.providers.update');
            Route::get('/llm/models', [AdminControlCenterController::class, 'llmModels'])->name('llm.models');
            Route::patch('/llm/models/{model}', [AdminControlCenterController::class, 'updateLlmModel'])->name('llm.models.update');

            Route::get('/integrations', [AdminControlCenterController::class, 'integrations'])->name('integrations');
            Route::get('/connectors', [AdminControlCenterController::class, 'connectors'])->name('connectors');
            Route::post('/connectors/tokens/{token}/revoke', [AdminControlCenterController::class, 'revokeConnectorToken'])->name('connectors.tokens.revoke');
            Route::get('/publishing-channels', [AdminControlCenterController::class, 'channels'])->name('publishing-channels');
            Route::get('/publishing-actions', [AdminControlCenterController::class, 'publishing'])->name('publishing-actions');
            Route::get('/content-engine', [AdminControlCenterController::class, 'publishing'])->name('content-engine');
            Route::get('/jobs', [AdminControlCenterController::class, 'developer'])->defaults('tool', 'system-health')->name('jobs');
            Route::get('/logs', [AdminControlCenterController::class, 'developer'])->defaults('tool', 'activity-logs')->name('logs');
            Route::get('/platform', PlatformOverviewController::class)->name('platform.overview');
            Route::get('/platform/queues', [PlatformQueueController::class, 'index'])->name('platform.queues');
            Route::post('/platform/queues/failed/{failedJob}/retry', [PlatformQueueController::class, 'retry'])->name('platform.queues.retry');
            Route::get('/platform/alerts', [PlatformAlertController::class, 'index'])->name('platform.alerts');
            Route::post('/platform/alerts/{alert}/acknowledge', [PlatformAlertController::class, 'acknowledge'])->name('platform.alerts.acknowledge');
            Route::post('/platform/alerts/{alert}/resolve', [PlatformAlertController::class, 'resolve'])->name('platform.alerts.resolve');
            Route::get('/platform/webhooks', [PlatformWebhookController::class, 'index'])->name('platform.webhooks');
            Route::post('/platform/webhooks', [PlatformWebhookController::class, 'store'])->name('platform.webhooks.store');
            Route::patch('/platform/webhooks/{endpoint}', [PlatformWebhookController::class, 'update'])->name('platform.webhooks.update');
            Route::post('/platform/webhooks/deliveries/{delivery}/retry', [PlatformWebhookController::class, 'retry'])->name('platform.webhooks.deliveries.retry');
            Route::get('/platform/feature-flags', [PlatformFeatureFlagController::class, 'index'])->name('platform.feature-flags');
            Route::post('/platform/feature-flags', [PlatformFeatureFlagController::class, 'store'])->name('platform.feature-flags.store');
            Route::patch('/platform/feature-flags/{featureFlag}', [PlatformFeatureFlagController::class, 'update'])->name('platform.feature-flags.update');
            Route::get('/pilot-signups', [AdminControlCenterController::class, 'pilotSignups'])->name('pilot-signups');
            Route::post('/pilot-signups/{signup}/follow-up', [AdminControlCenterController::class, 'sendPilotSignupFollowUp'])->name('pilot-signups.follow-up');
            Route::patch('/pilot-signups/{signup}', [AdminControlCenterController::class, 'updatePilotSignup'])->name('pilot-signups.update');
            Route::get('/contact-requests', [AdminControlCenterController::class, 'contactRequests'])->name('contact-requests');
            Route::patch('/contact-requests/{contactRequest}', [AdminControlCenterController::class, 'updateContactRequest'])->name('contact-requests.update');

            Route::get('/developer-tools', [AdminControlCenterController::class, 'developer'])->defaults('tool', 'system-health')->name('developer-tools');
            Route::get('/developer-tools/{tool}', [AdminControlCenterController::class, 'developer'])->name('developer-tools.show');
        });

    Route::post('/billing/mollie/webhook', function (\Illuminate\Http\Request $request) {
        $paymentId = (string) $request->input('id');

        if ($paymentId !== '') {
            BillingInvoice::query()
                ->where('provider', 'mollie')
                ->where('provider_payment_id', $paymentId)
                ->update(['status' => 'paid', 'paid_at' => now()]);

            Subscription::query()
                ->where('provider', 'mollie')
                ->where('provider_subscription_id', $paymentId)
                ->update(['status' => 'active']);
        }

        return response()->json(['ok' => true]);
    })->name('billing.mollie.webhook');

    Route::get('/developer-tools/domain-events', [DomainEventController::class, 'index'])
        ->middleware(['auth', 'current.account', 'current.brand', 'module.active:core', 'permission:manage_account'])
        ->name('app.domain-events');

    Route::middleware(['auth', 'current.account', 'current.brand'])->group(function (): void {
        Route::get('/dashboard', DashboardController::class)
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('dashboard');

        Route::post('/workspace/users/{user}/impersonate', WorkspaceImpersonationController::class)
            ->middleware(['module.active:core', 'permission:manage_users'])
            ->name('workspace.users.impersonate');

        Route::get('/search', SearchController::class)
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.search');

        Route::get('/intelligence', [IntelligenceSignalController::class, 'index'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.intelligence');
        Route::get('/intelligence/signals', fn () => deprecated_redirect('app.intelligence'))
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.intelligence.signals');
        Route::get('/intelligence/recommendations', [RecommendationController::class, 'index'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.intelligence.recommendations');
        Route::get('/intelligence/opportunities', [OpportunityController::class, 'index'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.intelligence.opportunities');
        Route::post('/intelligence/opportunities/project', [OpportunityController::class, 'project'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.intelligence.opportunities.project');
        Route::get('/intelligence/narratives', [NarrativeController::class, 'index'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.narratives.index');
        Route::get('/intelligence/graph', [GraphController::class, 'index'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.intelligence.graph');
        Route::post('/intelligence/narratives', [NarrativeController::class, 'store'])
            ->middleware(['module.active:core', 'permission:manage_account'])
            ->name('app.narratives.store');
        Route::post('/intelligence/narratives/{narrative}/observations', [NarrativeController::class, 'storeObservation'])
            ->middleware(['module.active:core', 'permission:manage_account'])
            ->name('app.narratives.observations.store');
        Route::post('/intelligence/narratives/{narrative}/gaps', [NarrativeController::class, 'storeGap'])
            ->middleware(['module.active:core', 'permission:manage_account'])
            ->name('app.narratives.gaps.store');
        Route::post('/intelligence/{signal}/reviewed', [IntelligenceSignalController::class, 'markReviewed'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.intelligence.reviewed');
        Route::post('/intelligence/{signal}/dismiss', [IntelligenceSignalController::class, 'dismiss'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.intelligence.dismiss');
        Route::get('/intelligence/{signal}', [IntelligenceSignalController::class, 'show'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->whereNumber('signal')
            ->name('app.intelligence.show');
        Route::post('/recommendations/{recommendation}/review', [RecommendationController::class, 'review'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.recommendations.review');
        Route::post('/recommendations/{recommendation}/accept', [RecommendationController::class, 'accept'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.recommendations.accept');
        Route::post('/recommendations/{recommendation}/execute', [RecommendationController::class, 'execute'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.recommendations.execute');
        Route::post('/recommendations/{recommendation}/task', [MarketingTaskController::class, 'storeFromRecommendation'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:manage_campaigns'])
            ->name('app.recommendations.task');
        Route::post('/recommendations/{recommendation}/dismiss', [RecommendationController::class, 'dismiss'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.recommendations.dismiss');
        Route::post('/recommendations/{recommendation}/archive', [RecommendationController::class, 'archive'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.recommendations.archive');
        Route::get('/intelligence/notifications', [NotificationController::class, 'index'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.notifications');
        Route::post('/notifications/preferences', [NotificationController::class, 'preferences'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.notifications.preferences');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.notifications.read');

        Route::get('/visibility', [VisibilityController::class, 'index'])
            ->middleware(['module.active:visibility', 'permission:view_visibility'])
            ->name('app.visibility');
        Route::get('/visibility/search', SearchPerformanceController::class)
            ->middleware(['module.active:visibility', 'permission:view_visibility'])
            ->name('app.search-performance');
        Route::get('/visibility/citations', [VisibilityController::class, 'citations'])
            ->middleware(['module.active:visibility', 'permission:view_visibility'])
            ->name('app.visibility.citations');
        Route::get('/visibility/prompts', [VisibilityController::class, 'index'])
            ->middleware(['module.active:visibility', 'permission:view_visibility'])
            ->name('app.visibility.prompts');
        Route::post('/visibility/checks', [VisibilityController::class, 'store'])
            ->middleware(['module.active:visibility', 'permission:manage_visibility', 'throttle:ai-actions'])
            ->name('app.visibility.checks.store');
        Route::post('/visibility/prompts', [VisibilityController::class, 'storePrompt'])
            ->middleware(['module.active:visibility', 'permission:manage_visibility'])
            ->name('app.visibility.prompts.store');
        Route::put('/visibility/prompts/{promptTemplate}', [VisibilityController::class, 'updatePrompt'])
            ->middleware(['module.active:visibility', 'permission:manage_visibility'])
            ->name('app.visibility.prompts.update');
        Route::post('/visibility/prompts/{promptTemplate}/archive', [VisibilityController::class, 'archivePrompt'])
            ->middleware(['module.active:visibility', 'permission:manage_visibility'])
            ->name('app.visibility.prompts.archive');
        Route::post('/visibility/prompts/{promptTemplate}/duplicate', [VisibilityController::class, 'duplicatePrompt'])
            ->middleware(['module.active:visibility', 'permission:manage_visibility'])
            ->name('app.visibility.prompts.duplicate');
        Route::post('/visibility/prompts/{promptTemplate}/run', [VisibilityController::class, 'runPrompt'])
            ->middleware(['module.active:visibility', 'permission:manage_visibility', 'throttle:ai-actions'])
            ->name('app.visibility.prompts.run');

        Route::get('/research/competitors', [CompetitorController::class, 'index'])
            ->middleware(['module.active:competitive_intelligence', 'permission:view_competitive_intelligence'])
            ->name('app.competitors');
        Route::post('/competitors', [CompetitorController::class, 'store'])
            ->middleware(['module.active:competitive_intelligence', 'permission:view_competitive_intelligence'])
            ->name('app.competitors.store');
        Route::put('/competitors/{competitor}', [CompetitorController::class, 'update'])
            ->middleware(['module.active:competitive_intelligence', 'permission:view_competitive_intelligence'])
            ->name('app.competitors.update');
        Route::post('/competitors/monitor', [CompetitorController::class, 'monitor'])
            ->middleware(['module.active:competitive_intelligence', 'permission:view_competitive_intelligence'])
            ->name('app.competitors.monitor');

        Route::get('/research/mentions', [MentionController::class, 'index'])
            ->middleware(['module.active:visibility', 'permission:view_visibility'])
            ->name('app.mentions');
        Route::get('/research/mentions/export', [MentionController::class, 'export'])
            ->middleware(['module.active:visibility', 'permission:view_visibility'])
            ->name('app.mentions.export');
        Route::get('/research/mentions/{mention}', [MentionController::class, 'show'])
            ->middleware(['module.active:visibility', 'permission:view_visibility'])
            ->name('app.mentions.show');

        Route::get('/agents', AgentController::class)
            ->middleware(['module.active:agentic_content,agentic_social', 'permission:view_agents'])
            ->name('app.agents');

        Route::get('/reporting/reports', [ReportController::class, 'index'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.reports');
        Route::post('/reports', [ReportController::class, 'store'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.reports.store');
        Route::get('/reporting/reports/{report}', [ReportController::class, 'show'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.reports.show');
        Route::get('/reporting/reports/{report}/export/pdf', [ReportController::class, 'exportPdf'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.reports.export.pdf');
        Route::get('/reporting/reports/{report}/export/powerpoint', [ReportController::class, 'exportPowerPoint'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.reports.export.powerpoint');
        Route::get('/reporting/executive', [ReportController::class, 'executive'])
            ->middleware(['module.active:core', 'permission:view_dashboard'])
            ->name('app.reporting.executive');

        Route::get('/marketing', [MarketingOsController::class, 'index'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:view_campaigns'])
            ->name('app.marketing');
        Route::post('/marketing/workspaces', [MarketingOsController::class, 'storeWorkspace'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:manage_campaigns'])
            ->name('app.marketing.workspaces.store');
        Route::post('/marketing/objectives', [MarketingOsController::class, 'storeObjective'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:manage_campaigns'])
            ->name('app.marketing.objectives.store');
        Route::post('/marketing/tasks', [MarketingTaskController::class, 'store'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:manage_campaigns'])
            ->name('app.marketing.tasks.store');
        Route::get('/marketing/audiences', [AudienceController::class, 'index'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:view_campaigns'])
            ->name('app.audiences');
        Route::post('/marketing/audiences', [AudienceController::class, 'store'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:manage_campaigns'])
            ->name('app.audiences.store');
        Route::get('/marketing/audiences/{audience}', [AudienceController::class, 'show'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:view_campaigns'])
            ->name('app.audiences.show');
        Route::post('/marketing/audiences/{audience}/members', [AudienceController::class, 'storeMember'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:manage_campaigns'])
            ->name('app.audiences.members.store');
        Route::post('/marketing/segments', [AudienceController::class, 'storeSegment'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:manage_campaigns'])
            ->name('app.segments.store');
        Route::view('/marketing/tasks', 'app.module-page', ['title' => 'Tasks', 'module' => 'Marketing'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:view_campaigns'])
            ->name('app.marketing.tasks');
        Route::get('/marketing/briefings', [BriefingController::class, 'index'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:view_campaigns'])
            ->name('app.briefings');
        Route::post('/marketing/briefings', [BriefingController::class, 'store'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:manage_campaigns'])
            ->name('app.briefings.store');
        Route::get('/marketing/briefings/{briefing}', [BriefingController::class, 'show'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:view_campaigns'])
            ->name('app.briefings.show');
        Route::post('/marketing/briefings/{briefing}/approval', [BriefingController::class, 'requestApproval'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:manage_campaigns'])
            ->name('app.briefings.approval.request');
        Route::post('/marketing/briefings/{briefing}/approve', [BriefingController::class, 'approve'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:manage_campaigns'])
            ->name('app.briefings.approve');
        Route::get('/marketing/newsletters', [NewsletterController::class, 'index'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:view_campaigns'])
            ->name('app.newsletters');
        Route::post('/marketing/newsletters', [NewsletterController::class, 'store'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:manage_campaigns'])
            ->name('app.newsletters.store');
        Route::get('/marketing/newsletters/{newsletter}', [NewsletterController::class, 'show'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:view_campaigns'])
            ->name('app.newsletters.show');
        Route::put('/marketing/newsletters/{newsletter}', [NewsletterController::class, 'update'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:manage_campaigns'])
            ->name('app.newsletters.update');
        Route::post('/marketing/newsletters/{newsletter}/sections', [NewsletterController::class, 'storeSection'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:manage_campaigns'])
            ->name('app.newsletters.sections.store');
        Route::post('/marketing/newsletters/{newsletter}/sections/reorder', [NewsletterController::class, 'reorderSections'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:manage_campaigns'])
            ->name('app.newsletters.sections.reorder');
        Route::post('/marketing/newsletters/{newsletter}/draft', [NewsletterController::class, 'saveDraft'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:manage_campaigns'])
            ->name('app.newsletters.draft');
        Route::post('/marketing/newsletters/{newsletter}/approval', [NewsletterController::class, 'requestApproval'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:manage_campaigns'])
            ->name('app.newsletters.approval.request');

        Route::get('/content/distribution', [DistributionController::class, 'index'])
            ->middleware(['module.active:content', 'permission:view_content'])
            ->name('app.distribution');
        Route::post('/distribution/content/{contentAsset}/publish-website', [DistributionController::class, 'publishWebsite'])
            ->middleware(['module.active:content', 'permission:publish_content'])
            ->name('app.distribution.publish-website');
        Route::post('/distribution/social-posts/{socialPost}/schedule', [DistributionController::class, 'scheduleSocial'])
            ->middleware(['module.active:content', 'permission:create_content'])
            ->name('app.distribution.social.schedule');
        Route::post('/distribution/content/{contentAsset}/audit', [DistributionController::class, 'runAudit'])
            ->middleware(['module.active:content', 'permission:edit_content'])
            ->name('app.distribution.audit');
        Route::post('/distribution/content/{contentAsset}/translate', [DistributionController::class, 'translate'])
            ->middleware(['module.active:content', 'permission:edit_content'])
            ->name('app.distribution.translate');
        Route::post('/distribution/content/{contentAsset}/reviewed', [DistributionController::class, 'markReviewed'])
            ->middleware(['module.active:content', 'permission:edit_content'])
            ->name('app.distribution.reviewed');

        Route::prefix('research/topics')->name('app.topics.')->middleware(['module.active:core'])->group(function (): void {
            Route::get('/', [TopicController::class, 'index'])
                ->middleware('permission:view_dashboard')
                ->name('index');
            Route::get('/create', [TopicController::class, 'create'])
                ->middleware('permission:manage_account')
                ->name('create');
            Route::post('/', [TopicController::class, 'store'])
                ->middleware('permission:manage_account')
                ->name('store');
            Route::post('/relationships', [TopicController::class, 'storeRelationship'])
                ->middleware('permission:manage_account')
                ->name('relationships.store');
            Route::post('/clusters', [TopicController::class, 'storeCluster'])
                ->middleware('permission:manage_account')
                ->name('clusters.store');
            Route::get('/clusters/{cluster}', [TopicController::class, 'showCluster'])
                ->middleware('permission:view_dashboard')
                ->name('clusters.show');
            Route::put('/clusters/{cluster}', [TopicController::class, 'updateCluster'])
                ->middleware('permission:manage_account')
                ->name('clusters.update');
            Route::delete('/clusters/{cluster}', [TopicController::class, 'destroyCluster'])
                ->middleware('permission:manage_account')
                ->name('clusters.destroy');
            Route::get('/{topic}', [TopicController::class, 'show'])
                ->middleware('permission:view_dashboard')
                ->name('show');
            Route::get('/{topic}/edit', [TopicController::class, 'edit'])
                ->middleware('permission:manage_account')
                ->name('edit');
            Route::put('/{topic}', [TopicController::class, 'update'])
                ->middleware('permission:manage_account')
                ->name('update');
            Route::delete('/{topic}', [TopicController::class, 'destroy'])
                ->middleware('permission:manage_account')
                ->name('destroy');
        });

        Route::prefix('content')->name('app.content.')->middleware(['module.active:content'])->group(function (): void {
            Route::get('/', [ContentAssetController::class, 'index'])
                ->middleware('permission:view_content')
                ->name('index');
            Route::get('/create', [ContentAssetController::class, 'create'])
                ->middleware('permission:create_content')
                ->name('create');
            Route::post('/', [ContentAssetController::class, 'store'])
                ->middleware('permission:create_content')
                ->name('store');
            Route::get('/operations', [ContentOperationsController::class, 'index'])
                ->middleware('permission:view_content')
                ->name('operations');
            Route::post('/operations/briefings/{briefing}/plan', [ContentOperationsController::class, 'planBriefing'])
                ->middleware('permission:create_content')
                ->name('operations.briefings.plan');
            Route::post('/operations/briefings/{briefing}/draft', [ContentOperationsController::class, 'draftBriefing'])
                ->middleware('permission:create_content')
                ->name('operations.briefings.draft');
            Route::post('/operations/lifecycle-scores/{score}/recommendation', [ContentOperationsController::class, 'refreshRecommendation'])
                ->middleware('permission:edit_content')
                ->name('operations.lifecycle.recommendation');
            Route::get('/brand-voice', [BrandKnowledgeCenterController::class, 'index'])
                ->middleware('permission:view_content')
                ->name('brand-voice');
            Route::post('/brand-voice/setup/generate', [BrandKnowledgeCenterController::class, 'generateSetup'])
                ->middleware('permission:edit_content')
                ->name('brand-voice.setup.generate');
            Route::post('/brand-voice/setup/apply', [BrandKnowledgeCenterController::class, 'applySetup'])
                ->middleware('permission:edit_content')
                ->name('brand-voice.setup.apply');
            Route::patch('/brand-voice/profile', [BrandKnowledgeCenterController::class, 'updateProfile'])
                ->middleware('permission:edit_content')
                ->name('brand-voice.profile.update');
            Route::post('/brand-voice/products', [BrandKnowledgeCenterController::class, 'storeProduct'])
                ->middleware('permission:edit_content')
                ->name('brand-voice.products.store');
            Route::post('/brand-voice/services', [BrandKnowledgeCenterController::class, 'storeService'])
                ->middleware('permission:edit_content')
                ->name('brand-voice.services.store');
            Route::post('/brand-voice/narratives', [BrandKnowledgeCenterController::class, 'storeNarrative'])
                ->middleware('permission:edit_content')
                ->name('brand-voice.narratives.store');
            Route::prefix('answer-blocks')->name('answer-blocks.')->group(function (): void {
                Route::get('/', [AnswerBlockController::class, 'index'])
                    ->middleware('permission:view_content')
                    ->name('index');
                Route::get('/create', [AnswerBlockController::class, 'create'])
                    ->middleware('permission:create_content')
                    ->name('create');
                Route::post('/', [AnswerBlockController::class, 'store'])
                    ->middleware('permission:create_content')
                    ->name('store');
                Route::get('/{answerBlock}', [AnswerBlockController::class, 'show'])
                    ->middleware('permission:view_content')
                    ->name('show');
                Route::get('/{answerBlock}/edit', [AnswerBlockController::class, 'edit'])
                    ->middleware('permission:edit_content')
                    ->name('edit');
                Route::put('/{answerBlock}', [AnswerBlockController::class, 'update'])
                    ->middleware('permission:edit_content')
                    ->name('update');
                Route::delete('/{answerBlock}', [AnswerBlockController::class, 'destroy'])
                    ->middleware('permission:edit_content')
                    ->name('destroy');
            });
            Route::get('/{contentAsset}/answer-blocks/create', [AnswerBlockController::class, 'create'])
                ->middleware('permission:create_content')
                ->name('answer-blocks.create-for-asset');
            Route::view('/audits', 'app.module-page', ['title' => 'Audits', 'module' => 'Content Engine'])
                ->middleware('permission:view_content')
                ->name('audits');
            Route::view('/lifecycle', 'app.module-page', ['title' => 'Lifecycle', 'module' => 'Content Engine'])
                ->middleware('permission:view_content')
                ->name('lifecycle.index');
            Route::view('/translations', 'app.module-page', ['title' => 'Translations', 'module' => 'Content Engine'])
                ->middleware('permission:view_content')
                ->name('translations');
            Route::post('/{contentAsset}/answer-blocks', [AnswerBlockController::class, 'store'])
                ->middleware('permission:create_content')
                ->name('answer-blocks.store-for-asset');
            Route::get('/{contentAsset}', [ContentAssetController::class, 'show'])
                ->middleware('permission:view_content')
                ->name('show');
            Route::get('/{contentAsset}/edit', [ContentAssetController::class, 'edit'])
                ->middleware('permission:edit_content')
                ->name('edit');
            Route::put('/{contentAsset}', [ContentAssetController::class, 'update'])
                ->middleware('permission:edit_content')
                ->name('update');
            Route::post('/{contentAsset}/approve', [ContentAssetController::class, 'approve'])
                ->middleware('permission:publish_content')
                ->name('approve');
            Route::post('/{contentAsset}/publish', [ContentAssetController::class, 'publish'])
                ->middleware('permission:publish_content')
                ->name('publish');
            Route::post('/{contentAsset}/publishing-actions', [ContentAssetController::class, 'publishingAction'])
                ->middleware('permission:publish_content')
                ->name('publishing-actions.store');
            Route::get('/{contentAsset}/social-posts/repurpose', [SocialRepurposingController::class, 'create'])
                ->middleware('permission:create_content')
                ->name('social-posts.repurpose');
            Route::post('/{contentAsset}/social-posts/repurpose', [SocialRepurposingController::class, 'store'])
                ->middleware('permission:create_content')
                ->name('social-posts.repurpose.store');
            Route::post('/{contentAsset}/generate', [ContentAssetController::class, 'generate'])
                ->middleware(['permission:edit_content', 'throttle:ai-actions'])
                ->name('generate');
            Route::post('/{contentAsset}/generate-draft', [ContentOperationsController::class, 'generateDraft'])
                ->middleware(['permission:edit_content', 'throttle:ai-actions'])
                ->name('generate-draft');
            Route::post('/{contentAsset}/generated-assets/{generatedAsset}/apply', [ContentOperationsController::class, 'applyGeneratedDraft'])
                ->middleware('permission:edit_content')
                ->name('generated-assets.apply');
            Route::post('/{contentAsset}/distribution-bundle', [ContentOperationsController::class, 'prepareDistribution'])
                ->middleware('permission:edit_content')
                ->name('distribution-bundle');
            Route::post('/{contentAsset}/translations', [ContentAssetController::class, 'translate'])
                ->middleware('permission:edit_content')
                ->name('translations.store');
            Route::post('/{contentAsset}/audit', [ContentAssetController::class, 'audit'])
                ->middleware('permission:edit_content')
                ->name('audit');
            Route::post('/{contentAsset}/lifecycle', [ContentAssetController::class, 'lifecycle'])
                ->middleware('permission:edit_content')
                ->name('lifecycle');
        });

        Route::get('/marketing/campaigns', [CampaignController::class, 'index'])
            ->middleware(['module.active:campaigns', 'permission:view_campaigns'])
            ->name('app.campaigns');
        Route::post('/campaigns', [CampaignController::class, 'store'])
            ->middleware(['module.active:campaigns', 'permission:manage_campaigns'])
            ->name('app.campaigns.store');
        Route::get('/marketing/campaigns/{campaign}', [CampaignController::class, 'show'])
            ->middleware(['module.active:campaigns', 'permission:view_campaigns'])
            ->name('app.campaigns.show');
        Route::get('/marketing/campaigns/{campaign}/board', [CampaignBoardController::class, 'show'])
            ->middleware(['module.active:campaigns', 'permission:view_campaigns'])
            ->name('app.campaigns.board');
        Route::post('/campaigns/{campaign}/board/items', [CampaignBoardController::class, 'storeItem'])
            ->middleware(['module.active:campaigns', 'permission:manage_campaigns'])
            ->name('app.campaigns.board.items.store');
        Route::patch('/campaigns/{campaign}/board/items/{item}/move', [CampaignBoardController::class, 'moveItem'])
            ->middleware(['module.active:campaigns', 'permission:manage_campaigns'])
            ->name('app.campaigns.board.items.move');
        Route::put('/campaigns/{campaign}', [CampaignController::class, 'update'])
            ->middleware(['module.active:campaigns', 'permission:manage_campaigns'])
            ->name('app.campaigns.update');
        Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy'])
            ->middleware(['module.active:campaigns', 'permission:manage_campaigns'])
            ->name('app.campaigns.destroy');

        Route::view('/agents/automations', 'app.module-page', ['title' => 'Automations', 'module' => 'Agentic Content or Agentic Social'])
            ->middleware(['module.active:agentic_content,agentic_social', 'permission:view_agents'])
            ->name('app.automations');
        Route::get('/agents/tasks', [AgentController::class, 'tasks'])
            ->middleware(['module.active:agentic_content,agentic_social', 'permission:view_agents'])
            ->name('app.agents.tasks');
        Route::get('/agents/runs', [AgentController::class, 'runs'])
            ->middleware(['module.active:agentic_content,agentic_social', 'permission:view_agents'])
            ->name('app.agents.runs');
        Route::post('/agents/recommendations/{recommendation}/plan', [AgentController::class, 'planRecommendation'])
            ->middleware(['module.active:agentic_content,agentic_social', 'permission:run_agents'])
            ->name('app.agents.recommendations.plan');
        Route::post('/agents/briefings/{briefing}/plan', [AgentController::class, 'planBriefing'])
            ->middleware(['module.active:agentic_content,agentic_social', 'permission:run_agents'])
            ->name('app.agents.briefings.plan');
        Route::post('/agents/tasks/{agentTask}/approval', [AgentController::class, 'requestTaskApproval'])
            ->middleware(['module.active:agentic_content,agentic_social', 'permission:run_agents'])
            ->name('app.agents.tasks.approval');
        Route::post('/agents/tasks/{agentTask}/queue', [AgentController::class, 'queueTask'])
            ->middleware(['module.active:agentic_content,agentic_social', 'permission:run_agents'])
            ->name('app.agents.tasks.queue');
        Route::post('/agents/tasks/{agentTask}/run', [AgentController::class, 'runTask'])
            ->middleware(['module.active:agentic_content,agentic_social', 'permission:run_agents'])
            ->name('app.agents.tasks.run');

        Route::prefix('marketing/social-posts')->name('app.social-posts.')->middleware(['module.active:content', 'permission:view_content'])->group(function (): void {
            Route::get('/', [SocialPostController::class, 'index'])
                ->name('index');
            Route::get('/create', [SocialPostController::class, 'create'])
                ->middleware('permission:create_content')
                ->name('create');
            Route::post('/', [SocialPostController::class, 'store'])
                ->middleware('permission:create_content')
                ->name('store');
            Route::get('/{socialPost}', [SocialPostController::class, 'show'])
                ->name('show');
            Route::get('/{socialPost}/variants', [SocialRepurposingController::class, 'variants'])
                ->name('variants');
            Route::post('/{socialPost}/variants/{variant}/select', [SocialRepurposingController::class, 'select'])
                ->middleware('permission:create_content')
                ->name('variants.select');
            Route::post('/{socialPost}/approve', [SocialPostController::class, 'approve'])
                ->middleware('permission:create_content')
                ->name('approve');
            Route::post('/{socialPost}/schedule', [SocialPostController::class, 'schedule'])
                ->middleware('permission:create_content')
                ->name('schedule');
            Route::post('/{socialPost}/publish', [SocialPostController::class, 'publish'])
                ->middleware('permission:publish_content')
                ->name('publish');
        });

        Route::get('/marketing/calendar', [MarketingCalendarController::class, 'index'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:view_campaigns'])
            ->name('app.calendar');
        Route::get('/marketing/calendar/{item}', [MarketingCalendarController::class, 'show'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:view_campaigns'])
            ->name('app.calendar.show');
        Route::post('/calendar/tasks', [MarketingCalendarController::class, 'storeTask'])
            ->middleware(['module.active:campaigns,marketing_os', 'permission:manage_campaigns'])
            ->name('app.calendar.tasks.store');

        Route::get('/reporting/analytics', AnalyticsController::class)
            ->middleware(['module.active:content', 'permission:view_content'])
            ->name('app.analytics');

        Route::prefix('relationships')->middleware(['module.active:core'])->group(function (): void {
            Route::get('/', [RelationshipController::class, 'index'])
                ->middleware('permission:view_dashboard')
                ->name('app.relationships');
            Route::get('/contacts', [RelationshipController::class, 'index'])
                ->middleware('permission:view_dashboard')
                ->name('app.relationships.contacts');
            Route::post('/contacts', [RelationshipController::class, 'storeContact'])
                ->middleware('permission:manage_account')
                ->name('app.relationships.contacts.store');
            Route::get('/contacts/{contact}', [RelationshipController::class, 'showContact'])
                ->middleware('permission:view_dashboard')
                ->name('app.relationships.contacts.show');
            Route::post('/organizations', [RelationshipController::class, 'storeOrganization'])
                ->middleware('permission:manage_account')
                ->name('app.relationships.organizations.store');
            Route::get('/organizations', [RelationshipController::class, 'index'])
                ->middleware('permission:view_dashboard')
                ->name('app.relationships.organizations');
            Route::get('/organizations/{organization}', [RelationshipController::class, 'showOrganization'])
                ->middleware('permission:view_dashboard')
                ->name('app.relationships.organizations.show');
            Route::view('/journalists', 'app.module-page', ['title' => 'Journalists', 'module' => 'Relationships'])
                ->middleware('permission:view_dashboard')
                ->name('app.relationships.journalists');
            Route::get('/influencers', [RelationshipController::class, 'influencers'])
                ->middleware('permission:view_dashboard')
                ->name('app.relationships.influencers');
            Route::post('/influencers', [RelationshipController::class, 'storeInfluencer'])
                ->middleware('permission:manage_account')
                ->name('app.relationships.influencers.store');
            Route::post('/influencers/{contact}/monitor', [RelationshipController::class, 'monitorInfluencer'])
                ->middleware('permission:manage_account')
                ->name('app.relationships.influencers.monitor');
            Route::post('/influencers/{contact}/campaigns', [RelationshipController::class, 'trackInfluencerCampaign'])
                ->middleware('permission:manage_account')
                ->name('app.relationships.influencers.campaigns.store');
            Route::post('/influencers/{contact}/crm', [RelationshipController::class, 'updateInfluencerCrm'])
                ->middleware('permission:manage_account')
                ->name('app.relationships.influencers.crm');
            Route::view('/experts', 'app.module-page', ['title' => 'Experts', 'module' => 'Relationships'])
                ->middleware('permission:view_dashboard')
                ->name('app.relationships.experts');
            Route::post('/edges', [RelationshipController::class, 'storeRelationship'])
                ->middleware('permission:manage_account')
                ->name('app.relationships.edges.store');
        });

        Route::prefix('assets')->name('app.assets.')->middleware(['module.active:content', 'permission:view_content'])->group(function (): void {
            Route::view('/', 'app.module-page', ['title' => 'Assets', 'module' => 'Asset Library'])->name('index');
            Route::view('/media', 'app.module-page', ['title' => 'Media', 'module' => 'Asset Library'])->name('media');
            Route::view('/brand', 'app.module-page', ['title' => 'Brand Assets', 'module' => 'Asset Library'])->name('brand');
            Route::view('/generated', 'app.module-page', ['title' => 'Generated Media', 'module' => 'Asset Library'])->name('generated');
        });

        Route::prefix('research/sources')->name('app.sources.')->middleware(['module.active:core', 'permission:manage_account'])->group(function (): void {
            Route::get('/', [SourceController::class, 'index'])
                ->name('index');
            Route::post('/', [SourceController::class, 'store'])
                ->name('store');
            Route::get('/syncs', [SourceController::class, 'history'])
                ->name('syncs');
            Route::get('/{source}', [SourceController::class, 'show'])
                ->name('show');
            Route::post('/{source}/syncs', [SourceController::class, 'planSync'])
                ->name('syncs.plan');
        });

        Route::prefix('research/entities')->name('app.entities.')->middleware(['module.active:core', 'permission:view_dashboard'])->group(function (): void {
            Route::get('/', [EntityController::class, 'index'])
                ->name('index');
            Route::get('/{entity}', [EntityController::class, 'show'])
                ->name('show');
        });

        Route::redirect('/settings', '/settings/account')
            ->middleware(['module.active:core', 'permission:manage_account'])
            ->name('app.settings');

        Route::prefix('settings')->name('settings.')->middleware(['module.active:core'])->group(function (): void {
            Route::get('/profile', [UserProfileController::class, 'edit'])
                ->middleware('permission:view_dashboard')
                ->name('profile');
            Route::patch('/profile/password', [UserProfileController::class, 'updatePassword'])
                ->middleware('permission:view_dashboard')
                ->name('profile.password.update');

            Route::get('/account', [SettingsController::class, 'account'])
                ->middleware('permission:manage_account')
                ->name('account');
            Route::patch('/account', [SettingsController::class, 'updateAccount'])
                ->middleware('permission:manage_account')
                ->name('account.update');

            Route::get('/brands', [SettingsController::class, 'brands'])
                ->middleware('permission:manage_account')
                ->name('brands');
            Route::patch('/brands/{brand}', [SettingsController::class, 'updateBrand'])
                ->middleware('permission:manage_account')
                ->name('brands.update');

            Route::get('/team', [SettingsController::class, 'team'])
                ->middleware('permission:manage_users')
                ->name('team');
            Route::patch('/team/memberships/{membership}', [SettingsController::class, 'updateMembership'])
                ->middleware('permission:manage_users')
                ->name('team.memberships.update');
            Route::post('/team/brand-memberships', [SettingsController::class, 'storeBrandMembership'])
                ->middleware('permission:manage_users')
                ->name('team.brand-memberships.store');
            Route::patch('/team/brand-memberships/{membership}', [SettingsController::class, 'updateBrandMembership'])
                ->middleware('permission:manage_users')
                ->name('team.brand-memberships.update');

            Route::get('/modules', [SettingsController::class, 'modules'])
                ->middleware('permission:manage_billing')
                ->name('modules');

            Route::get('/llm', [SettingsController::class, 'llm'])
                ->middleware('permission:manage_account')
                ->name('llm');
            Route::patch('/llm', [SettingsController::class, 'updateLlm'])
                ->middleware('permission:manage_account')
                ->name('llm.update');

            Route::get('/integrations', [SettingsController::class, 'integrations'])
                ->middleware('permission:manage_account')
                ->name('integrations');
            Route::get('/integrations/linkedin', [SettingsController::class, 'linkedinIntegration'])
                ->middleware('permission:manage_account')
                ->name('integrations.linkedin');
            Route::get('/integrations/google-analytics', [SettingsController::class, 'googleAnalyticsIntegration'])
                ->middleware('permission:manage_account')
                ->name('integrations.google-analytics');
            Route::post('/integrations/google-analytics/properties', [SettingsController::class, 'storeGoogleAnalyticsProperties'])
                ->middleware('permission:manage_account')
                ->name('integrations.google-analytics.properties.store');
            Route::get('/integrations/search-console', [SettingsController::class, 'searchConsoleIntegration'])
                ->middleware('permission:manage_account')
                ->name('integrations.search-console');
            Route::post('/integrations/search-console/sites', [SettingsController::class, 'storeSearchConsoleSites'])
                ->middleware('permission:manage_account')
                ->name('integrations.search-console.sites.store');
            Route::get('/integrations/linkedin/connect', [LinkedInIntegrationController::class, 'connect'])
                ->middleware('permission:manage_account')
                ->name('integrations.linkedin.connect');
            Route::get('/integrations/linkedin/callback', [LinkedInIntegrationController::class, 'callback'])
                ->middleware('permission:manage_account')
                ->name('integrations.linkedin.callback');
            Route::post('/integrations/linkedin/disconnect/{connection}', [LinkedInIntegrationController::class, 'disconnect'])
                ->middleware('permission:manage_account')
                ->name('integrations.linkedin.disconnect');
            Route::get('/integrations/google/connect', [GoogleIntegrationController::class, 'connect'])
                ->middleware('permission:manage_account')
                ->name('integrations.google.connect');
            Route::get('/integrations/google/callback', [GoogleIntegrationController::class, 'callback'])
                ->middleware('permission:manage_account')
                ->name('integrations.google.callback');
            Route::post('/integrations/google/disconnect/{connection}', [GoogleIntegrationController::class, 'disconnect'])
                ->middleware('permission:manage_account')
                ->name('integrations.google.disconnect');

            Route::get('/social-profiles', [SettingsController::class, 'socialProfiles'])
                ->middleware('permission:manage_account')
                ->name('social-profiles');

            Route::get('/email-providers', [EmailProviderController::class, 'index'])
                ->middleware('permission:manage_account')
                ->name('email-providers');
            Route::post('/email-providers', [EmailProviderController::class, 'store'])
                ->middleware('permission:manage_account')
                ->name('email-providers.store');
            Route::post('/email-providers/{emailProvider}/test', [EmailProviderController::class, 'sendTest'])
                ->middleware('permission:manage_account')
                ->name('email-providers.test');

            Route::get('/connectors', [ConnectorController::class, 'index'])
                ->middleware(['module.active:connectors', 'permission:manage_account'])
                ->name('connectors');
            Route::post('/connectors', [ConnectorController::class, 'store'])
                ->middleware(['module.active:connectors', 'permission:manage_account'])
                ->name('connectors.store');
            Route::patch('/connectors/{connector}', [ConnectorController::class, 'update'])
                ->middleware(['module.active:connectors', 'permission:manage_account'])
                ->name('connectors.update');
            Route::post('/connectors/tokens', [ConnectorController::class, 'storeToken'])
                ->middleware(['module.active:connectors', 'permission:manage_account'])
                ->name('connectors.tokens.store');
            Route::post('/connectors/tokens/{token}/rotate', [ConnectorController::class, 'rotateToken'])
                ->middleware(['module.active:connectors', 'permission:manage_account'])
                ->name('connectors.tokens.rotate');
            Route::delete('/connectors/tokens/{token}', [ConnectorController::class, 'revokeToken'])
                ->middleware(['module.active:connectors', 'permission:manage_account'])
                ->name('connectors.tokens.revoke');

            Route::get('/properties', [SettingsController::class, 'properties'])
                ->middleware(['module.active:content', 'permission:manage_account'])
                ->name('properties');
            Route::post('/properties', [SettingsController::class, 'storeProperty'])
                ->middleware(['module.active:content', 'permission:manage_account'])
                ->name('properties.store');
            Route::patch('/properties/{property}', [SettingsController::class, 'updateProperty'])
                ->middleware(['module.active:content', 'permission:manage_account'])
                ->name('properties.update');

            Route::get('/channels', [SettingsController::class, 'channels'])
                ->middleware(['module.active:content', 'permission:manage_account'])
                ->name('channels');
            Route::patch('/channels/{channel}', [SettingsController::class, 'updateChannel'])
                ->middleware(['module.active:content', 'permission:manage_account'])
                ->name('channels.update');

            Route::get('/knowledge-graph', [KnowledgeGraphController::class, 'index'])
                ->middleware('permission:manage_account')
                ->name('knowledge-graph');
            Route::post('/knowledge-graph/entities', [KnowledgeGraphController::class, 'storeEntity'])
                ->middleware('permission:manage_account')
                ->name('knowledge-graph.entities.store');
            Route::post('/knowledge-graph/relationships', [KnowledgeGraphController::class, 'storeRelationship'])
                ->middleware('permission:manage_account')
                ->name('knowledge-graph.relationships.store');

            Route::get('/knowledge-center', [BrandKnowledgeCenterController::class, 'index'])
                ->middleware('permission:manage_account')
                ->name('knowledge-center');
            Route::post('/knowledge-center/setup/generate', [BrandKnowledgeCenterController::class, 'generateSetup'])
                ->middleware('permission:manage_account')
                ->name('knowledge-center.setup.generate');
            Route::post('/knowledge-center/setup/apply', [BrandKnowledgeCenterController::class, 'applySetup'])
                ->middleware('permission:manage_account')
                ->name('knowledge-center.setup.apply');
            Route::patch('/knowledge-center/profile', [BrandKnowledgeCenterController::class, 'updateProfile'])
                ->middleware('permission:manage_account')
                ->name('knowledge-center.profile.update');
            Route::post('/knowledge-center/products', [BrandKnowledgeCenterController::class, 'storeProduct'])
                ->middleware('permission:manage_account')
                ->name('knowledge-center.products.store');
            Route::post('/knowledge-center/services', [BrandKnowledgeCenterController::class, 'storeService'])
                ->middleware('permission:manage_account')
                ->name('knowledge-center.services.store');
            Route::post('/knowledge-center/narratives', [BrandKnowledgeCenterController::class, 'storeNarrative'])
                ->middleware('permission:manage_account')
                ->name('knowledge-center.narratives.store');
        });

        Route::get('/search-performance', fn () => deprecated_redirect('app.search-performance'))->middleware(['module.active:visibility', 'permission:view_visibility']);
        Route::get('/notifications', fn () => deprecated_redirect('app.notifications'))->middleware(['module.active:core', 'permission:view_dashboard']);
        Route::get('/competitors', fn () => deprecated_redirect('app.competitors'))->middleware(['module.active:competitive_intelligence', 'permission:view_competitive_intelligence']);
        Route::get('/mentions', fn () => deprecated_redirect('app.mentions'))->middleware(['module.active:visibility', 'permission:view_visibility']);
        Route::get('/mentions/{mention}', fn ($mention) => deprecated_redirect('app.mentions.show', ['mention' => $mention]))->middleware(['module.active:visibility', 'permission:view_visibility']);
        Route::get('/reports', fn () => deprecated_redirect('app.reports'))->middleware(['module.active:core', 'permission:view_dashboard']);
        Route::get('/reports/{report}', fn ($report) => deprecated_redirect('app.reports.show', ['report' => $report]))->middleware(['module.active:core', 'permission:view_dashboard']);
        Route::get('/distribution', fn () => deprecated_redirect('app.distribution'))->middleware(['module.active:content', 'permission:view_content']);
        Route::get('/topics', fn () => deprecated_redirect('app.topics.index'))->middleware(['module.active:core', 'permission:view_dashboard']);
        Route::get('/topics/create', fn () => deprecated_redirect('app.topics.create'))->middleware(['module.active:core', 'permission:manage_account']);
        Route::get('/topics/clusters/{cluster}', fn ($cluster) => deprecated_redirect('app.topics.clusters.show', ['cluster' => $cluster]))->middleware(['module.active:core', 'permission:view_dashboard']);
        Route::get('/topics/{topic}', fn ($topic) => deprecated_redirect('app.topics.show', ['topic' => $topic]))->middleware(['module.active:core', 'permission:view_dashboard']);
        Route::get('/topics/{topic}/edit', fn ($topic) => deprecated_redirect('app.topics.edit', ['topic' => $topic]))->middleware(['module.active:core', 'permission:manage_account']);
        Route::get('/campaigns', fn () => deprecated_redirect('app.campaigns'))->middleware(['module.active:campaigns', 'permission:view_campaigns']);
        Route::get('/campaigns/{campaign}/board', fn ($campaign) => deprecated_redirect('app.campaigns.board', ['campaign' => $campaign]))->middleware(['module.active:campaigns', 'permission:view_campaigns']);
        Route::get('/campaigns/{campaign}', fn ($campaign) => deprecated_redirect('app.campaigns.show', ['campaign' => $campaign]))->middleware(['module.active:campaigns', 'permission:view_campaigns']);
        Route::get('/calendar', fn () => deprecated_redirect('app.calendar'))->middleware(['module.active:campaigns,marketing_os', 'permission:view_campaigns']);
        Route::get('/calendar/{item}', fn ($item) => deprecated_redirect('app.calendar.show', ['item' => $item]))->middleware(['module.active:campaigns,marketing_os', 'permission:view_campaigns']);
        Route::get('/analytics', fn () => deprecated_redirect('app.analytics'))->middleware(['module.active:content', 'permission:view_content']);
        Route::get('/automations', fn () => deprecated_redirect('app.automations'))->middleware(['module.active:agentic_content,agentic_social', 'permission:view_agents']);
        Route::get('/social-posts', fn () => deprecated_redirect('app.social-posts.index'))->middleware(['module.active:content', 'permission:view_content']);
        Route::get('/social-posts/create', fn () => deprecated_redirect('app.social-posts.create'))->middleware(['module.active:content', 'permission:create_content']);
        Route::get('/social-posts/{socialPost}', fn ($socialPost) => deprecated_redirect('app.social-posts.show', ['socialPost' => $socialPost]))->middleware(['module.active:content', 'permission:view_content']);
        Route::get('/social-posts/{socialPost}/variants', fn ($socialPost) => deprecated_redirect('app.social-posts.variants', ['socialPost' => $socialPost]))->middleware(['module.active:content', 'permission:view_content']);
        Route::get('/sources', fn () => deprecated_redirect('app.sources.index'))->middleware(['module.active:core', 'permission:manage_account']);
        Route::get('/sources/syncs', fn () => deprecated_redirect('app.sources.syncs'))->middleware(['module.active:core', 'permission:manage_account']);
        Route::get('/sources/{source}', fn ($source) => deprecated_redirect('app.sources.show', ['source' => $source]))->middleware(['module.active:core', 'permission:manage_account']);
        Route::get('/admin/domain-events', fn () => deprecated_redirect('app.domain-events'))->middleware(['module.active:core', 'permission:manage_account']);

        Route::post('/tenant/account', [TenantContextController::class, 'switchAccount'])
            ->middleware('throttle:tenant-switch')
            ->name('tenant.account.switch');

        Route::post('/tenant/brand', [TenantContextController::class, 'switchBrand'])
            ->middleware('throttle:tenant-switch')
            ->name('tenant.brand.switch');
    });
};

if (config('argusly.app_domain') && ! app()->runningUnitTests() && ! app()->environment('testing')) {
    Route::domain(config('argusly.app_domain'))->group($appRoutes);
} else {
    $appRoutes();
}
