<?php

namespace App\Support\Interaction\Providers;

use App\Models\MonitoredPage;
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

final class AppPageIntelligenceInteractionProvider implements InteractionMetadataProvider
{
    public const ACTION_MONITORED_PAGE_OPEN = 'app.monitored-page.open';

    public function resourceTypes(): array
    {
        return [ResourceType::MONITORED_PAGE];
    }

    public function actionKeys(): array
    {
        return [self::ACTION_MONITORED_PAGE_OPEN];
    }

    public function registerTypes(ResourceRegistry $resources): ResourceRegistry
    {
        return $resources->register(
            ResourceType::make(ResourceType::MONITORED_PAGE, 'Monitored page')
                ->description('Canonical durable external page asset used by Page Intelligence, Signal Intelligence, SERP, GEO, and PR Value.')
                ->icon('file-search')
                ->model(MonitoredPage::class)
                ->primaryRoute('app.page-intelligence.monitored-pages.show')
        );
    }

    public function registerActions(ActionRegistry $actions): ActionRegistry
    {
        return $actions->register(
            Action::make(self::ACTION_MONITORED_PAGE_OPEN, 'Open monitored page', 'open')
                ->description('Inspect the canonical monitored page evidence.')
                ->icon('file-search')
                ->route('app.page-intelligence.monitored-pages.show', fn (ActionContext $context): array => [
                    'monitoredPage' => $context->resourceId,
                ])
                ->resource(ResourceType::MONITORED_PAGE)
                ->authorize(fn (ActionContext $context): bool => $this->canViewMonitoredPage($context->user, $context->metadata('subject')))
                ->visibleIn(Action::SURFACE_ROW, Action::SURFACE_CONTEXT_MENU, Action::SURFACE_COMMAND_PALETTE, Action::SURFACE_QUICK)
                ->metadata(['provider' => self::class, 'route_backed' => true])
        );
    }

    public function resourceFor(object $subject): ?Resource
    {
        return $subject instanceof MonitoredPage ? $this->monitoredPageResource($subject) : null;
    }

    public function monitoredPageResource(MonitoredPage $page): Resource
    {
        return Resource::forModel($this->key(ResourceType::MONITORED_PAGE, $page), ResourceType::MONITORED_PAGE, $page)
            ->title((string) ($page->title_current ?: $page->canonical_url ?: $page->first_seen_url ?: 'Monitored page'))
            ->subtitle((string) ($page->domain ?: parse_url((string) ($page->canonical_url ?: $page->first_seen_url), PHP_URL_HOST) ?: 'External page'))
            ->status($this->status($page->crawl_status ?? null))
            ->icon('file-search')
            ->primaryRoute('app.page-intelligence.monitored-pages.show', ['monitoredPage' => $page->getKey()])
            ->drawer('monitored-page.inspect', 'inspect', 'xl', [
                'metadata_provider' => 'monitored_page',
                'resource_type' => ResourceType::MONITORED_PAGE,
            ])
            ->authorize(fn (ResourceContext $context): bool => $this->canViewMonitoredPage($context->user, $page))
            ->actions(self::ACTION_MONITORED_PAGE_OPEN)
            ->relationships(...array_filter([
                $this->siteRelationship($page),
            ]))
            ->preview(['summary_fields' => ['crawl_status', 'source_type', 'page_type', 'last_fetched_at']])
            ->history(['timeline_key' => ResourceType::MONITORED_PAGE])
            ->ai(['explainability' => ['inputs' => ['snapshots', 'extractions', 'entities', 'sentiment', 'pr_value'], 'safe_to_summarize' => true]])
            ->search(['tokens' => ['title_current', 'canonical_url', 'domain', 'source_type'], 'rank' => 'workspace-scoped'])
            ->metadata([
                'provider' => self::class,
                'canonical_url_hash' => $page->canonical_url_hash,
                'source_type' => $page->source_type,
                'page_type' => $page->page_type,
            ]);
    }

    private function canViewMonitoredPage(?Authenticatable $user, mixed $page): bool
    {
        if (! $page instanceof MonitoredPage) {
            return true;
        }

        if (! $user) {
            return false;
        }

        return $user->can('view', $page);
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

        $value = (string) $status;

        return ['label' => Str::headline($value), 'value' => $value];
    }

    private function siteRelationship(MonitoredPage $page): ?ResourceRelationship
    {
        if (! $page->client_site_id) {
            return null;
        }

        return ResourceRelationship::make('site', 'scoped_to', ResourceType::SITE)
            ->resourceId($page->client_site_id)
            ->resourceKey(ResourceType::SITE.':'.$page->client_site_id)
            ->metadata(['source' => 'client_site_id']);
    }
}
