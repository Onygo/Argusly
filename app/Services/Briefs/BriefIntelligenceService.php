<?php

namespace App\Services\Briefs;

use App\Models\BrandVoice;
use App\Models\Brief;
use App\Models\BriefSuggestion;
use App\Models\CompanyProfile;
use App\Models\ResearchProject;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\LlmManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class BriefIntelligenceService
{
    private const VERSION = 'brief_intelligence_v1';

    public function __construct(
        private readonly LlmManager $llmManager,
    ) {
    }

    /**
     * @return array{
     *   input_hash:string,
     *   suggestions_created:int,
     *   intelligence_summary:string,
     *   context_snapshot:array<string,mixed>,
     *   linked_research:array<string,mixed>|null,
     *   llm:array<string,string>
     * }
     */
    public function generateSuggestions(Brief $brief, bool $force = false): array
    {
        $brief->loadMissing([
            'clientSite.workspace.companyProfile',
            'clientSite.workspace.organization.organizationProfile',
            'clientSite.workspace.defaultBrandVoice',
            'researchProjects' => fn ($query) => $query->latest('created_at'),
        ]);

        $workspace = $brief->clientSite?->workspace;
        if (! $workspace) {
            throw new RuntimeException('Workspace context is missing for brief intelligence.');
        }

        $companyProfile = $workspace->companyProfile;
        $brandVoice = $this->resolveBrandVoice($brief, $workspace->defaultBrandVoice);
        $researchProject = $this->resolveLinkedResearchProject($brief);

        $context = $this->buildContext($brief, $companyProfile, $brandVoice, $researchProject);
        $inputHash = sha1(json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        if (! $force && $this->hasPendingSuggestionsForHash($brief, $inputHash)) {
            return [
                'input_hash' => $inputHash,
                'suggestions_created' => 0,
                'intelligence_summary' => (string) data_get($brief->client_refs, 'brief_intelligence.intelligence_summary', ''),
                'context_snapshot' => $context,
                'linked_research' => $this->linkedResearchPayload($researchProject),
                'llm' => [
                    'provider' => '',
                    'model' => '',
                    'request_id' => '',
                ],
            ];
        }

        $llm = $this->generateLlmSuggestions($context, $brief, $researchProject);
        $rows = $this->toSuggestionRows($llm['json']);

        if ($rows === []) {
            throw new RuntimeException('No intelligence suggestions were generated for this brief.');
        }

        $created = DB::transaction(function () use ($brief, $rows, $inputHash, $llm, $force): int {
            if ($force) {
                $pending = BriefSuggestion::query()
                    ->where('brief_id', $brief->id)
                    ->where('status', BriefSuggestion::STATUS_PENDING)
                    ->get();

                foreach ($pending as $item) {
                    $meta = is_array($item->meta) ? $item->meta : [];
                    $item->status = BriefSuggestion::STATUS_REJECTED;
                    $item->meta = array_replace_recursive($meta, [
                        'auto_rejected' => true,
                        'auto_rejected_at' => now()->toIso8601String(),
                    ]);
                    $item->save();
                }
            }

            $count = 0;

            foreach ($rows as $row) {
                $suggestedValue = (string) ($row['suggested_value'] ?? '');
                $suggestionType = (string) ($row['suggestion_type'] ?? '');
                if ($suggestionType === '' || trim($suggestedValue) === '') {
                    continue;
                }

                $duplicate = BriefSuggestion::query()
                    ->where('brief_id', $brief->id)
                    ->where('status', BriefSuggestion::STATUS_PENDING)
                    ->where('suggestion_type', $suggestionType)
                    ->where('suggested_value', $suggestedValue)
                    ->latest('created_at')
                    ->exists();

                if ($duplicate) {
                    continue;
                }

                BriefSuggestion::query()->create([
                    'brief_id' => $brief->id,
                    'suggestion_type' => $suggestionType,
                    'original_value' => (string) ($row['original_value'] ?? ''),
                    'suggested_value' => $suggestedValue,
                    'rationale' => (string) ($row['rationale'] ?? ''),
                    'status' => BriefSuggestion::STATUS_PENDING,
                    'meta' => [
                        'input_hash' => $inputHash,
                        'version' => self::VERSION,
                        'value_format' => (string) ($row['value_format'] ?? 'text'),
                        'provider' => (string) ($llm['provider'] ?? ''),
                        'model' => (string) ($llm['model'] ?? ''),
                        'request_id' => (string) ($llm['request_id'] ?? ''),
                        'generated_at' => now()->toIso8601String(),
                    ],
                ]);

                $count++;
            }

            return $count;
        });

        return [
            'input_hash' => $inputHash,
            'suggestions_created' => $created,
            'intelligence_summary' => trim((string) data_get($llm['json'], 'intelligence_summary', '')),
            'context_snapshot' => $context,
            'linked_research' => $this->linkedResearchPayload($researchProject),
            'llm' => [
                'provider' => (string) ($llm['provider'] ?? ''),
                'model' => (string) ($llm['model'] ?? ''),
                'request_id' => (string) ($llm['request_id'] ?? ''),
            ],
        ];
    }

    public function applySuggestion(Brief $brief, BriefSuggestion $suggestion, int $userId): BriefSuggestion
    {
        $this->assertSuggestionBelongsToBrief($brief, $suggestion);

        if ($suggestion->isApplied()) {
            return $suggestion;
        }

        DB::transaction(function () use ($brief, $suggestion, $userId): void {
            $brief = Brief::query()->whereKey($brief->id)->lockForUpdate()->firstOrFail();
            $suggestion = BriefSuggestion::query()->whereKey($suggestion->id)->lockForUpdate()->firstOrFail();

            if ($suggestion->isApplied()) {
                return;
            }

            $value = $this->parseSuggestionValue($suggestion);
            $updates = $this->updatesForSuggestion($brief, (string) $suggestion->suggestion_type, $value);

            if ($updates !== []) {
                $brief->fill($updates);
            }

            $refs = is_array($brief->client_refs) ? $brief->client_refs : [];
            $intelligence = is_array($refs['brief_intelligence'] ?? null) ? $refs['brief_intelligence'] : [];
            $history = collect((array) ($intelligence['applied_suggestion_history'] ?? []))
                ->push([
                    'suggestion_id' => (string) $suggestion->id,
                    'suggestion_type' => (string) $suggestion->suggestion_type,
                    'applied_by' => $userId,
                    'applied_at' => now()->toIso8601String(),
                ])
                ->take(-100)
                ->values()
                ->all();

            $intelligence['applied_suggestion_history'] = $history;
            $refs['brief_intelligence'] = $intelligence;
            $brief->client_refs = $refs;
            $brief->save();

            $meta = is_array($suggestion->meta) ? $suggestion->meta : [];
            $suggestion->status = BriefSuggestion::STATUS_APPLIED;
            $suggestion->meta = array_replace_recursive($meta, [
                'applied_at' => now()->toIso8601String(),
                'applied_by' => $userId,
            ]);
            $suggestion->save();
        });

        return $suggestion->fresh();
    }

    public function rejectSuggestion(Brief $brief, BriefSuggestion $suggestion, int $userId, ?string $reason = null): BriefSuggestion
    {
        $this->assertSuggestionBelongsToBrief($brief, $suggestion);

        if ($suggestion->isRejected()) {
            return $suggestion;
        }

        $meta = is_array($suggestion->meta) ? $suggestion->meta : [];
        $suggestion->status = BriefSuggestion::STATUS_REJECTED;
        $suggestion->meta = array_replace_recursive($meta, [
            'rejected_at' => now()->toIso8601String(),
            'rejected_by' => $userId,
            'rejected_reason' => $reason ? trim($reason) : null,
        ]);
        $suggestion->save();

        return $suggestion->fresh();
    }

    private function assertSuggestionBelongsToBrief(Brief $brief, BriefSuggestion $suggestion): void
    {
        if ((string) $suggestion->brief_id !== (string) $brief->id) {
            throw new RuntimeException('Suggestion does not belong to this brief.');
        }
    }

    private function hasPendingSuggestionsForHash(Brief $brief, string $hash): bool
    {
        return BriefSuggestion::query()
            ->where('brief_id', $brief->id)
            ->where('status', BriefSuggestion::STATUS_PENDING)
            ->get(['id', 'meta'])
            ->contains(function (BriefSuggestion $suggestion) use ($hash): bool {
                return (string) data_get($suggestion->meta, 'input_hash', '') === $hash;
            });
    }

    /**
     * @return array{json:array<string,mixed>,provider:string,model:string,request_id:string}
     */
    private function generateLlmSuggestions(array $context, Brief $brief, ?ResearchProject $researchProject): array
    {
        $response = $this->llmManager->generateJson(
            new LlmRequest(
                messages: [
                    new LlmMessage('system', implode("\n", [
                        'You are a brief intelligence assistant for B2B content strategy.',
                        'Return strict JSON only.',
                        'Give concise, practical suggestions grounded in provided context.',
                        'Do not invent company or research facts.',
                    ])),
                    new LlmMessage('user', implode("\n", [
                        'Generate brief suggestions with rationale.',
                        'Provide keys: intelligence_summary, title, angle, audience, keyword_cluster, semantic_terms, search_intent, recommended_headings, cta_direction.',
                        'For title/angle/audience/search_intent/cta_direction use object: {"value": string, "rationale": string}.',
                        'For keyword_cluster/semantic_terms/recommended_headings use object: {"values": string[], "rationale": string}.',
                        'Use clear actionable language.',
                        'Context JSON:',
                        json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ])),
                ],
                temperature: 0.0,
                responseFormat: 'json',
                metadata: [
                    'feature' => 'brief_intelligence',
                    'modality' => 'text',
                    'workspaceId' => (string) ($brief->clientSite?->workspace_id ?? ''),
                    'siteId' => (string) ($brief->client_site_id ?? ''),
                    'briefId' => (string) $brief->id,
                    'researchProjectId' => (string) ($researchProject?->id ?? ''),
                ],
            ),
            [
                'type' => 'object',
                'required' => [
                    'intelligence_summary',
                    'title',
                    'angle',
                    'audience',
                    'keyword_cluster',
                    'semantic_terms',
                    'search_intent',
                    'recommended_headings',
                    'cta_direction',
                ],
            ],
            [
                'feature' => 'brief_intelligence',
            ],
        );

        return [
            'json' => is_array($response->json) ? $response->json : [],
            'provider' => (string) $response->providerName,
            'model' => (string) $response->modelUsed,
            'request_id' => (string) ($response->requestId ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $json
     * @return array<int,array{suggestion_type:string,original_value:string,suggested_value:string,rationale:string,value_format:string}>
     */
    private function toSuggestionRows(array $json): array
    {
        $rows = [];

        $scalarMap = [
            'title' => 'title',
            'angle' => 'unique_angle',
            'audience' => 'target_audience',
            'search_intent' => 'search_intent',
            'cta_direction' => 'call_to_action',
        ];

        foreach ($scalarMap as $type => $briefField) {
            $value = trim((string) data_get($json, $type . '.value', ''));
            if ($value === '') {
                continue;
            }

            $rows[] = [
                'suggestion_type' => $type,
                'original_value' => (string) $this->briefFieldValue($briefField),
                'suggested_value' => $value,
                'rationale' => trim((string) data_get($json, $type . '.rationale', '')),
                'value_format' => 'text',
            ];
        }

        $listMap = [
            'keyword_cluster' => $this->listValue($this->briefFieldValue('secondary_keywords')),
            'semantic_terms' => $this->listValue($this->briefFieldValue('secondary_keywords')),
            'recommended_headings' => $this->listValue($this->briefFieldValue('key_points')),
        ];

        foreach ($listMap as $type => $original) {
            $values = collect((array) data_get($json, $type . '.values', []))
                ->map(fn (mixed $row): string => trim((string) $row))
                ->filter()
                ->unique()
                ->take(20)
                ->values()
                ->all();

            if ($values === []) {
                continue;
            }

            $rows[] = [
                'suggestion_type' => $type,
                'original_value' => json_encode($original, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'suggested_value' => json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'rationale' => trim((string) data_get($json, $type . '.rationale', '')),
                'value_format' => 'json',
            ];
        }

        return $rows;
    }

    private function briefFieldValue(string $field): mixed
    {
        return data_get($this->currentBriefContext, $field);
    }

    private array $currentBriefContext = [];

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function buildContext(
        Brief $brief,
        ?CompanyProfile $companyProfile,
        ?BrandVoice $brandVoice,
        ?ResearchProject $researchProject
    ): array {
        $organizationProfile = $brief->clientSite?->workspace?->organization?->organizationProfile;

        $this->currentBriefContext = [
            'title' => (string) ($brief->title ?? ''),
            'primary_keyword' => (string) ($brief->primary_keyword ?? ''),
            'secondary_keywords' => $this->listValue($brief->secondary_keywords),
            'target_audience' => (string) ($brief->target_audience ?: $brief->audience ?: ''),
            'search_intent' => (string) ($brief->search_intent ?? ''),
            'unique_angle' => (string) ($brief->unique_angle ?? ''),
            'tone_of_voice' => (string) ($brief->tone_of_voice ?? ''),
            'key_points' => $this->listValue($brief->key_points),
            'call_to_action' => (string) ($brief->call_to_action ?? ''),
            'notes' => (string) ($brief->notes ?? ''),
            'language' => (string) ($brief->language ?? ''),
            'content_type' => (string) ($brief->content_type ?? 'blog'),
        ];

        return [
            'brief' => $this->currentBriefContext,
            'company_profile' => [
                'company_name' => (string) ($companyProfile?->company_name ?? ''),
                'industry' => (string) ($companyProfile?->industry ?? ''),
                'value_propositions' => $this->splitLines((string) ($companyProfile?->value_propositions ?? '')),
                'proof_points' => $this->splitLines((string) ($companyProfile?->proof_points ?? '')),
                'target_audience' => (string) ($companyProfile?->target_audience ?? ''),
                'compliance_rules' => $this->splitLines((string) ($companyProfile?->compliance_rules ?? '')),
                'banned_claims' => $this->splitLines((string) ($companyProfile?->banned_claims ?? '')),
                'brand_summary' => (string) ($organizationProfile?->brand_summary ?? ''),
                'strategic_topics' => (array) ($organizationProfile?->strategic_topics ?? []),
                'seo_topics' => (array) ($organizationProfile?->seo_topics ?? []),
            ],
            'brand_voice' => [
                'id' => (string) ($brandVoice?->id ?? ''),
                'name' => (string) ($brandVoice?->name ?? ''),
                'tone_of_voice' => (string) ($brandVoice?->tone_of_voice ?? ''),
                'writing_style' => (string) ($brandVoice?->writing_style ?? ''),
                'do_rules' => $this->splitLines((string) ($brandVoice?->do_rules ?? '')),
                'dont_rules' => $this->splitLines((string) ($brandVoice?->dont_rules ?? '')),
                'preferred_terminology' => $this->splitLines((string) ($brandVoice?->preferred_terminology ?? '')),
                'disallowed_terminology' => $this->splitLines((string) ($brandVoice?->disallowed_terminology ?? '')),
            ],
            'research' => $this->linkedResearchPayload($researchProject),
        ];
    }

    private function resolveBrandVoice(Brief $brief, ?BrandVoice $fallback): ?BrandVoice
    {
        $preferredId = trim((string) data_get($brief->client_refs, 'brand_voice_id', ''));

        if ($preferredId !== '') {
            $voice = BrandVoice::query()->find($preferredId);
            if ($voice) {
                return $voice;
            }
        }

        return $fallback;
    }

    private function resolveLinkedResearchProject(Brief $brief): ?ResearchProject
    {
        $linkedId = trim((string) data_get($brief->client_refs, 'brief_intelligence.research_project_id', ''));

        if ($linkedId !== '') {
            $project = ResearchProject::query()
                ->with([
                    'findings' => fn ($query) => $query
                        ->where('is_selected', true)
                        ->orWhere('confidence_score', '>=', 0.8)
                        ->orderByDesc('confidence_score')
                        ->limit(24),
                ])
                ->find($linkedId);

            if ($project) {
                return $project;
            }
        }

        return $brief->researchProjects()
            ->with([
                'findings' => fn ($query) => $query
                    ->where('is_selected', true)
                    ->orWhere('confidence_score', '>=', 0.8)
                    ->orderByDesc('confidence_score')
                    ->limit(24),
            ])
            ->latest('created_at')
            ->first();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function linkedResearchPayload(?ResearchProject $project): ?array
    {
        if (! $project) {
            return null;
        }

        $project->loadMissing('findings');

        $findings = collect($project->findings)
            ->map(fn ($finding): string => trim((string) $finding->finding_text))
            ->filter()
            ->take((int) config('brief_intelligence.summary.max_findings', 24))
            ->values()
            ->all();

        return [
            'project_id' => (string) $project->id,
            'project_name' => (string) $project->name,
            'summary' => is_array($project->summary) ? $project->summary : [],
            'human_summary' => (string) ($project->human_summary ?? ''),
            'target_keywords' => $this->listValue($project->target_keywords),
            'selected_findings' => $findings,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function updatesForSuggestion(Brief $brief, string $type, mixed $value): array
    {
        $updates = [];

        switch ($type) {
            case 'title':
                $updates['title'] = trim((string) $value);
                break;
            case 'angle':
                $updates['unique_angle'] = trim((string) $value);
                break;
            case 'audience':
                $updates['target_audience'] = trim((string) $value);
                break;
            case 'search_intent':
                $updates['search_intent'] = trim((string) $value);
                break;
            case 'cta_direction':
                $updates['call_to_action'] = trim((string) $value);
                break;
            case 'keyword_cluster':
            case 'semantic_terms':
                $newTerms = $this->listValue($value);
                if ($newTerms !== []) {
                    $updates['secondary_keywords'] = collect($this->listValue($brief->secondary_keywords))
                        ->merge($newTerms)
                        ->map(fn (string $item): string => trim($item))
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();
                }
                break;
            case 'recommended_headings':
                $newHeadings = $this->listValue($value);
                if ($newHeadings !== []) {
                    $updates['key_points'] = collect($this->listValue($brief->key_points))
                        ->merge($newHeadings)
                        ->map(fn (string $item): string => trim($item))
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();
                }
                break;
        }

        return Arr::where($updates, function (mixed $value): bool {
            if (is_array($value)) {
                return true;
            }

            return trim((string) $value) !== '';
        });
    }

    private function parseSuggestionValue(BriefSuggestion $suggestion): mixed
    {
        $format = (string) data_get($suggestion->meta, 'value_format', 'text');
        $value = (string) ($suggestion->suggested_value ?? '');

        if ($format !== 'json') {
            return trim($value);
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    }

    /**
     * @return array<int,string>
     */
    private function splitLines(string $value): array
    {
        return collect(preg_split('/\R+/', $value) ?: [])
            ->map(fn (mixed $row): string => trim((string) $row))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function listValue(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            }
        }

        return collect(is_array($value) ? $value : (preg_split('/[\n,]+/', (string) $value) ?: []))
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
