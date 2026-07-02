<?php

namespace App\Support\Interaction\Providers;

use App\Models\ClientSite;
use App\Models\SignalDetection;
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

final class AppSignalInteractionProvider implements InteractionMetadataProvider
{
    public const ACTION_SIGNAL_DETECTION_OPEN = 'app.signal-detection.open';

    public function resourceTypes(): array
    {
        return [ResourceType::SIGNAL_DETECTION];
    }

    public function actionKeys(): array
    {
        return [self::ACTION_SIGNAL_DETECTION_OPEN];
    }

    public function registerTypes(ResourceRegistry $resources): ResourceRegistry
    {
        return $resources->register(
            ResourceType::make(ResourceType::SIGNAL_DETECTION, 'Signal detection')
                ->icon('radar')
                ->model(SignalDetection::class)
                ->primaryRoute('app.signal-intelligence.detections.show')
                ->policy('view')
        );
    }

    public function registerActions(ActionRegistry $actions): ActionRegistry
    {
        return $actions->register(
            Action::make(self::ACTION_SIGNAL_DETECTION_OPEN, 'Open signal detection', 'open')
                ->description('Open an existing signal detection.')
                ->icon('radar')
                ->route('app.signal-intelligence.detections.show', fn (ActionContext $context): array => ['detection' => $context->resourceId])
                ->resource(ResourceType::SIGNAL_DETECTION)
                ->authorize(fn (ActionContext $context): bool => $this->canViewContextSubject($context))
                ->visibleIn(Action::SURFACE_ROW, Action::SURFACE_CONTEXT_MENU, Action::SURFACE_COMMAND_PALETTE, Action::SURFACE_QUICK)
                ->metadata(['provider' => self::class, 'route_backed' => true])
        );
    }

    public function resourceFor(object $subject): ?Resource
    {
        return $subject instanceof SignalDetection ? $this->signalDetectionResource($subject) : null;
    }

    public function signalDetectionResource(SignalDetection $detection): Resource
    {
        $site = $detection->clientSite;

        return Resource::forModel($this->key(ResourceType::SIGNAL_DETECTION, $detection), ResourceType::SIGNAL_DETECTION, $detection)
            ->title((string) ($detection->title ?: 'Untitled signal detection'))
            ->subtitle($site ? (string) ($site->name ?: $site->site_url ?: 'Connected site') : null)
            ->status($this->status($detection->status ?? null))
            ->icon('radar')
            ->primaryRoute('app.signal-intelligence.detections.show', ['detection' => $detection->getKey()])
            ->policy('view', $detection)
            ->permission('update', ['ability' => 'update', 'target' => $detection])
            ->actions(self::ACTION_SIGNAL_DETECTION_OPEN)
            ->relationships(...array_filter([
                $this->siteRelationship($site),
            ]))
            ->preview(['summary_fields' => ['status', 'category', 'severity', 'priority_score']])
            ->history(['timeline_key' => ResourceType::SIGNAL_DETECTION])
            ->ai(['explainability' => ['inputs' => ['events', 'evidence', 'scores'], 'safe_to_summarize' => true]])
            ->search(['tokens' => ['title', 'summary', 'primary_topic', 'primary_entity'], 'rank' => 'workspace-scoped'])
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
}
