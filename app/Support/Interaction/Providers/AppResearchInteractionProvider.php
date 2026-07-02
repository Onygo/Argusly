<?php

namespace App\Support\Interaction\Providers;

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\ResearchProject;
use App\Support\Interaction\Action;
use App\Support\Interaction\ActionContext;
use App\Support\Interaction\ActionRegistry;
use App\Support\Interaction\InteractionMetadataProvider;
use App\Support\Interaction\Resource;
use App\Support\Interaction\ResourceRegistry;
use App\Support\Interaction\ResourceRelationship;
use App\Support\Interaction\ResourceType;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AppResearchInteractionProvider implements InteractionMetadataProvider
{
    public const ACTION_RESEARCH_PROJECT_OPEN = 'app.research-project.open';
    public const ACTION_RESEARCH_PROJECT_CREATE = 'app.research-project.create';

    public function resourceTypes(): array
    {
        return [ResourceType::RESEARCH_PROJECT];
    }

    public function actionKeys(): array
    {
        return [
            self::ACTION_RESEARCH_PROJECT_OPEN,
            self::ACTION_RESEARCH_PROJECT_CREATE,
        ];
    }

    public function registerTypes(ResourceRegistry $resources): ResourceRegistry
    {
        return $resources->register(
            ResourceType::make(ResourceType::RESEARCH_PROJECT, 'Research project')
                ->icon('search-check')
                ->model(ResearchProject::class)
                ->primaryRoute('app.research.show')
                ->policy('view')
        );
    }

    public function registerActions(ActionRegistry $actions): ActionRegistry
    {
        return $actions
            ->register(
                Action::make(self::ACTION_RESEARCH_PROJECT_OPEN, 'Open research project', 'open')
                    ->description('Open an existing research project.')
                    ->icon('search-check')
                    ->route('app.research.show', fn (ActionContext $context): array => ['project' => $context->resourceId])
                    ->resource(ResourceType::RESEARCH_PROJECT)
                    ->authorize(fn (ActionContext $context): bool => $this->canViewContextSubject($context))
                    ->visibleIn(Action::SURFACE_ROW, Action::SURFACE_CONTEXT_MENU, Action::SURFACE_COMMAND_PALETTE, Action::SURFACE_QUICK)
                    ->metadata(['provider' => self::class, 'route_backed' => true])
            )
            ->register(
                Action::make(self::ACTION_RESEARCH_PROJECT_CREATE, 'Create research project', 'create')
                    ->description('Open the existing research project creation flow.')
                    ->icon('plus')
                    ->route('app.research.create')
                    ->policy('create', ResearchProject::class)
                    ->visibleIn(Action::SURFACE_TOOLBAR, Action::SURFACE_COMMAND_PALETTE, Action::SURFACE_QUICK)
                    ->metadata(['provider' => self::class, 'route_backed' => true])
            );
    }

    public function resourceFor(object $subject): ?Resource
    {
        return $subject instanceof ResearchProject ? $this->researchProjectResource($subject) : null;
    }

    public function researchProjectResource(ResearchProject $project): Resource
    {
        $site = $project->clientSite;
        $brief = $project->brief;

        return Resource::forModel($this->key(ResourceType::RESEARCH_PROJECT, $project), ResourceType::RESEARCH_PROJECT, $project)
            ->title((string) ($project->name ?: 'Untitled research project'))
            ->subtitle($site ? (string) ($site->name ?: $site->site_url ?: 'Connected site') : null)
            ->status($this->status($project->status ?? null))
            ->icon('search-check')
            ->primaryRoute('app.research.show', ['project' => $project->getKey()])
            ->policy('view', $project)
            ->permission('run', ['ability' => 'run', 'target' => $project])
            ->actions(self::ACTION_RESEARCH_PROJECT_OPEN)
            ->relationships(...array_filter([
                $this->siteRelationship($site),
                $this->modelRelationship($brief, 'brief', 'generated_from', ResourceType::BRIEF, 'brief_id'),
            ]))
            ->preview(['summary_fields' => ['status', 'target_keywords']])
            ->history(['timeline_key' => ResourceType::RESEARCH_PROJECT])
            ->ai(['explainability' => ['inputs' => ['sources', 'findings', 'summary'], 'safe_to_summarize' => true]])
            ->search(['tokens' => ['name', 'target_keywords', 'site'], 'rank' => 'workspace-scoped'])
            ->metadata(['provider' => self::class]);
    }

    private function canViewContextSubject(ActionContext $context): bool
    {
        $subject = $context->metadata('subject');

        if ($subject === null) {
            return true;
        }

        return $context->user !== null && $context->user->can('view', $subject);
    }

    private function key(string $type, Model $model): string
    {
        $id = (string) $model->getKey();

        if ($id === '') {
            throw new InvalidArgumentException(sprintf('Cannot register [%s] resource metadata without a model key.', $type));
        }

        return $type.':'.$id;
    }

    private function status(mixed $status): ?array
    {
        if ($status === null || $status === '') {
            return null;
        }

        $value = $status instanceof BackedEnum ? $status->value : (string) $status;

        return ['label' => Str::headline($value), 'value' => $value];
    }

    private function siteRelationship(?ClientSite $site): ?ResourceRelationship
    {
        if (! $site || ! $site->getKey()) {
            return null;
        }

        return ResourceRelationship::make('site', 'scoped_to', ResourceType::SITE)
            ->resourceId($site->getKey())
            ->resourceKey(ResourceType::SITE.':'.$site->getKey())
            ->title((string) ($site->name ?: $site->site_url ?: 'Site'))
            ->metadata(['source' => 'client_site_id']);
    }

    private function modelRelationship(?Model $model, string $key, string $type, string $resourceType, string $source): ?ResourceRelationship
    {
        if (! $model || ! $model->getKey()) {
            return null;
        }

        return ResourceRelationship::make($key, $type, $resourceType)
            ->resourceId($model->getKey())
            ->resourceKey($resourceType.':'.$model->getKey())
            ->title((string) ($model->getAttribute('title') ?: $model->getAttribute('name') ?: $resourceType))
            ->metadata(['source' => $source]);
    }
}
