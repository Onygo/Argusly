<?php

/*
|--------------------------------------------------------------------------
| App Subdomain Routes
|--------------------------------------------------------------------------
|
| These routes are loaded on app.argusly.local (dev) or
| app.argusly.com (production). They serve the customer application.
|
| Routes are served WITHOUT the /app prefix since we're on a subdomain.
|
*/

use App\Http\Controllers\App\ActivationController;
use App\Http\Controllers\App\Api\AppBillingUpgradeStatusController;
use App\Http\Controllers\App\Api\AppBrandFieldActionsController;
use App\Http\Controllers\App\AppAgenticApprovalInboxController;
use App\Http\Controllers\App\AppAgenticMarketingController;
use App\Http\Controllers\App\AppAgentOrchestrationController;
use App\Http\Controllers\App\AppAiTrustCenterController;
use App\Http\Controllers\App\AppAnalyticsSiteController;
use App\Http\Controllers\App\AppAutonomousMarketingWorkflowController;
use App\Http\Controllers\App\AppBillingController;
use App\Http\Controllers\App\AppBrandController;
use App\Http\Controllers\App\AppBrandGrowthPlanController;
use App\Http\Controllers\App\AppBrandWizardController;
use App\Http\Controllers\App\AppBriefsController;
use App\Http\Controllers\App\AppCampaignClusterController;
use App\Http\Controllers\App\AppCampaignPlannerController;
use App\Http\Controllers\App\AppCompanyIntelligenceController;
use App\Http\Controllers\App\AppCompanyOnboardingController;
use App\Http\Controllers\App\AppCompetitorIntelligenceController;
use App\Http\Controllers\App\AppConnectorController;
use App\Http\Controllers\App\AppContentAutomationsController;
use App\Http\Controllers\App\AppContentBatchesController;
use App\Http\Controllers\App\AppContentChainController;
use App\Http\Controllers\App\AppContentController;
use App\Http\Controllers\App\AppContentImageAssetController;
use App\Http\Controllers\App\AppContentNetworkController;
use App\Http\Controllers\App\AppContentOpportunityController;
use App\Http\Controllers\App\AppContentPackageController;
use App\Http\Controllers\App\AppContentPipelineController;
use App\Http\Controllers\App\AppContentQualityController;
use App\Http\Controllers\App\AppContentSeriesController;
use App\Http\Controllers\App\AppDashboardController;
use App\Http\Controllers\App\AppDeveloperController;
use App\Http\Controllers\App\AppDeveloperDocsController;
use App\Http\Controllers\App\AppDraftComparisonsController;
use App\Http\Controllers\App\AppDraftsController;
use App\Http\Controllers\App\AppGrowthAutopilotQueueController;
use App\Http\Controllers\App\AppGrowthProgramController;
use App\Http\Controllers\App\AppHumanContentDashboardController;
use App\Http\Controllers\App\AppImagePresetController;
use App\Http\Controllers\App\AppInsightsController;
use App\Http\Controllers\App\AppInvoiceController;
use App\Http\Controllers\App\AppLearningOptimizationController;
use App\Http\Controllers\App\AppLearningsController;
use App\Http\Controllers\App\AppLlmTrackingController;
use App\Http\Controllers\App\AppLlmTrackingQuerySetController;
use App\Http\Controllers\App\AppMarketingIntelligenceWorkspaceController;
use App\Http\Controllers\App\AppMonitoredPageController;
use App\Http\Controllers\App\AppNotificationsController;
use App\Http\Controllers\App\AppOnboardingController;
use App\Http\Controllers\App\AppOpportunitiesController;
use App\Http\Controllers\App\AppOpportunityExecutionController;
use App\Http\Controllers\App\AppOpportunityIntelligenceController;
use App\Http\Controllers\App\AppPageIntelligenceReportController;
use App\Http\Controllers\App\AppProgrammaticBriefBlueprintController;
use App\Http\Controllers\App\AppProgrammaticClusterController;
use App\Http\Controllers\App\AppProgrammaticDraftRequestController;
use App\Http\Controllers\App\AppProgrammaticDraftReviewController;
use App\Http\Controllers\App\AppProgrammaticOpportunityController;
use App\Http\Controllers\App\AppProgrammaticPublicationPlanController;
use App\Http\Controllers\App\AppProgrammaticPublicationReadinessController;
use App\Http\Controllers\App\AppRecommendedActionsController;
use App\Http\Controllers\App\AppResearchController;
use App\Http\Controllers\App\AppScheduledPageIntelligenceBriefingController;
use App\Http\Controllers\App\AppSettingsController;
use App\Http\Controllers\App\AppSiteCompetitorsController;
use App\Http\Controllers\App\AppSitesController;
use App\Http\Controllers\App\AppSiteSeoAuditController;
use App\Http\Controllers\App\AppSocialDistributionController;
use App\Http\Controllers\App\AppTeamMembersController;
use App\Http\Controllers\App\AppWorkspaceIntelligenceController;
use App\Http\Controllers\App\AppWriterProfileController;
use App\Http\Controllers\App\ContentAnswerBlockController;
use App\Http\Controllers\App\ContentLifecycleDashboardController;
use App\Http\Controllers\App\DraftLinkSuggestionsController;
use App\Http\Controllers\App\NetworkLinkingController;
use App\Http\Controllers\App\OnboardingScanController;
use App\Http\Controllers\App\OpportunityReviewController;
use App\Http\Controllers\App\Settings\InstagramIntegrationController;
use App\Http\Controllers\App\Settings\LinkedInIntegrationController;
use App\Http\Controllers\App\SetupController;
use App\Http\Controllers\App\SignalIntelligenceController;
use App\Http\Controllers\Auth\EmailCodeVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Billing\PackCheckoutTestController;
use App\Http\Controllers\Billing\PackReturnController;
use App\Http\Controllers\Impersonation\StopImpersonationController;
use App\Http\Controllers\SearchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Authentication routes
Route::get('/login', [LoginController::class, 'show'])
    ->middleware('guest')
    ->name('login');
Route::post('/login', [LoginController::class, 'store'])
    ->middleware(['guest', 'throttle:login'])
    ->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
Route::post('/impersonation/stop', StopImpersonationController::class)
    ->middleware('auth')
    ->name('impersonation.stop');

Route::get('/register', [RegisterController::class, 'show'])
    ->middleware('registration.enabled')
    ->name('register');
Route::post('/register', [RegisterController::class, 'store'])
    ->middleware(['registration.enabled', 'throttle:organization-register'])
    ->name('register.store');
Route::get('/verify-code', [EmailCodeVerificationController::class, 'show'])
    ->middleware('auth')
    ->name('verify-code.show');
Route::post('/verify-code', [EmailCodeVerificationController::class, 'verify'])
    ->middleware('auth')
    ->name('verify-code.store');
Route::post('/verify-code/resend', [EmailCodeVerificationController::class, 'resend'])
    ->middleware('auth')
    ->name('verify-code.resend');

Route::view('/pending', 'auth.pending')->name('pending');
Route::view('/on-hold', 'auth.on-hold')->name('on-hold');
Route::get('/invite/{token}', [AppSettingsController::class, 'acceptForm'])->name('invite.show');
Route::post('/invite/{token}', [AppSettingsController::class, 'accept'])->name('invite.store');

Route::get('/billing/return', [PackReturnController::class, 'handle'])
    ->name('billing.pack.return');

if (app()->environment(['local', 'testing'])) {
    Route::get('/_test/pack-checkout/{purchaseId}', [PackCheckoutTestController::class, 'checkout'])
        ->name('test.pack.checkout');
}

// App routes (no /app prefix - we're on the app subdomain)
Route::middleware(['auth', 'app.locale', 'support.context:app', 'support.readonly', 'email.code.verified', 'user.approved', 'user.org', 'onboarding.billing'])
    ->group(function () {
        Route::redirect('/', '/dashboard')->name('app');
        Route::get('/dashboard', [AppDashboardController::class, 'index'])->name('app.dashboard');
        Route::get('/intelligence', [AppMarketingIntelligenceWorkspaceController::class, 'index'])->name('app.marketing-intelligence.index');
        Route::get('/activation', [ActivationController::class, 'index'])->name('app.activation.index');
        Route::get('/setup', [SetupController::class, 'index'])->name('app.setup.index');
        Route::get('/notifications', [AppNotificationsController::class, 'index'])->name('app.notifications.index');
        Route::post('/notifications/read-all', [AppNotificationsController::class, 'markAllRead'])->name('app.notifications.read-all');
        Route::post('/notifications/{notification}/read', [AppNotificationsController::class, 'markRead'])->name('app.notifications.read');
        Route::get('/recommended-actions', [AppRecommendedActionsController::class, 'index'])->name('app.recommended-actions.index');
        Route::post('/recommended-actions/{action}/dismiss', [AppRecommendedActionsController::class, 'dismiss'])->name('app.recommended-actions.dismiss');
        Route::post('/growth-autopilot-queue/{item}/approve', [AppGrowthAutopilotQueueController::class, 'approve'])->name('app.growth-autopilot-queue.approve');
        Route::post('/growth-autopilot-queue/{item}/dismiss', [AppGrowthAutopilotQueueController::class, 'dismiss'])->name('app.growth-autopilot-queue.dismiss');
        Route::post('/content-packages/from-queue/{item}', [AppContentPackageController::class, 'storeFromQueueItem'])->name('app.content-packages.from-queue');
        Route::get('/search', [SearchController::class, 'appIndex'])->middleware('protect.heavy:search')->name('app.search');
        Route::get('/search/suggest', [SearchController::class, 'appSuggest'])->middleware('protect.heavy:search')->name('app.search.suggest');
        Route::get('/opportunities', [AppOpportunitiesController::class, 'index'])->name('app.opportunities.index');
        Route::get('/opportunities/inbox', [AppOpportunitiesController::class, 'index'])->name('app.opportunities.inbox');
        Route::get('/opportunities/decisions', [AppOpportunitiesController::class, 'index'])->name('app.opportunities.decisions');
        Route::get('/opportunities/candidates/{detection}', [AppOpportunitiesController::class, 'showCandidate'])->name('app.opportunities.candidates.show');
        Route::get('/opportunities/execution-recommendations/{plan}', [AppOpportunitiesController::class, 'showExecutionRecommendation'])->name('app.opportunities.execution-recommendations.show');
        Route::get('/opportunities/{opportunity}', [AppOpportunitiesController::class, 'show'])->name('app.opportunities.show');
        Route::get('/onboarding/company', [AppCompanyOnboardingController::class, 'show'])->name('app.onboarding.company.show');
        Route::post('/onboarding/company', [AppCompanyOnboardingController::class, 'update'])->name('app.onboarding.company.update');
        Route::get('/onboarding', [AppOnboardingController::class, 'show'])->name('app.onboarding.show');
        Route::post('/onboarding/intent', [AppOnboardingController::class, 'storeIntent'])->name('app.onboarding.intent');
        Route::post('/onboarding/company-profile', [AppOnboardingController::class, 'storeCompanyProfile'])->name('app.onboarding.company-profile');
        Route::post('/onboarding/connect-site', [AppOnboardingController::class, 'completeSiteConnect'])->name('app.onboarding.connect-site');
        Route::post('/onboarding/scan', [OnboardingScanController::class, 'store'])
            ->middleware('protect.heavy:heavy')
            ->name('app.onboarding.scan.store');
        Route::get('/onboarding/scan/latest', [OnboardingScanController::class, 'latest'])->name('app.onboarding.scan.latest');
        Route::get('/onboarding/scan/{scan}', [OnboardingScanController::class, 'show'])->name('app.onboarding.scan.show');
        Route::post('/onboarding/scan/{scan}/confirm', [OnboardingScanController::class, 'confirm'])->name('app.onboarding.scan.confirm');
        Route::get('/insights', [AppInsightsController::class, 'index'])->name('app.insights.index');
        Route::get('/insights/human-content', [AppHumanContentDashboardController::class, 'index'])->name('app.insights.human-content.index');
        Route::get('/page-intelligence', [AppMonitoredPageController::class, 'index'])->name('app.page-intelligence.index');
        Route::get('/page-intelligence/monitored-pages', [AppMonitoredPageController::class, 'index'])->name('app.page-intelligence.monitored-pages.index');
        Route::post('/page-intelligence/content-inventory/{monitoredPage}/refresh', [AppMonitoredPageController::class, 'refresh'])->name('app.page-intelligence.content-inventory.refresh');
        Route::post('/page-intelligence/content-inventory/{monitoredPage}/exclude', [AppMonitoredPageController::class, 'exclude'])->name('app.page-intelligence.content-inventory.exclude');
        Route::post('/page-intelligence/content-inventory/{monitoredPage}/include', [AppMonitoredPageController::class, 'include'])->name('app.page-intelligence.content-inventory.include');
        Route::post('/page-intelligence/content-inventory/{monitoredPage}/link-content', [AppMonitoredPageController::class, 'linkContent'])->name('app.page-intelligence.content-inventory.link-content');
        Route::post('/page-intelligence/content-inventory/{monitoredPage}/activate', [AppMonitoredPageController::class, 'activate'])->name('app.page-intelligence.content-inventory.activate');
        Route::get('/page-intelligence/monitored-pages/{monitoredPage}', [AppMonitoredPageController::class, 'show'])->name('app.page-intelligence.monitored-pages.show');
        Route::get('/page-intelligence/scheduled-briefings', [AppScheduledPageIntelligenceBriefingController::class, 'index'])->name('app.page-intelligence.scheduled-briefings.index');
        Route::post('/page-intelligence/scheduled-briefings', [AppScheduledPageIntelligenceBriefingController::class, 'store'])->name('app.page-intelligence.scheduled-briefings.store');
        Route::get('/page-intelligence/scheduled-briefings/{briefing}/edit', [AppScheduledPageIntelligenceBriefingController::class, 'edit'])->name('app.page-intelligence.scheduled-briefings.edit');
        Route::put('/page-intelligence/scheduled-briefings/{briefing}', [AppScheduledPageIntelligenceBriefingController::class, 'update'])->name('app.page-intelligence.scheduled-briefings.update');
        Route::post('/page-intelligence/scheduled-briefings/{briefing}/activate', [AppScheduledPageIntelligenceBriefingController::class, 'activate'])->name('app.page-intelligence.scheduled-briefings.activate');
        Route::post('/page-intelligence/scheduled-briefings/{briefing}/deactivate', [AppScheduledPageIntelligenceBriefingController::class, 'deactivate'])->name('app.page-intelligence.scheduled-briefings.deactivate');
        Route::get('/page-intelligence/reports', [AppPageIntelligenceReportController::class, 'index'])->name('app.page-intelligence.reports.index');
        Route::post('/page-intelligence/reports', [AppPageIntelligenceReportController::class, 'store'])->middleware('protect.heavy:report')->name('app.page-intelligence.reports.store');
        Route::post('/page-intelligence/reports/{report}/artifact', [AppPageIntelligenceReportController::class, 'generateArtifact'])->middleware('protect.heavy:export')->name('app.page-intelligence.reports.artifact.generate');
        Route::get('/page-intelligence/reports/{report}/download', [AppPageIntelligenceReportController::class, 'downloadArtifact'])->name('app.page-intelligence.reports.artifact.download');
        Route::get('/page-intelligence/reports/{report}/export', [AppPageIntelligenceReportController::class, 'export'])->name('app.page-intelligence.reports.export');
        Route::get('/page-intelligence/reports/{report}', [AppPageIntelligenceReportController::class, 'show'])->name('app.page-intelligence.reports.show');
        Route::middleware(['ensure.feature.enabled:signal_intelligence'])->group(function () {
            Route::get('/signal-intelligence', [SignalIntelligenceController::class, 'index'])->name('app.signal-intelligence.index');
            Route::post('/signal-intelligence/run', [SignalIntelligenceController::class, 'run'])->middleware('protect.heavy:report')->name('app.signal-intelligence.run');
            Route::get('/signal-intelligence/detections/{detection}', [SignalIntelligenceController::class, 'show'])->name('app.signal-intelligence.detections.show');
            Route::post('/signal-intelligence/detections/{detection}/review', [SignalIntelligenceController::class, 'review'])->name('app.signal-intelligence.detections.review');
            Route::post('/signal-intelligence/detections/{detection}/dismiss', [SignalIntelligenceController::class, 'dismiss'])->name('app.signal-intelligence.detections.dismiss');
            Route::post('/signal-intelligence/detections/{detection}/resolve', [SignalIntelligenceController::class, 'resolve'])->name('app.signal-intelligence.detections.resolve');
            Route::post('/signal-intelligence/detections/{detection}/promote', [SignalIntelligenceController::class, 'promote'])->name('app.signal-intelligence.detections.promote');
            Route::get('/opportunity-review', [OpportunityReviewController::class, 'index'])->name('app.opportunity-review.index');
        });
        Route::middleware(['ensure.feature.enabled:agentic_marketing'])->group(function () {
            Route::get('/agentic-marketing', [AppAgenticMarketingController::class, 'index'])->name('app.agentic-marketing.index');
            Route::get('/agentic-marketing/approvals', [AppAgenticApprovalInboxController::class, 'index'])->name('app.agentic-marketing.approvals.index');
            Route::post('/agentic-marketing/approvals/approve-recommended', [AppAgenticApprovalInboxController::class, 'approveRecommended'])->name('app.agentic-marketing.approvals.approve-recommended');
            Route::post('/agentic-marketing/approvals/bulk-approve', [AppAgenticApprovalInboxController::class, 'bulkApprove'])->name('app.agentic-marketing.approvals.bulk-approve');
            Route::post('/agentic-marketing/approvals/{run}/approve', [AppAgenticApprovalInboxController::class, 'approve'])->name('app.agentic-marketing.approvals.approve');
            Route::post('/agentic-marketing/approvals/{run}/reject', [AppAgenticApprovalInboxController::class, 'reject'])->name('app.agentic-marketing.approvals.reject');
            Route::post('/agentic-marketing/approvals/{run}/request-changes', [AppAgenticApprovalInboxController::class, 'requestChanges'])->name('app.agentic-marketing.approvals.request-changes');
            Route::post('/agentic-marketing/approvals/{run}/run', [AppAgenticApprovalInboxController::class, 'run'])->name('app.agentic-marketing.approvals.run');
            Route::get('/agentic-marketing/orchestration', [AppAgentOrchestrationController::class, 'index'])->name('app.agentic-marketing.orchestration.index');
            Route::post('/agentic-marketing/orchestration/run', [AppAgentOrchestrationController::class, 'run'])->name('app.agentic-marketing.orchestration.run');
            Route::get('/agentic-marketing/orchestration/{run}', [AppAgentOrchestrationController::class, 'show'])->name('app.agentic-marketing.orchestration.show');
            Route::get('/agentic-marketing/objectives/create', [AppAgenticMarketingController::class, 'createObjective'])->name('app.agentic-marketing.objectives.create');
            Route::post('/agentic-marketing/objectives', [AppAgenticMarketingController::class, 'storeObjective'])->name('app.agentic-marketing.objectives.store');
            Route::post('/agentic-marketing/objectives/{objective}/scan', [AppAgenticMarketingController::class, 'scanObjective'])->name('app.agentic-marketing.objectives.scan');
            Route::get('/agentic-marketing/objectives/{objective}', [AppAgenticMarketingController::class, 'showObjective'])->name('app.agentic-marketing.objectives.show');
            Route::get('/agentic-marketing/objectives/{objective}/edit', [AppAgenticMarketingController::class, 'editObjective'])->name('app.agentic-marketing.objectives.edit');
            Route::put('/agentic-marketing/objectives/{objective}', [AppAgenticMarketingController::class, 'updateObjective'])->name('app.agentic-marketing.objectives.update');
            Route::delete('/agentic-marketing/objectives/{objective}', [AppAgenticMarketingController::class, 'destroyObjective'])->name('app.agentic-marketing.objectives.destroy');
            Route::get('/agentic-marketing/actions/{action}', [AppAgenticMarketingController::class, 'showAction'])->name('app.agentic-marketing.actions.show');
            Route::post('/agentic-marketing/actions/{action}/approve', [AppAgenticMarketingController::class, 'approve'])->name('app.agentic-marketing.actions.approve');
            Route::post('/agentic-marketing/actions/{action}/dismiss', [AppAgenticMarketingController::class, 'dismiss'])->name('app.agentic-marketing.actions.dismiss');
            Route::post('/agentic-marketing/actions/{action}/execute', [AppAgenticMarketingController::class, 'execute'])->name('app.agentic-marketing.actions.execute');
            Route::post('/agentic-marketing/actions/{action}/retry', [AppAgenticMarketingController::class, 'retry'])->name('app.agentic-marketing.actions.retry');
            Route::get('/agentic-marketing/content-opportunities', [AppContentOpportunityController::class, 'index'])->name('app.agentic-marketing.content-opportunities.index');
            Route::post('/agentic-marketing/content-opportunities/run', [AppContentOpportunityController::class, 'run'])->name('app.agentic-marketing.content-opportunities.run');
            Route::post('/agentic-marketing/content-opportunities/{opportunity}/brief', [AppContentOpportunityController::class, 'createBrief'])->name('app.agentic-marketing.content-opportunities.brief.create');
            Route::get('/agentic-marketing/brand-growth-plans', [AppBrandGrowthPlanController::class, 'index'])->name('app.agentic-marketing.brand-growth-plans.index');
            Route::post('/agentic-marketing/brand-growth-plans/generate', [AppBrandGrowthPlanController::class, 'generate'])->middleware('protect.heavy:report')->name('app.agentic-marketing.brand-growth-plans.generate');
            Route::post('/agentic-marketing/brand-growth-plans/{plan}/regenerate', [AppBrandGrowthPlanController::class, 'regenerate'])->middleware('protect.heavy:report')->name('app.agentic-marketing.brand-growth-plans.regenerate');
            Route::get('/agentic-marketing/brand-growth-plans/{plan}', [AppBrandGrowthPlanController::class, 'show'])->name('app.agentic-marketing.brand-growth-plans.show');
            Route::put('/agentic-marketing/brand-growth-plans/{plan}', [AppBrandGrowthPlanController::class, 'update'])->name('app.agentic-marketing.brand-growth-plans.update');
            Route::post('/agentic-marketing/brand-growth-plans/{plan}/approve', [AppBrandGrowthPlanController::class, 'approvePlan'])->name('app.agentic-marketing.brand-growth-plans.approve');
            Route::post('/agentic-marketing/brand-growth-plans/{plan}/promote-approved', [AppBrandGrowthPlanController::class, 'promoteApprovedItems'])->name('app.agentic-marketing.brand-growth-plans.promote-approved');
            Route::post('/agentic-marketing/brand-growth-plans/{plan}/execution-recommendations', [AppBrandGrowthPlanController::class, 'createExecutionRecommendations'])->name('app.agentic-marketing.brand-growth-plans.execution-recommendations.create');
            Route::post('/agentic-marketing/brand-growth-plans/{plan}/content-briefs', [AppBrandGrowthPlanController::class, 'createContentBriefs'])->name('app.agentic-marketing.brand-growth-plans.content-briefs.create');
            Route::post('/agentic-marketing/brand-growth-plans/{plan}/drafts', [AppBrandGrowthPlanController::class, 'createDrafts'])->name('app.agentic-marketing.brand-growth-plans.drafts.create');
            Route::post('/agentic-marketing/brand-growth-findings/{finding}/approve', [AppBrandGrowthPlanController::class, 'approveFinding'])->name('app.agentic-marketing.brand-growth-findings.approve');
            Route::post('/agentic-marketing/brand-growth-findings/{finding}/reject', [AppBrandGrowthPlanController::class, 'rejectFinding'])->name('app.agentic-marketing.brand-growth-findings.reject');
            Route::post('/agentic-marketing/brand-growth-findings/{finding}/promote', [AppBrandGrowthPlanController::class, 'promoteFinding'])->name('app.agentic-marketing.brand-growth-findings.promote');
            Route::post('/agentic-marketing/brand-growth-audiences/{proposal}/approve', [AppBrandGrowthPlanController::class, 'approveAudience'])->name('app.agentic-marketing.brand-growth-audiences.approve');
            Route::post('/agentic-marketing/brand-growth-audiences/{proposal}/reject', [AppBrandGrowthPlanController::class, 'rejectAudience'])->name('app.agentic-marketing.brand-growth-audiences.reject');
            Route::post('/agentic-marketing/brand-growth-audiences/{proposal}/promote', [AppBrandGrowthPlanController::class, 'promoteAudience'])->name('app.agentic-marketing.brand-growth-audiences.promote');
            Route::get('/agentic-marketing/intelligence', [AppOpportunityIntelligenceController::class, 'index'])->name('app.agentic-marketing.intelligence.index');
            Route::post('/agentic-marketing/intelligence/run', [AppOpportunityIntelligenceController::class, 'run'])->name('app.agentic-marketing.intelligence.run');
            Route::get('/opportunity-intelligence/opportunities/{opportunity}', [AppOpportunityIntelligenceController::class, 'show'])->name('app.opportunity-intelligence.opportunities.show');
            Route::post('/opportunity-intelligence/run', [AppOpportunityIntelligenceController::class, 'run'])->name('app.opportunity-intelligence.run');
            Route::post('/opportunity-intelligence/opportunities/{opportunity}/review', [AppOpportunityIntelligenceController::class, 'review'])->name('app.opportunity-intelligence.opportunities.review');
            Route::post('/opportunity-intelligence/opportunities/{opportunity}/approve', [AppOpportunityIntelligenceController::class, 'approve'])->name('app.opportunity-intelligence.opportunities.approve');
            Route::post('/opportunity-intelligence/opportunities/{opportunity}/dismiss', [AppOpportunityIntelligenceController::class, 'dismiss'])->name('app.opportunity-intelligence.opportunities.dismiss');
            Route::post('/opportunity-intelligence/opportunities/{opportunity}/resolve', [AppOpportunityIntelligenceController::class, 'resolve'])->name('app.opportunity-intelligence.opportunities.resolve');
            Route::post('/opportunity-intelligence/opportunities/{opportunity}/archive', [AppOpportunityIntelligenceController::class, 'archive'])->name('app.opportunity-intelligence.opportunities.archive');
            Route::get('/opportunity-intelligence/opportunities/{opportunity}/execution-plans', [AppOpportunityIntelligenceController::class, 'indexExecutionPlans'])->name('app.opportunity-intelligence.opportunities.execution-plans.index');
            Route::post('/opportunity-intelligence/opportunities/{opportunity}/execution-plans', [AppOpportunityIntelligenceController::class, 'storeExecutionPlan'])->name('app.opportunity-intelligence.opportunities.execution-plans.store');
            Route::get('/opportunity-intelligence/execution-plans/{plan}', [AppOpportunityIntelligenceController::class, 'showExecutionPlan'])->name('app.opportunity-intelligence.execution-plans.show');
            Route::post('/opportunity-intelligence/execution-plans/{plan}/review', [AppOpportunityIntelligenceController::class, 'reviewExecutionPlan'])->name('app.opportunity-intelligence.execution-plans.review');
            Route::post('/opportunity-intelligence/execution-plans/{plan}/approve', [AppOpportunityIntelligenceController::class, 'approveExecutionPlan'])->name('app.opportunity-intelligence.execution-plans.approve');
            Route::post('/opportunity-intelligence/execution-plans/{plan}/planned', [AppOpportunityIntelligenceController::class, 'plannedExecutionPlan'])->name('app.opportunity-intelligence.execution-plans.planned');
            Route::post('/opportunity-intelligence/execution-plans/{plan}/archive', [AppOpportunityIntelligenceController::class, 'archiveExecutionPlan'])->name('app.opportunity-intelligence.execution-plans.archive');
            Route::post('/opportunity-intelligence/execution-plans/{plan}/create-brief', [AppOpportunityIntelligenceController::class, 'createBrief'])->name('app.opportunity-intelligence.execution-plans.create-brief');
            Route::get('/programmatic-growth/beta-report', [AppGrowthProgramController::class, 'betaReport'])->name('app.programmatic-growth.beta-report');
            Route::post('/programmatic-growth/internal-beta-mode', [AppGrowthProgramController::class, 'toggleInternalBetaMode'])->name('app.programmatic-growth.internal-beta-mode');
            Route::get('/growth-programs', [AppGrowthProgramController::class, 'index'])->name('app.growth-programs.index');
            Route::post('/growth-programs/from-opportunity/{opportunity}', [AppGrowthProgramController::class, 'storeFromOpportunity'])->name('app.growth-programs.from-opportunity');
            Route::post('/growth-programs/from-execution-plan/{plan}', [AppGrowthProgramController::class, 'storeFromExecutionPlan'])->name('app.growth-programs.from-execution-plan');
            Route::post('/growth-programs/from-brief/{brief}', [AppGrowthProgramController::class, 'storeFromBrief'])->name('app.growth-programs.from-brief');
            Route::post('/growth-programs/from-draft/{draft}', [AppGrowthProgramController::class, 'storeFromDraft'])->name('app.growth-programs.from-draft');
            Route::post('/growth-programs/attach/opportunity/{opportunity}', [AppGrowthProgramController::class, 'attachOpportunity'])->name('app.growth-programs.attach.opportunity');
            Route::post('/growth-programs/attach/execution-plan/{plan}', [AppGrowthProgramController::class, 'attachExecutionPlan'])->name('app.growth-programs.attach.execution-plan');
            Route::post('/growth-programs/attach/brief/{brief}', [AppGrowthProgramController::class, 'attachBrief'])->name('app.growth-programs.attach.brief');
            Route::post('/growth-programs/attach/draft/{draft}', [AppGrowthProgramController::class, 'attachDraft'])->name('app.growth-programs.attach.draft');
            Route::get('/growth-programs/{program}', [AppGrowthProgramController::class, 'show'])->name('app.growth-programs.show');
            Route::post('/growth-programs/{program}/feedback', [AppGrowthProgramController::class, 'storeFeedback'])->name('app.growth-programs.feedback');
            Route::post('/growth-programs/{program}/transition', [AppGrowthProgramController::class, 'transition'])->name('app.growth-programs.transition');
            Route::post('/growth-programs/{program}/detect-programmatic-opportunities', [AppGrowthProgramController::class, 'detectProgrammaticOpportunities'])->name('app.growth-programs.detect-programmatic-opportunities');
            Route::post('/growth-programs/{program}/build-cluster-previews', [AppGrowthProgramController::class, 'buildClusterPreviews'])->name('app.growth-programs.build-cluster-previews');
            Route::post('/growth-programs/{program}/build-brief-blueprints', [AppGrowthProgramController::class, 'buildBriefBlueprints'])->name('app.growth-programs.build-brief-blueprints');
            Route::post('/growth-programs/{program}/convert-approved-blueprints', [AppGrowthProgramController::class, 'convertApprovedBlueprints'])->name('app.growth-programs.convert-approved-blueprints');
            Route::post('/growth-programs/{program}/prepare-draft-requests', [AppGrowthProgramController::class, 'prepareDraftRequests'])->name('app.growth-programs.prepare-draft-requests');
            Route::post('/growth-programs/{program}/generate-approved-draft-requests', [AppGrowthProgramController::class, 'generateApprovedDraftRequests'])->name('app.growth-programs.generate-approved-draft-requests');
            Route::post('/growth-programs/{program}/review-generated-drafts', [AppGrowthProgramController::class, 'reviewGeneratedDrafts'])->name('app.growth-programs.review-generated-drafts');
            Route::post('/growth-programs/{program}/convert-approved-reviews-to-content', [AppGrowthProgramController::class, 'convertApprovedReviewsToContent'])->name('app.growth-programs.convert-approved-reviews-to-content');
            Route::post('/growth-programs/{program}/publication-readiness', [AppGrowthProgramController::class, 'runPublicationReadiness'])->name('app.growth-programs.publication-readiness');
            Route::post('/growth-programs/{program}/publication-plans', [AppProgrammaticPublicationPlanController::class, 'createFromProgram'])->name('app.growth-programs.publication-plans.create');
            Route::post('/growth-programs/{program}/publication-plans/schedule', [AppProgrammaticPublicationPlanController::class, 'scheduleForProgram'])->name('app.growth-programs.publication-plans.schedule');
            Route::get('/programmatic-opportunities', [AppProgrammaticOpportunityController::class, 'index'])->name('app.programmatic-opportunities.index');
            Route::get('/programmatic-opportunities/{programmaticOpportunity}', [AppProgrammaticOpportunityController::class, 'show'])->name('app.programmatic-opportunities.show');
            Route::post('/programmatic-opportunities/{programmaticOpportunity}/validate', [AppProgrammaticOpportunityController::class, 'validateOpportunity'])->name('app.programmatic-opportunities.validate');
            Route::post('/programmatic-opportunities/{programmaticOpportunity}/reject', [AppProgrammaticOpportunityController::class, 'reject'])->name('app.programmatic-opportunities.reject');
            Route::post('/programmatic-opportunities/{programmaticOpportunity}/attach', [AppProgrammaticOpportunityController::class, 'attach'])->name('app.programmatic-opportunities.attach');
            Route::post('/programmatic-opportunities/{programmaticOpportunity}/growth-program', [AppProgrammaticOpportunityController::class, 'createGrowthProgram'])->name('app.programmatic-opportunities.growth-program');
            Route::post('/programmatic-opportunities/detect/opportunity/{opportunity}', [AppProgrammaticOpportunityController::class, 'detectFromOpportunity'])->name('app.programmatic-opportunities.detect.opportunity');
            Route::get('/programmatic-brief-blueprints', [AppProgrammaticBriefBlueprintController::class, 'index'])->name('app.programmatic-brief-blueprints.index');
            Route::post('/programmatic-brief-blueprints/build/item/{item}', [AppProgrammaticBriefBlueprintController::class, 'buildForItem'])->name('app.programmatic-brief-blueprints.build.item');
            Route::get('/programmatic-brief-blueprints/{blueprint}', [AppProgrammaticBriefBlueprintController::class, 'show'])->name('app.programmatic-brief-blueprints.show');
            Route::post('/programmatic-brief-blueprints/{blueprint}/review', [AppProgrammaticBriefBlueprintController::class, 'review'])->name('app.programmatic-brief-blueprints.review');
            Route::post('/programmatic-brief-blueprints/{blueprint}/approve', [AppProgrammaticBriefBlueprintController::class, 'approve'])->name('app.programmatic-brief-blueprints.approve');
            Route::post('/programmatic-brief-blueprints/{blueprint}/reject', [AppProgrammaticBriefBlueprintController::class, 'reject'])->name('app.programmatic-brief-blueprints.reject');
            Route::post('/programmatic-brief-blueprints/{blueprint}/convert', [AppProgrammaticBriefBlueprintController::class, 'convert'])->name('app.programmatic-brief-blueprints.convert');
            Route::get('/programmatic-draft-requests', [AppProgrammaticDraftRequestController::class, 'index'])->name('app.programmatic-draft-requests.index');
            Route::post('/programmatic-draft-requests/prepare/blueprint/{blueprint}', [AppProgrammaticDraftRequestController::class, 'prepareForBlueprint'])->name('app.programmatic-draft-requests.prepare.blueprint');
            Route::get('/programmatic-draft-requests/{draftRequest}', [AppProgrammaticDraftRequestController::class, 'show'])->name('app.programmatic-draft-requests.show');
            Route::post('/programmatic-draft-requests/{draftRequest}/approve', [AppProgrammaticDraftRequestController::class, 'approve'])->name('app.programmatic-draft-requests.approve');
            Route::post('/programmatic-draft-requests/{draftRequest}/reject', [AppProgrammaticDraftRequestController::class, 'reject'])->name('app.programmatic-draft-requests.reject');
            Route::post('/programmatic-draft-requests/{draftRequest}/cancel', [AppProgrammaticDraftRequestController::class, 'cancel'])->name('app.programmatic-draft-requests.cancel');
            Route::post('/programmatic-draft-requests/{draftRequest}/generate', [AppProgrammaticDraftRequestController::class, 'generate'])->name('app.programmatic-draft-requests.generate');
            Route::get('/programmatic-draft-reviews', [AppProgrammaticDraftReviewController::class, 'index'])->name('app.programmatic-draft-reviews.index');
            Route::post('/programmatic-draft-reviews/run/request/{draftRequest}', [AppProgrammaticDraftReviewController::class, 'runForRequest'])->name('app.programmatic-draft-reviews.run.request');
            Route::get('/programmatic-draft-reviews/{review}', [AppProgrammaticDraftReviewController::class, 'show'])->name('app.programmatic-draft-reviews.show');
            Route::post('/programmatic-draft-reviews/{review}/approve', [AppProgrammaticDraftReviewController::class, 'approve'])->name('app.programmatic-draft-reviews.approve');
            Route::post('/programmatic-draft-reviews/{review}/needs-work', [AppProgrammaticDraftReviewController::class, 'needsWork'])->name('app.programmatic-draft-reviews.needs-work');
            Route::post('/programmatic-draft-reviews/{review}/block', [AppProgrammaticDraftReviewController::class, 'block'])->name('app.programmatic-draft-reviews.block');
            Route::post('/programmatic-draft-reviews/{review}/reject', [AppProgrammaticDraftReviewController::class, 'reject'])->name('app.programmatic-draft-reviews.reject');
            Route::post('/programmatic-draft-reviews/{review}/convert-to-content', [AppProgrammaticDraftReviewController::class, 'convertToContent'])->name('app.programmatic-draft-reviews.convert-to-content');
            Route::get('/programmatic-publication-readiness', [AppProgrammaticPublicationReadinessController::class, 'index'])->name('app.programmatic-publication-readiness.index');
            Route::post('/programmatic-publication-readiness/run/content/{content}', [AppProgrammaticPublicationReadinessController::class, 'runForContent'])->name('app.programmatic-publication-readiness.run.content');
            Route::get('/programmatic-publication-readiness/{readiness}', [AppProgrammaticPublicationReadinessController::class, 'show'])->name('app.programmatic-publication-readiness.show');
            Route::post('/programmatic-publication-readiness/{readiness}/approve', [AppProgrammaticPublicationReadinessController::class, 'approve'])->name('app.programmatic-publication-readiness.approve');
            Route::post('/programmatic-publication-readiness/{readiness}/needs-work', [AppProgrammaticPublicationReadinessController::class, 'needsWork'])->name('app.programmatic-publication-readiness.needs-work');
            Route::post('/programmatic-publication-readiness/{readiness}/block', [AppProgrammaticPublicationReadinessController::class, 'block'])->name('app.programmatic-publication-readiness.block');
            Route::post('/programmatic-publication-readiness/{readiness}/reject', [AppProgrammaticPublicationReadinessController::class, 'reject'])->name('app.programmatic-publication-readiness.reject');
            Route::get('/programmatic-publication-plans', [AppProgrammaticPublicationPlanController::class, 'index'])->name('app.programmatic-publication-plans.index');
            Route::post('/programmatic-publication-plans/readiness/{readiness}', [AppProgrammaticPublicationPlanController::class, 'createFromReadiness'])->name('app.programmatic-publication-plans.create.readiness');
            Route::get('/programmatic-publication-plans/{plan}', [AppProgrammaticPublicationPlanController::class, 'show'])->name('app.programmatic-publication-plans.show');
            Route::post('/programmatic-publication-plans/{plan}/approve', [AppProgrammaticPublicationPlanController::class, 'approve'])->name('app.programmatic-publication-plans.approve');
            Route::post('/programmatic-publication-plans/{plan}/schedule', [AppProgrammaticPublicationPlanController::class, 'schedule'])->name('app.programmatic-publication-plans.schedule');
            Route::post('/programmatic-publication-plans/{plan}/cancel', [AppProgrammaticPublicationPlanController::class, 'cancel'])->name('app.programmatic-publication-plans.cancel');
            Route::post('/programmatic-publication-plans/{plan}/recalculate', [AppProgrammaticPublicationPlanController::class, 'recalculate'])->name('app.programmatic-publication-plans.recalculate');
            Route::get('/programmatic-clusters', [AppProgrammaticClusterController::class, 'index'])->name('app.programmatic-clusters.index');
            Route::post('/programmatic-clusters/build/{programmaticOpportunity}', [AppProgrammaticClusterController::class, 'build'])->name('app.programmatic-clusters.build');
            Route::post('/programmatic-clusters/{cluster}/brief-blueprints', [AppProgrammaticBriefBlueprintController::class, 'buildForCluster'])->name('app.programmatic-clusters.brief-blueprints.build');
            Route::post('/programmatic-clusters/{cluster}/convert-approved-blueprints', [AppProgrammaticClusterController::class, 'convertApprovedBlueprints'])->name('app.programmatic-clusters.convert-approved-blueprints');
            Route::post('/programmatic-clusters/{cluster}/prepare-draft-requests', [AppProgrammaticClusterController::class, 'prepareDraftRequests'])->name('app.programmatic-clusters.prepare-draft-requests');
            Route::post('/programmatic-clusters/{cluster}/generate-approved-requests', [AppProgrammaticClusterController::class, 'generateApprovedRequests'])->name('app.programmatic-clusters.generate-approved-requests');
            Route::post('/programmatic-clusters/{cluster}/review-generated-drafts', [AppProgrammaticClusterController::class, 'reviewGeneratedDrafts'])->name('app.programmatic-clusters.review-generated-drafts');
            Route::post('/programmatic-clusters/{cluster}/convert-approved-reviews-to-content', [AppProgrammaticClusterController::class, 'convertApprovedReviewsToContent'])->name('app.programmatic-clusters.convert-approved-reviews-to-content');
            Route::post('/programmatic-clusters/{cluster}/publication-readiness', [AppProgrammaticClusterController::class, 'runPublicationReadiness'])->name('app.programmatic-clusters.publication-readiness');
            Route::post('/programmatic-clusters/{cluster}/publication-plans', [AppProgrammaticPublicationPlanController::class, 'createFromCluster'])->name('app.programmatic-clusters.publication-plans.create');
            Route::get('/programmatic-clusters/{cluster}', [AppProgrammaticClusterController::class, 'show'])->name('app.programmatic-clusters.show');
            Route::post('/programmatic-clusters/{cluster}/validate', [AppProgrammaticClusterController::class, 'validateCluster'])->name('app.programmatic-clusters.validate');
            Route::post('/programmatic-clusters/{cluster}/reject', [AppProgrammaticClusterController::class, 'reject'])->name('app.programmatic-clusters.reject');
            Route::post('/programmatic-clusters/{cluster}/attach', [AppProgrammaticClusterController::class, 'attach'])->name('app.programmatic-clusters.attach');
            Route::get('/agentic-marketing/campaign-planner', [AppCampaignPlannerController::class, 'index'])->name('app.agentic-marketing.campaign-planner.index');
            Route::post('/agentic-marketing/campaign-planner', [AppCampaignPlannerController::class, 'store'])->name('app.agentic-marketing.campaign-planner.store');
            Route::post('/agentic-marketing/campaign-planner/{campaign}/generate', [AppCampaignPlannerController::class, 'generate'])->name('app.agentic-marketing.campaign-planner.generate');
            Route::post('/agentic-marketing/campaign-planner/assets/{campaignContent}/email-export', [AppCampaignPlannerController::class, 'exportEmailAsset'])->name('app.agentic-marketing.campaign-planner.assets.email-export');
            Route::get('/agentic-marketing/learning', [AppLearningOptimizationController::class, 'index'])->name('app.agentic-marketing.learning.index');
            Route::post('/agentic-marketing/learning/run', [AppLearningOptimizationController::class, 'run'])->name('app.agentic-marketing.learning.run');
            Route::get('/agentic-marketing/workflows', [AppAutonomousMarketingWorkflowController::class, 'index'])->name('app.agentic-marketing.workflows.index');
            Route::post('/agentic-marketing/workflows/run', [AppAutonomousMarketingWorkflowController::class, 'run'])->name('app.agentic-marketing.workflows.run');
            Route::post('/agentic-marketing/workflows/rules', [AppAutonomousMarketingWorkflowController::class, 'storeRule'])->name('app.agentic-marketing.workflows.rules.store');
            Route::post('/agentic-marketing/workflows/overrides', [AppAutonomousMarketingWorkflowController::class, 'storeOverride'])->name('app.agentic-marketing.workflows.overrides.store');
            Route::post('/agentic-marketing/workflows/overrides/{override}/clear', [AppAutonomousMarketingWorkflowController::class, 'clearOverride'])->name('app.agentic-marketing.workflows.overrides.clear');
            Route::get('/agentic-marketing/campaign-clusters', [AppCampaignClusterController::class, 'index'])->name('app.agentic-marketing.campaign-clusters.index');
            Route::post('/agentic-marketing/campaign-clusters/run', [AppCampaignClusterController::class, 'run'])->name('app.agentic-marketing.campaign-clusters.run');
            Route::post('/agentic-marketing/campaign-clusters/{cluster}/actions', [AppCampaignClusterController::class, 'materializeActions'])->name('app.agentic-marketing.campaign-clusters.actions.materialize');
            Route::get('/agentic-marketing/campaign-clusters/{cluster}', [AppCampaignClusterController::class, 'show'])->name('app.agentic-marketing.campaign-clusters.show');
            Route::get('/agentic-marketing/distribution', [AppSocialDistributionController::class, 'index'])->name('app.agentic-marketing.distribution.index');
            Route::post('/agentic-marketing/distribution/linkedin/connect', [AppSocialDistributionController::class, 'connectLinkedIn'])->name('app.agentic-marketing.distribution.linkedin.connect');
            Route::put('/agentic-marketing/distribution/accounts/{account}', [AppSocialDistributionController::class, 'updateAccount'])->name('app.agentic-marketing.distribution.accounts.update');
            Route::delete('/agentic-marketing/distribution/accounts/{account}', [AppSocialDistributionController::class, 'destroyAccount'])->name('app.agentic-marketing.distribution.accounts.destroy');
            Route::post('/agentic-marketing/distribution/variants', [AppSocialDistributionController::class, 'requestVariants'])->name('app.agentic-marketing.distribution.variants.request');
            Route::post('/agentic-marketing/distribution/content-drafts', [AppSocialDistributionController::class, 'createDraftFromContent'])->name('app.agentic-marketing.distribution.content-drafts.store');
            Route::put('/agentic-marketing/distribution/variants/{variant}', [AppSocialDistributionController::class, 'updateVariant'])->name('app.agentic-marketing.distribution.variants.update');
            Route::post('/agentic-marketing/distribution/variants/{variant}/approve', [AppSocialDistributionController::class, 'approveVariant'])->name('app.agentic-marketing.distribution.variants.approve');
            Route::post('/agentic-marketing/distribution/variants/{variant}/unapprove', [AppSocialDistributionController::class, 'unapproveVariant'])->name('app.agentic-marketing.distribution.variants.unapprove');
            Route::delete('/agentic-marketing/distribution/variants/{variant}', [AppSocialDistributionController::class, 'destroyVariant'])->name('app.agentic-marketing.distribution.variants.destroy');
            Route::post('/agentic-marketing/distribution/variants/{variant}/schedule', [AppSocialDistributionController::class, 'schedule'])->name('app.agentic-marketing.distribution.variants.schedule');
            Route::post('/agentic-marketing/distribution/publications/{publication}/queue', [AppSocialDistributionController::class, 'queuePublication'])->name('app.agentic-marketing.distribution.publications.queue');
            Route::get('/agentic-marketing/opportunities/{opportunity}/execution', [AppOpportunityExecutionController::class, 'show'])->name('app.agentic-marketing.opportunities.execution.show');
            Route::post('/agentic-marketing/opportunities/{opportunity}/execution/prepare', [AppOpportunityExecutionController::class, 'prepare'])->name('app.agentic-marketing.opportunities.execution.prepare');
            Route::post('/agentic-marketing/execution-assets/{asset}/approve', [AppOpportunityExecutionController::class, 'approveAsset'])->name('app.agentic-marketing.execution-assets.approve');
            Route::post('/agentic-marketing/execution-assets/{asset}/reject', [AppOpportunityExecutionController::class, 'rejectAsset'])->name('app.agentic-marketing.execution-assets.reject');
            Route::post('/agentic-marketing/execution-pipelines/{pipeline}/feedback', [AppOpportunityExecutionController::class, 'feedback'])->name('app.agentic-marketing.execution-pipelines.feedback');
            Route::post('/agentic-marketing/execution-pipelines/{pipeline}/retry', [AppOpportunityExecutionController::class, 'retry'])->name('app.agentic-marketing.execution-pipelines.retry');
        });
        Route::get('/sites', [AppSitesController::class, 'index'])->name('app.sites');
        Route::get('/sites/wordpress-plugin/download', [AppSitesController::class, 'downloadWordPressPlugin'])->name('app.sites.wordpress-plugin.download');
        Route::post('/sites', [AppSitesController::class, 'store'])->name('app.sites.store');
        Route::get('/sites/{site}', [AppSitesController::class, 'show'])->name('app.sites.show');
        Route::get('/sites/{site}/insights', [AppInsightsController::class, 'show'])->name('app.sites.insights.index');
        Route::post('/sites/{site}/test-wordpress-connection', [AppSitesController::class, 'testWordPressConnection'])
            ->middleware('protect.heavy:heavy')
            ->name('app.sites.test-wordpress');
        Route::post('/sites/{site}/test-laravel-connector', [AppSitesController::class, 'testLaravelConnector'])
            ->middleware('protect.heavy:heavy')
            ->name('app.sites.test-laravel');
        Route::post('/sites/{site}/update', [AppSitesController::class, 'update'])->name('app.sites.update');
        Route::post('/sites/{site}/automation', [AppSitesController::class, 'updateAutomationSettings'])->name('app.sites.automation.update');
        Route::post('/sites/{site}/regenerate-key', [AppSitesController::class, 'regenerateKey'])->name('app.sites.regenerate-key');
        Route::post('/sites/{site}/plugin-license-key', [AppSitesController::class, 'generatePluginLicenseKey'])->name('app.sites.plugin-license-key.generate');
        Route::post('/sites/{site}/toggle', [AppSitesController::class, 'toggle'])->name('app.sites.toggle');
        Route::delete('/sites/{site}', [AppSitesController::class, 'destroy'])->name('app.sites.destroy');
        Route::get('/sites/{site}/insights/llm', [AppLlmTrackingController::class, 'index'])->name('app.sites.llm-tracking.index');
        Route::post('/sites/{site}/insights/llm', [AppLlmTrackingController::class, 'store'])->middleware('protect.heavy:ai')->name('app.sites.llm-tracking.store');
        Route::get('/sites/{site}/insights/llm/starter-queries', [AppLlmTrackingController::class, 'starterPreview'])->name('app.sites.llm-tracking.starter.preview');
        Route::post('/sites/{site}/insights/llm/starter-queries', [AppLlmTrackingController::class, 'createStarterQueries'])->name('app.sites.llm-tracking.starter.store');
        Route::post('/sites/{site}/insights/llm/query-sets', [AppLlmTrackingQuerySetController::class, 'store'])->name('app.sites.llm-tracking.query-sets.store');
        Route::post('/sites/{site}/insights/llm/query-sets/{querySet}', [AppLlmTrackingQuerySetController::class, 'update'])->name('app.sites.llm-tracking.query-sets.update');
        Route::post('/sites/{site}/insights/llm/query-sets/{querySet}/toggle', [AppLlmTrackingQuerySetController::class, 'toggle'])->name('app.sites.llm-tracking.query-sets.toggle');
        Route::get('/sites/{site}/insights/llm/{query}', [AppLlmTrackingController::class, 'show'])->name('app.sites.llm-tracking.show');
        Route::post('/sites/{site}/insights/llm/{query}', [AppLlmTrackingController::class, 'update'])->name('app.sites.llm-tracking.update');
        Route::post('/sites/{site}/insights/llm/{query}/toggle', [AppLlmTrackingController::class, 'toggle'])->name('app.sites.llm-tracking.toggle');
        Route::post('/sites/{site}/insights/llm/{query}/run-now', [AppLlmTrackingController::class, 'runNow'])->middleware('protect.heavy:ai')->name('app.sites.llm-tracking.run-now');
        Route::post('/sites/{site}/insights/llm/{query}/rescore', [AppLlmTrackingController::class, 'rescore'])->name('app.sites.llm-tracking.rescore');
        Route::get('/sites/{site}/insights/llm/{query}/aggregates', [AppLlmTrackingController::class, 'aggregates'])->name('app.sites.llm-tracking.aggregates');
        Route::get('/sites/{site}/insights/llm/{query}/runs/{run}', [AppLlmTrackingController::class, 'runDetails'])->name('app.sites.llm-tracking.runs.show');
        Route::get('/sites/{site}/insights/competitors', [AppSiteCompetitorsController::class, 'index'])->name('app.sites.competitors.index');
        Route::post('/sites/{site}/insights/competitors', [AppSiteCompetitorsController::class, 'store'])->name('app.sites.competitors.store');
        Route::post('/sites/{site}/insights/competitors/context', [AppSiteCompetitorsController::class, 'updateContextSetting'])->name('app.sites.competitors.context.update');
        Route::post('/sites/{site}/insights/competitors/candidates/{candidate}/accept', [AppSiteCompetitorsController::class, 'acceptCandidate'])->name('app.sites.competitors.candidates.accept');
        Route::post('/sites/{site}/insights/competitors/candidates/{candidate}/ignore', [AppSiteCompetitorsController::class, 'ignoreCandidate'])->name('app.sites.competitors.candidates.ignore');
        Route::post('/sites/{site}/insights/competitors/{competitor}/toggle', [AppSiteCompetitorsController::class, 'toggle'])->name('app.sites.competitors.toggle');
        Route::get('/sites/{site}/insights/competitor-intelligence', [AppCompetitorIntelligenceController::class, 'index'])->name('app.sites.competitor-intelligence.index');
        Route::post('/sites/{site}/insights/competitor-intelligence/import', [AppCompetitorIntelligenceController::class, 'importContent'])->name('app.sites.competitor-intelligence.import');
        Route::post('/sites/{site}/insights/competitor-intelligence/analyze', [AppCompetitorIntelligenceController::class, 'analyze'])->name('app.sites.competitor-intelligence.analyze');
        Route::get('/sites/{site}/insights/competitor-intelligence/opportunities.json', [AppCompetitorIntelligenceController::class, 'opportunities'])->name('app.sites.competitor-intelligence.opportunities');
        Route::get('/sites/{site}/insights/audits', [AppSiteSeoAuditController::class, 'index'])->name('app.sites.seo-audits.index');
        Route::post('/sites/{site}/insights/audits/run', [AppSiteSeoAuditController::class, 'run'])->middleware('protect.heavy:audit')->name('app.sites.seo-audits.run');
        Route::get('/sites/{site}/insights/audits/{audit}', [AppSiteSeoAuditController::class, 'show'])->name('app.sites.seo-audits.show');
        Route::post('/sites/{site}/insights/audits/{audit}/ai-fix/generate', [AppSiteSeoAuditController::class, 'generateFixSuggestions'])->middleware('protect.heavy:ai')->name('app.sites.seo-audits.ai-fix.generate');
        Route::post('/sites/{site}/insights/audits/{audit}/ai-fix/{suggestion}/apply', [AppSiteSeoAuditController::class, 'applyFixSuggestion'])->name('app.sites.seo-audits.ai-fix.apply');
        Route::get('/sites/{site}/insights/audits/{audit}/ai-fix/{suggestion}/edit', [AppSiteSeoAuditController::class, 'editFixSuggestion'])->name('app.sites.seo-audits.ai-fix.edit');
        Route::post('/sites/{site}/insights/audits/{audit}/ai-fix/{suggestion}/sync', [AppSiteSeoAuditController::class, 'syncFixSuggestion'])->name('app.sites.seo-audits.ai-fix.sync');
        Route::get('/sites/{site}/insights/analytics', [AppAnalyticsSiteController::class, 'show'])->name('app.sites.analytics.show');
        Route::post('/sites/{site}/insights/analytics/enable', [AppAnalyticsSiteController::class, 'enable'])->name('app.sites.analytics.enable');
        Route::post('/sites/{site}/insights/analytics/disable', [AppAnalyticsSiteController::class, 'disable'])->name('app.sites.analytics.disable');
        Route::post('/sites/{site}/insights/analytics/verify', [AppAnalyticsSiteController::class, 'verify'])->name('app.sites.analytics.verify');
        Route::post('/sites/{site}/insights/analytics/regenerate-token', [AppAnalyticsSiteController::class, 'regenerateToken'])->name('app.sites.analytics.regenerate-token');
        Route::get('/sites/{site}/insights/learnings', [AppLearningsController::class, 'index'])->name('app.sites.learnings.index');
        Route::get('/sites/{site}/llm-tracking', function (Request $request, $site) {
            return redirect()->route('app.sites.llm-tracking.index', array_merge(['site' => $site], $request->query()));
        });
        Route::get('/sites/{site}/llm-tracking/{query}', function (Request $request, $site, $query) {
            return redirect()->route('app.sites.llm-tracking.show', array_merge(['site' => $site, 'query' => $query], $request->query()));
        });
        Route::get('/sites/{site}/competitors', function (Request $request, $site) {
            return redirect()->route('app.sites.competitors.index', array_merge(['site' => $site], $request->query()));
        });
        Route::get('/sites/{site}/seo-audits', function (Request $request, $site) {
            return redirect()->route('app.sites.seo-audits.index', array_merge(['site' => $site], $request->query()));
        });
        Route::get('/sites/{site}/seo-audits/{audit}', function (Request $request, $site, $audit) {
            return redirect()->route('app.sites.seo-audits.show', array_merge(['site' => $site, 'audit' => $audit], $request->query()));
        });
        Route::get('/sites/{site}/analytics', function (Request $request, $site) {
            return redirect()->route('app.sites.analytics.show', array_merge(['site' => $site], $request->query()));
        });
        Route::get('/sites/{site}/learnings', function (Request $request, $site) {
            return redirect()->route('app.sites.learnings.index', array_merge(['site' => $site], $request->query()));
        });
        Route::middleware(['ensure.feature.enabled:research_layer'])->group(function () {
            Route::get('/research', [AppResearchController::class, 'index'])->name('app.research.index');
            Route::get('/research/create', [AppResearchController::class, 'create'])->name('app.research.create');
            Route::post('/research', [AppResearchController::class, 'store'])->name('app.research.store');
            Route::get('/research/{project}', [AppResearchController::class, 'show'])->name('app.research.show');
            Route::post('/research/{project}/start', [AppResearchController::class, 'start'])->middleware('protect.heavy:report')->name('app.research.start');
            Route::post('/research/{project}/findings/select', [AppResearchController::class, 'updateSelectedFindings'])->name('app.research.findings.select');
        });
        // Sidebar cleanup checklist:
        // - Briefs/Drafts removed from primary client navigation.
        // - Keep legacy list URLs working by redirecting to Content inbox filters.
        Route::get('/briefs', function (Request $request) {
            return redirect()->route('app.content.index', array_merge(
                $request->query(),
                ['inbox' => 'needs_brief']
            ));
        })->name('app.briefs');
        Route::get('/content/create', [AppBriefsController::class, 'create'])->name('app.content.create');
        Route::post('/content/create', [AppBriefsController::class, 'store'])->name('app.content.create.store');
        Route::post('/content/create/from-url/preview', [AppBriefsController::class, 'previewUrlSource'])
            ->middleware('protect.heavy:heavy')
            ->name('app.content.create.from-url.preview');
        Route::post('/content/create/from-url/generate', [AppBriefsController::class, 'generateFromUrlSource'])
            ->middleware('protect.heavy:ai')
            ->name('app.content.create.from-url.generate');
        Route::post('/content/create/from-url/save', [AppBriefsController::class, 'saveFromUrlSource'])
            ->name('app.content.create.from-url.save');
        Route::post('/content/create/from-url/{source}/discard', [AppBriefsController::class, 'discardUrlSource'])
            ->name('app.content.create.from-url.discard');
        Route::get('/content/create/from-url/jobs/{source}', [AppBriefsController::class, 'sourceGenerationStatus'])
            ->name('app.content.create.from-url.jobs.status');
        Route::get('/content/create/from-url/{source}/status', [AppBriefsController::class, 'sourceGenerationStatus'])
            ->name('app.content.create.from-url.status');
        Route::post('/content/create/from-url/{source}/retry', [AppBriefsController::class, 'retrySourceGeneration'])
            ->name('app.content.create.from-url.retry');
        Route::middleware(['ensure.feature.enabled:brief_intelligence'])->group(function () {
            Route::post('/content/create-from-research', [AppBriefsController::class, 'storeFromResearch'])
                ->middleware('protect.heavy:ai')
                ->name('app.content.create.from-research');
        });
        Route::get('/content/workspace/{brief}', [AppBriefsController::class, 'show'])->name('app.content.workspace.show');
        Route::get('/content/workspace/{brief}/overview', [AppBriefsController::class, 'show'])
            ->defaults('workspace_section', 'overview')
            ->name('app.content.workspace.overview');
        Route::get('/content/workspace/{brief}/brief', [AppBriefsController::class, 'show'])
            ->defaults('workspace_section', 'brief')
            ->name('app.content.workspace.brief');
        Route::get('/content/workspace/{brief}/drafts', [AppBriefsController::class, 'show'])
            ->defaults('workspace_section', 'drafts')
            ->name('app.content.workspace.drafts');
        Route::get('/content/workspace/{brief}/compare', [AppDraftComparisonsController::class, 'setup'])->name('app.content.workspace.compare.setup');
        Route::post('/content/workspace/{brief}/compare/estimate', [AppDraftComparisonsController::class, 'estimate'])->name('app.content.workspace.compare.estimate');
        Route::post('/content/workspace/{brief}/compare', [AppDraftComparisonsController::class, 'store'])->name('app.content.workspace.compare.store');
        Route::post('/content/workspace/{brief}/compare/{comparison}/start', [AppDraftComparisonsController::class, 'start'])->name('app.content.workspace.compare.start');
        Route::get('/content/workspace/{brief}/compare/{comparison}/status', [AppDraftComparisonsController::class, 'status'])->name('app.content.workspace.compare.status');
        Route::get('/content/workspace/{brief}/compare/{comparison}/variants/{variant}/draft', [AppDraftComparisonsController::class, 'openVariantDraft'])->name('app.content.workspace.compare.open-variant-draft');
        Route::get('/content/workspace/{brief}/compare/{comparison}', [AppDraftComparisonsController::class, 'show'])->name('app.content.workspace.compare.show');
        Route::post('/content/workspace/{brief}/compare/{comparison}/winner', [AppDraftComparisonsController::class, 'selectWinner'])->name('app.content.workspace.compare.select-winner');
        Route::get('/content/workspace/{brief}/compare/{comparison}/hybrid/estimate', [AppDraftComparisonsController::class, 'estimateHybrid'])->name('app.content.workspace.compare.hybrid.estimate');
        Route::post('/content/workspace/{brief}/compare/{comparison}/hybrid', [AppDraftComparisonsController::class, 'queueHybrid'])->name('app.content.workspace.compare.hybrid');
        Route::get('/content/workspace/{brief}/brief/edit', [AppBriefsController::class, 'edit'])->name('app.content.workspace.brief.edit');
        Route::put('/content/workspace/{brief}/brief', [AppBriefsController::class, 'update'])->name('app.content.workspace.brief.update');
        Route::middleware(['ensure.feature.enabled:brief_intelligence'])->group(function () {
            Route::post('/content/workspace/{brief}/brief/enhance', [AppBriefsController::class, 'enhance'])
                ->name('app.content.workspace.brief.enhance');
            Route::post('/content/workspace/{brief}/brief/suggestions/{suggestion}/apply', [AppBriefsController::class, 'applySuggestion'])
                ->name('app.content.workspace.brief.suggestions.apply');
            Route::post('/content/workspace/{brief}/brief/suggestions/{suggestion}/reject', [AppBriefsController::class, 'rejectSuggestion'])
                ->name('app.content.workspace.brief.suggestions.reject');
        });
        Route::post('/content/workspace/{brief}/archive', [AppBriefsController::class, 'archive'])->name('app.content.workspace.archive');
        Route::post('/content/workspace/{brief}/drafts/generate', [AppBriefsController::class, 'generateDraft'])->middleware('protect.heavy:ai')->name('app.content.workspace.drafts.generate');

        // Legacy brief endpoints kept for compatibility with existing integrations/tests.
        Route::get('/briefs/create', [AppBriefsController::class, 'create'])->name('app.briefs.create');
        Route::post('/briefs', [AppBriefsController::class, 'store'])->name('app.briefs.store');
        Route::middleware(['ensure.feature.enabled:brief_intelligence'])->group(function () {
            Route::post('/briefs/from-research', [AppBriefsController::class, 'storeFromResearch'])->middleware('protect.heavy:ai')->name('app.briefs.from-research');
        });
        Route::get('/briefs/{brief}', [AppBriefsController::class, 'show'])->name('app.briefs.show');
        Route::get('/briefs/{brief}/edit', [AppBriefsController::class, 'edit'])->name('app.briefs.edit');
        Route::put('/briefs/{brief}', [AppBriefsController::class, 'update'])->name('app.briefs.update');
        Route::middleware(['ensure.feature.enabled:brief_intelligence'])->group(function () {
            Route::post('/briefs/{brief}/enhance', [AppBriefsController::class, 'enhance'])->name('app.briefs.enhance');
            Route::post('/briefs/{brief}/suggestions/{suggestion}/apply', [AppBriefsController::class, 'applySuggestion'])->name('app.briefs.suggestions.apply');
            Route::post('/briefs/{brief}/suggestions/{suggestion}/reject', [AppBriefsController::class, 'rejectSuggestion'])->name('app.briefs.suggestions.reject');
        });
        Route::post('/briefs/{brief}/archive', [AppBriefsController::class, 'archive'])->name('app.briefs.archive');
        Route::post('/briefs/{brief}/create-draft', [AppBriefsController::class, 'createDraft'])->name('app.briefs.create-draft');
        Route::post('/briefs/{brief}/generate-draft', [AppBriefsController::class, 'generateDraft'])->middleware('protect.heavy:ai')->name('app.briefs.generate-draft');
        Route::get('/briefs/{brief}/draft-compare/setup', function ($brief) {
            return redirect()->route('app.content.workspace.compare.setup', ['brief' => $brief]);
        })->name('app.briefs.compare.setup');
        Route::post('/briefs/{brief}/draft-compare/estimate', [AppDraftComparisonsController::class, 'estimate'])->name('app.briefs.compare.estimate');
        Route::post('/briefs/{brief}/draft-compare', [AppDraftComparisonsController::class, 'store'])->name('app.briefs.compare.store');
        Route::post('/briefs/{brief}/draft-compare/{comparison}/start', [AppDraftComparisonsController::class, 'start'])->name('app.briefs.compare.start');
        Route::get('/briefs/{brief}/draft-compare/{comparison}/status', [AppDraftComparisonsController::class, 'status'])->name('app.briefs.compare.status');
        Route::get('/briefs/{brief}/draft-compare/{comparison}/variants/{variant}/draft', [AppDraftComparisonsController::class, 'openVariantDraft'])->name('app.briefs.compare.open-variant-draft');
        Route::get('/briefs/{brief}/draft-compare/{comparison}', [AppDraftComparisonsController::class, 'show'])->name('app.briefs.compare.show');
        Route::post('/briefs/{brief}/draft-compare/{comparison}/winner', [AppDraftComparisonsController::class, 'selectWinner'])->name('app.briefs.compare.select-winner');
        Route::get('/briefs/{brief}/draft-compare/{comparison}/hybrid/estimate', [AppDraftComparisonsController::class, 'estimateHybrid'])->name('app.briefs.compare.hybrid.estimate');
        Route::post('/briefs/{brief}/draft-compare/{comparison}/hybrid', [AppDraftComparisonsController::class, 'queueHybrid'])->name('app.briefs.compare.hybrid');
        Route::get('/content/pipeline', [AppContentPipelineController::class, 'index'])->name('app.content.pipeline.index');
        Route::get('/content', [AppContentController::class, 'index'])->name('app.content.index');
        Route::post('/content', [AppContentController::class, 'store'])->name('app.content.store');

        // Content Lifecycle Dashboard
        Route::get('/content/lifecycle', [ContentLifecycleDashboardController::class, 'index'])->name('app.content.lifecycle.index');
        Route::post('/content/lifecycle/analyze', [ContentLifecycleDashboardController::class, 'analyze'])->name('app.content.lifecycle.analyze');
        Route::get('/workspaces/{workspace}/content-quality', [AppContentQualityController::class, 'index'])->name('app.workspaces.content-quality.index');
        Route::post('/workspaces/{workspace}/content-quality/run', [AppContentQualityController::class, 'run'])->middleware('protect.heavy:report')->name('app.workspaces.content-quality.run');

        Route::get('/content/series', [AppContentSeriesController::class, 'index'])->name('app.content.series.index');
        Route::get('/content/series/create', [AppContentSeriesController::class, 'create'])->name('app.content.series.create');
        Route::post('/content/series', [AppContentSeriesController::class, 'store'])->name('app.content.series.store');
        Route::get('/content/series/{series}', [AppContentSeriesController::class, 'show'])->name('app.content.series.show');
        Route::get('/content/series/{series}/structure', [AppContentSeriesController::class, 'structure'])->name('app.content.series.structure');
        Route::post('/content/series/{series}/generate-strategy', [AppContentSeriesController::class, 'generateStrategy'])->middleware('protect.heavy:ai')->name('app.content.series.generate-strategy');
        Route::post('/content/series/{series}/generate-articles', [AppContentSeriesController::class, 'generateArticles'])->middleware('protect.heavy:ai')->name('app.content.series.generate-articles');
        Route::post('/content/series/{series}/publish', [AppContentSeriesController::class, 'publish'])->name('app.content.series.publish');
        Route::post('/content/series/{series}/translate', [AppContentSeriesController::class, 'translate'])->name('app.content.series.translate');
        Route::post('/content/series/{series}/pillar', [AppContentSeriesController::class, 'setPillar'])->name('app.content.series.pillar.set');
        Route::post('/content/series/{series}/pillar/clear', [AppContentSeriesController::class, 'clearPillar'])->name('app.content.series.pillar.clear');
        Route::post('/content/series/{series}/duplicate', [AppContentSeriesController::class, 'duplicate'])->name('app.content.series.duplicate');
        Route::post('/content/series/{series}/archive', [AppContentSeriesController::class, 'archive'])->name('app.content.series.archive');
        Route::delete('/content/series/{series}', [AppContentSeriesController::class, 'destroy'])->name('app.content.series.destroy');
        Route::get('/content/batches/create', [AppContentBatchesController::class, 'create'])->name('app.content.batches.create');
        Route::post('/content/batches/suggest', [AppContentBatchesController::class, 'suggest'])->name('app.content.batches.suggest');
        Route::post('/content/batches', [AppContentBatchesController::class, 'store'])->name('app.content.batches.store');
        Route::post('/content/batches/{batch}/start', [AppContentBatchesController::class, 'start'])->name('app.content.batches.start');
        Route::get('/content/batches/{batch}', [AppContentBatchesController::class, 'show'])->name('app.content.batches.show');
        Route::post('/content/batches/{batch}/items/{item}/retry', [AppContentBatchesController::class, 'retryItem'])->middleware('protect.heavy:report')->name('app.content.batches.items.retry');
        Route::post('/content/batches/{batch}/cancel', [AppContentBatchesController::class, 'cancel'])->name('app.content.batches.cancel');
        Route::get('/content/calendar', [AppContentController::class, 'calendar'])->name('app.content.calendar');
        Route::post('/content/calendar/quick-plan', [AppContentController::class, 'quickPlan'])->name('app.content.calendar.quick-plan');
        Route::post('/content/schedule-bulk', [AppContentController::class, 'bulkSchedule'])->name('app.content.schedule-bulk');
        Route::post('/content/repair-kb-bulk', [AppContentController::class, 'bulkRepairKnowledgeBaseAndSync'])->name('app.content.repair-kb-bulk');
        Route::post('/content/sync-bulk', [AppContentController::class, 'bulkSyncLaravel'])->name('app.content.sync-bulk');
        Route::get('/content/automations', [AppContentAutomationsController::class, 'index'])->name('app.content.automations.index');
        Route::get('/content/automations/create', [AppContentAutomationsController::class, 'create'])->name('app.content.automations.create');
        Route::post('/content/automations', [AppContentAutomationsController::class, 'store'])->name('app.content.automations.store');
        Route::get('/content/automations/{automation}', [AppContentAutomationsController::class, 'show'])->name('app.content.automations.show');
        Route::get('/content/automations/{automation}/edit', [AppContentAutomationsController::class, 'edit'])->name('app.content.automations.edit');
        Route::put('/content/automations/{automation}', [AppContentAutomationsController::class, 'update'])->name('app.content.automations.update');
        Route::post('/content/automations/{automation}/run', [AppContentAutomationsController::class, 'runNow'])->middleware('protect.heavy:ai')->name('app.content.automations.run');
        Route::post('/content/automations/{automation}/pause', [AppContentAutomationsController::class, 'pause'])->name('app.content.automations.pause');
        Route::post('/content/automations/{automation}/resume', [AppContentAutomationsController::class, 'resume'])->name('app.content.automations.resume');
        Route::post('/content/automations/{automation}/duplicate', [AppContentAutomationsController::class, 'duplicate'])->name('app.content.automations.duplicate');
        Route::delete('/content/automations/{automation}', [AppContentAutomationsController::class, 'destroy'])->name('app.content.automations.destroy');
        Route::get('/content/{content}.md', [AppContentController::class, 'markdownDocument'])->name('app.content.markdown');
        Route::get('/content/{content}/answers', [AppContentController::class, 'answersDocument'])->name('app.content.answers');
        Route::get('/content/{content}/trust-center', [AppAiTrustCenterController::class, 'show'])->name('app.content.ai-trust.show');
        Route::get('/content/{content}/trust-center/audit-report', [AppAiTrustCenterController::class, 'downloadAuditReport'])->name('app.content.ai-trust.audit-report');
        Route::post('/content/{content}/trust-center/disclosure', [AppAiTrustCenterController::class, 'updateDisclosure'])->name('app.content.ai-trust.disclosure');
        Route::post('/content/{content}/trust-center/review', [AppAiTrustCenterController::class, 'review'])->name('app.content.ai-trust.review');
        Route::post('/content/{content}/trust-center/fact-check', [AppAiTrustCenterController::class, 'factCheck'])->name('app.content.ai-trust.fact-check');
        Route::post('/content/{content}/trust-center/source-trace', [AppAiTrustCenterController::class, 'sourceTrace'])->name('app.content.ai-trust.source-trace');
        Route::get('/content/{content}', [AppContentController::class, 'show'])->name('app.content.show');

        // Content Lifecycle Actions
        Route::post('/content/{content}/lifecycle/transition', [ContentLifecycleDashboardController::class, 'transition'])->name('app.content.lifecycle.transition');
        Route::post('/content/{content}/lifecycle/send-to-review', [ContentLifecycleDashboardController::class, 'sendToReview'])->name('app.content.lifecycle.send-to-review');
        Route::post('/content/{content}/lifecycle/approve', [ContentLifecycleDashboardController::class, 'approve'])->name('app.content.lifecycle.approve');
        Route::post('/content/{content}/lifecycle/reject', [ContentLifecycleDashboardController::class, 'reject'])->name('app.content.lifecycle.reject');
        Route::post('/content/{content}/lifecycle/assign', [ContentLifecycleDashboardController::class, 'assign'])->name('app.content.lifecycle.assign');
        Route::post('/content/{content}/lifecycle/set-reviewer', [ContentLifecycleDashboardController::class, 'setReviewer'])->name('app.content.lifecycle.set-reviewer');
        Route::post('/content/{content}/lifecycle/mark-refresh-needed', [ContentLifecycleDashboardController::class, 'markRefreshNeeded'])->name('app.content.lifecycle.mark-refresh-needed');
        Route::get('/content/{content}/lifecycle/history', [ContentLifecycleDashboardController::class, 'lifecycleHistory'])->name('app.content.lifecycle.history');

        Route::get('/content/{content}/markdown-preview', [AppContentController::class, 'markdownPreview'])->name('app.content.markdown-preview');
        Route::post('/content/{content}/aeo/recalculate', [AppContentController::class, 'recalculateAeo'])->name('app.content.aeo.recalculate');
        Route::post('/content/{content}/improvements', [AppContentController::class, 'queueImprovement'])->name('app.content.improvements.queue');
        Route::get('/content/{content}/improvements/status', [AppContentController::class, 'improvementStatus'])->name('app.content.improvements.status');
        Route::post('/content/{content}/improvements/{run}/accept', [AppContentController::class, 'acceptImprovement'])->name('app.content.improvements.accept');
        Route::post('/content/{content}/improvements/{run}/reject', [AppContentController::class, 'rejectImprovement'])->name('app.content.improvements.reject');
        Route::post('/content/{content}/answer-blocks/generate', [AppContentController::class, 'generateAnswerBlocks'])->middleware('protect.heavy:ai')->name('app.content.answer-blocks.generate');
        Route::post('/content/{content}/answer-blocks/settings', [ContentAnswerBlockController::class, 'updateSettings'])->name('app.content.answer-blocks.settings');
        Route::post('/content/{content}/answer-blocks', [AppContentController::class, 'storeAnswerBlock'])->name('app.content.answer-blocks.store');
        Route::put('/content/{content}/answer-blocks/{block}', [AppContentController::class, 'updateAnswerBlock'])->name('app.content.answer-blocks.update');
        Route::post('/content/{content}/answer-blocks/{block}/move', [AppContentController::class, 'moveAnswerBlock'])->name('app.content.answer-blocks.move');
        Route::delete('/content/{content}/answer-blocks/{block}', [AppContentController::class, 'destroyAnswerBlock'])->name('app.content.answer-blocks.destroy');
        Route::post('/content/{content}/chain-guidance', [AppContentChainController::class, 'updateGuidance'])->name('app.content.chain-guidance.update');
        Route::post('/content/{content}/chain-suggestions/refresh', [AppContentChainController::class, 'refresh'])->middleware('protect.heavy:report')->name('app.content.chain-suggestions.refresh');
        Route::post('/content/{content}/chain-suggestions/apply-approved-links', [AppContentChainController::class, 'applyApprovedLinks'])->name('app.content.chain-suggestions.apply-approved-links');
        Route::post('/content/{content}/chain-suggestions/{suggestion}/approve', [AppContentChainController::class, 'approve'])->name('app.content.chain-suggestions.approve');
        Route::post('/content/{content}/chain-suggestions/{suggestion}/reject', [AppContentChainController::class, 'reject'])->name('app.content.chain-suggestions.reject');
        Route::post('/content/{content}/chain-suggestions/{suggestion}/create', [AppContentChainController::class, 'createFromSuggestion'])->name('app.content.chain-suggestions.create');
        Route::post('/content/{content}/schedule', [AppContentController::class, 'schedule'])->name('app.content.schedule');
        Route::post('/content/{content}/publish-now', [AppContentController::class, 'publishNow'])->name('app.content.publish-now');
        Route::post('/content/{content}/publishing-destination', [AppContentController::class, 'updatePublishingDestination'])->name('app.content.publishing-destination.update');
        Route::post('/content/{content}/publishing-sync', [AppContentController::class, 'updatePublishingSyncSettings'])->name('app.content.publishing-sync.update');
        Route::post('/content/{content}/push-to-site', [AppContentController::class, 'pushToSite'])->name('app.content.push-to-site');
        Route::post('/content/{content}/translate', [AppContentController::class, 'translate'])->name('app.content.translate');
        Route::post('/content/{content}/fix-locale', [AppContentController::class, 'fixLocale'])->name('app.content.fix-locale');
        Route::post('/content/{content}/fix-locale-and-set-source', [AppContentController::class, 'fixLocaleAndSetAsSource'])->name('app.content.fix-locale-and-set-source');
        Route::post('/content/{content}/convert-to-nl-and-regenerate-en', [AppContentController::class, 'convertToNlAndRegenerateEn'])->name('app.content.convert-to-nl-and-regenerate-en');
        Route::post('/content/{content}/localization', [AppContentController::class, 'runLocalization'])->name('app.content.localization.run');
        Route::post('/content/{content}/refresh-recommendations', [AppContentController::class, 'runRefreshRecommendations'])->name('app.content.refresh-recommendations.run');
        Route::post('/content/{content}/refresh-recommendations/create-draft', [AppContentController::class, 'createRefreshDraft'])->name('app.content.refresh-recommendations.create-draft');
        Route::post('/content/{content}/internal-linking', [AppContentController::class, 'runInternalLinking'])->name('app.content.internal-linking.run');
        Route::post('/content/{content}/internal-linking/apply', [AppContentController::class, 'applyInternalLinkSuggestion'])->name('app.content.internal-linking.apply');
        Route::post('/content/{content}/generation-preferences', [AppContentController::class, 'updateGenerationPreferences'])->name('app.content.generation-preferences.update');
        Route::post('/content/{content}/regenerate', [AppContentController::class, 'regenerateDraft'])->middleware('protect.heavy:ai')->name('app.content.regenerate');
        Route::post('/content/{content}/revisions', [AppContentController::class, 'storeRevision'])->name('app.content.revisions.store');
        Route::post('/content/{content}/versions/{version}/restore', [AppContentController::class, 'restoreVersion'])->name('app.content.versions.restore');
        Route::post('/content/{contentId}/delete', [AppContentController::class, 'destroy'])->name('app.content.delete');
        Route::post('/content/{contentId}/restore', [AppContentController::class, 'restore'])->name('app.content.restore');
        Route::post('/content/{content}/republish', [AppContentController::class, 'republish'])->name('app.content.republish');
        Route::post('/content/{content}/verify-remote', [AppContentController::class, 'verifyRemote'])->name('app.content.verify-remote');
        Route::post('/content/{content}/unpublish-remote', [AppContentController::class, 'unpublishRemote'])->name('app.content.unpublish-remote');
        Route::post('/content/{content}/images/featured/generate', [AppContentController::class, 'generateFeaturedImage'])->middleware('protect.heavy:ai')->name('app.content.images.featured.generate');
        Route::post('/content/{content}/images/upload', [AppContentImageAssetController::class, 'storeForContent'])->name('app.content.images.upload');
        Route::post('/content/{content}/images/{imageVersion}/reuse', [AppContentImageAssetController::class, 'reuseFromLinkedContent'])->name('app.content.images.reuse');
        Route::post('/content/{content}/images/{imageVersion}/usage', [AppContentImageAssetController::class, 'updateUsageForContent'])->name('app.content.images.usage.update');
        Route::post('/content/{content}/images/inline/{assetKey}/generate', [AppContentController::class, 'generateInlineVisualImage'])->middleware('protect.heavy:ai')->name('app.content.images.inline.generate');
        Route::post('/content/{content}/images/featured/unsplash', [AppContentController::class, 'useUnsplashFeaturedImage'])->name('app.content.images.featured.unsplash');
        Route::post('/content/{content}/images/featured/push', [AppContentController::class, 'pushFeaturedImageToWordPress'])->name('app.content.images.featured.push');
        Route::post('/content/{content}/images/{imageVersion}/restore', [AppContentController::class, 'restoreImageVersion'])->name('app.content.images.versions.restore');
        Route::delete('/content/{content}/images/{imageVersion}', [AppContentController::class, 'deleteImageVersion'])->name('app.content.images.versions.delete');
        Route::post('/content/{content}/images/preferences', [AppContentController::class, 'updateImageGenerationPreferences'])->name('app.content.images.preferences.update');
        Route::post('/content/{content}/images/og/generate', [AppContentController::class, 'generateOgImage'])->middleware('protect.heavy:ai')->name('app.content.images.og.generate');
        Route::post('/content/{content}/images/og/push', [AppContentController::class, 'pushOgImageToWordPress'])->name('app.content.images.og.push');
        Route::post('/campaigns/{campaign}/images/upload', [AppContentImageAssetController::class, 'storeForCampaign'])->name('app.campaigns.images.upload');
        Route::post('/campaigns/{campaign}/images/{imageVersion}/usage', [AppContentImageAssetController::class, 'updateUsageForCampaign'])->name('app.campaigns.images.usage.update');
        Route::post('/social-publications/{socialPublication}/images/upload', [AppContentImageAssetController::class, 'storeForSocialPublication'])->name('app.social-publications.images.upload');
        Route::get('/drafts', function (Request $request) {
            return redirect()->route('app.content.index', array_merge(
                $request->query(),
                ['inbox' => 'needs_draft']
            ));
        })->name('app.drafts');
        Route::get('/drafts/{draft}', [AppDraftsController::class, 'show'])->name('app.drafts.show');
        // Backwards compatibility: legacy edit links now resolve to the unified draft workspace view.
        Route::get('/drafts/{draft}/edit', [AppDraftsController::class, 'show'])->name('app.drafts.edit');
        Route::post('/drafts/{draft}/smart-suggestions', [AppDraftsController::class, 'runSmartSuggestions'])->name('app.drafts.smart-suggestions.run');
        Route::post('/drafts/{draft}/localization', [AppDraftsController::class, 'runLocalization'])->name('app.drafts.localization.run');
        Route::post('/drafts/{draft}/internal-linking', [AppDraftsController::class, 'runInternalLinking'])->name('app.drafts.internal-linking.run');
        Route::post('/drafts/{draft}/internal-linking/apply', [AppDraftsController::class, 'applyInternalLinkSuggestion'])->name('app.drafts.internal-linking.apply');
        Route::post('/drafts/{draft}/analyze', [AppDraftsController::class, 'analyze'])->name('app.drafts.analyze');
        Route::post('/drafts/{draft}/humanize', [AppDraftsController::class, 'humanize'])->name('app.drafts.humanize');
        Route::post('/drafts/{draft}/improve', [AppDraftsController::class, 'improve'])->name('app.drafts.improve');
        Route::post('/drafts/{draft}/translate', [AppDraftsController::class, 'translate'])->name('app.drafts.translate');
        Route::post('/drafts/{draft}/ready-for-review', [AppDraftsController::class, 'markReadyForReview'])->name('app.drafts.ready-for-review');
        Route::post('/drafts/{draft}/request-changes', [AppDraftsController::class, 'requestChanges'])->name('app.drafts.request-changes');
        Route::post('/drafts/{draft}/approve-for-publishing', [AppDraftsController::class, 'approveForPublishing'])->name('app.drafts.approve-for-publishing');
        Route::post('/drafts/{draft}/archive-governance', [AppDraftsController::class, 'archiveGovernance'])->name('app.drafts.archive-governance');
        Route::post('/drafts/{draft}/republish', [AppDraftsController::class, 'republish'])->name('app.drafts.republish');
        Route::post('/drafts/{draft}/images/{imageVersion}/restore', [AppDraftsController::class, 'restoreImageVersion'])->name('app.drafts.images.versions.restore');
        Route::middleware('ensure.feature:link_intelligence')->group(function () {
            Route::post('/drafts/{draft}/link-suggestions/generate', [DraftLinkSuggestionsController::class, 'generate'])->middleware('protect.heavy:ai')->name('app.drafts.link-suggestions.generate');
            Route::post('/drafts/{draft}/link-suggestions/reset-applied', [DraftLinkSuggestionsController::class, 'resetApplied'])->name('app.drafts.link-suggestions.reset-applied');
            Route::post('/drafts/{draft}/link-suggestions/clear-rejected', [DraftLinkSuggestionsController::class, 'clearRejected'])->name('app.drafts.link-suggestions.clear-rejected');
            Route::post('/drafts/{draft}/link-suggestions/{suggestion}/approve', [DraftLinkSuggestionsController::class, 'approve'])->name('app.drafts.link-suggestions.approve');
            Route::post('/drafts/{draft}/link-suggestions/{suggestion}/reject', [DraftLinkSuggestionsController::class, 'reject'])->name('app.drafts.link-suggestions.reject');
            Route::post('/drafts/{draft}/link-suggestions/{suggestion}/apply', [DraftLinkSuggestionsController::class, 'apply'])->name('app.drafts.link-suggestions.apply');
            Route::post('/drafts/{draft}/link-suggestions/{suggestion}/delete', [DraftLinkSuggestionsController::class, 'delete'])->name('app.drafts.link-suggestions.delete');
        });

        // TODO(FEATURE): Re-enable network linking when ready.
        Route::middleware(['ensure.feature.enabled:network_linking', 'ensure.feature:link_intelligence'])->group(function () {
            Route::get('/network-linking', [NetworkLinkingController::class, 'index'])->name('app.network-linking.index');
            Route::post('/network-linking/workspaces/{workspace}/profile', [NetworkLinkingController::class, 'updateProfile'])->name('app.network-linking.profile.update');
            Route::post('/network-linking/workspaces/{workspace}/permissions/request', [NetworkLinkingController::class, 'requestPermission'])->name('app.network-linking.permissions.request');
            Route::post('/network-linking/permissions/{permission}/approve', [NetworkLinkingController::class, 'approvePermission'])->name('app.network-linking.permissions.approve');
            Route::post('/network-linking/permissions/{permission}/revoke', [NetworkLinkingController::class, 'revokePermission'])->name('app.network-linking.permissions.revoke');
        });
        Route::middleware(['ensure.feature.enabled:content_network_analysis'])->group(function () {
            Route::get('/content-network', [AppContentNetworkController::class, 'index'])->name('app.content-network.index');
            Route::post('/content-network/{workspace}/run', [AppContentNetworkController::class, 'run'])
                ->middleware('ensure.feature:content_network_analysis_enabled')
                ->name('app.content-network.run');
        });

        // Brand section - Company Profile and Brand Voices
        Route::get('/brand/company-profile', [AppBrandController::class, 'companyProfile'])->name('app.brand.company-profile');
        Route::post('/brand/company-profile', [AppSettingsController::class, 'upsertCompanyProfile'])->name('app.brand.company-profile.upsert');
        Route::get('/brand/company-intelligence', [AppCompanyIntelligenceController::class, 'index'])->name('app.brand.company-intelligence');
        Route::post('/brand/company-intelligence', [AppCompanyIntelligenceController::class, 'store'])->name('app.brand.company-intelligence.store');
        Route::post('/brand/company-intelligence/{profile}', [AppCompanyIntelligenceController::class, 'update'])->name('app.brand.company-intelligence.update');
        Route::delete('/brand/company-intelligence/{profile}', [AppCompanyIntelligenceController::class, 'destroy'])->name('app.brand.company-intelligence.delete');
        Route::get('/brand/company-intelligence/{profile}.json', [AppCompanyIntelligenceController::class, 'showJson'])->name('app.brand.company-intelligence.json');
        Route::get('/brand/voices', [AppBrandController::class, 'voices'])->name('app.brand.voices');
        Route::get('/brand/writer-profiles', [AppWriterProfileController::class, 'index'])->name('app.brand.writer-profiles');
        Route::post('/brand/writer-profiles', [AppWriterProfileController::class, 'store'])->name('app.brand.writer-profiles.store');
        Route::post('/brand/writer-profiles/{writerProfile}', [AppWriterProfileController::class, 'update'])->name('app.brand.writer-profiles.update');
        Route::post('/brand/writer-profiles/{writerProfile}/analyze', [AppWriterProfileController::class, 'analyze'])->name('app.brand.writer-profiles.analyze');
        Route::post('/brand/writer-profiles/{writerProfile}/activate', [AppWriterProfileController::class, 'activate'])->name('app.brand.writer-profiles.activate');
        Route::post('/brand/writer-profiles/{writerProfile}/archive', [AppWriterProfileController::class, 'archive'])->name('app.brand.writer-profiles.archive');
        Route::get('/brand/personas', [AppBrandController::class, 'personas'])->name('app.brand.personas');
        Route::post('/brand/personas', [AppBrandController::class, 'storePersona'])->name('app.brand.personas.store');
        Route::post('/brand/personas/{persona}', [AppBrandController::class, 'updatePersona'])->name('app.brand.personas.update');
        Route::post('/brand/voices', [AppSettingsController::class, 'storeBrandVoice'])->name('app.brand.voices.store');
        Route::post('/brand/voices/{brandVoice}', [AppSettingsController::class, 'updateBrandVoice'])->name('app.brand.voices.update');
        Route::post('/brand/voices/{brandVoice}/default', [AppSettingsController::class, 'setDefaultBrandVoice'])->name('app.brand.voices.default');
        Route::delete('/brand/voices/{brandVoice}', [AppSettingsController::class, 'deleteBrandVoice'])->name('app.brand.voices.delete');

        // Team Member Personas
        Route::get('/brand/team-members', [AppTeamMembersController::class, 'index'])->name('app.brand.team-members');
        Route::post('/brand/team-members', [AppTeamMembersController::class, 'store'])->name('app.brand.team-members.store');
        Route::post('/brand/team-members/{teamMember}', [AppTeamMembersController::class, 'update'])->name('app.brand.team-members.update');
        Route::post('/brand/team-members/{teamMember}/toggle', [AppTeamMembersController::class, 'toggleActive'])->name('app.brand.team-members.toggle');

        // Brand AI Wizard
        Route::get('/brand/setup', [AppBrandWizardController::class, 'index'])->name('app.brand.wizard');
        Route::post('/brand/setup', [AppBrandWizardController::class, 'store'])->name('app.brand.wizard.store');
        Route::get('/brand/setup/{run}/status', [AppBrandWizardController::class, 'status'])->name('app.brand.wizard.status');
        Route::get('/brand/setup/{run}/review', [AppBrandWizardController::class, 'review'])->name('app.brand.wizard.review');
        Route::post('/brand/setup/{run}/retry', [AppBrandWizardController::class, 'retry'])->name('app.brand.wizard.retry');
        Route::post('/brand/setup/{run}/apply', [AppBrandWizardController::class, 'apply'])->name('app.brand.wizard.apply');

        // Brand Field AI Actions API
        Route::post('/api/brand/field-actions', [AppBrandFieldActionsController::class, 'transform'])->name('app.api.brand.field-actions');

        Route::get('/workspace-intelligence', [AppWorkspaceIntelligenceController::class, 'index'])->name('app.workspace-intelligence.index');
        Route::get('/workspace-intelligence/runs/{run}', [AppWorkspaceIntelligenceController::class, 'showRun'])->name('app.workspace-intelligence.runs.show');
        Route::post('/workspace-intelligence/organization-enrichments', [AppWorkspaceIntelligenceController::class, 'storeOrganization'])->name('app.workspace-intelligence.organization.store');
        Route::post('/workspace-intelligence/persona-enrichments', [AppWorkspaceIntelligenceController::class, 'storePersona'])->name('app.workspace-intelligence.personas.store');
        Route::post('/workspace-intelligence/team-member-enrichments', [AppWorkspaceIntelligenceController::class, 'storeTeamMember'])->name('app.workspace-intelligence.team-members.store');
        Route::post('/workspace-intelligence/runs/{run}/approve', [AppWorkspaceIntelligenceController::class, 'approve'])->name('app.workspace-intelligence.runs.approve');
        Route::post('/workspace-intelligence/runs/{run}/reject', [AppWorkspaceIntelligenceController::class, 'reject'])->name('app.workspace-intelligence.runs.reject');

        Route::get('/settings', [AppSettingsController::class, 'index'])->name('app.settings');
        Route::get('/connectors', [AppConnectorController::class, 'index'])->name('app.connectors.index');
        Route::get('/connectors/{provider}/connect', [AppConnectorController::class, 'connect'])->name('app.connectors.connect');
        Route::get('/connectors/oauth/{provider}/callback', [AppConnectorController::class, 'callback'])->name('app.connectors.oauth.callback');
        Route::post('/connectors/{connectorAccount}/reconnect', [AppConnectorController::class, 'reconnect'])->name('app.connectors.reconnect');
        Route::post('/connectors/{connectorAccount}/disconnect', [AppConnectorController::class, 'disconnect'])->name('app.connectors.disconnect');
        Route::post('/connectors/{connectorAccount}/discover', [AppConnectorController::class, 'discover'])->name('app.connectors.discover');
        Route::post('/connectors/{connectorAccount}/sync', [AppConnectorController::class, 'sync'])->name('app.connectors.sync');
        Route::post('/connectors/{connectorAccount}/health-check', [AppConnectorController::class, 'healthCheck'])->name('app.connectors.health-check');
        Route::post('/connectors/{connectorAccount}/normalize', [AppConnectorController::class, 'normalize'])->name('app.connectors.normalize');
        Route::get('/connectors/{connectorAccount}/diagnostics', [AppConnectorController::class, 'diagnostics'])->name('app.connectors.diagnostics');
        Route::get('/connectors/{connectorAccount}/field-mapping', [AppConnectorController::class, 'fieldMapping'])->name('app.connectors.field-mapping');
        Route::post('/connectors/{connectorAccount}/field-mapping/prepare', [AppConnectorController::class, 'prepareFieldMapping'])->name('app.connectors.field-mapping.prepare');
        Route::post('/connectors/datasets/{connectorDataset}/enable', [AppConnectorController::class, 'enableDataset'])->name('app.connectors.datasets.enable');
        Route::post('/connectors/datasets/{connectorDataset}/disable', [AppConnectorController::class, 'disableDataset'])->name('app.connectors.datasets.disable');
        Route::post('/connectors/datasets/{connectorDataset}/backfill', [AppConnectorController::class, 'backfill'])->name('app.connectors.datasets.backfill');
        Route::post('/connectors/datasets/{connectorDataset}/backfills/retry', [AppConnectorController::class, 'retryBackfills'])->name('app.connectors.datasets.backfills.retry');
        Route::post('/connectors/normalization-runs/{normalizationRun}/retry', [AppConnectorController::class, 'retryNormalization'])->name('app.connectors.normalization-runs.retry');
        Route::get('/connectors/{connectorAccount}', [AppConnectorController::class, 'show'])->name('app.connectors.show');
        Route::get('/settings/integrations/linkedin', [LinkedInIntegrationController::class, 'show'])->name('app.settings.integrations.linkedin');
        Route::get('/settings/integrations/linkedin/connect', [LinkedInIntegrationController::class, 'connect'])->name('app.settings.integrations.linkedin.connect');
        Route::get('/settings/integrations/linkedin/callback', [LinkedInIntegrationController::class, 'callback'])->name('app.settings.integrations.linkedin.callback');
        Route::post('/settings/integrations/linkedin/disconnect', [LinkedInIntegrationController::class, 'disconnect'])->name('app.settings.integrations.linkedin.disconnect');
        Route::get('/settings/integrations/instagram', [InstagramIntegrationController::class, 'show'])->name('app.settings.integrations.instagram');
        Route::get('/settings/integrations/instagram/connect', [InstagramIntegrationController::class, 'connect'])->name('app.settings.integrations.instagram.connect');
        Route::get('/settings/integrations/instagram/callback', [InstagramIntegrationController::class, 'callback'])->name('app.settings.integrations.instagram.callback');
        Route::post('/settings/integrations/instagram/disconnect', [InstagramIntegrationController::class, 'disconnect'])->name('app.settings.integrations.instagram.disconnect');
        Route::get('/developer', [AppDeveloperController::class, 'index'])->name('app.developer.index');
        Route::get('/developer/api', [AppDeveloperController::class, 'api'])->name('app.developer.api');
        Route::get('/developer/webhooks', [AppDeveloperController::class, 'webhooks'])->name('app.developer.webhooks');
        Route::get('/developer/docs', [AppDeveloperController::class, 'docs'])->name('app.developer.docs');
        Route::post('/developer/destinations', [AppDeveloperController::class, 'storeDestination'])->name('app.developer.destinations.store');
        Route::post('/developer/destinations/{destination}', [AppDeveloperController::class, 'updateDestination'])->name('app.developer.destinations.update');
        Route::post('/developer/destinations/{destination}/test-connection', [AppDeveloperController::class, 'testDestinationConnection'])->middleware('protect.heavy:heavy')->name('app.developer.destinations.test-connection');
        Route::post('/developer/email-marketing-connections', [AppDeveloperController::class, 'storeEmailMarketingConnection'])->name('app.developer.email-marketing-connections.store');
        Route::post('/developer/email-marketing-connections/{connection}', [AppDeveloperController::class, 'updateEmailMarketingConnection'])->name('app.developer.email-marketing-connections.update');
        Route::post('/developer/api-keys', [AppDeveloperController::class, 'storeApiKey'])->name('app.developer.api-keys.store');
        Route::post('/developer/api-keys/{apiKey}/revoke', [AppDeveloperController::class, 'revokeApiKey'])->name('app.developer.api-keys.revoke');
        Route::post('/developer/webhooks', [AppDeveloperController::class, 'storeWebhook'])->name('app.developer.webhooks.store');
        Route::post('/developer/webhooks/{webhook}', [AppDeveloperController::class, 'updateWebhook'])->name('app.developer.webhooks.update');
        Route::delete('/developer/webhooks/{webhook}', [AppDeveloperController::class, 'destroyWebhook'])->name('app.developer.webhooks.destroy');

        // Developer API Documentation
        Route::get('/developer/docs/reference', [AppDeveloperDocsController::class, 'index'])->name('app.developer.docs.index');
        Route::get('/developer/docs/downloads', [AppDeveloperDocsController::class, 'downloads'])->name('app.developer.docs.downloads');
        Route::get('/developer/docs/download/openapi', [AppDeveloperDocsController::class, 'downloadOpenApi'])->name('app.developer.docs.download.openapi');
        Route::get('/developer/docs/download/postman-collection', [AppDeveloperDocsController::class, 'downloadPostmanCollection'])->name('app.developer.docs.download.postman-collection');
        Route::get('/developer/docs/download/postman-environment', [AppDeveloperDocsController::class, 'downloadPostmanEnvironment'])->name('app.developer.docs.download.postman-environment');

        Route::get('/billing', [AppBillingController::class, 'index'])->name('app.billing.index');
        Route::get('/api/workspaces/{workspace}/billing/upgrade-status', AppBillingUpgradeStatusController::class)
            ->name('app.api.billing.upgrade-status');
        Route::post('/billing/profile', [AppBillingController::class, 'updateBillingProfile'])->name('app.billing.profile.update');
        Route::post('/billing/subscription/start', [AppBillingController::class, 'startSubscription'])->name('app.billing.subscription.start');
        Route::post('/billing/subscription/change-plan', [AppBillingController::class, 'changePlan'])->name('app.billing.subscription.change-plan');
        Route::post('/billing/packs/purchase', [AppBillingController::class, 'purchasePack'])->name('app.billing.packs.purchase');
        Route::post('/billing/allocations/allocate', [AppBillingController::class, 'allocateSiteCredits'])->name('app.billing.allocations.allocate');
        Route::post('/billing/allocations/reclaim', [AppBillingController::class, 'reclaimSiteCredits'])->name('app.billing.allocations.reclaim');
        Route::post('/billing/allocations/transfer', [AppBillingController::class, 'transferSiteCredits'])->name('app.billing.allocations.transfer');
        Route::get('/billing/invoices/{invoice}/download', [AppInvoiceController::class, 'download'])->name('app.billing.invoices.download');
        Route::post('/settings/organization', [AppSettingsController::class, 'updateOrganization'])->name('app.settings.organization');
        Route::post('/settings/workspace-name', [AppSettingsController::class, 'updateWorkspaceName'])->name('app.settings.workspace-name.update');
        Route::post('/settings/workspace-timezone', [AppSettingsController::class, 'updateWorkspaceTimezone'])->name('app.settings.workspace-timezone.update');
        Route::post('/settings/workspace-languages', [AppSettingsController::class, 'updateWorkspaceLanguages'])->name('app.settings.workspace-languages.update');
        Route::post('/settings/advanced-mode', [AppSettingsController::class, 'updateAdvancedMode'])->name('app.settings.advanced-mode.update');
        Route::post('/settings/workspace-agent-automation', [AppSettingsController::class, 'updateWorkspaceAgentAutomation'])->name('app.settings.workspace-agent-automation.update');
        Route::post('/settings/agentic-marketing-execution', [AppSettingsController::class, 'updateAgenticMarketingExecutionSettings'])->name('app.settings.agentic-marketing-execution.update');
        Route::post('/settings/notifications', [AppSettingsController::class, 'updateNotifications'])->name('app.settings.notifications');
        Route::post('/settings/invites', [AppSettingsController::class, 'invite'])->name('app.settings.invites');
        Route::post('/settings/company-profile', [AppSettingsController::class, 'upsertCompanyProfile'])->name('app.settings.company-profile.upsert');
        Route::post('/settings/brand-voices', [AppSettingsController::class, 'storeBrandVoice'])->name('app.settings.brand-voices.store');
        Route::post('/settings/brand-voices/{brandVoice}', [AppSettingsController::class, 'updateBrandVoice'])->name('app.settings.brand-voices.update');
        Route::post('/settings/brand-voices/{brandVoice}/default', [AppSettingsController::class, 'setDefaultBrandVoice'])->name('app.settings.brand-voices.default');
        Route::delete('/settings/brand-voices/{brandVoice}', [AppSettingsController::class, 'deleteBrandVoice'])->name('app.settings.brand-voices.delete');

        // Image Presets (organization-scoped visual style presets for AI image generation)
        Route::get('/settings/image-presets', [AppImagePresetController::class, 'index'])->name('app.settings.image-presets.index');
        Route::get('/settings/image-presets/create', [AppImagePresetController::class, 'create'])->name('app.settings.image-presets.create');
        Route::post('/settings/image-presets', [AppImagePresetController::class, 'store'])->name('app.settings.image-presets.store');
        Route::get('/settings/image-presets/{imagePreset}/edit', [AppImagePresetController::class, 'edit'])->name('app.settings.image-presets.edit');
        Route::put('/settings/image-presets/{imagePreset}', [AppImagePresetController::class, 'update'])->name('app.settings.image-presets.update');
        Route::delete('/settings/image-presets/{imagePreset}', [AppImagePresetController::class, 'destroy'])->name('app.settings.image-presets.destroy');
        Route::post('/settings/image-presets/{imagePreset}/set-default', [AppImagePresetController::class, 'setDefault'])->name('app.settings.image-presets.set-default');

        // Image Presets JSON API (for AJAX/modal usage)
        Route::get('/api/image-presets', [AppImagePresetController::class, 'apiIndex'])->name('app.api.image-presets.index');
        Route::post('/api/image-presets', [AppImagePresetController::class, 'apiStore'])->name('app.api.image-presets.store');
        Route::put('/api/image-presets/{imagePreset}', [AppImagePresetController::class, 'apiUpdate'])->name('app.api.image-presets.update');
        Route::delete('/api/image-presets/{imagePreset}', [AppImagePresetController::class, 'apiDestroy'])->name('app.api.image-presets.destroy');
        Route::post('/api/image-presets/{imagePreset}/set-default', [AppImagePresetController::class, 'apiSetDefault'])->name('app.api.image-presets.set-default');

        // Backwards compatibility: redirect old settings brand URLs to new brand section
        Route::get('/settings/company-profile', fn () => redirect()->route('app.brand.company-profile', [], 301));
        Route::get('/settings/brand-voices', fn () => redirect()->route('app.brand.voices', [], 301));
        Route::match(['GET', 'POST'], '/settings/api', fn () => redirect()->route('app.developer.api'))
            ->name('app.settings.api.redirect');
        Route::match(['GET', 'POST'], '/settings/api/regenerate', fn () => redirect()->route('app.developer.api'))
            ->name('app.settings.api.regenerate.redirect');
    });

// Backwards compatibility: redirect legacy /app/* paths to root
Route::get('/app/{any?}', fn (string $any = '') => redirect("/{$any}", 301))->where('any', '.*');
