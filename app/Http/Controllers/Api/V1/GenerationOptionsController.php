<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BrandVoice;
use App\Models\Persona;
use App\Models\TeamMember;
use App\Services\BrandContext\BrandGenerationDefaultsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GenerationOptionsController extends Controller
{
    public function index(Request $request, BrandGenerationDefaultsService $brandGenerationDefaults): JsonResponse
    {
        $siteToken = $request->attributes->get('siteToken');
        if (! $siteToken || ! $siteToken->hasScope('briefs:write')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $clientSite = $request->attributes->get('clientSite');
        if (! $clientSite) {
            return response()->json(['error' => 'Client site not resolved'], 401);
        }

        $workspaceId = (string) $clientSite->workspace_id;
        $organizationId = (int) ($clientSite->workspace?->organization_id ?? 0);
        if ($organizationId <= 0) {
            return response()->json(['error' => 'Organization not resolved'], 422);
        }

        $brandVoices = BrandVoice::query()
            ->where(function ($query) use ($workspaceId, $organizationId): void {
                $query->where('workspace_id', $workspaceId)
                    ->orWhere('organization_id', $organizationId);
            })
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'default_language', 'default_tone', 'is_default'])
            ->map(fn (BrandVoice $voice): array => [
                'id' => (string) $voice->id,
                'name' => (string) $voice->name,
                'default_language' => (string) ($voice->default_language ?? 'en'),
                'default_tone' => (string) ($voice->default_tone ?? ''),
                'is_default' => (bool) $voice->is_default,
            ])
            ->values();

        $buyerPersonas = Persona::query()
            ->where('organization_id', $organizationId)
            ->where('status', Persona::STATUS_APPROVED)
            ->orderBy('type')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'profile_data'])
            ->map(fn (Persona $persona): array => [
                'id' => (int) $persona->id,
                'name' => (string) $persona->name,
                'type' => (string) $persona->type,
                'role' => (string) data_get($persona->profile_data, 'role', ''),
            ])
            ->values();

        $teamMembers = TeamMember::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'title', 'role', 'expertise', 'profile_data'])
            ->map(fn (TeamMember $member): array => [
                'id' => (int) $member->id,
                'name' => (string) $member->name,
                'role' => (string) ($member->title ?? $member->role ?? ''),
                'expertise' => (string) ($member->expertise ?: implode(', ', (array) data_get($member->profile_data, 'expertise_areas', []))),
            ])
            ->values();
        $clientSite->loadMissing('workspace.organization.personas', 'workspace.organization.teamMembers', 'workspace.brandVoices');
        $generationDefaults = $brandGenerationDefaults->forWorkspace($clientSite->workspace);

        return response()->json([
            'defaults' => [
                'brand_voice_id' => $generationDefaults['brand_voice_id'],
                'buyer_persona_id' => $generationDefaults['buyer_persona_id'],
                'team_member_id' => $generationDefaults['team_member_id'],
                'preferred_length' => 'medium',
            ],
            'brand_voices' => $brandVoices,
            'buyer_personas' => $buyerPersonas,
            'team_members' => $teamMembers,
            'lengths' => [
                ['key' => 'short', 'label' => 'Short (600-800)', 'min_words' => 600, 'max_words' => 800],
                ['key' => 'medium', 'label' => 'Medium (900-1200)', 'min_words' => 900, 'max_words' => 1200],
                ['key' => 'long', 'label' => 'Long (1400-1800)', 'min_words' => 1400, 'max_words' => 1800],
                ['key' => 'pillar', 'label' => 'Pillar (2200-3000)', 'min_words' => 2200, 'max_words' => 3000],
            ],
        ]);
    }
}
