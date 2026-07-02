<?php

namespace App\Support\Interaction\Providers;

use App\Models\ClientSite;
use App\Models\LlmTrackingQuery;
use App\Models\SeoAudit;
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

final class AppSiteInteractionProvider implements InteractionMetadataProvider
{
    public const ACTION_SITE_OPEN = 'app.site.open';
    public const ACTION_LLM_TRACKING_QUERY_OPEN = 'app.llm-tracking-query.open';
    public const ACTION_SEO_AUDIT_OPEN = 'app.seo-audit.open';

    public function resourceTypes(): array
    {
        return [
            ResourceType::SITE,
            ResourceType::LLM_TRACKING_QUERY,
            ResourceType::SEO_AUDIT,
        ];
    }

    public function actionKeys(): array
    {
        return [
            self::ACTION_SITE_OPEN,
            self::ACTION_LLM_TRACKING_QUERY_OPEN,
            self::ACTION_SEO_AUDIT_OPEN,
        ];
    }

    public function registerTypes(ResourceRegistry $resources): ResourceRegistry
    {
        return $resources
            ->register(ResourceType::make(ResourceType::SITE, 'Site')->icon('globe-2')->model(ClientSite::class)->primaryRoute('app.sites.show'))
            ->register(ResourceType::make(ResourceType::LLM_TRACKING_QUERY, 'LLM tracking query')->icon('messages-square')->model(LlmTrackingQuery::class)->primaryRoute('app.sites.llm-tracking.show'))
            ->register(ResourceType::make(ResourceType::SEO_AUDIT, 'SEO audit')->icon('scan-search')->model(SeoAudit::class)->primaryRoute('app.sites.seo-audits.show'));
    }

    public function registerActions(ActionRegistry $actions): ActionRegistry
    {
        return $actions
            ->register(
                Action::make(self::ACTION_SITE_OPEN, 'Open site', 'open')
                    ->description('Open an existing connected site.')
                    ->icon('globe-2')
                    ->route('app.sites.show', fn (ActionContext $context): array => ['site' => $context->resourceId])
                    ->resource(ResourceType::SITE)
                    ->authorize(fn (ActionContext $context): bool => $this->canViewSite($context->user, $context->metadata('site')))
                    ->visibleIn(Action::SURFACE_ROW, Action::SURFACE_CONTEXT_MENU, Action::SURFACE_COMMAND_PALETTE, Action::SURFACE_QUICK)
                    ->metadata(['provider' => self::class, 'route_backed' => true])
            )
            ->register(
                Action::make(self::ACTION_LLM_TRACKING_QUERY_OPEN, 'Open LLM tracking query', 'open')
                    ->description('Open an existing LLM tracking query.')
                    ->icon('messages-square')
                    ->route('app.sites.llm-tracking.show', fn (ActionContext $context): array => [
                        'site' => $context->metadata('site_id') ?? $context->siteId,
                        'query' => $context->resourceId,
                    ])
                    ->resource(ResourceType::LLM_TRACKING_QUERY)
                    ->authorize(fn (ActionContext $context): bool => $this->canViewSite($context->user, $context->metadata('site')))
                    ->visibleIn(Action::SURFACE_ROW, Action::SURFACE_CONTEXT_MENU, Action::SURFACE_COMMAND_PALETTE, Action::SURFACE_QUICK)
                    ->metadata(['provider' => self::class, 'route_backed' => true])
            )
            ->register(
                Action::make(self::ACTION_SEO_AUDIT_OPEN, 'Open SEO audit', 'open')
                    ->description('Open an existing SEO audit run.')
                    ->icon('scan-search')
                    ->route('app.sites.seo-audits.show', fn (ActionContext $context): array => [
                        'site' => $context->metadata('site_id') ?? $context->siteId,
                        'audit' => $context->resourceId,
                    ])
                    ->resource(ResourceType::SEO_AUDIT)
                    ->authorize(fn (ActionContext $context): bool => $this->canViewSite($context->user, $context->metadata('site')))
                    ->visibleIn(Action::SURFACE_ROW, Action::SURFACE_CONTEXT_MENU, Action::SURFACE_COMMAND_PALETTE, Action::SURFACE_QUICK)
                    ->metadata(['provider' => self::class, 'route_backed' => true])
            );
    }

    public function resourceFor(object $subject): ?Resource
    {
        return match (true) {
            $subject instanceof ClientSite => $this->siteResource($subject),
            $subject instanceof LlmTrackingQuery => $this->llmTrackingQueryResource($subject),
            $subject instanceof SeoAudit => $this->seoAuditResource($subject),
            default => null,
        };
    }

    public function siteResource(ClientSite $site): Resource
    {
        return Resource::forModel($this->key(ResourceType::SITE, $site), ResourceType::SITE, $site)
            ->title((string) ($site->name ?: $site->site_url ?: 'Connected site'))
            ->subtitle((string) ($site->site_url ?: $site->base_url ?: ''))
            ->status($this->status($site->status ?? ($site->is_active ? 'active' : 'inactive')))
            ->icon('globe-2')
            ->primaryRoute('app.sites.show', ['site' => $site->getKey()])
            ->authorize(fn (ResourceContext $context): bool => $this->canViewSite($context->user, $site))
            ->permission('manage', fn (ResourceContext $context): bool => $context->user !== null && $context->user->can('manage-organization'))
            ->actions(self::ACTION_SITE_OPEN)
            ->preview(['summary_fields' => ['status', 'type', 'site_url']])
            ->history(['timeline_key' => ResourceType::SITE])
            ->ai(['explainability' => ['inputs' => ['workspace', 'site_health', 'connected_tools'], 'safe_to_summarize' => true]])
            ->search(['tokens' => ['name', 'site_url'], 'rank' => 'workspace-scoped'])
            ->metadata(['provider' => self::class, 'policy' => 'organization_site_membership']);
    }

    public function llmTrackingQueryResource(LlmTrackingQuery $query): Resource
    {
        $site = $query->site;

        return Resource::forModel($this->key(ResourceType::LLM_TRACKING_QUERY, $query), ResourceType::LLM_TRACKING_QUERY, $query)
            ->title((string) ($query->name ?: $query->query_text ?: 'Untitled tracking query'))
            ->subtitle($site ? (string) ($site->name ?: $site->site_url ?: 'Connected site') : null)
            ->status($this->status($query->is_active ? 'active' : 'inactive'))
            ->icon('messages-square')
            ->primaryRoute('app.sites.llm-tracking.show', ['site' => $query->client_site_id, 'query' => $query->getKey()])
            ->authorize(fn (ResourceContext $context): bool => $this->canViewSite($context->user, $site))
            ->actions(self::ACTION_LLM_TRACKING_QUERY_OPEN)
            ->relationship($this->siteRelationship($site, 'client_site_id'))
            ->preview(['summary_fields' => ['query_text', 'target_brand', 'last_run_at']])
            ->history(['timeline_key' => ResourceType::LLM_TRACKING_QUERY])
            ->ai(['explainability' => ['inputs' => ['runs', 'aggregates', 'brand_terms'], 'safe_to_summarize' => true]])
            ->search(['tokens' => ['name', 'query_text', 'target_brand'], 'rank' => 'site-scoped'])
            ->metadata(['provider' => self::class, 'policy' => 'organization_site_membership']);
    }

    public function seoAuditResource(SeoAudit $audit): Resource
    {
        $site = $audit->site;

        return Resource::forModel($this->key(ResourceType::SEO_AUDIT, $audit), ResourceType::SEO_AUDIT, $audit)
            ->title('SEO audit #'.$audit->getKey())
            ->subtitle($site ? (string) ($site->name ?: $site->site_url ?: 'Connected site') : null)
            ->status($this->status($audit->status ?? null))
            ->icon('scan-search')
            ->primaryRoute('app.sites.seo-audits.show', ['site' => $audit->client_site_id, 'audit' => $audit->getKey()])
            ->authorize(fn (ResourceContext $context): bool => $this->canViewSite($context->user, $site))
            ->actions(self::ACTION_SEO_AUDIT_OPEN)
            ->relationship($this->siteRelationship($site, 'client_site_id'))
            ->preview(['summary_fields' => ['status', 'pages_crawled', 'issue_counts']])
            ->history(['timeline_key' => ResourceType::SEO_AUDIT])
            ->ai(['explainability' => ['inputs' => ['crawl', 'issues', 'fix_suggestions'], 'safe_to_summarize' => true]])
            ->search(['tokens' => ['seo', 'audit', 'site'], 'rank' => 'site-scoped'])
            ->notification(['channels' => ['in_app'], 'template' => 'seo_audit_completed'])
            ->metadata(['provider' => self::class, 'policy' => 'organization_site_membership']);
    }

    private function canViewSite(?Authenticatable $user, mixed $site): bool
    {
        if (! $site instanceof ClientSite) {
            return true;
        }

        if (! $user) {
            return false;
        }

        if ((bool) ($user->is_admin ?? false)) {
            return true;
        }

        return (int) ($site->workspace?->organization_id ?? 0) === (int) ($user->organization_id ?? 0);
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

    private function siteRelationship(?ClientSite $site, string $source): ResourceRelationship
    {
        return ResourceRelationship::make('site', 'scoped_to', ResourceType::SITE)
            ->resourceId($site?->getKey())
            ->resourceKey($site?->getKey() ? ResourceType::SITE.':'.$site->getKey() : null)
            ->title($site ? (string) ($site->name ?: $site->site_url ?: 'Site') : null)
            ->metadata(['source' => $source]);
    }
}
