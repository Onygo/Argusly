<?php

namespace App\Services\WorkspaceIntelligence;

use App\Models\CompanyProfile;
use App\Models\EnrichmentRun;
use App\Models\Organization;
use App\Models\OrganizationProfile;
use App\Models\Persona;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class WorkspaceIntelligenceService
{
    public function __construct(
        private readonly WebsiteCrawlerService $crawler,
        private readonly ProfileInputNormalizationService $normalizer,
        private readonly AIAnalysisService $analysis,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createOrganizationProposalRun(Organization $organization, array $payload, User $actor): EnrichmentRun
    {
        $normalized = $this->normalizer->normalizeOrganizationInput($payload);

        return EnrichmentRun::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'enrichable_type' => EnrichmentRun::ENRICHABLE_ORGANIZATION,
            'enrichment_type' => EnrichmentRun::TYPE_ORGANIZATION,
            'source_type' => $normalized['source_type'],
            'source_payload' => $normalized['source_payload'],
            'status' => EnrichmentRun::STATUS_QUEUED,
            'progress' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createBuyerPersonaRun(Organization $organization, array $payload, User $actor): EnrichmentRun
    {
        $normalized = $this->normalizer->normalizePersonaInput($payload);

        return EnrichmentRun::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'enrichable_type' => EnrichmentRun::ENRICHABLE_PERSONA,
            'enrichment_type' => EnrichmentRun::TYPE_BUYER_PERSONA,
            'source_type' => $normalized['source_type'],
            'source_payload' => $normalized['source_payload'],
            'status' => EnrichmentRun::STATUS_QUEUED,
            'progress' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createTeamMemberPersonaRun(Organization $organization, TeamMember $teamMember, array $payload, User $actor): EnrichmentRun
    {
        $normalized = $this->normalizer->normalizeTeamMemberInput($payload);

        return EnrichmentRun::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'enrichable_type' => EnrichmentRun::ENRICHABLE_TEAM_MEMBER,
            'enrichable_id' => $teamMember->id,
            'enrichment_type' => EnrichmentRun::TYPE_TEAM_MEMBER_PERSONA,
            'source_type' => $normalized['source_type'],
            'source_payload' => $normalized['source_payload'],
            'status' => EnrichmentRun::STATUS_QUEUED,
            'progress' => 0,
        ]);
    }

    public function generateOrganizationProposal(EnrichmentRun $run): EnrichmentRun
    {
        return $this->processRun($run, function (EnrichmentRun $run, array $source): array {
            $organization = $run->organization()->with(['organizationProfile', 'workspaces.companyProfile', 'personas'])->firstOrFail();
            $websitePayload = $this->extractWebsitePayload($source, 0.45, $run);

            $context = [
                'organization' => $this->organizationContext($organization),
                'approved_profile' => $this->approvedOrganizationProfile($organization),
                'approved_personas' => $this->approvedPersonas($organization),
                'source_text' => $websitePayload['source_text'],
                'source_metadata' => $websitePayload['source_metadata'],
            ];

            return [
                'extracted_payload' => $websitePayload['extracted_payload'],
                'ai_payload' => $this->analysis->generateBrandProfile($context),
            ];
        });
    }

    public function generateBuyerPersonas(EnrichmentRun $run): EnrichmentRun
    {
        return $this->processRun($run, function (EnrichmentRun $run, array $source): array {
            $organization = $run->organization()->with(['organizationProfile', 'workspaces.companyProfile', 'personas'])->firstOrFail();
            $websitePayload = $this->extractWebsitePayload($source, 0.5, $run);

            return [
                'extracted_payload' => $websitePayload['extracted_payload'],
                'ai_payload' => $this->analysis->generateBuyerPersonas([
                    'organization' => $this->organizationContext($organization),
                    'approved_profile' => $this->approvedOrganizationProfile($organization),
                    'approved_personas' => $this->approvedPersonas($organization),
                    'source_text' => $websitePayload['source_text'],
                    'source_metadata' => $websitePayload['source_metadata'],
                ]),
            ];
        });
    }

    public function generateTeamMemberPersona(EnrichmentRun $run): EnrichmentRun
    {
        return $this->processRun($run, function (EnrichmentRun $run, array $source): array {
            $organization = $run->organization()->with(['organizationProfile', 'workspaces.companyProfile'])->firstOrFail();
            $teamMember = TeamMember::query()
                ->where('organization_id', $organization->id)
                ->whereKey($run->enrichable_id)
                ->firstOrFail();

            $sourceText = trim((string) ($source['input_text'] ?? $source['pasted_profile_text'] ?? $teamMember->bio_source_text ?? ''));
            $sourceMetadata = array_filter([
                'linkedin_reference_url' => $source['linkedin_reference_url'] ?? null,
                'public_profile_url' => $teamMember->public_profile_url,
            ]);

            return [
                'extracted_payload' => [
                    'source_text' => $sourceText,
                    'metadata' => $sourceMetadata,
                ],
                'ai_payload' => $this->analysis->generateTeamMemberExpertPersona([
                    'organization' => $this->organizationContext($organization),
                    'approved_profile' => $this->approvedOrganizationProfile($organization),
                    'team_member' => [
                        'name' => (string) $teamMember->name,
                        'title' => (string) ($teamMember->title ?? $teamMember->role ?? ''),
                        'email' => (string) ($teamMember->email ?? ''),
                        'bio_source_text' => (string) ($teamMember->bio_source_text ?? ''),
                        'profile_data' => (array) ($teamMember->profile_data ?? []),
                    ],
                    'source_text' => $sourceText,
                    'source_metadata' => $sourceMetadata,
                ]),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function approveRun(EnrichmentRun $run, array $payload, User $actor): array
    {
        return match ($run->enrichment_type) {
            EnrichmentRun::TYPE_ORGANIZATION => $this->approveOrganizationSections($run, $payload, $actor),
            EnrichmentRun::TYPE_BUYER_PERSONA => $this->approvePersonaSelections($run, $payload, $actor),
            EnrichmentRun::TYPE_TEAM_MEMBER_PERSONA => $this->approveTeamMemberSections($run, $payload, $actor),
            default => throw new RuntimeException('Unsupported enrichment type.'),
        };
    }

    public function rejectRun(EnrichmentRun $run): void
    {
        $run->update([
            'status' => EnrichmentRun::STATUS_REJECTED,
            'progress' => 1,
        ]);
    }

    public function markFailed(EnrichmentRun $run, \Throwable $e): void
    {
        $run->update([
            'status' => EnrichmentRun::STATUS_FAILED,
            'progress' => 1,
            'error_message' => mb_substr($e->getMessage(), 0, 5000),
        ]);
    }

    /**
     * @param  callable(EnrichmentRun, array<string,mixed>): array{extracted_payload: array<string,mixed>, ai_payload: array<string,mixed>}  $callback
     */
    private function processRun(EnrichmentRun $run, callable $callback): EnrichmentRun
    {
        $run->update([
            'status' => EnrichmentRun::STATUS_RUNNING,
            'progress' => 0.1,
            'error_message' => null,
        ]);

        $source = (array) ($run->source_payload ?? []);
        $result = $callback($run, $source);

        $run->update([
            'extracted_payload' => $result['extracted_payload'],
            'ai_payload' => $result['ai_payload'],
            'status' => EnrichmentRun::STATUS_DRAFT,
            'progress' => 1,
        ]);

        return $run->fresh();
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array{source_text: string, source_metadata: array<string,mixed>, extracted_payload: array<string,mixed>}
     */
    private function extractWebsitePayload(array $source, float $progressAfterCrawl, EnrichmentRun $run): array
    {
        if (($run->source_type ?? '') === 'website_url' && ! empty($source['website_url'])) {
            $crawl = $this->crawler->crawlWebsite((string) $source['website_url'], 5);
            $run->update([
                'progress' => $progressAfterCrawl,
                'extracted_payload' => $crawl,
            ]);

            return [
                'source_text' => (string) ($crawl['combined_text'] ?? ''),
                'source_metadata' => (array) ($crawl['metadata'] ?? []),
                'extracted_payload' => $crawl,
            ];
        }

        $sourceText = trim((string) ($source['input_text'] ?? $source['manual_text'] ?? $source['pasted_profile_text'] ?? ''));

        return [
            'source_text' => $sourceText,
            'source_metadata' => Arr::except($source, ['input_text', 'manual_text', 'pasted_profile_text']),
            'extracted_payload' => [
                'source_text' => $sourceText,
                'metadata' => Arr::except($source, ['input_text', 'manual_text', 'pasted_profile_text']),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function approveOrganizationSections(EnrichmentRun $run, array $payload, User $actor): array
    {
        $sections = collect($payload['sections'] ?? OrganizationProfile::SECTION_KEYS)
            ->filter(fn ($section) => in_array((string) $section, OrganizationProfile::SECTION_KEYS, true))
            ->values()
            ->all();
        $replaceExisting = (bool) ($payload['replace_existing'] ?? false);
        $proposal = (array) ($run->ai_payload ?? []);

        $profile = OrganizationProfile::query()->firstOrCreate(
            ['organization_id' => $run->organization_id],
            ['created_by' => $actor->id]
        );

        $approved = [];
        $blocked = [];

        foreach ($sections as $section) {
            $existingValue = $profile->getAttribute($section);
            $newValue = $proposal[$section] ?? null;

            if ($this->hasMeaningfulValue($existingValue) && ! $replaceExisting) {
                $blocked[] = $section;
                continue;
            }

            $profile->setAttribute($section, $newValue);
            $approved[] = $section;
        }

        if ($approved === []) {
            throw new RuntimeException('Selected sections already contain approved data. Confirm replacement to continue.');
        }

        $profile->updated_by = $actor->id;
        $profile->save();

        $this->syncLegacyOrganizationProfiles($run->organization()->firstOrFail(), $profile);

        $run->update([
            'status' => count($approved) === count($sections) ? EnrichmentRun::STATUS_APPROVED : EnrichmentRun::STATUS_REVIEWED,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        return [
            'approved_sections' => $approved,
            'blocked_sections' => $blocked,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function approvePersonaSelections(EnrichmentRun $run, array $payload, User $actor): array
    {
        $proposals = collect((array) data_get($run->ai_payload, 'personas', []))->values();
        $indexes = collect($payload['persona_indexes'] ?? $proposals->keys()->all())
            ->map(fn ($index) => (int) $index)
            ->filter(fn (int $index) => $proposals->has($index))
            ->values();

        if ($indexes->isEmpty()) {
            throw new RuntimeException('Select at least one persona proposal to approve.');
        }

        $createdIds = [];

        foreach ($indexes as $index) {
            $proposal = (array) $proposals->get($index, []);

            $persona = Persona::query()->create([
                'organization_id' => $run->organization_id,
                'type' => (string) ($proposal['type'] ?? Persona::TYPE_BUYER),
                'name' => (string) ($proposal['name'] ?? 'Untitled Persona'),
                'source_type' => (string) $run->source_type,
                'source_payload' => array_merge((array) ($run->source_payload ?? []), [
                    'enrichment_run_id' => (string) $run->id,
                    'proposal_index' => $index,
                ]),
                'profile_data' => Arr::except($proposal, ['type', 'name']),
                'status' => Persona::STATUS_APPROVED,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $createdIds[] = $persona->id;
        }

        $run->update([
            'status' => $indexes->count() === $proposals->count() ? EnrichmentRun::STATUS_APPROVED : EnrichmentRun::STATUS_REVIEWED,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        return ['persona_ids' => $createdIds];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function approveTeamMemberSections(EnrichmentRun $run, array $payload, User $actor): array
    {
        $teamMember = TeamMember::query()
            ->where('organization_id', $run->organization_id)
            ->whereKey($run->enrichable_id)
            ->firstOrFail();

        $proposal = (array) data_get($run->ai_payload, 'profile_data', []);
        $sections = collect($payload['sections'] ?? array_keys($proposal))
            ->map(fn ($section) => (string) $section)
            ->filter(fn (string $section) => array_key_exists($section, $proposal))
            ->values()
            ->all();
        $replaceExisting = (bool) ($payload['replace_existing'] ?? false);
        $current = (array) ($teamMember->profile_data ?? []);

        $approved = [];
        $blocked = [];

        foreach ($sections as $section) {
            $existingValue = $current[$section] ?? null;

            if ($this->hasMeaningfulValue($existingValue) && ! $replaceExisting) {
                $blocked[] = $section;
                continue;
            }

            $current[$section] = $proposal[$section];
            $approved[] = $section;
        }

        if ($approved === []) {
            throw new RuntimeException('Selected profile sections already contain approved data. Confirm replacement to continue.');
        }

        $teamMember->update([
            'profile_data' => $current,
            'bio_source_text' => (string) (((array) $run->source_payload)['pasted_profile_text'] ?? $teamMember->bio_source_text),
            'status' => TeamMember::STATUS_APPROVED,
            'updated_by' => $actor->id,
            'expertise' => implode(', ', (array) ($current['expertise_areas'] ?? [])),
            'writing_perspective' => (string) ($current['point_of_view'] ?? $teamMember->writing_perspective),
            'personality_traits' => implode(', ', (array) ($current['tone_traits'] ?? [])),
        ]);

        $run->update([
            'status' => count($approved) === count($sections) ? EnrichmentRun::STATUS_APPROVED : EnrichmentRun::STATUS_REVIEWED,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        return [
            'approved_sections' => $approved,
            'blocked_sections' => $blocked,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function organizationContext(Organization $organization): array
    {
        $companyProfile = $organization->workspaces->sortBy('created_at')->first()?->companyProfile;

        return [
            'name' => (string) $organization->name,
            'industry' => (string) ($companyProfile?->industry ?? $organization->industry ?? ''),
            'company_description' => (string) ($organization->company_description ?? ''),
            'positioning_statement' => (string) ($organization->positioning_statement ?? ''),
            'target_audience' => (string) ($companyProfile?->target_audience ?? $organization->target_audience ?? ''),
            'value_propositions' => $this->splitLines((string) ($companyProfile?->value_propositions ?? '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function approvedOrganizationProfile(Organization $organization): array
    {
        $profile = $organization->organizationProfile;

        if (! $profile) {
            return [];
        }

        return Arr::only($profile->toArray(), OrganizationProfile::SECTION_KEYS);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function approvedPersonas(Organization $organization): array
    {
        return $organization->personas
            ->where('status', Persona::STATUS_APPROVED)
            ->map(fn (Persona $persona): array => [
                'type' => (string) $persona->type,
                'name' => (string) $persona->name,
                'profile_data' => (array) ($persona->profile_data ?? []),
            ])
            ->values()
            ->all();
    }

    private function syncLegacyOrganizationProfiles(Organization $organization, OrganizationProfile $profile): void
    {
        $organization->update([
            'brand_profile' => array_filter([
                'brand_summary' => $profile->brand_summary,
                'tone_of_voice' => $profile->tone_of_voice,
                'audience_profiles' => $profile->audience_profiles,
                'offerings' => $profile->offerings,
                'differentiators' => $profile->differentiators,
            ], fn ($value) => $this->hasMeaningfulValue($value)),
            'seo_profile' => array_filter([
                'strategic_topics' => $profile->strategic_topics,
                'seo_topics' => $profile->seo_topics,
            ], fn ($value) => $this->hasMeaningfulValue($value)),
            'design_profile' => array_filter([
                'visual_direction' => $profile->visual_direction,
            ], fn ($value) => $this->hasMeaningfulValue($value)),
        ]);

        $workspace = $organization->workspaces()->orderBy('created_at')->first();
        if (! $workspace) {
            return;
        }

        CompanyProfile::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'company_name' => $organization->name,
                'industry' => (string) ($organization->industry ?? ''),
                'value_propositions' => implode("\n", (array) ($profile->offerings ?? [])),
                'proof_points' => implode("\n", (array) ($profile->differentiators ?? [])),
                'target_audience' => collect((array) ($profile->audience_profiles ?? []))
                    ->map(function ($audience): string {
                        $name = trim((string) data_get($audience, 'name', ''));
                        $summary = trim((string) data_get($audience, 'summary', ''));

                        return trim($name . ($summary !== '' ? ': ' . $summary : ''));
                    })
                    ->filter()
                    ->implode("\n"),
            ]
        );
    }

    private function hasMeaningfulValue(mixed $value): bool
    {
        if (is_array($value)) {
            return collect($value)->filter(fn ($item) => $this->hasMeaningfulValue($item))->isNotEmpty();
        }

        return trim((string) $value) !== '';
    }

    /**
     * @return array<int, string>
     */
    private function splitLines(string $value): array
    {
        return collect(preg_split('/\R+/', $value) ?: [])
            ->map(fn ($line) => trim((string) $line))
            ->filter()
            ->values()
            ->all();
    }
}
