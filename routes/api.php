<?php

use App\Http\Controllers\Api\ConnectorHeartbeatController;
use App\Http\Controllers\Api\Plugin\CheckUpdateController;
use App\Http\Controllers\Api\Plugin\DownloadPluginController;
use App\Http\Controllers\Api\Plugin\RegisterDomainController;
use App\Http\Controllers\Api\SiteMarkdownController;
use App\Http\Controllers\Api\V1\Admin\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Api\V1\Admin\WorkspaceController as AdminWorkspaceController;
use App\Http\Controllers\Api\V1\AiDisclosureController;
use App\Http\Controllers\Api\V1\Auth\ClientWebhookController;
use App\Http\Controllers\Api\V1\Auth\SiteTokenController;
use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\BriefController;
use App\Http\Controllers\Api\V1\BriefTestDraftController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\ConnectorContentController;
use App\Http\Controllers\Api\V1\ContentAnswerController;
use App\Http\Controllers\Api\V1\ContentDeletionController;
use App\Http\Controllers\Api\V1\CreditsController;
use App\Http\Controllers\Api\V1\DraftController;
use App\Http\Controllers\Api\V1\EmailCampaignExportController;
use App\Http\Controllers\Api\V1\EmailMarketingConnectionController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\GenerationOptionsController;
use App\Http\Controllers\Api\V1\HumanSignalController;
use App\Http\Controllers\Api\V1\Headless\AnalyticsIngestController;
use App\Http\Controllers\Api\V1\Headless\ApiKeyController as HeadlessApiKeyController;
use App\Http\Controllers\Api\V1\Headless\DestinationController as HeadlessDestinationController;
use App\Http\Controllers\Api\V1\Headless\IdentityController as HeadlessIdentityController;
use App\Http\Controllers\Api\V1\Headless\OperationController as HeadlessOperationController;
use App\Http\Controllers\Api\V1\Headless\SeoAuditController as HeadlessSeoAuditController;
use App\Http\Controllers\Api\V1\Headless\WebhookController as HeadlessWebhookController;
use App\Http\Controllers\Api\V1\ImageController;
use App\Http\Controllers\Api\V1\RecommendedActionController;
use App\Http\Controllers\Api\V1\TaxonomyController;
use App\Http\Controllers\Webhooks\MollieWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Public webhooks need their own limiter so upstream retries are tolerated
    // without sharing the generic API bucket with client traffic.
    Route::post('webhooks/mollie', [MollieWebhookController::class, 'handle'])
        ->withoutMiddleware(['throttle:api'])
        ->middleware('throttle:webhook-public')
        ->name('webhooks.mollie');

    // Admin
    Route::middleware(['admin.key'])->group(function () {
        Route::post('/auth/site-tokens', [SiteTokenController::class, 'store']);
        Route::post('/auth/site-tokens/{id}/revoke', [SiteTokenController::class, 'revoke']);
        Route::post('/auth/site-tokens/{id}/rotate', [SiteTokenController::class, 'rotate']);
        Route::patch('/admin/workspaces/{workspace}', [AdminWorkspaceController::class, 'update']);
        Route::patch('/admin/organizations/{organization}/legal-fields', [AdminOrganizationController::class, 'updateLegalFields']);

        Route::post('/clients/webhooks', [ClientWebhookController::class, 'store']);
    });

    // Integration token protected (site tokens + workspace API keys)
    Route::middleware(['integration.token', 'throttle:integration-api', 'client.domain', 'integration.log'])->group(function () {
        Route::get('/me', [HeadlessIdentityController::class, 'me'])
            ->middleware('integration.scope:usage:read');
        Route::get('/usage', [HeadlessIdentityController::class, 'usage'])
            ->middleware('integration.scope:usage:read');

        Route::get('/destinations', [HeadlessDestinationController::class, 'index'])
            ->middleware('integration.scope:destinations:read');
        Route::post('/destinations', [HeadlessDestinationController::class, 'store'])
            ->middleware('integration.scope:destinations:write');
        Route::get('/destinations/{destination}', [HeadlessDestinationController::class, 'show'])
            ->middleware('integration.scope:destinations:read');
        Route::patch('/destinations/{destination}', [HeadlessDestinationController::class, 'update'])
            ->middleware('integration.scope:destinations:write');

        Route::get('/api-keys', [HeadlessApiKeyController::class, 'index'])
            ->middleware('integration.scope:api_keys:read');
        Route::post('/api-keys', [HeadlessApiKeyController::class, 'store'])
            ->middleware('integration.scope:api_keys:write');
        Route::delete('/api-keys/{apiKey}', [HeadlessApiKeyController::class, 'destroy'])
            ->middleware('integration.scope:api_keys:write');
        Route::post('/api-keys/{apiKey}/revoke', [HeadlessApiKeyController::class, 'revoke'])
            ->middleware('integration.scope:api_keys:write');

        Route::get('/webhooks', [HeadlessWebhookController::class, 'index'])
            ->middleware('integration.scope:webhooks:read');
        Route::post('/webhooks', [HeadlessWebhookController::class, 'store'])
            ->middleware('integration.scope:webhooks:write');
        Route::patch('/webhooks/{webhook}', [HeadlessWebhookController::class, 'update'])
            ->middleware('integration.scope:webhooks:write');
        Route::delete('/webhooks/{webhook}', [HeadlessWebhookController::class, 'destroy'])
            ->middleware('integration.scope:webhooks:write');
        Route::get('/webhooks/events', [HeadlessWebhookController::class, 'events'])
            ->middleware('integration.scope:webhooks:read');
        Route::get('/webhooks/events/{event}', [HeadlessWebhookController::class, 'eventSample'])
            ->middleware('integration.scope:webhooks:read')
            ->where('event', '[a-z_]+\\.[a-z_]+(\\.[a-z_]+)?');

        Route::post('/briefs', [BriefController::class, 'store']);
        Route::get('/articles', [ArticleController::class, 'index']);
        Route::get('/articles/{id}', [ArticleController::class, 'show']);
        Route::get('/articles/{id}/drafts', [ArticleController::class, 'drafts']);
        Route::get('/articles/{id}/publications', [ArticleController::class, 'publications']);
        Route::get('/articles/{id}/publications/{publicationId}', [ArticleController::class, 'publication']);
        Route::post('/articles/{id}/publish', [ArticleController::class, 'publish']);
        Route::get('/articles/{id}/publish-status', [ArticleController::class, 'publishStatus']);
        Route::post('/articles/{id}/publications/{publicationId}/verify', [ArticleController::class, 'verifyPublication']);
        Route::get('/campaigns', [CampaignController::class, 'index'])
            ->middleware('integration.scope:content:read');
        Route::get('/campaigns/channels', [CampaignController::class, 'channels'])
            ->middleware('integration.scope:content:read');
        Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])
            ->middleware('integration.scope:content:read');
        Route::get('/email-marketing/connections', [EmailMarketingConnectionController::class, 'index'])
            ->middleware('integration.scope:destinations:read');
        Route::post('/email-marketing/connections', [EmailMarketingConnectionController::class, 'store'])
            ->middleware('integration.scope:destinations:write');
        Route::get('/email-marketing/connections/{connection}', [EmailMarketingConnectionController::class, 'show'])
            ->middleware('integration.scope:destinations:read');
        Route::post('/campaign-contents/{campaignContent}/email-exports', [EmailCampaignExportController::class, 'store'])
            ->middleware('integration.scope:content:publish');
        Route::get('/email-campaign-exports/{emailCampaignExport}', [EmailCampaignExportController::class, 'show'])
            ->middleware('integration.scope:content:read');
        Route::post('/email-campaign-exports/{emailCampaignExport}/metrics', [EmailCampaignExportController::class, 'metrics'])
            ->middleware('integration.scope:analytics:write');
        Route::get('/recommended-actions', [RecommendedActionController::class, 'index'])
            ->middleware('integration.scope:content:read');
        Route::get('/recommended-actions/{action}', [RecommendedActionController::class, 'show'])
            ->middleware('integration.scope:content:read');
        Route::get('/human-signals', [HumanSignalController::class, 'index'])
            ->middleware('integration.scope:content:read');
        Route::get('/human-signals/{id}', [HumanSignalController::class, 'show'])
            ->middleware('integration.scope:content:read');
        Route::post('/human-signals/detect', [HumanSignalController::class, 'detect'])
            ->middleware('integration.scope:content:write');
        Route::post('/human-signals/{id}/generate-content', [HumanSignalController::class, 'generateContent'])
            ->middleware('integration.scope:content:read');
        Route::post('/human-signals/{id}/create-opportunity', [HumanSignalController::class, 'createOpportunity'])
            ->middleware('integration.scope:content:write');
        Route::delete('/content/bulk', [ContentDeletionController::class, 'bulkDestroy']);
        Route::delete('/content/{id}', [ContentDeletionController::class, 'destroy']);
        Route::post('/content/{id}/restore', [ContentDeletionController::class, 'restore']);
        Route::get('/content/{id}/answers', [ContentAnswerController::class, 'show']);
        Route::get('/content/{id}/ai-disclosure', [AiDisclosureController::class, 'disclosure'])
            ->middleware('integration.scope:content:read')
            ->name('api.v1.content.ai-disclosure');
        Route::get('/content/{id}/provenance', [AiDisclosureController::class, 'provenance'])
            ->middleware('integration.scope:content:read')
            ->name('api.v1.content.ai-provenance');
        Route::get('/content/{id}/audit-report', [AiDisclosureController::class, 'auditReport'])
            ->middleware('integration.scope:content:read')
            ->name('api.v1.content.ai-audit-report');
        Route::get('/briefs', [BriefController::class, 'index']);
        Route::get('/briefs/{id}', [BriefController::class, 'show']);
        Route::patch('/briefs/{id}', [BriefController::class, 'update']);
        Route::post('/briefs/{id}/generate-draft', [BriefController::class, 'generateDraft'])->middleware('protect.heavy:ai');
        Route::post('/briefs/{id}/test-draft', [BriefTestDraftController::class, 'send'])->middleware('protect.heavy:ai');
        Route::get('/taxonomy/intents', [TaxonomyController::class, 'intents']);
        Route::get('/taxonomy/audiences', [TaxonomyController::class, 'audiences']);
        Route::get('/generation/options', [GenerationOptionsController::class, 'index']);
        Route::get('/credits', [CreditsController::class, 'show']);
        Route::get('/credits/quote', [CreditsController::class, 'quote']);

        Route::get('/drafts', [DraftController::class, 'index']);
        Route::get('/drafts/{id}', [DraftController::class, 'show']);
        Route::post('/drafts/{id}/analyze', [DraftController::class, 'analyze'])->middleware('protect.heavy:ai');
        Route::get('/drafts/{id}/analysis', [DraftController::class, 'analysis']);
        Route::post('/drafts/{id}/generate', [DraftController::class, 'generate'])->middleware('protect.heavy:ai')->name('api.v1.drafts.generate');
        Route::post('/drafts/{id}/regenerate', [DraftController::class, 'regenerate'])->middleware('protect.heavy:ai');
        Route::post('/drafts/{id}/translate', [DraftController::class, 'translate']);
        Route::get('/drafts/{id}/export', [DraftController::class, 'export'])->middleware('protect.heavy:export');
        Route::post('/drafts/{id}/ack', [DraftController::class, 'ack'])->name('api.v1.drafts.ack');
        Route::post('/drafts/{id}/feedback', [DraftController::class, 'feedback']);
        Route::post('/images/generate', [ImageController::class, 'generate'])->middleware('protect.heavy:ai')->name('api.v1.images.generate');
        Route::post('/seo-audits', [HeadlessSeoAuditController::class, 'store'])
            ->middleware('protect.heavy:audit')
            ->middleware('integration.scope:seo_audits:write');
        Route::get('/seo-audits/{audit}', [HeadlessSeoAuditController::class, 'show'])
            ->middleware('integration.scope:seo_audits:read');

        Route::get('/operations/{operation}', [HeadlessOperationController::class, 'show'])
            ->middleware('integration.scope:generations:read');

        Route::post('/analytics/events', [AnalyticsIngestController::class, 'store'])
            ->middleware('integration.scope:analytics:write');
        Route::post('/events', [EventController::class, 'store']);

        Route::get('/connectors/content', [ConnectorContentController::class, 'index'])
            ->name('api.v1.connectors.content.index');
        Route::get('/connectors/content/{content}', [ConnectorContentController::class, 'show'])
            ->name('api.v1.connectors.content.show');
        Route::post('/connectors/content/{content}/sync-results', [ConnectorContentController::class, 'syncResults'])
            ->name('api.v1.connectors.content.sync-results');
    });

    Route::prefix('plugin')->middleware(['throttle:30,1'])->group(function () {
        Route::post('/register-domain', RegisterDomainController::class)->name('api.v1.plugin.register-domain');
        Route::post('/check-update', CheckUpdateController::class)
            ->middleware(['plugin.signature'])
            ->name('api.v1.plugin.check-update');
        Route::get('/download/{token}', DownloadPluginController::class)
            ->middleware(['throttle:20,1'])
            ->name('api.v1.plugin.download');
        Route::get('/download-token/{token}', DownloadPluginController::class)
            ->middleware(['throttle:20,1'])
            ->name('api.v1.plugin.download-token');
    });
});

Route::prefix('v1')->group(function () {
    Route::post('/connectors/heartbeat', ConnectorHeartbeatController::class)
        ->middleware(['site.token', 'throttle:20,1'])
        ->name('api.v1.connectors.heartbeat');
});

Route::middleware(['integration.token', 'throttle:integration-api', 'client.domain', 'integration.log'])->group(function () {
    Route::get('/sites/{site}/content/{content}/markdown', [SiteMarkdownController::class, 'markdown'])
        ->name('api.sites.content.markdown');
    Route::get('/sites/{site}/content/{content}/html', [SiteMarkdownController::class, 'html'])
        ->name('api.sites.content.html');
    Route::get('/sites/{site}/content/{content}/answers', [SiteMarkdownController::class, 'answers'])
        ->name('api.sites.content.answers');
    Route::get('/sites/{site}/markdown-index', [SiteMarkdownController::class, 'index'])
        ->name('api.sites.markdown-index');
});
