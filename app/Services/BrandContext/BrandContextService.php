<?php

namespace App\Services\BrandContext;

use App\Models\BrandContext;
use App\Models\BrandVoice;
use App\Models\CompanyProfile;
use App\Models\EnrichmentRun;
use App\Models\Organization;
use App\Models\Persona;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceIntelligence\AIAnalysisService;
use App\Services\WorkspaceIntelligence\WebsiteCrawlerService;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class BrandContextService
{
    public function __construct(
        private readonly WebsiteCrawlerService $crawler,
        private readonly AIAnalysisService $analysis,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createBrandContextRun(Organization $organization, array $payload, User $actor): EnrichmentRun
    {
        $existingRun = $this->findActiveBrandContextRun($organization);
        if ($existingRun) {
            return $existingRun;
        }

        $sourceType = $payload['input_type'] ?? 'text';
        $sourcePayload = $this->normalizeSourcePayload($payload);
        $requestedSections = $payload['sections'] ?? EnrichmentRun::BRAND_SECTIONS;
        $generationMode = $payload['generation_mode'] ?? EnrichmentRun::GENERATION_MODE_FULL;
        $queuedAt = now();

        return EnrichmentRun::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'enrichable_type' => EnrichmentRun::ENRICHABLE_ORGANIZATION,
            'enrichment_type' => EnrichmentRun::TYPE_BRAND_CONTEXT,
            'source_type' => $sourceType,
            'source_payload' => $sourcePayload,
            'requested_sections' => $requestedSections,
            'generation_mode' => $generationMode,
            'status' => EnrichmentRun::STATUS_QUEUED,
            'progress' => 0,
            'queued_at' => $queuedAt,
            'last_heartbeat_at' => $queuedAt,
        ]);
    }

    public function generateBrandContext(EnrichmentRun $run): EnrichmentRun
    {
        $run = $run->fresh();

        $this->syncRunState($run, [
            'status' => EnrichmentRun::STATUS_PROCESSING,
            'progress' => 0.1,
            'error_message' => null,
            'failure_reason' => null,
            'started_at' => $run->started_at ?? now(),
            'failed_at' => null,
            'completed_at' => null,
            'diagnostic_payload' => null,
            'last_heartbeat_at' => now(),
        ]);

        $organization = $run->organization()->with([
            'organizationProfile',
            'workspaces.companyProfile',
            'workspaces.brandVoices',
            'personas',
            'teamMembers',
        ])->firstOrFail();
        $workspace = $this->firstWorkspaceForOrganization($organization);

        $source = (array) ($run->source_payload ?? []);
        $sourceText = $this->extractSourceText($source, $run);

        $this->syncRunState($run, [
            'progress' => 0.3,
            'last_heartbeat_at' => now(),
        ]);

        $context = [
            'organization' => $this->buildOrganizationContext($organization),
            'existing_profile' => $this->buildExistingProfile($organization),
            'existing_voices' => $this->buildExistingVoices($organization),
            'existing_personas' => $this->buildExistingPersonas($organization),
            'existing_team_members' => $this->buildExistingTeamMembers($organization),
            'source_text' => $sourceText,
            'requested_sections' => $run->requested_sections ?? EnrichmentRun::BRAND_SECTIONS,
            'generation_mode' => $run->generation_mode ?? EnrichmentRun::GENERATION_MODE_FULL,
        ];

        $this->syncRunState($run, [
            'progress' => 0.4,
            'last_heartbeat_at' => now(),
        ]);

        $analysisResult = $this->analysis->generateBrandContextDetailed($context);
        $aiPayload = (array) ($analysisResult['payload'] ?? []);
        $generatedSections = $this->extractGeneratedSections($aiPayload, $run);
        $sectionsCount = count($generatedSections);
        $diagnostics = $this->buildDiagnostics($run, $workspace, $analysisResult, $sourceText, $sectionsCount);

        if (($analysisResult['parser_error'] ?? null) !== null) {
            $parserError = (string) $analysisResult['parser_error'];
            $isJsonParserError = $parserError === 'json_decode_failed';

            $this->failRun(
                $run,
                $isJsonParserError ? 'parser_error' : 'generation_exception',
                $isJsonParserError ? 'The AI response could not be parsed into editable brand sections.' : $parserError,
                $diagnostics
            );

            return $run->fresh();
        }

        if ($sectionsCount === 0) {
            $this->completeEmptyRun(
                $run,
                $aiPayload,
                $sourceText,
                $context,
                'no_sections_generated',
                'The AI run finished, but did not return usable brand context.',
                $diagnostics
            );

            return $run->fresh();
        }

        $brandContext = DB::transaction(function () use ($workspace, $sourceText, $run, $context, $generatedSections, $diagnostics) {
            $brandContext = $workspace
                ? BrandContext::query()->create([
                    'workspace_id' => (string) $workspace->id,
                    'raw_input' => $sourceText,
                    'structured_json' => $this->buildStructuredContextPayload($generatedSections),
                    'source_type' => (string) $run->source_type,
                ])
                : null;

            $extractedPayload = array_filter(
                array_merge((array) ($run->extracted_payload ?? []), [
                    'source_text' => $sourceText,
                    'context' => Arr::only($context, ['organization', 'requested_sections', 'generation_mode']),
                    'brand_context_id' => $brandContext?->id,
                ]),
                static fn ($value) => $value !== null
            );

            $this->syncRunState($run, [
                'ai_payload' => $generatedSections,
                'extracted_payload' => $extractedPayload,
                'status' => EnrichmentRun::STATUS_COMPLETED,
                'progress' => 1,
                'completed_at' => now(),
                'failed_at' => null,
                'error_message' => null,
                'failure_reason' => null,
                'diagnostic_payload' => $diagnostics,
                'last_heartbeat_at' => now(),
            ]);

            return $brandContext;
        });

        Log::info('Brand context generated', [
            'run_id' => (string) $run->id,
            'organization_id' => (int) $run->organization_id,
            'workspace_id' => (string) ($workspace?->id ?? ''),
            'brand_context_id' => (string) ($brandContext?->id ?? ''),
            'sections' => array_keys($generatedSections),
        ]);

        return $run->fresh();
    }

    public function retryBrandContextRun(EnrichmentRun $run, User $actor): EnrichmentRun
    {
        $organization = $run->organization()->firstOrFail();
        $activeRun = $this->findActiveBrandContextRun($organization);

        if ($activeRun && (string) $activeRun->id !== (string) $run->id) {
            return $activeRun;
        }

        if ($run->isInProgress()) {
            return $run;
        }

        return $this->createBrandContextRun($organization, [
            'input_type' => $run->source_type,
            'sections' => $run->requested_sections ?? EnrichmentRun::BRAND_SECTIONS,
            'generation_mode' => $run->generation_mode ?? EnrichmentRun::GENERATION_MODE_FULL,
            ...$this->denormalizeSourcePayload($run),
        ], $actor);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function approveSections(EnrichmentRun $run, array $payload, User $actor): array
    {
        if (! $run->isCompletedSuccessfully()) {
            throw new RuntimeException('This run is not ready to be applied.');
        }

        $sections = collect($payload['sections'] ?? $run->requested_sections ?? [])
            ->filter(fn ($section) => in_array((string) $section, EnrichmentRun::BRAND_SECTIONS, true))
            ->values()
            ->all();

        if (empty($sections)) {
            throw new RuntimeException('Select at least one section to apply.');
        }

        $availableSections = array_keys($this->extractGeneratedSections((array) ($run->ai_payload ?? []), $run));

        if ($availableSections === []) {
            throw new RuntimeException('This run does not contain any generated sections to apply.');
        }

        $missingSections = array_values(array_diff($sections, $availableSections));
        if ($missingSections !== []) {
            throw new RuntimeException('One or more selected sections are not available in this run.');
        }

        $organization = $run->organization()->with(['workspaces'])->firstOrFail();
        $workspace = $this->firstWorkspaceForOrganization($organization);
        $aiPayload = (array) ($run->ai_payload ?? []);
        $brandContext = $this->resolveBrandContext($run, $workspace, $aiPayload);
        $generationMode = (string) ($run->generation_mode ?? EnrichmentRun::GENERATION_MODE_FULL);
        $results = [];

        DB::transaction(function () use ($sections, $aiPayload, $organization, $workspace, $actor, $brandContext, $generationMode, &$results) {
            foreach ($sections as $section) {
                $sectionData = $aiPayload[$section] ?? null;
                if (! $sectionData) {
                    continue;
                }

                $results[$section] = match ($section) {
                    'company_profile' => $this->applyCompanyProfile($organization, $workspace, $sectionData, $actor, $brandContext, $generationMode),
                    'brand_voices' => $this->applyBrandVoices($workspace, $sectionData, $actor, $brandContext, $generationMode),
                    'buyer_personas' => $this->applyBuyerPersonas($organization, $sectionData, $actor, $brandContext, $generationMode),
                    'team_personas' => $this->applyTeamPersonas($organization, $sectionData, $actor, $brandContext, $generationMode),
                    default => null,
                };
            }
        });

        $run->update([
            'status' => EnrichmentRun::STATUS_APPROVED,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        Log::info('Brand context sections applied', [
            'run_id' => (string) $run->id,
            'organization_id' => (int) $run->organization_id,
            'brand_context_id' => (string) ($brandContext?->id ?? ''),
            'sections' => $sections,
            'generation_mode' => $generationMode,
        ]);

        return $results;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function applyCompanyProfile(
        Organization $organization,
        ?Workspace $workspace,
        array $data,
        User $actor,
        ?BrandContext $brandContext = null,
        string $generationMode = EnrichmentRun::GENERATION_MODE_FULL,
    ): array
    {
        if (! $workspace) {
            return ['error' => 'No workspace found'];
        }

        $profile = CompanyProfile::query()->firstOrNew(['workspace_id' => $workspace->id]);

        $attributes = [
            'company_name' => $data['company_name'] ?? $organization->name,
            'industry' => $data['industry'] ?? null,
            'short_description' => $data['short_description'] ?? $data['company_description_short'] ?? null,
            'long_description' => $data['long_description'] ?? $data['company_description_long'] ?? null,
            'mission' => $data['mission'] ?? null,
            'vision' => $data['vision'] ?? null,
            'value_proposition' => $data['value_proposition'] ?? null,
            'key_services' => $this->arrayToLines($data['key_services'] ?? []),
            'value_propositions' => $this->arrayToLines($data['value_propositions'] ?? []),
            'proof_points' => $this->arrayToLines($data['proof_points'] ?? []),
            'compliance_rules' => $data['compliance_rules'] ?? null,
            'banned_claims' => $data['banned_claims'] ?? null,
            'target_audience' => $data['target_audience'] ?? null,
        ];

        $profile->fill($this->resolveAttributesForGenerationMode($profile, $attributes, $generationMode));

        if ($brandContext) {
            $profile->generated_from_context_id = $brandContext->id;
        }

        $profile->save();

        return ['profile_id' => $profile->id];
    }

    /**
     * @param  array<int, array<string, mixed>>  $voices
     */
    public function applyBrandVoices(
        ?Workspace $workspace,
        array $voices,
        User $actor,
        ?BrandContext $brandContext = null,
        string $generationMode = EnrichmentRun::GENERATION_MODE_FULL,
    ): Collection
    {
        if (! $workspace) {
            return collect();
        }

        $created = collect();
        $hasDefault = BrandVoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->exists();
        $existingVoices = BrandVoice::query()
            ->where('workspace_id', $workspace->id)
            ->get()
            ->keyBy(fn (BrandVoice $voice) => Str::lower(trim((string) $voice->name)));

        foreach ($voices as $index => $voiceData) {
            $name = trim((string) ($voiceData['name'] ?? 'Brand Voice ' . ($index + 1)));
            if ($name === '') {
                continue;
            }

            $existingVoice = $existingVoices->get(Str::lower($name));
            if ($generationMode === EnrichmentRun::GENERATION_MODE_MISSING_ONLY && $existingVoice) {
                continue;
            }

            $voice = $existingVoice ?? new BrandVoice([
                'workspace_id' => $workspace->id,
                'organization_id' => $workspace->organization_id,
            ]);

            $attributes = [
                'workspace_id' => $workspace->id,
                'organization_id' => $workspace->organization_id,
                'name' => $name,
                'tone_of_voice' => $voiceData['tone_of_voice'] ?? $voiceData['tone'] ?? null,
                'writing_style' => $voiceData['writing_style'] ?? null,
                'example_paragraph' => $voiceData['example_paragraph'] ?? null,
                'do_rules' => $this->arrayToLines($voiceData['do_rules'] ?? []),
                'dont_rules' => $this->arrayToLines($voiceData['dont_rules'] ?? []),
                'vocabulary_guidelines' => $voiceData['vocabulary_guidelines'] ?? null,
                'default_language' => $voiceData['default_language'] ?? 'en',
                'default_tone' => $voiceData['default_tone'] ?? $voiceData['tone_of_voice'] ?? $voiceData['tone'] ?? null,
                'style_guide' => $voiceData['style_guide'] ?? $voiceData['description'] ?? $voiceData['writing_style'] ?? null,
                'preferred_terminology' => $this->arrayToLines($voiceData['preferred_terminology'] ?? []),
                'disallowed_terminology' => $this->arrayToLines($voiceData['disallowed_terminology'] ?? []),
                'formatting_rules' => $voiceData['formatting_rules'] ?? $this->arrayToLines($voiceData['do_rules'] ?? []),
                'is_default' => $existingVoice?->is_default ?? (! $hasDefault && $index === 0),
            ];

            $voice->fill($this->resolveAttributesForGenerationMode($voice, $attributes, $generationMode));

            if ($brandContext) {
                $voice->generated_from_context_id = $brandContext->id;
            }

            $voice->save();

            if ($voice->is_default) {
                $hasDefault = true;
            }

            $created->push($voice);
            $existingVoices->put(Str::lower($name), $voice);
        }

        return $created;
    }

    /**
     * @param  array<int, array<string, mixed>>  $personas
     */
    public function applyBuyerPersonas(
        Organization $organization,
        array $personas,
        User $actor,
        ?BrandContext $brandContext = null,
        string $generationMode = EnrichmentRun::GENERATION_MODE_FULL,
    ): Collection
    {
        $created = collect();
        $existingPersonas = Persona::query()
            ->where('organization_id', $organization->id)
            ->get()
            ->keyBy(fn (Persona $persona) => $this->personaKey($persona->type, $persona->name));

        foreach ($personas as $personaData) {
            $type = (string) ($personaData['type'] ?? Persona::TYPE_BUYER);
            $name = trim((string) ($personaData['name'] ?? 'Unnamed Persona'));
            $key = $this->personaKey($type, $name);
            $existingPersona = $existingPersonas->get($key);

            if ($generationMode === EnrichmentRun::GENERATION_MODE_MISSING_ONLY && $existingPersona) {
                continue;
            }

            $persona = $existingPersona ?? new Persona([
                'organization_id' => $organization->id,
                'created_by' => $actor->id,
            ]);

            $attributes = [
                'organization_id' => $organization->id,
                'type' => $type,
                'name' => $name,
                'source_type' => 'ai_generated',
                'source_payload' => [
                    'generated_from' => 'brand_context',
                    'brand_context_id' => $brandContext?->id,
                ],
                'profile_data' => Arr::except($personaData, ['type', 'name']),
                'status' => Persona::STATUS_APPROVED,
                'updated_by' => $actor->id,
            ];

            $persona->fill($this->resolveAttributesForGenerationMode($persona, $attributes, $generationMode));

            if ($brandContext) {
                $persona->generated_from_context_id = $brandContext->id;
            }

            $persona->save();

            $created->push($persona);
            $existingPersonas->put($key, $persona);
        }

        return $created;
    }

    /**
     * @param  array<int, array<string, mixed>>  $members
     */
    public function applyTeamPersonas(
        Organization $organization,
        array $members,
        User $actor,
        ?BrandContext $brandContext = null,
        string $generationMode = EnrichmentRun::GENERATION_MODE_FULL,
    ): Collection
    {
        $created = collect();
        $existingMembers = TeamMember::query()
            ->where('organization_id', $organization->id)
            ->get()
            ->keyBy(fn (TeamMember $member) => Str::lower(trim((string) $member->name)));

        foreach ($members as $memberData) {
            $name = trim((string) ($memberData['name'] ?? 'Team Member'));
            $existingMember = $existingMembers->get(Str::lower($name));

            if ($generationMode === EnrichmentRun::GENERATION_MODE_MISSING_ONLY && $existingMember) {
                continue;
            }

            $member = $existingMember ?? new TeamMember([
                'organization_id' => $organization->id,
                'created_by' => $actor->id,
            ]);

            $profileData = array_merge(
                Arr::except($memberData, ['name', 'title', 'role']),
                [
                    'use_as_writing_persona' => (bool) data_get($memberData, 'use_as_writing_persona', true),
                    'link_to_real_team_member_later' => (bool) data_get($memberData, 'link_to_real_team_member_later', true),
                ]
            );

            $attributes = [
                'organization_id' => $organization->id,
                'name' => $name,
                'title' => $memberData['title'] ?? $memberData['role'] ?? null,
                'role' => $memberData['role'] ?? $memberData['title'] ?? null,
                'expertise' => $memberData['expertise'] ?? $this->arrayToLines($memberData['expertise_areas'] ?? []),
                'writing_perspective' => $memberData['writing_perspective'] ?? null,
                'personality_traits' => $memberData['personality_traits'] ?? $this->arrayToLines($memberData['tone_traits'] ?? []),
                'profile_data' => $profileData,
                'status' => TeamMember::STATUS_APPROVED,
                'is_active' => true,
                'updated_by' => $actor->id,
            ];

            $member->fill($this->resolveAttributesForGenerationMode($member, $attributes, $generationMode));

            if ($brandContext) {
                $member->generated_from_context_id = $brandContext->id;
            }

            $member->save();

            $created->push($member);
            $existingMembers->put(Str::lower($name), $member);
        }

        return $created;
    }

    public function markFailed(EnrichmentRun $run, \Throwable $e): void
    {
        $workspace = $run->organization()->with('workspaces')->first()?->workspaces->sortBy('created_at')->first();
        $this->failRun(
            $run,
            'generation_exception',
            mb_substr($e->getMessage(), 0, 5000),
            $this->buildDiagnostics($run, $workspace, [
                'provider' => null,
                'model' => null,
                'request_id' => null,
                'raw_response_length' => 0,
                'parser_error' => $e->getMessage(),
            ], (string) data_get($run->extracted_payload, 'source_text', ''), $this->sectionsCountForRun($run))
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeSourcePayload(array $payload): array
    {
        $inputType = $payload['input_type'] ?? 'text';

        return match ($inputType) {
            'text' => [
                'input_text' => trim((string) ($payload['pasted_text'] ?? '')),
            ],
            'website_url' => [
                'website_url' => trim((string) ($payload['website_url'] ?? '')),
            ],
            'guided' => [
                'company_name' => trim((string) ($payload['company_name'] ?? '')),
                'what_you_do' => trim((string) ($payload['what_you_do'] ?? '')),
                'target_audience' => trim((string) ($payload['target_audience'] ?? '')),
                'tone_description' => trim((string) ($payload['tone_description'] ?? '')),
            ],
            default => $payload,
        };
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function extractSourceText(array $source, EnrichmentRun $run): string
    {
        $sourceType = $run->source_type;

        if ($sourceType === 'website_url' && ! empty($source['website_url'])) {
            $crawl = $this->crawler->crawlWebsite((string) $source['website_url'], 5);
            $run->update([
                'extracted_payload' => array_merge((array) ($run->extracted_payload ?? []), $crawl),
            ]);

            return (string) ($crawl['combined_text'] ?? '');
        }

        if ($sourceType === 'guided') {
            $parts = array_filter([
                'Company: ' . ($source['company_name'] ?? ''),
                'What we do: ' . ($source['what_you_do'] ?? ''),
                'Target audience: ' . ($source['target_audience'] ?? ''),
                'Tone: ' . ($source['tone_description'] ?? ''),
            ], fn ($part) => strlen($part) > 15);

            return implode("\n\n", $parts);
        }

        return trim((string) ($source['input_text'] ?? $source['pasted_text'] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOrganizationContext(Organization $organization): array
    {
        $companyProfile = $this->firstWorkspaceForOrganization($organization)?->companyProfile;

        return [
            'name' => (string) $organization->name,
            'industry' => (string) ($companyProfile?->industry ?? $organization->industry ?? ''),
            'company_description' => (string) ($organization->company_description ?? ''),
            'target_audience' => (string) ($companyProfile?->target_audience ?? $organization->target_audience ?? ''),
            'value_proposition' => (string) ($companyProfile?->value_proposition ?? ''),
            'key_services' => $companyProfile?->keyServicesArray() ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExistingProfile(Organization $organization): array
    {
        $profile = $this->firstWorkspaceForOrganization($organization)?->companyProfile;
        if (! $profile) {
            return [];
        }

        return [
            'company_name' => $profile->company_name,
            'industry' => $profile->industry,
            'short_description' => $profile->short_description,
            'long_description' => $profile->long_description,
            'mission' => $profile->mission,
            'vision' => $profile->vision,
            'value_proposition' => $profile->value_proposition,
            'key_services' => $profile->keyServicesArray(),
            'value_propositions' => $profile->valuePropositionsArray(),
            'proof_points' => $profile->proofPointsArray(),
            'target_audience' => $profile->target_audience,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildExistingVoices(Organization $organization): array
    {
        $workspace = $this->firstWorkspaceForOrganization($organization);
        if (! $workspace) {
            return [];
        }

        return $workspace->brandVoices->map(fn (BrandVoice $voice) => [
            'name' => $voice->name,
            'tone_of_voice' => $voice->tone_of_voice,
            'writing_style' => $voice->writing_style,
            'is_default' => $voice->is_default,
        ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildExistingPersonas(Organization $organization): array
    {
        return $organization->personas
            ->where('status', Persona::STATUS_APPROVED)
            ->map(fn (Persona $persona) => [
                'type' => $persona->type,
                'name' => $persona->name,
                'profile_data' => $persona->profile_data,
            ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildExistingTeamMembers(Organization $organization): array
    {
        return $organization->teamMembers
            ->where('status', TeamMember::STATUS_APPROVED)
            ->map(fn (TeamMember $member) => [
                'name' => $member->name,
                'title' => $member->title,
                'role' => $member->role,
            ])->values()->all();
    }

    /**
     * @param  array<int, string>  $items
     */
    private function arrayToLines(array $items): string
    {
        return collect($items)
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->implode("\n");
    }

    private function firstWorkspaceForOrganization(Organization $organization): ?Workspace
    {
        return $organization->workspaces->sortBy('created_at')->first();
    }

    private function resolveBrandContext(EnrichmentRun $run, ?Workspace $workspace, array $aiPayload): ?BrandContext
    {
        $brandContextId = data_get($run->extracted_payload, 'brand_context_id');
        if ($brandContextId) {
            $brandContext = BrandContext::query()->find($brandContextId);
            if ($brandContext) {
                return $brandContext;
            }
        }

        if (! $workspace) {
            return null;
        }

        return BrandContext::query()->create([
            'workspace_id' => (string) $workspace->id,
            'raw_input' => (string) data_get($run->extracted_payload, 'source_text', ''),
            'structured_json' => $this->buildStructuredContextPayload($aiPayload),
            'source_type' => (string) $run->source_type,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStructuredContextPayload(array $aiPayload): array
    {
        $companyProfile = (array) ($aiPayload['company_profile'] ?? []);
        $brandVoices = collect((array) ($aiPayload['brand_voices'] ?? []))->values();
        $buyerPersonas = collect((array) ($aiPayload['buyer_personas'] ?? []))->values();

        return [
            'positioning' => [
                'company_description_short' => $companyProfile['short_description'] ?? $companyProfile['company_description_short'] ?? null,
                'company_description_long' => $companyProfile['long_description'] ?? $companyProfile['company_description_long'] ?? null,
                'value_proposition' => $companyProfile['value_proposition'] ?? null,
            ],
            'tone' => [
                'primary_voice' => data_get($brandVoices->first(), 'name'),
                'tone_of_voice' => data_get($brandVoices->first(), 'tone_of_voice'),
                'voice_count' => $brandVoices->count(),
            ],
            'icp' => [
                'target_audience' => $companyProfile['target_audience'] ?? null,
                'primary_personas' => $buyerPersonas->pluck('name')->filter()->values()->all(),
            ],
            'services' => $companyProfile['key_services']
                ?? $companyProfile['services']
                ?? $companyProfile['value_propositions']
                ?? [],
            'sections' => $aiPayload,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function resolveAttributesForGenerationMode(object $model, array $attributes, string $generationMode): array
    {
        if ($generationMode !== EnrichmentRun::GENERATION_MODE_MISSING_ONLY) {
            return $attributes;
        }

        $resolved = [];

        foreach ($attributes as $key => $value) {
            $currentValue = data_get($model, $key);

            if ($this->isFilledValue($currentValue)) {
                continue;
            }

            $resolved[$key] = $value;
        }

        return $resolved;
    }

    private function isFilledValue(mixed $value): bool
    {
        if (is_array($value)) {
            return collect($value)->filter(fn ($item) => ! blank($item))->isNotEmpty();
        }

        return ! blank($value);
    }

    private function personaKey(?string $type, ?string $name): string
    {
        return Str::lower(trim((string) $type) . '::' . trim((string) $name));
    }

    private function findActiveBrandContextRun(Organization $organization): ?EnrichmentRun
    {
        return EnrichmentRun::query()
            ->where('organization_id', $organization->id)
            ->where('enrichment_type', EnrichmentRun::TYPE_BRAND_CONTEXT)
            ->whereIn('status', [
                EnrichmentRun::STATUS_QUEUED,
                EnrichmentRun::STATUS_PROCESSING,
                EnrichmentRun::STATUS_RUNNING,
            ])
            ->latest('created_at')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function denormalizeSourcePayload(EnrichmentRun $run): array
    {
        $sourcePayload = (array) ($run->source_payload ?? []);

        return match ((string) $run->source_type) {
            'text' => [
                'pasted_text' => (string) ($sourcePayload['input_text'] ?? ''),
            ],
            'website_url' => [
                'website_url' => (string) ($sourcePayload['website_url'] ?? ''),
            ],
            'guided' => [
                'company_name' => (string) ($sourcePayload['company_name'] ?? ''),
                'what_you_do' => (string) ($sourcePayload['what_you_do'] ?? ''),
                'target_audience' => (string) ($sourcePayload['target_audience'] ?? ''),
                'tone_description' => (string) ($sourcePayload['tone_description'] ?? ''),
            ],
            default => $sourcePayload,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractGeneratedSections(array $payload, EnrichmentRun $run): array
    {
        $sections = [];

        foreach ((array) ($run->requested_sections ?? EnrichmentRun::BRAND_SECTIONS) as $section) {
            $value = $payload[(string) $section] ?? null;

            if ($this->hasUsableSectionValue($value)) {
                $sections[(string) $section] = $value;
            }
        }

        return $sections;
    }

    private function hasUsableSectionValue(mixed $value): bool
    {
        if (! is_array($value)) {
            return ! blank($value);
        }

        foreach ($value as $item) {
            if (is_array($item) && Arr::where($item, fn ($field) => ! blank($field)) !== []) {
                return true;
            }

            if (! is_array($item) && ! blank($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $analysisResult
     * @return array<string, mixed>
     */
    private function buildDiagnostics(EnrichmentRun $run, ?Workspace $workspace, array $analysisResult, string $sourceText, int $sectionsCount): array
    {
        $sourcePayload = (array) ($run->source_payload ?? []);

        return [
            'run_id' => (string) $run->id,
            'workspace_id' => $workspace?->id ? (string) $workspace->id : null,
            'brand_setup_id' => data_get($run->extracted_payload, 'brand_context_id'),
            'source_type' => (string) $run->source_type,
            'source_url' => (string) ($sourcePayload['website_url'] ?? ''),
            'model' => $analysisResult['model'] ?? null,
            'provider' => $analysisResult['provider'] ?? null,
            'request_id' => $analysisResult['request_id'] ?? null,
            'raw_response_length' => (int) ($analysisResult['raw_response_length'] ?? 0),
            'parser_error' => $analysisResult['parser_error'] ?? null,
            'sections_count' => $sectionsCount,
            'source_text_length' => mb_strlen($sourceText),
        ];
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    private function failRun(EnrichmentRun $run, string $failureReason, string $message, array $diagnostics): void
    {
        Log::error('Brand context generation failed', $diagnostics + [
            'organization_id' => (int) $run->organization_id,
            'generation_mode' => (string) ($run->generation_mode ?? ''),
            'failure_reason' => $failureReason,
            'error' => $message,
        ]);

        $this->syncRunState($run, [
            'status' => EnrichmentRun::STATUS_FAILED,
            'progress' => 1,
            'error_message' => $message,
            'failure_reason' => $failureReason,
            'diagnostic_payload' => $diagnostics,
            'failed_at' => now(),
            'completed_at' => null,
            'last_heartbeat_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $diagnostics
     * @param  array<string, mixed>  $aiPayload
     */
    private function completeEmptyRun(
        EnrichmentRun $run,
        array $aiPayload,
        string $sourceText,
        array $context,
        string $failureReason,
        string $message,
        array $diagnostics
    ): void {
        Log::warning('Brand context generation completed without usable sections', $diagnostics + [
            'organization_id' => (int) $run->organization_id,
            'generation_mode' => (string) ($run->generation_mode ?? ''),
            'failure_reason' => $failureReason,
        ]);

        $this->syncRunState($run, [
            'ai_payload' => $aiPayload,
            'extracted_payload' => array_filter(array_merge((array) ($run->extracted_payload ?? []), [
                'source_text' => $sourceText,
                'context' => Arr::only($context, ['organization', 'requested_sections', 'generation_mode']),
            ]), static fn ($value) => $value !== null),
            'status' => EnrichmentRun::STATUS_COMPLETED_EMPTY,
            'progress' => 1,
            'completed_at' => now(),
            'failed_at' => null,
            'error_message' => $message,
            'failure_reason' => $failureReason,
            'diagnostic_payload' => $diagnostics,
            'last_heartbeat_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function syncRunState(EnrichmentRun $run, array $attributes): void
    {
        if (array_key_exists('progress', $attributes)) {
            $attributes['progress'] = max((float) ($run->progress ?? 0), (float) $attributes['progress']);
        }

        if (array_key_exists('queued_at', $attributes) && $run->queued_at instanceof Carbon) {
            $attributes['queued_at'] = $run->queued_at;
        }

        $run->fill($attributes);
        $run->save();
    }

    private function sectionsCountForRun(EnrichmentRun $run): int
    {
        return count($this->extractGeneratedSections((array) ($run->ai_payload ?? []), $run));
    }
}
