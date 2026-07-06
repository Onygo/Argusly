<?php

namespace App\Services\PageIntelligence\MarketPacks;

use App\Models\AlertRule;
use App\Models\ClientSite;
use App\Models\MarketPack;
use App\Models\MarketPackAlertTemplate;
use App\Models\MarketPackCompetitor;
use App\Models\MarketPackInstallation;
use App\Models\MarketPackSource;
use App\Models\MonitoredSource;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketPackInstaller
{
    public function install(
        Workspace $workspace,
        MarketPack|string $pack,
        ?ClientSite $site = null,
        array $customizedConfig = [],
    ): MarketPackInstallation {
        $pack = $this->resolvePack($pack);

        return DB::transaction(function () use ($workspace, $pack, $site, $customizedConfig): MarketPackInstallation {
            $installation = MarketPackInstallation::query()->updateOrCreate([
                'workspace_id' => $workspace->id,
                'market_pack_id' => $pack->id,
            ], [
                'organization_id' => $workspace->organization_id,
                'client_site_id' => $site?->id,
                'status' => MarketPackInstallation::STATUS_ACTIVE,
                'installed_at' => now(),
                'customized_config_json' => $customizedConfig,
                'source_overrides_json' => (array) ($customizedConfig['sources'] ?? []),
                'competitor_overrides_json' => (array) ($customizedConfig['competitors'] ?? []),
                'theme_overrides_json' => (array) ($customizedConfig['themes'] ?? []),
                'keyword_overrides_json' => (array) ($customizedConfig['keywords'] ?? []),
                'alert_overrides_json' => (array) ($customizedConfig['alerts'] ?? []),
                'scoring_overrides_json' => (array) ($customizedConfig['scoring_models'] ?? []),
                'metadata_json' => [
                    'market_pack_key' => $pack->key,
                    'installer_version' => 'page-intelligence-market-packs-v1',
                ],
            ]);

            $pack->loadMissing(['sources', 'competitors', 'alertTemplates']);

            foreach ($pack->sources as $source) {
                $this->installSource($workspace, $installation, $source, $site);
            }

            if ($site !== null) {
                foreach ($pack->competitors as $competitor) {
                    $this->installCompetitor($workspace, $installation, $competitor, $site);
                }
            }

            foreach ($pack->alertTemplates as $template) {
                $this->installAlertRule($workspace, $installation, $template, $site);
            }

            return $installation->refresh();
        });
    }

    private function resolvePack(MarketPack|string $pack): MarketPack
    {
        if ($pack instanceof MarketPack) {
            return $pack;
        }

        $resolved = MarketPack::query()
            ->where('key', $pack)
            ->where('status', MarketPack::STATUS_ACTIVE)
            ->first();

        if (! $resolved instanceof MarketPack) {
            throw (new ModelNotFoundException())->setModel(MarketPack::class, [$pack]);
        }

        return $resolved;
    }

    private function installSource(
        Workspace $workspace,
        MarketPackInstallation $installation,
        MarketPackSource $template,
        ?ClientSite $site,
    ): MonitoredSource {
        $sourceOverrides = $this->overridesFor($installation->source_overrides_json, $template->key);
        $metadata = array_replace_recursive((array) ($template->metadata_json ?? []), (array) ($sourceOverrides['metadata_json'] ?? []), [
            'market_pack_id' => $installation->market_pack_id,
            'market_pack_key' => $installation->marketPack?->key,
            'market_pack_installation_id' => $installation->id,
            'market_pack_source_id' => $template->id,
            'market_pack_source_key' => $template->key,
        ]);

        $attributes = [
            'workspace_id' => $workspace->id,
            'source_type' => (string) ($sourceOverrides['source_type'] ?? $template->source_type),
            'name' => (string) ($sourceOverrides['name'] ?? $template->name),
            'base_url' => $sourceOverrides['base_url'] ?? $template->base_url,
        ];

        $source = MonitoredSource::query()->firstOrNew($attributes);
        $source->forceFill([
            'organization_id' => $workspace->organization_id,
            'client_site_id' => $site?->id,
            'domain' => $sourceOverrides['domain'] ?? $template->domain ?? $this->domainFromUrl((string) ($attributes['base_url'] ?? '')),
            'status' => $sourceOverrides['status'] ?? MonitoredSource::STATUS_ACTIVE,
            'trust_level' => (int) ($sourceOverrides['trust_level'] ?? $template->trust_level),
            'authority_score' => $sourceOverrides['authority_score'] ?? $template->authority_score,
            'polling_frequency' => $sourceOverrides['polling_frequency'] ?? $template->polling_frequency,
            'crawl_policy_json' => array_replace_recursive((array) ($template->crawl_policy_json ?? []), (array) ($sourceOverrides['crawl_policy_json'] ?? [])),
            'fetch_config_json' => array_replace_recursive((array) ($template->fetch_config_json ?? []), (array) ($sourceOverrides['fetch_config_json'] ?? [])),
            'discovery_config_json' => array_replace_recursive((array) ($template->discovery_config_json ?? []), (array) ($sourceOverrides['discovery_config_json'] ?? [])),
            'metadata_json' => $metadata,
        ])->save();

        return $source;
    }

    private function installCompetitor(
        Workspace $workspace,
        MarketPackInstallation $installation,
        MarketPackCompetitor $template,
        ClientSite $site,
    ): SiteCompetitor {
        $competitorOverrides = $this->overridesFor($installation->competitor_overrides_json, $template->key);
        $domain = (string) ($competitorOverrides['domain'] ?? $template->domain);

        $competitor = SiteCompetitor::query()->firstOrNew([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'domain' => $domain,
        ]);

        $aliases = collect((array) ($template->aliases_json ?? []))
            ->merge((array) ($competitorOverrides['aliases'] ?? []))
            ->filter()
            ->values()
            ->all();

        $competitor->forceFill([
            'name' => (string) ($competitorOverrides['name'] ?? $template->name),
            'notes' => trim(implode("\n", array_filter([
                'Installed from Page Intelligence market pack: '.$installation->marketPack?->name,
                $aliases === [] ? null : 'Aliases: '.implode(', ', $aliases),
            ]))),
            'is_active' => (bool) ($competitorOverrides['is_active'] ?? true),
        ])->save();

        return $competitor;
    }

    private function installAlertRule(
        Workspace $workspace,
        MarketPackInstallation $installation,
        MarketPackAlertTemplate $template,
        ?ClientSite $site,
    ): AlertRule {
        $alertOverrides = $this->overridesFor($installation->alert_overrides_json, $template->key);

        $rule = AlertRule::query()->firstOrNew([
            'workspace_id' => $workspace->id,
            'trigger' => (string) ($alertOverrides['trigger'] ?? $template->trigger),
            'name' => (string) ($alertOverrides['name'] ?? $template->name),
        ]);

        $rule->forceFill([
            'organization_id' => $workspace->organization_id,
            'client_site_id' => $site?->id,
            'conditions_json' => array_replace_recursive((array) ($template->conditions_json ?? []), (array) ($alertOverrides['conditions_json'] ?? [])),
            'cooldown_minutes' => (int) ($alertOverrides['cooldown_minutes'] ?? $template->cooldown_minutes),
            'severity' => (string) ($alertOverrides['severity'] ?? $template->severity),
            'is_active' => (bool) ($alertOverrides['is_active'] ?? $template->is_active),
            'metadata_json' => [
                'market_pack_id' => $installation->market_pack_id,
                'market_pack_key' => $installation->marketPack?->key,
                'market_pack_installation_id' => $installation->id,
                'market_pack_alert_template_id' => $template->id,
                'market_pack_alert_template_key' => $template->key,
            ],
        ])->save();

        return $rule;
    }

    private function overridesFor(?array $overrides, string $key): array
    {
        $overrides = (array) $overrides;

        return (array) (Arr::get($overrides, $key, Arr::get($overrides, Str::snake($key), [])));
    }

    private function domainFromUrl(string $url): ?string
    {
        if (trim($url) === '') {
            return null;
        }

        return Str::lower((string) (parse_url($url, PHP_URL_HOST) ?: '')) ?: null;
    }
}
