<?php

namespace App\Services\Agents;

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\Workspace;

class AgentAutomationSettingsResolver
{
    public const WORKSPACE_SETTINGS_KEY = 'agent_automation';

    /**
     * @return array<string, bool>
     */
    public function defaults(): array
    {
        return [
            'smart_suggestions_enabled' => true,
            'automatic_recommendation_generation_enabled' => true,
            'automatic_refresh_draft_creation_enabled' => false,
            'localization_checks_enabled' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, bool>
     */
    public function normalize(array $input): array
    {
        $defaults = $this->defaults();

        return collect($defaults)
            ->mapWithKeys(fn (bool $default, string $key): array => [$key => (bool) ($input[$key] ?? $default)])
            ->all();
    }

    /**
     * @return array<string, bool>
     */
    public function forWorkspace(?Workspace $workspace): array
    {
        $defaults = $this->defaults();
        $workspaceSettings = $workspace instanceof Workspace
            ? (array) data_get($workspace->visual_settings, self::WORKSPACE_SETTINGS_KEY, [])
            : [];

        return $this->normalize(array_replace($defaults, $workspaceSettings));
    }

    /**
     * @return array<string, bool>
     */
    public function forSite(?ClientSite $site): array
    {
        $workspaceDefaults = $site instanceof ClientSite
            ? $this->forWorkspace($site->workspace)
            : $this->defaults();
        $siteOverrides = $site instanceof ClientSite ? (array) ($site->automation_settings ?? []) : [];

        return $this->normalize(array_replace($workspaceDefaults, $siteOverrides));
    }

    /**
     * @return array<string, bool>
     */
    public function forDraft(Draft $draft): array
    {
        $draft->loadMissing('clientSite.workspace');

        return $this->forSite($draft->clientSite);
    }

    /**
     * @return array<string, bool>
     */
    public function forContent(Content $content): array
    {
        $content->loadMissing('clientSite.workspace');

        return $this->forSite($content->clientSite);
    }

    public function automaticRecommendationGenerationEnabledForSite(?ClientSite $site): bool
    {
        return (bool) ($this->forSite($site)['automatic_recommendation_generation_enabled'] ?? false);
    }

    public function smartSuggestionsEnabledForSite(?ClientSite $site): bool
    {
        $settings = $this->forSite($site);

        return (bool) ($settings['automatic_recommendation_generation_enabled'] ?? false)
            && (bool) ($settings['smart_suggestions_enabled'] ?? false);
    }

    public function localizationChecksEnabledForSite(?ClientSite $site): bool
    {
        $settings = $this->forSite($site);

        return (bool) ($settings['automatic_recommendation_generation_enabled'] ?? false)
            && (bool) ($settings['localization_checks_enabled'] ?? false);
    }

    public function automaticRefreshDraftCreationEnabledForSite(?ClientSite $site): bool
    {
        $settings = $this->forSite($site);

        return (bool) ($settings['automatic_recommendation_generation_enabled'] ?? false)
            && (bool) ($settings['automatic_refresh_draft_creation_enabled'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function storeWorkspaceSettings(Workspace $workspace, array $settings): void
    {
        $visualSettings = is_array($workspace->visual_settings) ? $workspace->visual_settings : [];
        $visualSettings[self::WORKSPACE_SETTINGS_KEY] = $this->normalize($settings);

        $workspace->forceFill([
            'visual_settings' => $visualSettings,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function storeSiteSettings(ClientSite $site, array $settings): void
    {
        $site->forceFill([
            'automation_settings' => $this->normalize($settings),
        ])->save();
    }
}
