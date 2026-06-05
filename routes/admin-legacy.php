<?php

/*
|--------------------------------------------------------------------------
| Legacy Admin Routes (Backwards Compatibility)
|--------------------------------------------------------------------------
|
| These routes provide backwards compatibility for the /admin/* path structure.
| They will be deprecated once all consumers migrate to the admin subdomain.
|
| DO NOT add route names to these routes - use routes/admin.php for that.
|
*/

use App\Http\Controllers\Admin\AdminBillingController;
use App\Http\Controllers\Admin\AdminAnnouncementsController;
use App\Http\Controllers\Admin\AdminBrandProfileController;
use App\Http\Controllers\Admin\AdminBriefsController;
use App\Http\Controllers\Admin\AdminContactSubmissionsController;
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
use App\Http\Controllers\Admin\AdminQueueController;
use App\Http\Controllers\Admin\AdminSitesController;
use App\Http\Controllers\Admin\AdminSystemHealthController;
use App\Http\Controllers\Admin\AdminUsersController;
use App\Http\Controllers\Admin\AdminWebhookController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

// Admin routes with auth middleware
Route::middleware(['admin.locale', 'auth', 'admin.area', 'support.context:admin', 'support.readonly'])
    ->group(function () {
        Route::redirect('/', '/admin/dashboard');
        Route::get('/support', [\App\Http\Controllers\Admin\AdminSupportModeController::class, 'index']);
        Route::post('/support/start', [\App\Http\Controllers\Admin\AdminSupportModeController::class, 'start']);
        Route::post('/support/stop', [\App\Http\Controllers\Admin\AdminSupportModeController::class, 'stop']);
        Route::get('/support/diagnostics', [\App\Http\Controllers\Admin\AdminSupportModeController::class, 'diagnostics']);
        Route::get('/support/snapshot', [\App\Http\Controllers\Admin\AdminSupportModeController::class, 'snapshot']);
        Route::get('/dashboard', [AdminDashboardController::class, 'index']);
        Route::get('/search', [SearchController::class, 'adminIndex'])->middleware('protect.heavy:search');
        Route::get('/search/suggest', [SearchController::class, 'adminSuggest'])->middleware('protect.heavy:search');

        Route::middleware('can:admin-area-manage-approvals')->group(function () {
            Route::get('/organizations', [AdminOrganizationsController::class, 'index']);
            Route::get('/organizations/{organization}', [AdminOrganizationsController::class, 'show']);
            Route::post('/organizations/{organization}/approve', [AdminOrganizationsController::class, 'approve']);
            Route::post('/organizations/{organization}/hold', [AdminOrganizationsController::class, 'hold']);
            Route::post('/organizations/{organization}/activate', [AdminOrganizationsController::class, 'activate']);

            Route::get('/users', [AdminUsersController::class, 'index']);
            Route::post('/users/{user}/approve', [AdminUsersController::class, 'approve']);
            Route::post('/users/{user}/disable', [AdminUsersController::class, 'disable']);
            Route::post('/users/{user}/activate', [AdminUsersController::class, 'activate']);
            Route::get('/early-access', [AdminEarlyAccessController::class, 'index']);
            Route::get('/early-access/{signup}', [AdminEarlyAccessController::class, 'show']);
            Route::post('/early-access/{signup}/review', [AdminEarlyAccessController::class, 'markReviewed']);
            Route::post('/early-access/{signup}/approve', [AdminEarlyAccessController::class, 'approve']);
            Route::post('/early-access/{signup}/send-invite', [AdminEarlyAccessController::class, 'sendInvite']);
            Route::post('/early-access/{signup}/resend-invite', [AdminEarlyAccessController::class, 'resendInvite']);
            Route::post('/early-access/{signup}/reject', [AdminEarlyAccessController::class, 'reject']);
            Route::post('/early-access/{signup}/notes', [AdminEarlyAccessController::class, 'updateNotes']);
            Route::post('/early-access/{signup}/pilot-costs', [AdminEarlyAccessController::class, 'storePilotCost']);
            Route::delete('/early-access/{signup}/pilot-costs/{cost}', [AdminEarlyAccessController::class, 'destroyPilotCost']);

            Route::get('/system-health', [AdminSystemHealthController::class, 'index']);
            Route::get('/queues', [AdminQueueController::class, 'index']);
            Route::get('/queues/failed', [AdminQueueController::class, 'failed']);
            Route::get('/queues/failed/{failedJob}', [AdminQueueController::class, 'show']);
            Route::post('/queues/failed/{failedJob}/retry', [AdminQueueController::class, 'retry'])->middleware('protect.heavy:report');
            Route::delete('/queues/failed/{failedJob}', [AdminQueueController::class, 'destroy']);
            Route::get('/webhooks', [AdminWebhookController::class, 'index']);

            Route::get('/llm-monitor', [AdminLlmController::class, 'monitor']);
            Route::get('/llm-monitor/{llmRequest}', [AdminLlmController::class, 'monitorShow']);
            Route::get('/llm/monitor', [AdminLlmController::class, 'monitor']);
            Route::get('/llm/monitor/{llmRequest}', [AdminLlmController::class, 'monitorShow']);
            Route::post('/editorial-taxonomy/sets', [AdminEditorialTaxonomyController::class, 'storeSet']);
            Route::post('/editorial-taxonomy/sets/{set}/update', [AdminEditorialTaxonomyController::class, 'updateSet']);
            Route::delete('/editorial-taxonomy/sets/{set}', [AdminEditorialTaxonomyController::class, 'destroySet']);
            Route::post('/editorial-taxonomy/sets/{set}/assignments', [AdminEditorialTaxonomyController::class, 'updateAssignments']);
            Route::post('/editorial-taxonomy/sets/{set}/items', [AdminEditorialTaxonomyController::class, 'storeItem']);
            Route::post('/editorial-taxonomy/sets/{set}/items/{item}/update', [AdminEditorialTaxonomyController::class, 'updateItem']);
            Route::delete('/editorial-taxonomy/sets/{set}/items/{item}', [AdminEditorialTaxonomyController::class, 'destroyItem']);

            Route::get('/brand-profiles', [AdminBrandProfileController::class, 'index']);
            Route::post('/brand-profiles', [AdminBrandProfileController::class, 'store']);
            Route::post('/brand-profiles/{brandProfile}', [AdminBrandProfileController::class, 'update']);
            Route::delete('/brand-profiles/{brandProfile}', [AdminBrandProfileController::class, 'destroy']);

            Route::get('/content-policies', [AdminContentPolicyController::class, 'index']);
            Route::post('/content-policies', [AdminContentPolicyController::class, 'store']);
            Route::post('/content-policies/{contentPolicy}', [AdminContentPolicyController::class, 'update']);
            Route::delete('/content-policies/{contentPolicy}', [AdminContentPolicyController::class, 'destroy']);

            Route::get('/feature-flags', [AdminFeatureFlagController::class, 'index']);
            Route::post('/feature-flags', [AdminFeatureFlagController::class, 'store']);
            Route::patch('/feature-flags/{featureFlag}', [AdminFeatureFlagController::class, 'update']);

            Route::get('/announcements', [AdminAnnouncementsController::class, 'index']);
            Route::get('/announcements/create', [AdminAnnouncementsController::class, 'create']);
            Route::post('/announcements', [AdminAnnouncementsController::class, 'store']);
            Route::get('/product-updates', [AdminProductUpdatesController::class, 'index']);
            Route::get('/product-updates/create', [AdminProductUpdatesController::class, 'create']);
            Route::post('/product-updates', [AdminProductUpdatesController::class, 'store']);
            Route::get('/product-updates/{productUpdate}/edit', [AdminProductUpdatesController::class, 'edit']);
            Route::post('/product-updates/{productUpdate}', [AdminProductUpdatesController::class, 'update']);
            Route::delete('/product-updates/{productUpdate}', [AdminProductUpdatesController::class, 'destroy']);
            Route::get('/notifications', [AdminNotificationsController::class, 'index']);
            Route::post('/notifications/read-all', [AdminNotificationsController::class, 'markAllRead']);
            Route::post('/notifications/{notification}/read', [AdminNotificationsController::class, 'markRead']);
            Route::get('/workspaces/{workspace}/notifications', [AdminNotificationsController::class, 'workspace']);
        });

        Route::middleware('can:admin-area-view-sites')->group(function () {
            Route::get('/sites', [AdminSitesController::class, 'index']);
            Route::get('/sites/index', [AdminSitesController::class, 'index']);
            Route::get('/editorial-taxonomy', [AdminEditorialTaxonomyController::class, 'index']);
        });

        Route::middleware('can:admin-area-manage-billing')->group(function () {
            Route::get('/billing', [AdminBillingController::class, 'index']);
            Route::post('/billing/invoice-issuer', [AdminBillingController::class, 'updateInvoiceIssuerProfile']);
            Route::post('/billing/plans', [AdminBillingController::class, 'storePlan']);
            Route::post('/billing/plans/{plan}', [AdminBillingController::class, 'updatePlan']);
            Route::post('/billing/plans/{plan}/quota-limits', [AdminBillingController::class, 'updatePlanQuotaLimits']);
            Route::post('/billing/plans/{plan}/features', [AdminBillingController::class, 'storePlanFeature']);
            Route::post('/billing/plans/{plan}/features/{feature}', [AdminBillingController::class, 'updatePlanFeature']);
            Route::delete('/billing/plans/{plan}/features/{feature}', [AdminBillingController::class, 'destroyPlanFeature']);
            Route::get('/invoices', [AdminInvoiceController::class, 'index']);
            Route::get('/invoices/{invoice}/download', [AdminInvoiceController::class, 'download']);
            Route::get('/invoices/{invoice}/preview', [AdminInvoiceController::class, 'preview']);
            Route::post('/invoices/{invoice}/refund', [AdminInvoiceController::class, 'markRefunded']);
            Route::get('/organizations/{organization}/billing', [AdminBillingController::class, 'show']);
            Route::post('/organizations/{organization}/billing/grant-credits', [AdminBillingController::class, 'grantCredits']);
            Route::post('/organizations/{organization}/billing/subscription/grant-monthly-credits', [AdminBillingController::class, 'grantMonthlySubscriptionCredits']);
            Route::post('/organizations/{organization}/billing/mandate-recheck', [AdminBillingController::class, 'triggerMandateRecheck']);
            Route::post('/organizations/{organization}/billing/renewal-retry', [AdminBillingController::class, 'triggerRenewalRetry']);
            Route::post('/organizations/{organization}/billing/subscription/force-cancel', [AdminBillingController::class, 'forceCancelSubscription']);

            // Credit Reservations Management
            Route::get('/credit-reservations', [\App\Http\Controllers\Admin\AdminCreditReservationsController::class, 'index']);
            Route::get('/credit-reservations/{reservation}', [\App\Http\Controllers\Admin\AdminCreditReservationsController::class, 'show']);
            Route::post('/credit-reservations/{reservation}/release', [\App\Http\Controllers\Admin\AdminCreditReservationsController::class, 'release']);
            Route::post('/credit-reservations/{reservation}/capture', [\App\Http\Controllers\Admin\AdminCreditReservationsController::class, 'capture']);
            Route::post('/credit-reservations/bulk-release', [\App\Http\Controllers\Admin\AdminCreditReservationsController::class, 'bulkRelease']);
            Route::post('/credit-reservations/expire-stale', [\App\Http\Controllers\Admin\AdminCreditReservationsController::class, 'expireStale']);
        });

        Route::middleware('can:admin-area-superadmin')->group(function () {
            Route::post('/organizations/{organization}/update', [AdminOrganizationsController::class, 'update']);
            Route::post('/organizations/{organization}/legal-profile', [AdminOrganizationsController::class, 'updateLegalProfile']);
            Route::post('/organizations/{organization}/workspaces/{workspace}/display-name', [AdminOrganizationsController::class, 'updateWorkspaceDisplayName']);
            Route::post('/organizations/{organization}/api-key/regenerate', [AdminOrganizationsController::class, 'regenerateApiKey'])->middleware('protect.heavy:heavy');
            Route::post('/workspaces/{workspace}/impersonate', [AdminOrganizationsController::class, 'impersonateWorkspace']);
            Route::post('/users/{user}/update', [AdminUsersController::class, 'update']);
            Route::post('/users/{user}/role', [AdminUsersController::class, 'setRole']);
            Route::get('/contact-submissions', [AdminContactSubmissionsController::class, 'index']);
            Route::post('/contact-submissions/{submission}/resend', [AdminContactSubmissionsController::class, 'resend']);
            Route::get('/llm/settings', [AdminLlmController::class, 'settings']);
            Route::post('/llm/settings/global', [AdminLlmController::class, 'updateGlobalSettings']);
            Route::post('/llm/settings/rules', [AdminLlmController::class, 'upsertRule']);
            Route::delete('/llm/settings/rules/{rule}', [AdminLlmController::class, 'deleteRule']);
            Route::post('/llm/settings/test-connection', [AdminLlmController::class, 'testConnection'])->middleware('protect.heavy:ai');
            Route::get('/briefs', [AdminBriefsController::class, 'index']);
            Route::delete('/briefs/{brief}', [AdminBriefsController::class, 'destroy']);
            Route::get('/drafts', [AdminDraftsController::class, 'index']);
            Route::delete('/drafts/{draft}', [AdminDraftsController::class, 'destroy']);
        });
    });
