<?php

namespace App\Providers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Contracts\LlmClientInterface;
use App\Models\Account;
use App\Models\Agent;
use App\Models\AnswerBlock;
use App\Models\Audience;
use App\Models\Brand;
use App\Models\BrandMembership;
use App\Models\BrandNarrative;
use App\Models\BrandProduct;
use App\Models\BrandProfile;
use App\Models\BrandService;
use App\Models\Briefing;
use App\Models\Campaign;
use App\Models\Competitor;
use App\Models\Contact;
use App\Models\ContentAsset;
use App\Models\Entity;
use App\Models\EvidenceItem;
use App\Models\IntelligenceSignal;
use App\Models\MarketingObjective;
use App\Models\MarketingTask;
use App\Models\MarketingWorkspace;
use App\Models\Membership;
use App\Models\Mention;
use App\Models\Module;
use App\Models\Narrative;
use App\Models\Newsletter;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Property;
use App\Models\Recommendation;
use App\Models\Relationship;
use App\Models\Role;
use App\Models\Segment;
use App\Models\SocialPost;
use App\Models\Source;
use App\Models\SourceConnection;
use App\Models\SourceSync;
use App\Models\Subscription;
use App\Models\Topic;
use App\Models\User;
use App\Models\VisibilityCheck;
use App\Policies\AccountPolicy;
use App\Policies\AgentPolicy;
use App\Policies\AnswerBlockPolicy;
use App\Policies\AudiencePolicy;
use App\Policies\BrandPolicy;
use App\Policies\BrandMembershipPolicy;
use App\Policies\BrandNarrativePolicy;
use App\Policies\BrandProductPolicy;
use App\Policies\BrandProfilePolicy;
use App\Policies\BrandServicePolicy;
use App\Policies\BriefingPolicy;
use App\Policies\CampaignPolicy;
use App\Policies\CompetitorPolicy;
use App\Policies\ContactPolicy;
use App\Policies\ContentAssetPolicy;
use App\Policies\EntityPolicy;
use App\Policies\EvidenceItemPolicy;
use App\Policies\IntelligenceSignalPolicy;
use App\Policies\MarketingObjectivePolicy;
use App\Policies\MarketingTaskPolicy;
use App\Policies\MarketingWorkspacePolicy;
use App\Policies\MembershipPolicy;
use App\Policies\MentionPolicy;
use App\Policies\ModulePolicy;
use App\Policies\NarrativePolicy;
use App\Policies\NewsletterPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\PermissionPolicy;
use App\Policies\PropertyPolicy;
use App\Policies\RecommendationPolicy;
use App\Policies\RelationshipPolicy;
use App\Policies\RolePolicy;
use App\Policies\SegmentPolicy;
use App\Policies\SocialPostPolicy;
use App\Policies\SourceConnectionPolicy;
use App\Policies\SourcePolicy;
use App\Policies\SourceSyncPolicy;
use App\Policies\SubscriptionPolicy;
use App\Policies\TopicPolicy;
use App\Policies\UserPolicy;
use App\Policies\VisibilityCheckPolicy;
use App\Services\ActivityLogger;
use App\Services\CreditCostResolver;
use App\Services\DomainEvents\ActivityLogProjector;
use App\Services\DomainEvents\GraphProjector;
use App\Services\DomainEvents\NotificationProjector;
use App\Services\DomainEvents\ProjectorRegistry;
use App\Services\DomainEvents\RecommendationProjector;
use App\Services\DomainEvents\SignalProjector;
use App\Services\EntitlementService;
use App\Services\FeatureGate;
use App\Services\Llm\LlmClientManager;
use App\Services\PermissionService;
use App\Services\PlanResolver;
use App\Services\SignalManager;
use App\Services\Signals\Producers\ContentAuditCompletedProducer;
use App\Services\Signals\Producers\ContentPublishedProducer;
use App\Services\Signals\Producers\CreditsLowProducer;
use App\Services\Signals\Producers\GenerationCompletedProducer;
use App\Services\Signals\Producers\IntegrationConnectedProducer;
use App\Services\Signals\Producers\LifecycleScoreDegradedProducer;
use App\Services\Signals\Producers\PublishingCompletedProducer;
use App\Services\Signals\Producers\PublishingFailedProducer;
use App\Services\Signals\SignalManager as InternalSignalManager;
use App\Services\Subscriptions\ModuleAccessService;
use App\Services\Tenancy\CurrentAccount;
use App\Services\Tenancy\CurrentBrand;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentAccountContract::class, CurrentAccount::class);
        $this->app->scoped(CurrentBrandContract::class, CurrentBrand::class);
        $this->app->scoped(LlmClientInterface::class, LlmClientManager::class);
        $this->app->scoped(ModuleAccessService::class);
        $this->app->scoped(PermissionService::class);
        $this->app->scoped(PlanResolver::class);
        $this->app->scoped(EntitlementService::class);
        $this->app->scoped(FeatureGate::class);
        $this->app->scoped(CreditCostResolver::class);

        $signalManagerFactory = fn ($app) => new SignalManager([
            $app->make(ContentPublishedProducer::class),
            $app->make(ContentAuditCompletedProducer::class),
            $app->make(LifecycleScoreDegradedProducer::class),
            $app->make(GenerationCompletedProducer::class),
            $app->make(CreditsLowProducer::class),
            $app->make(IntegrationConnectedProducer::class),
            $app->make(PublishingFailedProducer::class),
            $app->make(PublishingCompletedProducer::class),
        ]);

        $this->app->scoped(SignalManager::class, $signalManagerFactory);
        $this->app->scoped(InternalSignalManager::class, fn ($app) => $app->make(SignalManager::class));
        $this->app->scoped(ProjectorRegistry::class, fn ($app) => new ProjectorRegistry([
            $app->make(ActivityLogProjector::class),
            $app->make(GraphProjector::class),
            $app->make(SignalProjector::class),
            $app->make(RecommendationProjector::class),
            $app->make(NotificationProjector::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('connector-api', function (Request $request) {
            $plainToken = $request->bearerToken() ?: $request->header('X-Connector-Token');
            $key = is_string($plainToken) && $plainToken !== ''
                ? hash('sha256', $plainToken)
                : $request->ip();

            return Limit::perMinute(120)->by('connector-api:'.$key);
        });

        RateLimiter::for('analytics-events', function (Request $request) {
            $siteKey = (string) ($request->input('site_key', $request->input('site', '')) ?: $request->ip());

            return Limit::perMinute((int) config('analytics.ingestion.rate_limit_per_minute', 120))
                ->by('analytics:'.hash('sha256', $siteKey));
        });

        RateLimiter::for('auth-actions', fn (Request $request) => Limit::perMinute(10)
            ->by('auth:'.strtolower((string) $request->input('email', $request->ip()))));

        RateLimiter::for('marketing-forms', fn (Request $request) => Limit::perMinute(5)
            ->by('marketing:'.$request->ip()));

        RateLimiter::for('admin-actions', fn (Request $request) => Limit::perMinute(120)
            ->by('admin:'.($request->user()?->id ?? $request->ip())));

        RateLimiter::for('tenant-switch', fn (Request $request) => Limit::perMinute(30)
            ->by('tenant-switch:'.($request->user()?->id ?? $request->ip())));

        RateLimiter::for('ai-actions', fn (Request $request) => Limit::perMinute(30)
            ->by('ai:'.($request->user()?->id ?? $request->ip())));

        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                app(ActivityLogger::class)->log(
                    event: 'auth.login',
                    description: "{$event->user->name} logged in.",
                    user: $event->user,
                    subject: $event->user,
                );
            }
        });

        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->user instanceof User) {
                app(ActivityLogger::class)->log(
                    event: 'auth.logout',
                    description: "{$event->user->name} logged out.",
                    user: $event->user,
                    subject: $event->user,
                );
            }
        });

        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(Brand::class, BrandPolicy::class);
        Gate::policy(Membership::class, MembershipPolicy::class);
        Gate::policy(BrandMembership::class, BrandMembershipPolicy::class);
        Gate::policy(Property::class, PropertyPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        Gate::policy(Module::class, ModulePolicy::class);
        Gate::policy(Subscription::class, SubscriptionPolicy::class);
        Gate::policy(Audience::class, AudiencePolicy::class);
        Gate::policy(BrandProfile::class, BrandProfilePolicy::class);
        Gate::policy(BrandProduct::class, BrandProductPolicy::class);
        Gate::policy(BrandService::class, BrandServicePolicy::class);
        Gate::policy(BrandNarrative::class, BrandNarrativePolicy::class);
        Gate::policy(Briefing::class, BriefingPolicy::class);
        Gate::policy(ContentAsset::class, ContentAssetPolicy::class);
        Gate::policy(AnswerBlock::class, AnswerBlockPolicy::class);
        Gate::policy(Source::class, SourcePolicy::class);
        Gate::policy(SocialPost::class, SocialPostPolicy::class);
        Gate::policy(SourceConnection::class, SourceConnectionPolicy::class);
        Gate::policy(SourceSync::class, SourceSyncPolicy::class);
        Gate::policy(Campaign::class, CampaignPolicy::class);
        Gate::policy(MarketingWorkspace::class, MarketingWorkspacePolicy::class);
        Gate::policy(MarketingObjective::class, MarketingObjectivePolicy::class);
        Gate::policy(MarketingTask::class, MarketingTaskPolicy::class);
        Gate::policy(Newsletter::class, NewsletterPolicy::class);
        Gate::policy(Topic::class, TopicPolicy::class);
        Gate::policy(Mention::class, MentionPolicy::class);
        Gate::policy(IntelligenceSignal::class, IntelligenceSignalPolicy::class);
        Gate::policy(Recommendation::class, RecommendationPolicy::class);
        Gate::policy(EvidenceItem::class, EvidenceItemPolicy::class);
        Gate::policy(Narrative::class, NarrativePolicy::class);
        Gate::policy(Relationship::class, RelationshipPolicy::class);
        Gate::policy(Segment::class, SegmentPolicy::class);
        Gate::policy(Contact::class, ContactPolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(Competitor::class, CompetitorPolicy::class);
        Gate::policy(Entity::class, EntityPolicy::class);
        Gate::policy(VisibilityCheck::class, VisibilityCheckPolicy::class);
        Gate::policy(Agent::class, AgentPolicy::class);

        foreach (app(PermissionService::class)->configuredPermissionNames() as $permission) {
            Gate::define($permission, function (User $user, mixed ...$arguments) use ($permission): bool {
                return app(PermissionService::class)->userCan(
                    $user,
                    $permission,
                    $this->authorizationContext($arguments, $user),
                );
            });
        }

        Gate::before(function (User $user, string $ability, mixed $arguments = null): ?bool {
            $permissions = app(PermissionService::class);

            if (! $permissions->isKnownPermission($ability)) {
                return null;
            }

            return $permissions->userCan(
                $user,
                $ability,
                $this->authorizationContext($arguments, $user),
            );
        });
    }

    /**
     * @return array{account_id?: int|null, brand_id?: int|null}
     */
    private function authorizationContext(mixed $arguments, User $user): array
    {
        $arguments = is_array($arguments) ? $arguments : [$arguments];

        return [
            'account_id' => $this->valueFromArguments($arguments, 'account_id', 'account') ?? app(CurrentAccountContract::class)->id($user),
            'brand_id' => $this->valueFromArguments($arguments, 'brand_id', 'brand') ?? app(CurrentBrandContract::class)->id($user),
        ];
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function valueFromArguments(array $arguments, string $key, string $objectName): ?int
    {
        foreach ($arguments as $argument) {
            if (is_array($argument) && array_key_exists($key, $argument)) {
                return $argument[$key] === null ? null : (int) $argument[$key];
            }

            if (is_object($argument) && isset($argument->{$key})) {
                return (int) $argument->{$key};
            }

            if (is_object($argument) && isset($argument->{$objectName}) && is_object($argument->{$objectName}) && isset($argument->{$objectName}->id)) {
                return (int) $argument->{$objectName}->id;
            }
        }

        return null;
    }
}
