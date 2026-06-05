<?php

namespace App\Services\BrandContext;

use App\Models\BrandVoice;
use App\Models\Persona;
use App\Models\TeamMember;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BrandGenerationDefaultsService
{
    /**
     * @return array{
     *     brand_voice_id: string|null,
     *     buyer_persona_id: int|null,
     *     team_member_id: int|null,
     *     audience_persona: string
     * }
     */
    public function forWorkspace(Workspace $workspace): array
    {
        $workspace->loadMissing(['brandVoices', 'organization.personas', 'organization.teamMembers']);

        $brandVoices = $workspace->brandVoices
            ->sortBy(fn (BrandVoice $voice) => sprintf(
                '%d-%s',
                $voice->is_default ? 0 : 1,
                Str::lower((string) $voice->name)
            ))
            ->values();

        $personas = $workspace->organization?->personas
            ? $workspace->organization->personas
                ->where('status', Persona::STATUS_APPROVED)
                ->values()
            : collect();

        $teamMembers = $workspace->organization?->teamMembers
            ? $workspace->organization->teamMembers
                ->where('is_active', true)
                ->values()
            : collect();

        $defaultBrandVoice = $brandVoices->firstWhere('is_default', true) ?? $brandVoices->first();
        $defaultBuyerPersona = $this->defaultBuyerPersona($personas);
        $defaultTeamMember = $this->defaultTeamMember($teamMembers);

        return [
            'brand_voice_id' => $defaultBrandVoice instanceof BrandVoice ? (string) $defaultBrandVoice->id : null,
            'buyer_persona_id' => $defaultBuyerPersona instanceof Persona ? (int) $defaultBuyerPersona->id : null,
            'team_member_id' => $defaultTeamMember instanceof TeamMember ? (int) $defaultTeamMember->id : null,
            'audience_persona' => $this->audienceLabel($defaultBuyerPersona),
        ];
    }

    public function defaultBuyerPersona(Collection $personas): ?Persona
    {
        return $personas
            ->sortBy(fn (Persona $persona) => sprintf(
                '%d-%s',
                match ((string) $persona->type) {
                    Persona::TYPE_BUYER => 0,
                    Persona::TYPE_DECISION_MAKER => 1,
                    Persona::TYPE_USER => 2,
                    Persona::TYPE_INFLUENCER => 3,
                    default => 4,
                },
                Str::lower((string) $persona->name)
            ))
            ->first();
    }

    public function defaultTeamMember(Collection $teamMembers): ?TeamMember
    {
        $preferred = $teamMembers->first(function (TeamMember $member): bool {
            return (bool) data_get($member->profile_data, 'use_as_writing_persona', false);
        });

        if ($preferred instanceof TeamMember) {
            return $preferred;
        }

        return $teamMembers->count() === 1 ? $teamMembers->first() : null;
    }

    public function audienceLabel(?Persona $persona): string
    {
        if (! $persona) {
            return 'website visitor';
        }

        $role = trim((string) data_get($persona->profile_data, 'role', ''));

        return $role !== ''
            ? trim($persona->name . ' (' . $role . ')')
            : (string) $persona->name;
    }
}
