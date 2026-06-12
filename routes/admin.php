<?php

/*
|--------------------------------------------------------------------------
| Admin Subdomain Routes
|--------------------------------------------------------------------------
|
| These routes are loaded on admin.argusly.local (dev) or
| admin.argusly.com (production). They serve the admin panel.
|
| Routes are served WITHOUT the /admin prefix since we're on a subdomain.
|
*/

use App\Http\Controllers\Admin\AdminBillingController;
use App\Http\Controllers\Admin\AdminAnalyticsController;
use App\Http\Controllers\Admin\AdminAnnouncementsController;
use App\Http\Controllers\Admin\AdminAgentRunController;
use App\Http\Controllers\Admin\AdminAgenticActionRunController;
use App\Http\Controllers\Admin\AdminBrandProfileController;
use App\Http\Controllers\Admin\AdminBriefsController;
use App\Http\Controllers\Admin\AdminCampaignController;
use App\Http\Controllers\Admin\AdminContactSubmissionsController;
use App\Http\Controllers\Admin\AdminCompanyIntelligenceController;
use App\Http\Controllers\Admin\AdminContentQualityController;
use App\Http\Controllers\Admin\AdminContentPolicyController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminDraftsController;
use App\Http\Controllers\Admin\AdminEarlyAccessController;
use App\Http\Controllers\Admin\AdminEditorialTaxonomyController;
use App\Http\Controllers\Admin\AdminFeatureFlagController;
use App\Http\Controllers\Admin\AdminInvoiceController;
use App\Http\Controllers\Admin\AdminLlmController;
use App\Http\Controllers\Admin\AdminNotificationsController;
use App\Http\Controllers\Admin\AdminOrganizationsController;
use App\Http\Controllers\Admin\AdminProductUpdatesController;
use App\Http\Controllers\Admin\AdminQueryIntentController;
use App\Http\Controllers\Admin\AdminQueueController;
use App\Http\Controllers\Admin\AdminSitesController;
use App\Http\Controllers\Admin\AdminSystemHealthController;
use App\Http\Controllers\Admin\AdminUserAccessOverrideController;
use App\Http\Controllers\Admin\AdminUsersController;
use App\Http\Controllers\Admin\AdminWebhookController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Impersonation\StopImpersonationController;
use App\Http\Controllers\SearchController;
use App\Support\DomainHelper;
use Illuminate\Support\Facades\Route;

Route::middleware('admin.locale')->group(function () {
    // Authentication routes for admin subdomain
    Route::get('/login', [LoginController::class, 'show'])
        ->middleware('guest')
        ->name('admin.login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware(['guest', 'throttle:login'])
        ->name('admin.login.store');
    Route::post('/logout', [LoginController::class, 'destroy'])
        ->middleware('auth')
        ->name('admin.logout');
    Route::post('/impersonation/stop', StopImpersonationController::class)
        ->middleware('auth')
        ->name('admin.impersonation.stop');

    // Admin routes (no /admin prefix - we're on the admin subdomain)
    Route::middleware(['auth', 'admin.area', 'support.context:admin', 'support.readonly'])
        ->group(function () {
        Route::redirect('/', '/dashboard')->name('admin');
        Route::get('/support', [\App\Http\Controllers\Admin\AdminSupportModeController::class, 'index'])->name('admin.support.index');
        Route::post('/support/start', [\App\Http\Controllers\Admin\AdminSupportModeController::class, 'start'])->name('admin.support.start');
        Route::post('/support/stop', [\App\Http\Controllers\Admin\AdminSupportModeController::class, 'stop'])->name('admin.support.stop');
        Route::get('/support/diagnostics', [\App\Http\Controllers\Admin\AdminSupportModeController::class, 'diagnostics'])->name('admin.support.diagnostics');
        Route::get('/support/snapshot', [\App\Http\Controllers\Admin\AdminSupportModeController::class, 'snapshot'])->name('admin.support.snapshot');
        Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
        Route::get('/search', [SearchController::class, 'adminIndex'])->middleware('protect.heavy:search')->name('admin.search');
        Route::get('/search/suggest', [SearchController::class, 'adminSuggest'])->middleware('protect.heavy:search')->name('admin.search.suggest');

        Route::middleware('can:admin-area-superadmin')->group(function () {
            Route::get('/organizations/create', [AdminOrganizationsController::class, 'create'])->name('admin.organizations.create');
            Route::post('/organizations', [AdminOrganizationsController::class, 'store'])->name('admin.organizations.store');
        });

        Route::middleware('can:admin-area-manage-approvals')->group(function () {
            Route::get('/organizations', [AdminOrganizationsController::class, 'index'])->name('admin.organizations');
            Route::get('/organizations/{organization}', [AdminOrganizationsController::class, 'show'])->name('admin.organizations.show');
            Route::post('/organizations/{organization}/approve', [AdminOrganizationsController::class, 'approve'])->name('admin.organizations.approve');
            Route::post('/organizations/{organization}/hold', [AdminOrganizationsController::class, 'hold'])->name('admin.organizations.hold');
            Route::post('/organizations/{organization}/activate', [AdminOrganizationsController::class, 'activate'])->name('admin.organizations.activate');
            Route::post('/organizations/{organization}/archive', [AdminOrganizationsController::class, 'archive'])->name('admin.organizations.archive');
            Route::post('/organizations/{organization}/unarchive', [AdminOrganizationsController::class, 'unarchive'])->name('admin.organizations.unarchive');
            Route::post('/organizations/{organization}/access/grant-early-bird', [AdminOrganizationsController::class, 'grantEarlyBirdAccess'])->name('admin.organizations.access.grant-early-bird');
            Route::post('/organizations/{organization}/access/extend-early-bird', [AdminOrganizationsController::class, 'extendEarlyBirdAccess'])->name('admin.organizations.access.extend-early-bird');
            Route::post('/organizations/{organization}/access/end-early-bird', [AdminOrganizationsController::class, 'endEarlyBirdAccess'])->name('admin.organizations.access.end-early-bird');
            Route::post('/organizations/{organization}/access/convert-to-paid', [AdminOrganizationsController::class, 'convertToPaidAccess'])->name('admin.organizations.access.convert-to-paid');
            Route::post('/organizations/{organization}/impersonate', [AdminOrganizationsController::class, 'impersonateOrganization'])->name('admin.organizations.impersonate');
            Route::post('/workspaces/{workspace}/impersonate', [AdminOrganizationsController::class, 'impersonateWorkspace'])->name('admin.workspaces.impersonate');

            Route::get('/users', [AdminUsersController::class, 'index'])->name('admin.users');
            Route::get('/users/{user}', [AdminUsersController::class, 'show'])->name('admin.users.show');
            Route::post('/users/{user}/approve', [AdminUsersController::class, 'approve'])->name('admin.users.approve');
            Route::post('/users/{user}/disable', [AdminUsersController::class, 'disable'])->name('admin.users.disable');
            Route::post('/users/{user}/activate', [AdminUsersController::class, 'activate'])->name('admin.users.activate');
            Route::post('/users/{user}/password', [AdminUsersController::class, 'updateOwnPassword'])->name('admin.users.password.update');
            Route::post('/users/{user}/access-overrides', [AdminUserAccessOverrideController::class, 'store'])->name('admin.users.access-overrides.store');
            Route::post('/users/{user}/access-overrides/{accessOverride}/extend', [AdminUserAccessOverrideController::class, 'extend'])->name('admin.users.access-overrides.extend');
            Route::post('/users/{user}/access-overrides/{accessOverride}/stop', [AdminUserAccessOverrideController::class, 'stop'])->name('admin.users.access-overrides.stop');
            Route::get('/early-access', [AdminEarlyAccessController::class, 'index'])->name('admin.early-access.index');
            Route::post('/early-access/invite-pilot-user', [AdminEarlyAccessController::class, 'invitePilotUser'])->name('admin.early-access.invite-pilot-user');
            Route::post('/early-access/add-existing-user', [AdminEarlyAccessController::class, 'addExistingUser'])->name('admin.early-access.add-existing-user');
            Route::get('/early-access/{signup}', [AdminEarlyAccessController::class, 'show'])->name('admin.early-access.show');
            Route::post('/early-access/{signup}/review', [AdminEarlyAccessController::class, 'markReviewed'])->name('admin.early-access.review');
            Route::post('/early-access/{signup}/approve', [AdminEarlyAccessController::class, 'approve'])->name('admin.early-access.approve');
            Route::post('/early-access/{signup}/send-invite', [AdminEarlyAccessController::class, 'sendInvite'])->name('admin.early-access.send-invite');
            Route::post('/early-access/{signup}/resend-invite', [AdminEarlyAccessController::class, 'resendInvite'])->name('admin.early-access.resend-invite');
            Route::post('/early-access/{signup}/reject', [AdminEarlyAccessController::class, 'reject'])->name('admin.early-access.reject');
            Route::post('/early-access/{signup}/notes', [AdminEarlyAccessController::class, 'updateNotes'])->name('admin.early-access.notes.update');
            Route::post('/early-access/{signup}/pilot-costs', [AdminEarlyAccessController::class, 'storePilotCost'])->name('admin.early-access.pilot-costs.store');
            Route::delete('/early-access/{signup}/pilot-costs/{cost}', [AdminEarlyAccessController::class, 'destroyPilotCost'])->name('admin.early-access.pilot-costs.destroy');

            Route::get('/system-health', [AdminSystemHealthController::class, 'index'])->name('admin.system-health.index');
            Route::get('/queues', [AdminQueueController::class, 'index'])->name('admin.queues.index');
            Route::get('/queues/failed', [AdminQueueController::class, 'failed'])->name('admin.queues.failed');
            Route::get('/queues/pending/{job}', [AdminQueueController::class, 'showPending'])->name('admin.queues.pending.show');
            Route::delete('/queues/pending/{job}', [AdminQueueController::class, 'destroyPending'])->name('admin.queues.pending.destroy');
            Route::post('/queues/pending/{job}/requeue', [AdminQueueController::class, 'requeuePending'])->name('admin.queues.pending.requeue');
            Route::post('/queues/pending/flush', [AdminQueueController::class, 'flush'])->name('admin.queues.pending.flush');
            Route::post('/queues/maintenance/delete-older', [AdminQueueController::class, 'deleteOlder'])->name('admin.queues.delete-older');
            Route::get('/queues/failed/{failedJob}', [AdminQueueController::class, 'show'])->name('admin.queues.show');
            Route::post('/queues/failed/{failedJob}/retry', [AdminQueueController::class, 'retry'])->middleware('protect.heavy:report')->name('admin.queues.retry');
            Route::post('/queues/failed/retry-all', [AdminQueueController::class, 'retryAll'])->middleware('protect.heavy:report')->name('admin.queues.retry-all');
            Route::delete('/queues/failed/{failedJob}', [AdminQueueController::class, 'destroy'])->name('admin.queues.destroy');
            Route::post('/queues/failed/bulk-delete', [AdminQueueController::class, 'destroyBulk'])->name('admin.queues.destroy-bulk');
            Route::get('/webhooks', [AdminWebhookController::class, 'index'])->name('admin.webhooks.index');

            Route::get('/llm-monitor', [AdminLlmController::class, 'monitor'])->name('admin.llm-monitor.index');
            Route::get('/llm-monitor/{llmRequest}', [AdminLlmController::class, 'monitorShow'])->name('admin.llm-monitor.show');
            Route::get('/llm/monitor', [AdminLlmController::class, 'monitor'])->name('admin.llm.monitor');
            Route::get('/llm/monitor/{llmRequest}', [AdminLlmController::class, 'monitorShow'])->name('admin.llm.monitor.show');
            Route::get('/content-quality', [AdminContentQualityController::class, 'legacy'])->name('admin.content-quality.index');
            Route::match(['GET', 'POST'], '/content-quality/run', [AdminContentQualityController::class, 'legacy'])->middleware('protect.heavy:report')->name('admin.content-quality.run');
            Route::get('/agent-runs', [AdminAgentRunController::class, 'index'])->name('admin.agent-runs.index');
            Route::get('/agentic-action-runs', [AdminAgenticActionRunController::class, 'index'])->name('admin.agentic-action-runs.index');
            Route::get('/campaigns', [AdminCampaignController::class, 'index'])->name('admin.campaigns.index');
            Route::get('/campaigns/{campaign}', [AdminCampaignController::class, 'show'])->name('admin.campaigns.show');

            Route::post('/editorial-taxonomy/sets', [AdminEditorialTaxonomyController::class, 'storeSet'])->name('admin.editorial-taxonomy.sets.store');
            Route::post('/editorial-taxonomy/sets/{set}/update', [AdminEditorialTaxonomyController::class, 'updateSet'])->name('admin.editorial-taxonomy.sets.update');
            Route::delete('/editorial-taxonomy/sets/{set}', [AdminEditorialTaxonomyController::class, 'destroySet'])->name('admin.editorial-taxonomy.sets.destroy');
            Route::post('/editorial-taxonomy/sets/{set}/assignments', [AdminEditorialTaxonomyController::class, 'updateAssignments'])->name('admin.editorial-taxonomy.assignments.update');
            Route::post('/editorial-taxonomy/sets/{set}/items', [AdminEditorialTaxonomyController::class, 'storeItem'])->name('admin.editorial-taxonomy.items.store');
            Route::post('/editorial-taxonomy/sets/{set}/items/{item}/update', [AdminEditorialTaxonomyController::class, 'updateItem'])->name('admin.editorial-taxonomy.items.update');
            Route::delete('/editorial-taxonomy/sets/{set}/items/{item}', [AdminEditorialTaxonomyController::class, 'destroyItem'])->name('admin.editorial-taxonomy.items.destroy');

            Route::get('/brand-profiles', [AdminBrandProfileController::class, 'index'])->name('admin.brand-profiles.index');
            Route::post('/brand-profiles', [AdminBrandProfileController::class, 'store'])->name('admin.brand-profiles.store');
            Route::post('/brand-profiles/{brandProfile}', [AdminBrandProfileController::class, 'update'])->name('admin.brand-profiles.update');
            Route::delete('/brand-profiles/{brandProfile}', [AdminBrandProfileController::class, 'destroy'])->name('admin.brand-profiles.destroy');
            Route::get('/company-intelligence', [AdminCompanyIntelligenceController::class, 'index'])->name('admin.company-intelligence.index');
            Route::get('/query-intent', [AdminQueryIntentController::class, 'index'])->name('admin.query-intent.index');
            Route::post('/query-intent/debug', [AdminQueryIntentController::class, 'debug'])->name('admin.query-intent.debug');

            Route::get('/content-policies', [AdminContentPolicyController::class, 'index'])->name('admin.content-policies.index');
            Route::post('/content-policies', [AdminContentPolicyController::class, 'store'])->name('admin.content-policies.store');
            Route::post('/content-policies/{contentPolicy}', [AdminContentPolicyController::class, 'update'])->name('admin.content-policies.update');
            Route::delete('/content-policies/{contentPolicy}', [AdminContentPolicyController::class, 'destroy'])->name('admin.content-policies.destroy');

            Route::get('/feature-flags', [AdminFeatureFlagController::class, 'index'])->name('admin.feature-flags.index');
            Route::post('/feature-flags', [AdminFeatureFlagController::class, 'store'])->name('admin.feature-flags.store');
            Route::patch('/feature-flags/{featureFlag}', [AdminFeatureFlagController::class, 'update'])->name('admin.feature-flags.update');

            Route::get('/announcements', [AdminAnnouncementsController::class, 'index'])->name('admin.announcements.index');
            Route::get('/announcements/create', [AdminAnnouncementsController::class, 'create'])->name('admin.announcements.create');
            Route::post('/announcements', [AdminAnnouncementsController::class, 'store'])->name('admin.announcements.store');
            Route::get('/product-updates', [AdminProductUpdatesController::class, 'index'])->name('admin.product-updates.index');
            Route::get('/product-updates/create', [AdminProductUpdatesController::class, 'create'])->name('admin.product-updates.create');
            Route::post('/product-updates', [AdminProductUpdatesController::class, 'store'])->name('admin.product-updates.store');
            Route::get('/product-updates/{productUpdate}/edit', [AdminProductUpdatesController::class, 'edit'])->name('admin.product-updates.edit');
            Route::post('/product-updates/{productUpdate}', [AdminProductUpdatesController::class, 'update'])->name('admin.product-updates.update');
            Route::delete('/product-updates/{productUpdate}', [AdminProductUpdatesController::class, 'destroy'])->name('admin.product-updates.destroy');
            Route::get('/notifications', [AdminNotificationsController::class, 'index'])->name('admin.notifications.index');
            Route::post('/notifications/read-all', [AdminNotificationsController::class, 'markAllRead'])->name('admin.notifications.read-all');
            Route::post('/notifications/{notification}/read', [AdminNotificationsController::class, 'markRead'])->name('admin.notifications.read');
            Route::get('/workspaces/{workspace}/notifications', [AdminNotificationsController::class, 'workspace'])->name('admin.workspaces.notifications');
        });

        Route::middleware('can:admin-area-view-sites')->group(function () {
            Route::get('/sites', [AdminSitesController::class, 'index'])->name('admin.sites');
            Route::get('/sites/index', [AdminSitesController::class, 'index'])->name('admin.sites.index');
            Route::get('/dashboard/plugin-releases/{release}/download', [AdminDashboardController::class, 'downloadPluginRelease'])->name('admin.dashboard.plugin-releases.download');
            Route::get('/editorial-taxonomy', [AdminEditorialTaxonomyController::class, 'index'])->name('admin.editorial-taxonomy.index');
        });

        Route::middleware('can:admin-area-manage-billing')->group(function () {
            Route::get('/billing', [AdminBillingController::class, 'index'])->name('admin.billing.index');
            Route::post('/billing/invoice-issuer', [AdminBillingController::class, 'updateInvoiceIssuerProfile'])->name('admin.billing.invoice-issuer.update');
            Route::get('/billing/pricing-page', [AdminBillingController::class, 'pricingPageContent'])->name('admin.billing.pricing-page.index');
            Route::post('/billing/pricing-page', [AdminBillingController::class, 'updatePricingPageContent'])->name('admin.billing.pricing-page.update');
            Route::post('/billing/plans', [AdminBillingController::class, 'storePlan'])->name('admin.billing.plans.store');
            Route::post('/billing/plans/{plan}', [AdminBillingController::class, 'updatePlan'])->name('admin.billing.plans.update');
            Route::post('/billing/plans/{plan}/quota-limits', [AdminBillingController::class, 'updatePlanQuotaLimits'])->name('admin.billing.plans.quota-limits.update');
            Route::post('/billing/plans/{plan}/features', [AdminBillingController::class, 'storePlanFeature'])->name('admin.billing.plans.features.store');
            Route::post('/billing/plans/{plan}/features/{feature}', [AdminBillingController::class, 'updatePlanFeature'])->name('admin.billing.plans.features.update');
            Route::delete('/billing/plans/{plan}/features/{feature}', [AdminBillingController::class, 'destroyPlanFeature'])->name('admin.billing.plans.features.destroy');
            Route::get('/invoices', [AdminInvoiceController::class, 'index'])->name('admin.invoices.index');
            Route::get('/invoices/{invoice}/download', [AdminInvoiceController::class, 'download'])->name('admin.invoices.download');
            Route::get('/invoices/{invoice}/preview', [AdminInvoiceController::class, 'preview'])->name('admin.invoices.preview');
            Route::post('/invoices/{invoice}/refund', [AdminInvoiceController::class, 'markRefunded'])->name('admin.invoices.refund');
            Route::get('/organizations/{organization}/billing', [AdminBillingController::class, 'show'])->name('admin.organizations.billing');
            Route::post('/organizations/{organization}/billing/grant-credits', [AdminBillingController::class, 'grantCredits'])->name('admin.organizations.billing.grant-credits');
            Route::post('/organizations/{organization}/billing/subscription/grant-monthly-credits', [AdminBillingController::class, 'grantMonthlySubscriptionCredits'])->name('admin.organizations.billing.subscription.grant-monthly-credits');
            Route::post('/organizations/{organization}/billing/mandate-recheck', [AdminBillingController::class, 'triggerMandateRecheck'])->name('admin.organizations.billing.mandate-recheck');
            Route::post('/organizations/{organization}/billing/renewal-retry', [AdminBillingController::class, 'triggerRenewalRetry'])->name('admin.organizations.billing.renewal-retry');
            Route::post('/organizations/{organization}/billing/subscription/force-cancel', [AdminBillingController::class, 'forceCancelSubscription'])->name('admin.organizations.billing.subscription.force-cancel');
            // Credit Reservations Management
            Route::get('/credit-reservations', [\App\Http\Controllers\Admin\AdminCreditReservationsController::class, 'index'])->name('admin.credit-reservations.index');
            Route::get('/credit-reservations/{reservation}', [\App\Http\Controllers\Admin\AdminCreditReservationsController::class, 'show'])->name('admin.credit-reservations.show');
            Route::post('/credit-reservations/{reservation}/release', [\App\Http\Controllers\Admin\AdminCreditReservationsController::class, 'release'])->name('admin.credit-reservations.release');
            Route::post('/credit-reservations/{reservation}/capture', [\App\Http\Controllers\Admin\AdminCreditReservationsController::class, 'capture'])->name('admin.credit-reservations.capture');
            Route::post('/credit-reservations/bulk-release', [\App\Http\Controllers\Admin\AdminCreditReservationsController::class, 'bulkRelease'])->name('admin.credit-reservations.bulk-release');
            Route::post('/credit-reservations/expire-stale', [\App\Http\Controllers\Admin\AdminCreditReservationsController::class, 'expireStale'])->name('admin.credit-reservations.expire-stale');
        });

        Route::middleware('can:admin-area-superadmin')->group(function () {
            Route::get('/analytics', [AdminAnalyticsController::class, 'index'])->name('admin.analytics.index');
            Route::post('/analytics', [AdminAnalyticsController::class, 'update'])->name('admin.analytics.update');

            Route::get('/organizations/{organization}/delete', [AdminOrganizationsController::class, 'confirmDelete'])->name('admin.organizations.confirm-delete');
            Route::delete('/organizations/{organization}', [AdminOrganizationsController::class, 'delete'])->name('admin.organizations.delete');
            Route::post('/organizations/{organization}/update', [AdminOrganizationsController::class, 'update'])->name('admin.organizations.update');
            Route::post('/organizations/{organization}/legal-profile', [AdminOrganizationsController::class, 'updateLegalProfile'])->name('admin.organizations.legal-profile.update');
            Route::post('/organizations/{organization}/workspaces/{workspace}/display-name', [AdminOrganizationsController::class, 'updateWorkspaceDisplayName'])->name('admin.organizations.workspaces.display-name.update');
            Route::post('/organizations/{organization}/api-key/regenerate', [AdminOrganizationsController::class, 'regenerateApiKey'])->middleware('protect.heavy:heavy')->name('admin.organizations.api-key.regenerate');
            Route::post('/users/{user}/update', [AdminUsersController::class, 'update'])->name('admin.users.update');
            Route::post('/users/{user}/role', [AdminUsersController::class, 'setRole'])->name('admin.users.role.update');
            Route::get('/contact-submissions', [AdminContactSubmissionsController::class, 'index'])->name('admin.contact-submissions');
            Route::post('/contact-submissions/{submission}/resend', [AdminContactSubmissionsController::class, 'resend'])->name('admin.contact-submissions.resend');
            Route::get('/llm/settings', [AdminLlmController::class, 'settings'])->name('admin.llm.settings');
            Route::post('/llm/settings/global', [AdminLlmController::class, 'updateGlobalSettings'])->name('admin.llm.settings.global.update');
            Route::post('/llm/settings/rules', [AdminLlmController::class, 'upsertRule'])->name('admin.llm.settings.rules.upsert');
            Route::delete('/llm/settings/rules/{rule}', [AdminLlmController::class, 'deleteRule'])->name('admin.llm.settings.rules.delete');
            Route::post('/llm/settings/test-connection', [AdminLlmController::class, 'testConnection'])->middleware('protect.heavy:ai')->name('admin.llm.settings.test-connection');
            Route::get('/briefs', [AdminBriefsController::class, 'index'])->name('admin.briefs');
            Route::delete('/briefs/{brief}', [AdminBriefsController::class, 'destroy'])->name('admin.briefs.destroy');
            Route::get('/drafts', [AdminDraftsController::class, 'index'])->name('admin.drafts');
            Route::delete('/drafts/{draft}', [AdminDraftsController::class, 'destroy'])->name('admin.drafts.destroy');
            Route::post('/queues/translations/repair-stale-locks', [AdminQueueController::class, 'repairStaleTranslationLocks'])->name('admin.queues.translations.repair-stale-locks');
            Route::post('/queues/translations/{translation}/release-lock', [AdminQueueController::class, 'releaseTranslationLock'])->name('admin.queues.translations.release-lock');
            Route::post('/queues/translations/{translation}/mark-failed', [AdminQueueController::class, 'markTranslationFailed'])->name('admin.queues.translations.mark-failed');
            Route::post('/queues/translations/{translation}/retry', [AdminQueueController::class, 'retryTranslation'])->name('admin.queues.translations.retry');
            Route::post('/queues/translations/{translation}/failed-job/retry', [AdminQueueController::class, 'retryTranslationFailedJob'])->name('admin.queues.translations.failed-job.retry');
            Route::delete('/queues/translations/{translation}/failed-job', [AdminQueueController::class, 'deleteTranslationFailedJob'])->name('admin.queues.translations.failed-job.delete');
            Route::post('/queues/translations/{translation}/force-reset-and-retry', [AdminQueueController::class, 'forceResetAndRetryTranslation'])->name('admin.queues.translations.force-reset-and-retry');
            Route::post('/dashboard/plugin-releases', [AdminDashboardController::class, 'storePluginRelease'])->name('admin.dashboard.plugin-releases.store');
            Route::delete('/dashboard/plugin-releases/{release}', [AdminDashboardController::class, 'destroyPluginRelease'])->name('admin.dashboard.plugin-releases.destroy');
            Route::get('/dashboard/upload-diagnostics', [AdminDashboardController::class, 'uploadDiagnostics'])->name('admin.dashboard.upload-diagnostics');
        });
        });

    // Backwards compatibility: redirect legacy /admin/* paths to root
    Route::get('/admin/{any?}', fn (string $any = '') => redirect("/{$any}", 301))->where('any', '.*');
});
