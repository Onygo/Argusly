<?php

namespace App\Services\SourceBriefing;

use App\Models\ClientSite;
use App\Models\Persona;
use App\Models\Workspace;
use Illuminate\Support\Str;

class WorkspaceSourceContextBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(Workspace $workspace, ?ClientSite $site = null): array
    {
        $workspace->loadMissing([
            'companyProfile',
            'defaultBrandVoice',
            'brandVoices',
            'organization.personas',
            'siteCompetitors',
        ]);

        $companyProfile = $workspace->companyProfile;
        $brandVoice = $workspace->defaultBrandVoice ?: $workspace->brandVoices->first();
        $personas = $workspace->organization?->personas
            ? $workspace->organization->personas->where('status', Persona::STATUS_APPROVED)->take(4)
            : collect();
        $competitors = $site
            ? $site->competitors()->where('is_active', true)->limit(6)->get(['name', 'domain', 'notes'])
            : $workspace->siteCompetitors()->where('is_active', true)->limit(6)->get(['name', 'domain', 'notes']);

        return [
            'workspace' => [
                'id' => (string) $workspace->id,
                'name' => (string) $workspace->display_name,
                'default_language' => $workspace->defaultContentLanguageCode(),
            ],
            'company_profile' => [
                'company_name' => (string) ($companyProfile?->company_name ?: $workspace->organization?->name ?: ''),
                'industry' => (string) ($companyProfile?->industry ?? ''),
                'summary' => (string) ($companyProfile?->short_description ?: $companyProfile?->long_description ?: ''),
                'value_proposition' => (string) ($companyProfile?->value_proposition ?? ''),
                'services' => $companyProfile?->keyServicesArray() ?? [],
                'proof_points' => $companyProfile?->proofPointsArray() ?? [],
                'target_audience' => (string) ($companyProfile?->target_audience ?? ''),
                'banned_claims' => $this->splitLines((string) ($companyProfile?->banned_claims ?? '')),
            ],
            'brand_voice' => [
                'name' => (string) ($brandVoice?->name ?? ''),
                'tone_of_voice' => (string) ($brandVoice?->tone_of_voice ?? ''),
                'writing_style' => (string) ($brandVoice?->writing_style ?? ''),
                'do_rules' => $this->splitLines((string) ($brandVoice?->do_rules ?? '')),
                'dont_rules' => $this->splitLines((string) ($brandVoice?->dont_rules ?? '')),
                'preferred_terminology' => $brandVoice?->preferredTerminologyArray() ?? [],
                'disallowed_terminology' => $brandVoice?->disallowedTerminologyArray() ?? [],
            ],
            'personas' => $personas->map(fn (Persona $persona): array => [
                'name' => (string) $persona->name,
                'type' => (string) $persona->type,
                'role' => (string) data_get($persona->profile_data, 'role', ''),
                'summary' => (string) data_get($persona->profile_data, 'summary', ''),
                'goals' => (array) data_get($persona->profile_data, 'goals', []),
                'pain_points' => (array) data_get($persona->profile_data, 'pain_points', []),
            ])->values()->all(),
            'competitors' => $competitors->map(fn ($competitor): array => [
                'name' => (string) $competitor->name,
                'domain' => (string) $competitor->domain,
                'notes' => Str::limit((string) ($competitor->notes ?? ''), 220, ''),
            ])->values()->all(),
            'site' => $site ? [
                'id' => (string) $site->id,
                'name' => (string) $site->name,
                'site_url' => (string) ($site->site_url ?? ''),
                'type' => (string) $site->type,
            ] : null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function splitLines(string $value): array
    {
        return collect(preg_split('/\R+/', $value) ?: [])
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->values()
            ->all();
    }
}
