<?php

namespace App\Support\Interaction\Providers;

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
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

final class AppContentInteractionProvider implements InteractionMetadataProvider
{
    public const ACTION_CONTENT_OPEN = 'app.content.open';
    public const ACTION_CONTENT_CREATE = 'app.content.create';
    public const ACTION_CONTENT_OPEN_CALENDAR = 'app.content.open-calendar';
    public const ACTION_DRAFT_OPEN = 'app.draft.open';
    public const ACTION_BRIEF_OPEN = 'app.brief.open';

    public function resourceTypes(): array
    {
        return [
            ResourceType::CONTENT,
            ResourceType::DRAFT,
            ResourceType::BRIEF,
        ];
    }

    public function actionKeys(): array
    {
        return [
            self::ACTION_CONTENT_OPEN,
            self::ACTION_CONTENT_CREATE,
            self::ACTION_CONTENT_OPEN_CALENDAR,
            self::ACTION_DRAFT_OPEN,
            self::ACTION_BRIEF_OPEN,
        ];
    }

    public function registerTypes(ResourceRegistry $resources): ResourceRegistry
    {
        return $resources
            ->register(ResourceType::make(ResourceType::CONTENT, 'Content')->icon('file-text')->model(Content::class)->primaryRoute('app.content.show')->policy('view'))
            ->register(ResourceType::make(ResourceType::DRAFT, 'Draft')->icon('file-pen-line')->model(Draft::class)->primaryRoute('app.drafts.show')->policy('view'))
            ->register(ResourceType::make(ResourceType::BRIEF, 'Brief')->icon('clipboard-list')->model(Brief::class)->primaryRoute('app.briefs.show')->policy('view'));
    }

    public function registerActions(ActionRegistry $actions): ActionRegistry
    {
        return $actions
            ->register(
                Action::make(self::ACTION_CONTENT_OPEN, 'Open content', 'open')
                    ->description('Open an existing content workspace.')
                    ->icon('file-text')
                    ->route('app.content.show', fn (ActionContext $context): array => ['content' => $context->resourceId])
                    ->resource(ResourceType::CONTENT)
                    ->authorize(fn (ActionContext $context): bool => $this->canViewContextSubject($context))
                    ->visibleIn(Action::SURFACE_ROW, Action::SURFACE_CONTEXT_MENU, Action::SURFACE_COMMAND_PALETTE, Action::SURFACE_QUICK)
                    ->metadata(['provider' => self::class, 'route_backed' => true])
            )
            ->register(
                Action::make(self::ACTION_CONTENT_CREATE, 'Create content', 'create')
                    ->description('Open the existing content creation flow.')
                    ->icon('plus')
                    ->route('app.content.create')
                    ->policy('create', Brief::class)
                    ->visibleIn(Action::SURFACE_TOOLBAR, Action::SURFACE_COMMAND_PALETTE, Action::SURFACE_QUICK)
                    ->metadata(['provider' => self::class, 'route_backed' => true])
            )
            ->register(
                Action::make(self::ACTION_CONTENT_OPEN_CALENDAR, 'Open content calendar', 'open')
                    ->description('Open the existing content calendar.')
                    ->icon('calendar-days')
                    ->route('app.content.calendar')
                    ->visibleIn(Action::SURFACE_TOOLBAR, Action::SURFACE_COMMAND_PALETTE, Action::SURFACE_QUICK)
                    ->metadata(['provider' => self::class, 'route_backed' => true])
            )
            ->register(
                Action::make(self::ACTION_DRAFT_OPEN, 'Open draft', 'open')
                    ->description('Open an existing draft workspace.')
                    ->icon('file-pen-line')
                    ->route('app.drafts.show', fn (ActionContext $context): array => ['draft' => $context->resourceId])
                    ->resource(ResourceType::DRAFT)
                    ->authorize(fn (ActionContext $context): bool => $this->canViewContextSubject($context))
                    ->visibleIn(Action::SURFACE_ROW, Action::SURFACE_CONTEXT_MENU, Action::SURFACE_COMMAND_PALETTE, Action::SURFACE_QUICK)
                    ->metadata(['provider' => self::class, 'route_backed' => true])
            )
            ->register(
                Action::make(self::ACTION_BRIEF_OPEN, 'Open brief', 'open')
                    ->description('Open an existing brief workspace.')
                    ->icon('clipboard-list')
                    ->route('app.briefs.show', fn (ActionContext $context): array => ['brief' => $context->resourceId])
                    ->resource(ResourceType::BRIEF)
                    ->authorize(fn (ActionContext $context): bool => $this->canViewContextSubject($context))
                    ->visibleIn(Action::SURFACE_ROW, Action::SURFACE_CONTEXT_MENU, Action::SURFACE_COMMAND_PALETTE, Action::SURFACE_QUICK)
                    ->metadata(['provider' => self::class, 'route_backed' => true])
            );
    }

    public function resourceFor(object $subject): ?Resource
    {
        return match (true) {
            $subject instanceof Content => $this->contentResource($subject),
            $subject instanceof Draft => $this->draftResource($subject),
            $subject instanceof Brief => $this->briefResource($subject),
            default => null,
        };
    }

    public function contentResource(Content $content): Resource
    {
        $site = $content->clientSite;

        return Resource::forModel($this->key(ResourceType::CONTENT, $content), ResourceType::CONTENT, $content)
            ->title((string) ($content->title ?: 'Untitled content'))
            ->subtitle($this->siteSubtitle($site))
            ->status($this->status($content->status ?? null))
            ->icon('file-text')
            ->primaryRoute('app.content.show', ['content' => $content->getKey()])
            ->policy('view', $content)
            ->permission('update', ['ability' => 'update', 'target' => $content])
            ->actions(self::ACTION_CONTENT_OPEN)
            ->relationships(...array_filter([
                $this->siteRelationship($site, 'site', 'belongs_to', 'client_site_id'),
            ]))
            ->preview(['summary_fields' => ['status', 'language', 'primary_keyword']])
            ->history(['timeline_key' => ResourceType::CONTENT])
            ->ai(['explainability' => ['inputs' => ['brief', 'drafts', 'performance'], 'safe_to_summarize' => true]])
            ->search(['tokens' => ['title', 'primary_keyword', 'site'], 'rank' => 'site-scoped'])
            ->metadata(['provider' => self::class]);
    }

    public function draftResource(Draft $draft): Resource
    {
        $site = $draft->clientSite;
        $brief = $draft->brief;
        $content = $draft->content;

        return Resource::forModel($this->key(ResourceType::DRAFT, $draft), ResourceType::DRAFT, $draft)
            ->title((string) ($draft->title ?: 'Untitled draft'))
            ->subtitle($this->siteSubtitle($site))
            ->status($this->status($draft->status ?? null))
            ->icon('file-pen-line')
            ->primaryRoute('app.drafts.show', ['draft' => $draft->getKey()])
            ->policy('view', $draft)
            ->permission('update', ['ability' => 'update', 'target' => $draft])
            ->actions(self::ACTION_DRAFT_OPEN)
            ->relationships(...array_filter([
                $this->modelRelationship($brief, 'brief', 'generated_from', ResourceType::BRIEF, 'brief_id'),
                $this->modelRelationship($content, 'content', 'generated_from', ResourceType::CONTENT, 'content_id'),
                $this->siteRelationship($site, 'site', 'belongs_to', 'client_site_id'),
            ]))
            ->preview(['summary_fields' => ['status', 'language', 'delivery_status']])
            ->history(['timeline_key' => ResourceType::DRAFT])
            ->ai(['explainability' => ['inputs' => ['brief', 'content', 'quality_signals'], 'safe_to_summarize' => true]])
            ->search(['tokens' => ['title', 'site'], 'rank' => 'site-scoped'])
            ->metadata(['provider' => self::class]);
    }

    public function briefResource(Brief $brief): Resource
    {
        $site = $brief->clientSite;
        $content = $brief->content;

        return Resource::forModel($this->key(ResourceType::BRIEF, $brief), ResourceType::BRIEF, $brief)
            ->title((string) ($brief->title ?: 'Untitled brief'))
            ->subtitle($this->siteSubtitle($site))
            ->status($this->status($brief->status ?? null))
            ->icon('clipboard-list')
            ->primaryRoute('app.briefs.show', ['brief' => $brief->getKey()])
            ->policy('view', $brief)
            ->permission('update', ['ability' => 'update', 'target' => $brief])
            ->actions(self::ACTION_BRIEF_OPEN)
            ->relationships(...array_filter([
                $this->modelRelationship($content, 'content', 'related_to', ResourceType::CONTENT, 'content_id'),
                $this->siteRelationship($site, 'site', 'belongs_to', 'client_site_id'),
            ]))
            ->preview(['summary_fields' => ['status', 'intent', 'primary_keyword']])
            ->history(['timeline_key' => ResourceType::BRIEF])
            ->ai(['explainability' => ['inputs' => ['intent', 'keyword', 'source'], 'safe_to_summarize' => true]])
            ->search(['tokens' => ['title', 'primary_keyword', 'site'], 'rank' => 'site-scoped'])
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

    private function siteSubtitle(?ClientSite $site): ?string
    {
        return $site ? (string) ($site->name ?: $site->site_url ?: $site->base_url ?: 'Connected site') : null;
    }

    private function status(mixed $status): ?array
    {
        if ($status === null || $status === '') {
            return null;
        }

        $value = $status instanceof BackedEnum ? $status->value : (string) $status;

        return [
            'label' => Str::headline($value),
            'value' => $value,
        ];
    }

    private function siteRelationship(?ClientSite $site, string $key, string $type, string $source): ?ResourceRelationship
    {
        if (! $site || ! $site->getKey()) {
            return null;
        }

        return ResourceRelationship::make($key, $type, ResourceType::SITE)
            ->resourceId($site->getKey())
            ->resourceKey(ResourceType::SITE.':'.$site->getKey())
            ->title((string) ($site->name ?: $site->site_url ?: 'Site'))
            ->metadata(['source' => $source]);
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
