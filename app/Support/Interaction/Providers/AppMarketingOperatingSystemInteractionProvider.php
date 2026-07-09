<?php

namespace App\Support\Interaction\Providers;

use App\Models\MarketingInitiative;
use App\Models\MarketingObjective;
use App\Models\MarketingOperatingLink;
use App\Models\MarketingPriority;
use App\Models\MarketingReview;
use App\Models\MarketingTheme;
use App\Models\MarketingTimelineEvent;
use App\Models\MarketingWorkflow;
use App\Support\Interaction\Action;
use App\Support\Interaction\ActionContext;
use App\Support\Interaction\ActionRegistry;
use App\Support\Interaction\InteractionMetadataProvider;
use App\Support\Interaction\Resource;
use App\Support\Interaction\ResourceContext;
use App\Support\Interaction\ResourceRegistry;
use App\Support\Interaction\ResourceRelationship;
use App\Support\Interaction\ResourceType;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AppMarketingOperatingSystemInteractionProvider implements InteractionMetadataProvider
{
    public const ACTION_OBJECTIVE_INSPECT = 'app.marketing-objective.inspect';
    public const ACTION_INITIATIVE_INSPECT = 'app.marketing-initiative.inspect';
    public const ACTION_WORKFLOW_INSPECT = 'app.marketing-workflow.inspect';

    public function resourceTypes(): array
    {
        return [
            ResourceType::MARKETING_OBJECTIVE,
            ResourceType::MARKETING_INITIATIVE,
            ResourceType::MARKETING_THEME,
            ResourceType::MARKETING_PRIORITY,
            ResourceType::MARKETING_WORKFLOW,
            ResourceType::MARKETING_REVIEW,
            ResourceType::MARKETING_TIMELINE_EVENT,
            ResourceType::MARKETING_OPERATING_LINK,
        ];
    }

    public function actionKeys(): array
    {
        return [
            self::ACTION_OBJECTIVE_INSPECT,
            self::ACTION_INITIATIVE_INSPECT,
            self::ACTION_WORKFLOW_INSPECT,
        ];
    }

    public function registerTypes(ResourceRegistry $resources): ResourceRegistry
    {
        return $resources
            ->register(ResourceType::make(ResourceType::MARKETING_OBJECTIVE, 'Marketing objective')->icon('target')->model(MarketingObjective::class))
            ->register(ResourceType::make(ResourceType::MARKETING_INITIATIVE, 'Marketing initiative')->icon('git-branch')->model(MarketingInitiative::class))
            ->register(ResourceType::make(ResourceType::MARKETING_THEME, 'Marketing theme')->icon('tags')->model(MarketingTheme::class))
            ->register(ResourceType::make(ResourceType::MARKETING_PRIORITY, 'Marketing priority')->icon('list-filter')->model(MarketingPriority::class))
            ->register(ResourceType::make(ResourceType::MARKETING_WORKFLOW, 'Marketing workflow')->icon('workflow')->model(MarketingWorkflow::class))
            ->register(ResourceType::make(ResourceType::MARKETING_REVIEW, 'Marketing review')->icon('badge-check')->model(MarketingReview::class))
            ->register(ResourceType::make(ResourceType::MARKETING_TIMELINE_EVENT, 'Marketing timeline event')->icon('history')->model(MarketingTimelineEvent::class))
            ->register(ResourceType::make(ResourceType::MARKETING_OPERATING_LINK, 'Marketing operating link')->icon('network')->model(MarketingOperatingLink::class));
    }

    public function registerActions(ActionRegistry $actions): ActionRegistry
    {
        return $actions
            ->register(
                Action::make(self::ACTION_OBJECTIVE_INSPECT, 'Inspect marketing objective', 'inspect')
                    ->description('Inspect objective context, linked initiatives, evidence, recommendations, reports, and briefings.')
                    ->icon('target')
                    ->resource(ResourceType::MARKETING_OBJECTIVE)
                    ->drawer('marketing-objective.inspect', 'inspect', 'xl')
                    ->authorize(fn (ActionContext $context): bool => $this->canView($context->user, $context->metadata('subject')))
                    ->visibleIn(Action::SURFACE_ROW, Action::SURFACE_CONTEXT_MENU, Action::SURFACE_COMMAND_PALETTE, Action::SURFACE_DRAWER, Action::SURFACE_NOTIFICATION)
                    ->ai(['intent' => 'inspect_marketing_objective', 'uses_universal_ui' => true])
                    ->metadata(['provider' => self::class, 'dashboard' => false])
            )
            ->register(
                Action::make(self::ACTION_INITIATIVE_INSPECT, 'Inspect marketing initiative', 'inspect')
                    ->description('Inspect initiative workflow, timeline, linked resources, and performance evidence.')
                    ->icon('git-branch')
                    ->resource(ResourceType::MARKETING_INITIATIVE)
                    ->drawer('marketing-initiative.inspect', 'inspect', 'xl')
                    ->authorize(fn (ActionContext $context): bool => $this->canView($context->user, $context->metadata('subject')))
                    ->visibleIn(Action::SURFACE_ROW, Action::SURFACE_CONTEXT_MENU, Action::SURFACE_COMMAND_PALETTE, Action::SURFACE_DRAWER, Action::SURFACE_NOTIFICATION)
                    ->ai(['intent' => 'inspect_marketing_initiative', 'uses_universal_ui' => true])
                    ->metadata(['provider' => self::class, 'dashboard' => false])
            )
            ->register(
                Action::make(self::ACTION_WORKFLOW_INSPECT, 'Inspect marketing workflow', 'inspect')
                    ->description('Inspect workflow stage, gates, review state, and timeline context.')
                    ->icon('workflow')
                    ->resource(ResourceType::MARKETING_WORKFLOW)
                    ->drawer('marketing-workflow.inspect', 'inspect', 'lg')
                    ->authorize(fn (ActionContext $context): bool => $this->canView($context->user, $context->metadata('subject')))
                    ->visibleIn(Action::SURFACE_ROW, Action::SURFACE_CONTEXT_MENU, Action::SURFACE_COMMAND_PALETTE, Action::SURFACE_DRAWER)
                    ->ai(['intent' => 'inspect_marketing_workflow', 'uses_universal_ui' => true])
                    ->metadata(['provider' => self::class, 'dashboard' => false])
            );
    }

    public function resourceFor(object $subject): ?Resource
    {
        return match (true) {
            $subject instanceof MarketingObjective => $this->objectiveResource($subject),
            $subject instanceof MarketingInitiative => $this->initiativeResource($subject),
            $subject instanceof MarketingTheme => $this->modelResource($subject, ResourceType::MARKETING_THEME, 'tags', $subject->name),
            $subject instanceof MarketingPriority => $this->modelResource($subject, ResourceType::MARKETING_PRIORITY, 'list-filter', $subject->name),
            $subject instanceof MarketingWorkflow => $this->workflowResource($subject),
            $subject instanceof MarketingReview => $this->modelResource($subject, ResourceType::MARKETING_REVIEW, 'badge-check', $subject->review_type),
            $subject instanceof MarketingTimelineEvent => $this->modelResource($subject, ResourceType::MARKETING_TIMELINE_EVENT, 'history', $subject->title),
            $subject instanceof MarketingOperatingLink => $this->modelResource($subject, ResourceType::MARKETING_OPERATING_LINK, 'network', $subject->resource_title ?: $subject->resource_key),
            default => null,
        };
    }

    public function objectiveResource(MarketingObjective $objective): Resource
    {
        return $this->modelResource($objective, ResourceType::MARKETING_OBJECTIVE, 'target', $objective->name)
            ->subtitle($objective->desired_outcome ?: $objective->target_metric_key)
            ->status($this->status($objective->status, $objective->priority))
            ->drawer('marketing-objective.inspect', 'inspect', 'xl', ['metadata_provider' => 'marketing_operating_system'])
            ->actions(self::ACTION_OBJECTIVE_INSPECT)
            ->relationships(...array_filter([
                $this->workspaceRelationship($objective),
                $this->siteRelationship($objective),
                $this->themeRelationship($objective),
            ]))
            ->preview(['summary_fields' => ['status', 'priority', 'target_metric_key', 'market_pack_key']])
            ->history(['timeline_key' => ResourceType::MARKETING_OBJECTIVE])
            ->ai(['explainability' => ['inputs' => ['initiatives', 'links', 'timeline', 'reviews'], 'safe_to_summarize' => true]])
            ->search(['tokens' => ['name', 'desired_outcome', 'target_metric_key', 'market_pack_key'], 'rank' => 'workspace-scoped'])
            ->notification(['channels' => ['in_app'], 'resource_family' => 'marketing_operating_system'])
            ->metadata(['provider' => self::class, 'dashboard' => false]);
    }

    public function initiativeResource(MarketingInitiative $initiative): Resource
    {
        return $this->modelResource($initiative, ResourceType::MARKETING_INITIATIVE, 'git-branch', $initiative->name)
            ->subtitle($initiative->summary)
            ->status($this->status($initiative->status, $initiative->priority))
            ->drawer('marketing-initiative.inspect', 'inspect', 'xl', ['metadata_provider' => 'marketing_operating_system'])
            ->actions(self::ACTION_INITIATIVE_INSPECT)
            ->relationships(...array_filter([
                $this->objectiveRelationship($initiative),
                $this->workspaceRelationship($initiative),
                $this->siteRelationship($initiative),
                $this->themeRelationship($initiative),
            ]))
            ->preview(['summary_fields' => ['status', 'priority', 'market_pack_key', 'starts_on', 'ends_on']])
            ->history(['timeline_key' => ResourceType::MARKETING_INITIATIVE])
            ->ai(['explainability' => ['inputs' => ['links', 'performance', 'recommendations', 'reports', 'briefings'], 'safe_to_summarize' => true]])
            ->search(['tokens' => ['name', 'summary', 'market_pack_key'], 'rank' => 'workspace-scoped'])
            ->notification(['channels' => ['in_app'], 'resource_family' => 'marketing_operating_system'])
            ->metadata(['provider' => self::class, 'dashboard' => false]);
    }

    public function workflowResource(MarketingWorkflow $workflow): Resource
    {
        return $this->modelResource($workflow, ResourceType::MARKETING_WORKFLOW, 'workflow', $workflow->name)
            ->subtitle($workflow->workflow_key)
            ->status($this->status($workflow->status, $workflow->current_stage))
            ->drawer('marketing-workflow.inspect', 'inspect', 'lg', ['metadata_provider' => 'marketing_operating_system'])
            ->actions(self::ACTION_WORKFLOW_INSPECT)
            ->relationships(...array_filter([
                $workflow->marketing_initiative_id
                    ? ResourceRelationship::make('initiative', 'belongs_to', ResourceType::MARKETING_INITIATIVE)
                        ->resourceId($workflow->marketing_initiative_id)
                        ->resourceKey(ResourceType::MARKETING_INITIATIVE.':'.$workflow->marketing_initiative_id)
                    : null,
                $workflow->marketing_objective_id
                    ? ResourceRelationship::make('objective', 'belongs_to', ResourceType::MARKETING_OBJECTIVE)
                        ->resourceId($workflow->marketing_objective_id)
                        ->resourceKey(ResourceType::MARKETING_OBJECTIVE.':'.$workflow->marketing_objective_id)
                    : null,
            ]))
            ->preview(['summary_fields' => ['workflow_key', 'status', 'current_stage']])
            ->history(['timeline_key' => ResourceType::MARKETING_WORKFLOW])
            ->ai(['explainability' => ['inputs' => ['stages', 'gates', 'reviews'], 'safe_to_summarize' => true]])
            ->metadata(['provider' => self::class, 'dashboard' => false]);
    }

    private function modelResource(Model $model, string $type, string $icon, string $title): Resource
    {
        return Resource::forModel($this->key($type, $model), $type, $model)
            ->title($title)
            ->icon($icon)
            ->authorize(fn (ResourceContext $context): bool => $this->canView($context->user, $model));
    }

    private function canView(?Authenticatable $user, mixed $subject): bool
    {
        if (! $subject instanceof Model || ! $user) {
            return true;
        }

        $organizationId = $subject->getAttribute('organization_id');

        return $organizationId === null || (int) $organizationId === (int) ($user->organization_id ?? 0);
    }

    private function key(string $type, Model $model): string
    {
        $id = (string) $model->getKey();

        if ($id === '') {
            throw new InvalidArgumentException(sprintf('Cannot register [%s] resource metadata without a model key.', $type));
        }

        return $type.':'.$id;
    }

    private function status(mixed $value, mixed $secondary = null): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        return array_filter([
            'label' => Str::headline((string) $value),
            'value' => (string) $value,
            'tone' => $secondary ? (string) $secondary : null,
        ]);
    }

    private function workspaceRelationship(Model $model): ?ResourceRelationship
    {
        $workspaceId = $model->getAttribute('workspace_id');

        return $workspaceId
            ? ResourceRelationship::make('workspace', 'scoped_to', ResourceType::WORKSPACE)
                ->resourceId($workspaceId)
                ->resourceKey(ResourceType::WORKSPACE.':'.$workspaceId)
            : null;
    }

    private function siteRelationship(Model $model): ?ResourceRelationship
    {
        $siteId = $model->getAttribute('client_site_id');

        return $siteId
            ? ResourceRelationship::make('site', 'scoped_to', ResourceType::SITE)
                ->resourceId($siteId)
                ->resourceKey(ResourceType::SITE.':'.$siteId)
            : null;
    }

    private function themeRelationship(Model $model): ?ResourceRelationship
    {
        $themeId = $model->getAttribute('marketing_theme_id');

        return $themeId
            ? ResourceRelationship::make('theme', 'belongs_to', ResourceType::MARKETING_THEME)
                ->resourceId($themeId)
                ->resourceKey(ResourceType::MARKETING_THEME.':'.$themeId)
            : null;
    }

    private function objectiveRelationship(MarketingInitiative $initiative): ?ResourceRelationship
    {
        return $initiative->marketing_objective_id
            ? ResourceRelationship::make('objective', 'belongs_to', ResourceType::MARKETING_OBJECTIVE)
                ->resourceId($initiative->marketing_objective_id)
                ->resourceKey(ResourceType::MARKETING_OBJECTIVE.':'.$initiative->marketing_objective_id)
            : null;
    }
}
