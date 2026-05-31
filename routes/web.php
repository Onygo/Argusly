<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AnswerBlockController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CompetitorController;
use App\Http\Controllers\ConnectorController;
use App\Http\Controllers\ContentAssetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainEventController;
use App\Http\Controllers\IntelligenceSignalController;
use App\Http\Controllers\KnowledgeGraphController;
use App\Http\Controllers\LinkedInIntegrationController;
use App\Http\Controllers\MarketingCalendarController;
use App\Http\Controllers\MentionController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\RelationshipController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SocialPostController;
use App\Http\Controllers\SocialRepurposingController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\TenantContextController;
use App\Http\Controllers\TopicController;
use App\Http\Controllers\UserLocaleController;
use App\Http\Controllers\VisibilityController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('marketing.home');
})->name('marketing.home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::post('/user/locale', UserLocaleController::class)
    ->middleware('auth')
    ->name('user.locale.update');

Route::middleware(['auth', 'current.account', 'current.brand'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)
        ->middleware(['module.active:core', 'permission:view_dashboard'])
        ->name('dashboard');

    Route::get('/admin/domain-events', [DomainEventController::class, 'index'])
        ->middleware(['module.active:core', 'permission:manage_account'])
        ->name('app.domain-events');

    Route::get('/intelligence', [IntelligenceSignalController::class, 'index'])
        ->middleware(['module.active:core', 'permission:view_dashboard'])
        ->name('app.intelligence');
    Route::post('/intelligence/{signal}/reviewed', [IntelligenceSignalController::class, 'markReviewed'])
        ->middleware(['module.active:core', 'permission:view_dashboard'])
        ->name('app.intelligence.reviewed');
    Route::post('/intelligence/{signal}/dismiss', [IntelligenceSignalController::class, 'dismiss'])
        ->middleware(['module.active:core', 'permission:view_dashboard'])
        ->name('app.intelligence.dismiss');
    Route::post('/recommendations/{recommendation}/accept', [RecommendationController::class, 'accept'])
        ->middleware(['module.active:core', 'permission:view_dashboard'])
        ->name('app.recommendations.accept');
    Route::post('/recommendations/{recommendation}/dismiss', [RecommendationController::class, 'dismiss'])
        ->middleware(['module.active:core', 'permission:view_dashboard'])
        ->name('app.recommendations.dismiss');

    Route::get('/visibility', [VisibilityController::class, 'index'])
        ->middleware(['module.active:visibility', 'permission:view_visibility'])
        ->name('app.visibility');
    Route::post('/visibility/checks', [VisibilityController::class, 'store'])
        ->middleware(['module.active:visibility', 'permission:manage_visibility'])
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
        ->middleware(['module.active:visibility', 'permission:manage_visibility'])
        ->name('app.visibility.prompts.run');

    Route::get('/competitors', [CompetitorController::class, 'index'])
        ->middleware(['module.active:competitive_intelligence', 'permission:view_competitive_intelligence'])
        ->name('app.competitors');
    Route::post('/competitors', [CompetitorController::class, 'store'])
        ->middleware(['module.active:competitive_intelligence', 'permission:view_competitive_intelligence'])
        ->name('app.competitors.store');

    Route::get('/mentions', [MentionController::class, 'index'])
        ->middleware(['module.active:visibility', 'permission:view_visibility'])
        ->name('app.mentions');
    Route::get('/mentions/{mention}', [MentionController::class, 'show'])
        ->middleware(['module.active:visibility', 'permission:view_visibility'])
        ->name('app.mentions.show');

    Route::get('/agents', AgentController::class)
        ->middleware(['module.active:agentic_content,agentic_social', 'permission:view_agents'])
        ->name('app.agents');

    Route::prefix('topics')->name('app.topics.')->middleware(['module.active:core'])->group(function (): void {
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
            ->middleware('permission:edit_content')
            ->name('generate');
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

    Route::get('/campaigns', [CampaignController::class, 'index'])
        ->middleware(['module.active:campaigns', 'permission:view_campaigns'])
        ->name('app.campaigns');
    Route::post('/campaigns', [CampaignController::class, 'store'])
        ->middleware(['module.active:campaigns', 'permission:manage_campaigns'])
        ->name('app.campaigns.store');
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])
        ->middleware(['module.active:campaigns', 'permission:view_campaigns'])
        ->name('app.campaigns.show');
    Route::put('/campaigns/{campaign}', [CampaignController::class, 'update'])
        ->middleware(['module.active:campaigns', 'permission:manage_campaigns'])
        ->name('app.campaigns.update');
    Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy'])
        ->middleware(['module.active:campaigns', 'permission:manage_campaigns'])
        ->name('app.campaigns.destroy');

    Route::view('/automations', 'app.module-page', ['title' => 'Automations', 'module' => 'Agentic Content or Agentic Social'])
        ->middleware(['module.active:agentic_content,agentic_social', 'permission:view_agents'])
        ->name('app.automations');

    Route::prefix('social-posts')->name('app.social-posts.')->middleware(['module.active:content', 'permission:view_content'])->group(function (): void {
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

    Route::view('/reports', 'app.module-page', ['title' => 'Reports', 'module' => 'Core'])
        ->middleware(['module.active:core', 'permission:view_dashboard'])
        ->name('app.reports');

    Route::get('/calendar', [MarketingCalendarController::class, 'index'])
        ->middleware(['module.active:content', 'permission:view_content'])
        ->name('app.calendar');

    Route::prefix('relationships')->middleware(['module.active:core'])->group(function (): void {
        Route::get('/', [RelationshipController::class, 'index'])
            ->middleware('permission:view_dashboard')
            ->name('app.relationships');
        Route::post('/contacts', [RelationshipController::class, 'storeContact'])
            ->middleware('permission:manage_account')
            ->name('app.relationships.contacts.store');
        Route::get('/contacts/{contact}', [RelationshipController::class, 'showContact'])
            ->middleware('permission:view_dashboard')
            ->name('app.relationships.contacts.show');
        Route::post('/organizations', [RelationshipController::class, 'storeOrganization'])
            ->middleware('permission:manage_account')
            ->name('app.relationships.organizations.store');
        Route::get('/organizations/{organization}', [RelationshipController::class, 'showOrganization'])
            ->middleware('permission:view_dashboard')
            ->name('app.relationships.organizations.show');
        Route::post('/edges', [RelationshipController::class, 'storeRelationship'])
            ->middleware('permission:manage_account')
            ->name('app.relationships.edges.store');
    });

    Route::prefix('sources')->name('app.sources.')->middleware(['module.active:core', 'permission:manage_account'])->group(function (): void {
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

    Route::redirect('/settings', '/settings/account')
        ->middleware(['module.active:core', 'permission:manage_account'])
        ->name('app.settings');

    Route::prefix('settings')->name('settings.')->middleware(['module.active:core'])->group(function (): void {
        Route::get('/account', [SettingsController::class, 'account'])
            ->middleware('permission:manage_account')
            ->name('account');

        Route::get('/brands', [SettingsController::class, 'brands'])
            ->middleware('permission:manage_account')
            ->name('brands');

        Route::get('/team', [SettingsController::class, 'team'])
            ->middleware('permission:manage_users')
            ->name('team');

        Route::get('/modules', [SettingsController::class, 'modules'])
            ->middleware('permission:manage_billing')
            ->name('modules');

        Route::get('/integrations', [SettingsController::class, 'integrations'])
            ->middleware('permission:manage_account')
            ->name('integrations');
        Route::get('/integrations/linkedin', [SettingsController::class, 'linkedinIntegration'])
            ->middleware('permission:manage_account')
            ->name('integrations.linkedin');
        Route::get('/integrations/linkedin/connect', [LinkedInIntegrationController::class, 'connect'])
            ->middleware('permission:manage_account')
            ->name('integrations.linkedin.connect');
        Route::get('/integrations/linkedin/callback', [LinkedInIntegrationController::class, 'callback'])
            ->middleware('permission:manage_account')
            ->name('integrations.linkedin.callback');
        Route::post('/integrations/linkedin/disconnect/{connection}', [LinkedInIntegrationController::class, 'disconnect'])
            ->middleware('permission:manage_account')
            ->name('integrations.linkedin.disconnect');

        Route::get('/social-profiles', [SettingsController::class, 'socialProfiles'])
            ->middleware('permission:manage_account')
            ->name('social-profiles');

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

        Route::get('/channels', [SettingsController::class, 'channels'])
            ->middleware(['module.active:content', 'permission:manage_account'])
            ->name('channels');

        Route::get('/knowledge-graph', [KnowledgeGraphController::class, 'index'])
            ->middleware('permission:manage_account')
            ->name('knowledge-graph');
        Route::post('/knowledge-graph/entities', [KnowledgeGraphController::class, 'storeEntity'])
            ->middleware('permission:manage_account')
            ->name('knowledge-graph.entities.store');
        Route::post('/knowledge-graph/relationships', [KnowledgeGraphController::class, 'storeRelationship'])
            ->middleware('permission:manage_account')
            ->name('knowledge-graph.relationships.store');
    });

    Route::post('/tenant/account', [TenantContextController::class, 'switchAccount'])
        ->name('tenant.account.switch');

    Route::post('/tenant/brand', [TenantContextController::class, 'switchBrand'])
        ->name('tenant.brand.switch');
});
