<?php

namespace App\Providers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Agent;
use App\Models\AnswerBlock;
use App\Models\Audience;
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
use App\Models\Mention;
use App\Models\Narrative;
use App\Models\MarketingObjective;
use App\Models\MarketingTask;
use App\Models\MarketingWorkspace;
use App\Models\Newsletter;
use App\Models\Organization;
use App\Models\Relationship;
use App\Models\Segment;
use App\Models\SocialPost;
use App\Models\Source;
use App\Models\SourceConnection;
use App\Models\SourceSync;
use App\Models\Topic;
use App\Models\User;
use App\Models\VisibilityCheck;
use App\Policies\AgentPolicy;
use App\Policies\AnswerBlockPolicy;
use App\Policies\AudiencePolicy;
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
use App\Policies\MentionPolicy;
use App\Policies\NarrativePolicy;
use App\Policies\MarketingObjectivePolicy;
use App\Policies\MarketingTaskPolicy;
use App\Policies\MarketingWorkspacePolicy;
use App\Policies\NewsletterPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\RelationshipPolicy;
use App\Policies\SegmentPolicy;
use App\Policies\SocialPostPolicy;
use App\Policies\SourceConnectionPolicy;
use App\Policies\SourcePolicy;
use App\Policies\SourceSyncPolicy;
use App\Policies\TopicPolicy;
use App\Policies\UserPolicy;
use App\Policies\VisibilityCheckPolicy;
use App\Services\ActivityLogger;
use App\Services\DomainEvents\ActivityLogProjector;
use App\Services\DomainEvents\GraphProjector;
use App\Services\DomainEvents\NotificationProjector;
use App\Services\DomainEvents\ProjectorRegistry;
use App\Services\DomainEvents\RecommendationProjector;
use App\Services\DomainEvents\SignalProjector;
use App\Services\PermissionService;
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
        $this->app->scoped(ModuleAccessService::class);
        $this->app->scoped(PermissionService::class);

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

        Gate::policy(User::class, UserPolicy::class);
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
