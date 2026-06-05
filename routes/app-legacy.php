<?php

/*
|--------------------------------------------------------------------------
| Legacy App Routes (Backwards Compatibility)
|--------------------------------------------------------------------------
|
| These routes provide backwards compatibility for the /app/* path structure.
| They will be deprecated once all consumers migrate to the app subdomain.
|
| DO NOT add route names to these routes - use routes/app.php for that.
|
*/

use App\Http\Controllers\App\AppBillingController;
use App\Http\Controllers\App\AppBrandController;
use App\Http\Controllers\App\AppBriefsController;
use App\Http\Controllers\App\AppCompanyOnboardingController;
use App\Http\Controllers\App\AppContentBatchesController;
use App\Http\Controllers\App\AppContentController;
use App\Http\Controllers\App\AppContentNetworkController;
use App\Http\Controllers\App\AppContentSeriesController;
use App\Http\Controllers\App\AppDashboardController;
use App\Http\Controllers\App\AppDeveloperController;
use App\Http\Controllers\App\AppDraftsController;
use App\Http\Controllers\App\AppInvoiceController;
use App\Http\Controllers\App\AppInsightsController;
use App\Http\Controllers\App\AppAnalyticsSiteController;
use App\Http\Controllers\App\AppLearningsController;
use App\Http\Controllers\App\AppLlmTrackingController;
use App\Http\Controllers\App\AppNotificationsController;
use App\Http\Controllers\App\AppOnboardingController;
use App\Http\Controllers\App\OnboardingScanController;
use App\Http\Controllers\App\AppResearchController;
use App\Http\Controllers\App\AppSettingsController;
use App\Http\Controllers\App\AppSiteCompetitorsController;
use App\Http\Controllers\App\AppSitesController;
use App\Http\Controllers\App\AppSiteSeoAuditController;
use App\Http\Controllers\App\DraftLinkSuggestionsController;
use App\Http\Controllers\App\NetworkLinkingController;
use App\Models\Content;
use App\Models\Draft;
use App\Http\Controllers\Billing\PackCheckoutTestController;
use App\Http\Controllers\Billing\PackReturnController;
use App\Http\Controllers\SearchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/billing/return', [PackReturnController::class, 'handle']);

Route::get('/_test/pack-checkout/{purchaseId}', [PackCheckoutTestController::class, 'checkout']);

// App routes with auth middleware
Route::middleware(['auth', 'support.context:app', 'support.readonly', 'email.code.verified', 'user.approved', 'user.org', 'onboarding.billing'])
    ->group(function () {
        Route::redirect('/', '/app/dashboard');
        Route::get('/dashboard', [AppDashboardController::class, 'index']);
        Route::get('/notifications', [AppNotificationsController::class, 'index']);
        Route::post('/notifications/read-all', [AppNotificationsController::class, 'markAllRead']);
        Route::post('/notifications/{notification}/read', [AppNotificationsController::class, 'markRead']);
        Route::get('/search', [SearchController::class, 'appIndex'])->middleware('protect.heavy:search');
        Route::get('/search/suggest', [SearchController::class, 'appSuggest'])->middleware('protect.heavy:search');
        Route::get('/onboarding/company', [AppCompanyOnboardingController::class, 'show']);
        Route::post('/onboarding/company', [AppCompanyOnboardingController::class, 'update']);
        Route::get('/onboarding', [AppOnboardingController::class, 'show']);
        Route::post('/onboarding/intent', [AppOnboardingController::class, 'storeIntent']);
        Route::post('/onboarding/company-profile', [AppOnboardingController::class, 'storeCompanyProfile']);
        Route::post('/onboarding/connect-site', [AppOnboardingController::class, 'completeSiteConnect']);
        Route::post('/onboarding/scan', [OnboardingScanController::class, 'store'])->middleware('protect.heavy:heavy');
        Route::get('/insights', [AppInsightsController::class, 'index']);
        Route::get('/sites', [AppSitesController::class, 'index']);
        Route::post('/sites', [AppSitesController::class, 'store']);
        Route::get('/sites/{site}', [AppSitesController::class, 'show']);
        Route::get('/sites/{site}/insights', [AppInsightsController::class, 'show']);
        Route::post('/sites/{site}/test-wordpress-connection', [AppSitesController::class, 'testWordPressConnection'])->middleware('protect.heavy:heavy');
        Route::post('/sites/{site}/test-laravel-connector', [AppSitesController::class, 'testLaravelConnector'])->middleware('protect.heavy:heavy');
        Route::post('/sites/{site}/update', [AppSitesController::class, 'update']);
        Route::post('/sites/{site}/regenerate-key', [AppSitesController::class, 'regenerateKey']);
        Route::post('/sites/{site}/toggle', [AppSitesController::class, 'toggle']);
        Route::delete('/sites/{site}', [AppSitesController::class, 'destroy']);
        Route::get('/sites/{site}/insights/llm', [AppLlmTrackingController::class, 'index']);
        Route::post('/sites/{site}/insights/llm', [AppLlmTrackingController::class, 'store'])->middleware('protect.heavy:ai');
        Route::get('/sites/{site}/insights/llm/{query}', [AppLlmTrackingController::class, 'show']);
        Route::post('/sites/{site}/insights/llm/{query}', [AppLlmTrackingController::class, 'update']);
        Route::post('/sites/{site}/insights/llm/{query}/toggle', [AppLlmTrackingController::class, 'toggle']);
        Route::post('/sites/{site}/insights/llm/{query}/run-now', [AppLlmTrackingController::class, 'runNow'])->middleware('protect.heavy:ai');
        Route::get('/sites/{site}/insights/llm/{query}/aggregates', [AppLlmTrackingController::class, 'aggregates']);
        Route::get('/sites/{site}/insights/llm/{query}/runs/{run}', [AppLlmTrackingController::class, 'runDetails']);
        Route::get('/sites/{site}/insights/competitors', [AppSiteCompetitorsController::class, 'index']);
        Route::post('/sites/{site}/insights/competitors', [AppSiteCompetitorsController::class, 'store']);
        Route::post('/sites/{site}/insights/competitors/context', [AppSiteCompetitorsController::class, 'updateContextSetting']);
        Route::post('/sites/{site}/insights/competitors/{competitor}/toggle', [AppSiteCompetitorsController::class, 'toggle']);
        Route::get('/sites/{site}/insights/audits', [AppSiteSeoAuditController::class, 'index']);
        Route::post('/sites/{site}/insights/audits/run', [AppSiteSeoAuditController::class, 'run'])->middleware('protect.heavy:audit');
        Route::get('/sites/{site}/insights/audits/{audit}', [AppSiteSeoAuditController::class, 'show']);
        Route::get('/sites/{site}/insights/analytics', [AppAnalyticsSiteController::class, 'show']);
        Route::post('/sites/{site}/insights/analytics/enable', [AppAnalyticsSiteController::class, 'enable']);
        Route::post('/sites/{site}/insights/analytics/disable', [AppAnalyticsSiteController::class, 'disable']);
        Route::post('/sites/{site}/insights/analytics/verify', [AppAnalyticsSiteController::class, 'verify']);
        Route::post('/sites/{site}/insights/analytics/regenerate-token', [AppAnalyticsSiteController::class, 'regenerateToken']);
        Route::get('/sites/{site}/insights/learnings', [AppLearningsController::class, 'index']);
        Route::get('/sites/{site}/llm-tracking', function (Request $request, $site) {
            $query = http_build_query($request->query());

            return redirect('/app/sites/' . $site . '/insights/llm' . ($query !== '' ? '?' . $query : ''));
        });
        Route::get('/sites/{site}/llm-tracking/{query}', function (Request $request, $site, $queryId) {
            $query = http_build_query($request->query());

            return redirect('/app/sites/' . $site . '/insights/llm/' . $queryId . ($query !== '' ? '?' . $query : ''));
        });
        Route::get('/sites/{site}/competitors', function (Request $request, $site) {
            $query = http_build_query($request->query());

            return redirect('/app/sites/' . $site . '/insights/competitors' . ($query !== '' ? '?' . $query : ''));
        });
        Route::get('/sites/{site}/seo-audits', function (Request $request, $site) {
            $query = http_build_query($request->query());

            return redirect('/app/sites/' . $site . '/insights/audits' . ($query !== '' ? '?' . $query : ''));
        });
        Route::get('/sites/{site}/seo-audits/{audit}', function (Request $request, $site, $audit) {
            $query = http_build_query($request->query());

            return redirect('/app/sites/' . $site . '/insights/audits/' . $audit . ($query !== '' ? '?' . $query : ''));
        });
        Route::get('/sites/{site}/analytics', function (Request $request, $site) {
            $query = http_build_query($request->query());

            return redirect('/app/sites/' . $site . '/insights/analytics' . ($query !== '' ? '?' . $query : ''));
        });
        Route::get('/sites/{site}/learnings', function (Request $request, $site) {
            $query = http_build_query($request->query());

            return redirect('/app/sites/' . $site . '/insights/learnings' . ($query !== '' ? '?' . $query : ''));
        });
        Route::middleware(['ensure.feature.enabled:research_layer'])->group(function () {
            Route::get('/research', [AppResearchController::class, 'index']);
            Route::get('/research/create', [AppResearchController::class, 'create']);
            Route::post('/research', [AppResearchController::class, 'store']);
            Route::get('/research/{project}', [AppResearchController::class, 'show']);
            Route::post('/research/{project}/start', [AppResearchController::class, 'start'])->middleware('protect.heavy:report');
            Route::post('/research/{project}/findings/select', [AppResearchController::class, 'updateSelectedFindings']);
        });
        // Sidebar cleanup checklist:
        // - Briefs/Drafts removed from primary client navigation.
        // - Keep legacy list URLs working by redirecting to Content inbox filters.
        Route::get('/briefs', function (Request $request) {
            return redirect('/app/content?'.http_build_query(array_merge(
                $request->query(),
                ['inbox' => 'needs_brief']
            )));
        });
        Route::get('/content/create', [AppBriefsController::class, 'create']);
        Route::post('/content/create', [AppBriefsController::class, 'store']);
        Route::middleware(['ensure.feature.enabled:brief_intelligence'])->group(function () {
            Route::post('/content/create-from-research', [AppBriefsController::class, 'storeFromResearch'])->middleware('protect.heavy:ai');
        });
        Route::get('/content/workspace/{brief}', [AppBriefsController::class, 'show']);
        Route::get('/content/workspace/{brief}/overview', [AppBriefsController::class, 'show'])->defaults('workspace_section', 'overview');
        Route::get('/content/workspace/{brief}/brief', [AppBriefsController::class, 'show'])->defaults('workspace_section', 'brief');
        Route::get('/content/workspace/{brief}/drafts', [AppBriefsController::class, 'show'])->defaults('workspace_section', 'drafts');
        Route::get('/content/workspace/{brief}/compare', [\App\Http\Controllers\App\AppDraftComparisonsController::class, 'setup']);
        Route::post('/content/workspace/{brief}/compare/estimate', [\App\Http\Controllers\App\AppDraftComparisonsController::class, 'estimate']);
        Route::post('/content/workspace/{brief}/compare', [\App\Http\Controllers\App\AppDraftComparisonsController::class, 'store']);
        Route::post('/content/workspace/{brief}/compare/{comparison}/start', [\App\Http\Controllers\App\AppDraftComparisonsController::class, 'start']);
        Route::get('/content/workspace/{brief}/compare/{comparison}/status', [\App\Http\Controllers\App\AppDraftComparisonsController::class, 'status']);
        Route::get('/content/workspace/{brief}/compare/{comparison}/variants/{variant}/draft', [\App\Http\Controllers\App\AppDraftComparisonsController::class, 'openVariantDraft']);
        Route::get('/content/workspace/{brief}/compare/{comparison}', [\App\Http\Controllers\App\AppDraftComparisonsController::class, 'show']);
        Route::post('/content/workspace/{brief}/compare/{comparison}/winner', [\App\Http\Controllers\App\AppDraftComparisonsController::class, 'selectWinner']);
        Route::get('/content/workspace/{brief}/compare/{comparison}/hybrid/estimate', [\App\Http\Controllers\App\AppDraftComparisonsController::class, 'estimateHybrid']);
        Route::post('/content/workspace/{brief}/compare/{comparison}/hybrid', [\App\Http\Controllers\App\AppDraftComparisonsController::class, 'queueHybrid']);
        Route::get('/content/workspace/{brief}/brief/edit', [AppBriefsController::class, 'edit']);
        Route::put('/content/workspace/{brief}/brief', [AppBriefsController::class, 'update']);
        Route::middleware(['ensure.feature.enabled:brief_intelligence'])->group(function () {
            Route::post('/content/workspace/{brief}/brief/enhance', [AppBriefsController::class, 'enhance']);
            Route::post('/content/workspace/{brief}/brief/suggestions/{suggestion}/apply', [AppBriefsController::class, 'applySuggestion']);
            Route::post('/content/workspace/{brief}/brief/suggestions/{suggestion}/reject', [AppBriefsController::class, 'rejectSuggestion']);
        });
        Route::post('/content/workspace/{brief}/archive', [AppBriefsController::class, 'archive']);
        Route::post('/content/workspace/{brief}/drafts/generate', [AppBriefsController::class, 'generateDraft'])->middleware('protect.heavy:ai');

        Route::get('/briefs/create', [AppBriefsController::class, 'create']);
        Route::post('/briefs', [AppBriefsController::class, 'store']);
        Route::middleware(['ensure.feature.enabled:brief_intelligence'])->group(function () {
            Route::post('/briefs/from-research', [AppBriefsController::class, 'storeFromResearch'])->middleware('protect.heavy:ai');
        });
        Route::get('/briefs/{brief}', [AppBriefsController::class, 'show']);
        Route::get('/briefs/{brief}/edit', [AppBriefsController::class, 'edit']);
        Route::put('/briefs/{brief}', [AppBriefsController::class, 'update']);
        Route::middleware(['ensure.feature.enabled:brief_intelligence'])->group(function () {
            Route::post('/briefs/{brief}/enhance', [AppBriefsController::class, 'enhance']);
            Route::post('/briefs/{brief}/suggestions/{suggestion}/apply', [AppBriefsController::class, 'applySuggestion']);
            Route::post('/briefs/{brief}/suggestions/{suggestion}/reject', [AppBriefsController::class, 'rejectSuggestion']);
        });
        Route::post('/briefs/{brief}/archive', [AppBriefsController::class, 'archive']);
        Route::post('/briefs/{brief}/generate-draft', [AppBriefsController::class, 'generateDraft'])->middleware('protect.heavy:ai');
        Route::get('/briefs/{brief}/draft-compare/setup', function ($brief) {
            return redirect('/app/content/workspace/'.$brief.'/compare');
        });
        Route::get('/content', [AppContentController::class, 'index']);
        Route::post('/content', [AppContentController::class, 'store']);
        Route::get('/content/series', [AppContentSeriesController::class, 'index']);
        Route::get('/content/series/create', [AppContentSeriesController::class, 'create']);
        Route::post('/content/series', [AppContentSeriesController::class, 'store']);
        Route::get('/content/series/{series}', [AppContentSeriesController::class, 'show']);
        Route::get('/content/series/{series}/structure', [AppContentSeriesController::class, 'structure']);
        Route::post('/content/series/{series}/generate-strategy', [AppContentSeriesController::class, 'generateStrategy'])->middleware('protect.heavy:ai');
        Route::post('/content/series/{series}/generate-articles', [AppContentSeriesController::class, 'generateArticles'])->middleware('protect.heavy:ai');
        Route::post('/content/series/{series}/publish', [AppContentSeriesController::class, 'publish']);
        Route::post('/content/series/{series}/pillar', [AppContentSeriesController::class, 'setPillar']);
        Route::post('/content/series/{series}/pillar/clear', [AppContentSeriesController::class, 'clearPillar']);
        Route::post('/content/series/{series}/duplicate', [AppContentSeriesController::class, 'duplicate']);
        Route::post('/content/series/{series}/archive', [AppContentSeriesController::class, 'archive']);
        Route::delete('/content/series/{series}', [AppContentSeriesController::class, 'destroy']);
        Route::get('/content/batches/create', [AppContentBatchesController::class, 'create']);
        Route::post('/content/batches/suggest', [AppContentBatchesController::class, 'suggest']);
        Route::post('/content/batches', [AppContentBatchesController::class, 'store']);
        Route::post('/content/batches/{batch}/start', [AppContentBatchesController::class, 'start']);
        Route::get('/content/batches/{batch}', [AppContentBatchesController::class, 'show']);
        Route::post('/content/batches/{batch}/items/{item}/retry', [AppContentBatchesController::class, 'retryItem'])->middleware('protect.heavy:report');
        Route::post('/content/batches/{batch}/cancel', [AppContentBatchesController::class, 'cancel']);
        Route::get('/content/calendar', [AppContentController::class, 'calendar']);
        Route::post('/content/schedule-bulk', [AppContentController::class, 'bulkSchedule']);
        Route::get('/content/{content}', [AppContentController::class, 'show']);
        Route::get('/content/{content}/markdown-preview', [AppContentController::class, 'markdownPreview']);
        Route::post('/content/{content}/schedule', [AppContentController::class, 'schedule']);
        Route::post('/content/{content}/publish-now', [AppContentController::class, 'publishNow']);
        Route::post('/content/{content}/push-to-site', [AppContentController::class, 'pushToSite']);
        Route::post('/content/{content}/generation-preferences', [AppContentController::class, 'updateGenerationPreferences']);
        Route::post('/content/{content}/regenerate', [AppContentController::class, 'regenerateDraft'])->middleware('protect.heavy:ai');
        Route::post('/content/{content}/revisions', [AppContentController::class, 'storeRevision']);
        Route::post('/content/{content}/versions/{version}/restore', [AppContentController::class, 'restoreVersion']);
        // Legacy redirect: /repush → /republish (temporary backward compatibility)
        Route::post('/content/{content}/repush', fn (Content $content) => redirect()->route('app.content.republish', $content));
        Route::post('/content/{content}/images/featured/generate', [AppContentController::class, 'generateFeaturedImage'])->middleware('protect.heavy:ai');
        Route::post('/content/{content}/images/featured/unsplash', [AppContentController::class, 'useUnsplashFeaturedImage']);
        Route::post('/content/{content}/images/featured/push', [AppContentController::class, 'pushFeaturedImageToWordPress']);
        Route::post('/content/{content}/images/{imageVersion}/restore', [AppContentController::class, 'restoreImageVersion']);
        Route::delete('/content/{content}/images/{imageVersion}', [AppContentController::class, 'deleteImageVersion']);
        Route::post('/content/{content}/images/preferences', [AppContentController::class, 'updateImageGenerationPreferences']);
        Route::post('/content/{content}/images/og/generate', [AppContentController::class, 'generateOgImage'])->middleware('protect.heavy:ai');
        Route::post('/content/{content}/images/og/push', [AppContentController::class, 'pushOgImageToWordPress']);
        Route::get('/drafts', function (Request $request) {
            return redirect('/app/content?'.http_build_query(array_merge(
                $request->query(),
                ['inbox' => 'needs_draft']
            )));
        });
        Route::get('/drafts/{draft}', [AppDraftsController::class, 'show']);
        // Backwards compatibility for legacy edit URLs.
        Route::get('/drafts/{draft}/edit', [AppDraftsController::class, 'show']);
        Route::post('/drafts/{draft}/analyze', [AppDraftsController::class, 'analyze']);
        Route::post('/drafts/{draft}/improve', [AppDraftsController::class, 'improve']);
        // Legacy redirect: /repush → /republish (temporary backward compatibility)
        Route::post('/drafts/{draft}/repush', fn (Draft $draft) => redirect()->route('app.drafts.republish', $draft));
        Route::post('/drafts/{draft}/images/{imageVersion}/restore', [AppDraftsController::class, 'restoreImageVersion']);
        Route::middleware('ensure.feature:link_intelligence')->group(function () {
            Route::post('/drafts/{draft}/link-suggestions/generate', [DraftLinkSuggestionsController::class, 'generate'])->middleware('protect.heavy:ai');
            Route::post('/drafts/{draft}/link-suggestions/reset-applied', [DraftLinkSuggestionsController::class, 'resetApplied']);
            Route::post('/drafts/{draft}/link-suggestions/clear-rejected', [DraftLinkSuggestionsController::class, 'clearRejected']);
            Route::post('/drafts/{draft}/link-suggestions/{suggestion}/approve', [DraftLinkSuggestionsController::class, 'approve']);
            Route::post('/drafts/{draft}/link-suggestions/{suggestion}/reject', [DraftLinkSuggestionsController::class, 'reject']);
            Route::post('/drafts/{draft}/link-suggestions/{suggestion}/apply', [DraftLinkSuggestionsController::class, 'apply']);
            Route::post('/drafts/{draft}/link-suggestions/{suggestion}/delete', [DraftLinkSuggestionsController::class, 'delete']);
        });

        // TODO(FEATURE): Re-enable network linking when ready.
        Route::middleware(['ensure.feature.enabled:network_linking', 'ensure.feature:link_intelligence'])->group(function () {
            Route::get('/network-linking', [NetworkLinkingController::class, 'index']);
            Route::post('/network-linking/workspaces/{workspace}/profile', [NetworkLinkingController::class, 'updateProfile']);
            Route::post('/network-linking/workspaces/{workspace}/permissions/request', [NetworkLinkingController::class, 'requestPermission']);
            Route::post('/network-linking/permissions/{permission}/approve', [NetworkLinkingController::class, 'approvePermission']);
            Route::post('/network-linking/permissions/{permission}/revoke', [NetworkLinkingController::class, 'revokePermission']);
        });
        Route::middleware(['ensure.feature.enabled:content_network_analysis'])->group(function () {
            Route::get('/content-network', [AppContentNetworkController::class, 'index']);
            Route::post('/content-network/{workspace}/run', [AppContentNetworkController::class, 'run'])
                ->middleware('ensure.feature:content_network_analysis_enabled');
        });
        Route::get('/settings', [AppSettingsController::class, 'index']);
        Route::get('/developer', [AppDeveloperController::class, 'index']);
        Route::get('/developer/api', [AppDeveloperController::class, 'api']);
        Route::get('/developer/webhooks', [AppDeveloperController::class, 'webhooks']);
        Route::get('/developer/docs', [AppDeveloperController::class, 'docs']);
        Route::post('/developer/destinations', [AppDeveloperController::class, 'storeDestination']);
        Route::post('/developer/destinations/{destination}', [AppDeveloperController::class, 'updateDestination']);
        Route::post('/developer/api-keys', [AppDeveloperController::class, 'storeApiKey']);
        Route::post('/developer/api-keys/{apiKey}/revoke', [AppDeveloperController::class, 'revokeApiKey']);
        Route::post('/developer/webhooks', [AppDeveloperController::class, 'storeWebhook']);
        Route::post('/developer/webhooks/{webhook}', [AppDeveloperController::class, 'updateWebhook']);
        Route::delete('/developer/webhooks/{webhook}', [AppDeveloperController::class, 'destroyWebhook']);
        Route::get('/billing', [AppBillingController::class, 'index']);
        Route::post('/billing/profile', [AppBillingController::class, 'updateBillingProfile']);
        Route::post('/billing/subscription/start', [AppBillingController::class, 'startSubscription']);
        Route::post('/billing/subscription/change-plan', [AppBillingController::class, 'changePlan']);
        Route::post('/billing/packs/purchase', [AppBillingController::class, 'purchasePack']);
        Route::get('/billing/invoices/{invoice}/download', [AppInvoiceController::class, 'download']);
        Route::post('/settings/organization', [AppSettingsController::class, 'updateOrganization']);
        Route::post('/settings/workspace-name', [AppSettingsController::class, 'updateWorkspaceName']);
        Route::post('/settings/notifications', [AppSettingsController::class, 'updateNotifications']);
        Route::post('/settings/invites', [AppSettingsController::class, 'invite']);
        Route::post('/settings/company-profile', [AppSettingsController::class, 'upsertCompanyProfile']);
        Route::post('/settings/brand-voices', [AppSettingsController::class, 'storeBrandVoice']);
        Route::post('/settings/brand-voices/{brandVoice}', [AppSettingsController::class, 'updateBrandVoice']);
        Route::post('/settings/brand-voices/{brandVoice}/default', [AppSettingsController::class, 'setDefaultBrandVoice']);
        Route::delete('/settings/brand-voices/{brandVoice}', [AppSettingsController::class, 'deleteBrandVoice']);

        // Brand section - Company Profile and Brand Voices
        Route::get('/brand/company-profile', [AppBrandController::class, 'companyProfile']);
        Route::post('/brand/company-profile', [AppSettingsController::class, 'upsertCompanyProfile']);
        Route::get('/brand/voices', [AppBrandController::class, 'voices']);
        Route::post('/brand/voices', [AppSettingsController::class, 'storeBrandVoice']);
        Route::post('/brand/voices/{brandVoice}', [AppSettingsController::class, 'updateBrandVoice']);
        Route::post('/brand/voices/{brandVoice}/default', [AppSettingsController::class, 'setDefaultBrandVoice']);
        Route::delete('/brand/voices/{brandVoice}', [AppSettingsController::class, 'deleteBrandVoice']);

        // Backwards compatibility: redirect old settings brand URLs to new brand section
        Route::get('/settings/company-profile', fn () => redirect('/app/brand/company-profile', 301));
        Route::get('/settings/brand-voices', fn () => redirect('/app/brand/voices', 301));
        Route::match(['GET', 'POST'], '/settings/api', fn () => redirect('/app/developer/api'));
        Route::match(['GET', 'POST'], '/settings/api/regenerate', fn () => redirect('/app/developer/api'));
    });
