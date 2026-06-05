<?php

namespace App\View\Presenters;

use App\Models\EnrichmentRun;
use App\Models\Organization;
use App\Models\OrganizationProfile;
use App\Models\Persona;
use App\Models\TeamMember;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WorkspaceIntelligencePresenter
{
    /**
     * @param  Collection<int,Persona>  $personas
     * @param  Collection<int,TeamMember>  $teamMembers
     * @param  Collection<int,EnrichmentRun>  $runs
     * @return array<string,mixed>
     */
    public static function make(
        Organization $organization,
        ?OrganizationProfile $organizationProfile,
        Collection $personas,
        Collection $teamMembers,
        Collection $runs
    ): array {
        $pendingRuns = $runs->filter(fn (EnrichmentRun $run): bool => in_array($run->status, [
            EnrichmentRun::STATUS_QUEUED,
            EnrichmentRun::STATUS_RUNNING,
            EnrichmentRun::STATUS_DRAFT,
            EnrichmentRun::STATUS_REVIEWED,
        ], true))->values();

        $status = self::workspaceStatus($organizationProfile, $personas, $teamMembers, $pendingRuns, $runs->first());
        $completion = self::completionMetrics($organizationProfile);
        $presentedPersonas = $personas->map(fn (Persona $persona): array => self::presentPersona($persona))->values();
        $teamMemberLookup = $teamMembers->keyBy(fn (TeamMember $member): string => (string) $member->id);
        $presentedTeamMembers = $teamMembers->map(fn (TeamMember $member): array => self::presentTeamMember($member))->values();
        $presentedRuns = $runs->map(function (EnrichmentRun $run) use ($organizationProfile, $personas, $teamMemberLookup): array {
            return self::presentRun($run, $organizationProfile, $personas, $teamMemberLookup);
        })->values();

        return [
            'status' => $status,
            'metrics' => [
                [
                    'label' => 'Brand profile completion',
                    'value' => sprintf('%d/%d', $completion['count'], $completion['total']),
                    'hint' => $completion['count'] === 0 ? 'No approved profile sections yet.' : 'Approved profile sections ready for reuse.',
                    'icon' => 'building-2',
                ],
                [
                    'label' => 'Approved personas',
                    'value' => (string) $presentedPersonas->count(),
                    'hint' => $presentedPersonas->isEmpty() ? 'No personas approved yet.' : 'Reusable buyer and stakeholder context.',
                    'icon' => 'users',
                ],
                [
                    'label' => 'Team profiles',
                    'value' => (string) $presentedTeamMembers->count(),
                    'hint' => $presentedTeamMembers->isEmpty() ? 'No expert profiles yet.' : 'Author and expert context ready for content generation.',
                    'icon' => 'user-round',
                ],
                [
                    'label' => 'Pending review',
                    'value' => (string) $pendingRuns->count(),
                    'hint' => $pendingRuns->isEmpty() ? 'No proposals waiting for review.' : 'Open proposals that can be applied now.',
                    'icon' => 'sparkles',
                ],
            ],
            'usage' => [
                ['label' => 'Content tone', 'icon' => 'message-square-quote'],
                ['label' => 'SEO positioning', 'icon' => 'search'],
                ['label' => 'GEO / LLM relevance', 'icon' => 'bot'],
            ],
            'tabs' => [
                ['id' => 'brand-profile', 'label' => 'Brand Profile', 'count' => $completion['count']],
                ['id' => 'personas', 'label' => 'Personas', 'count' => $presentedPersonas->count()],
                ['id' => 'team', 'label' => 'Team', 'count' => $presentedTeamMembers->count()],
                ['id' => 'insights', 'label' => 'Insights / Runs', 'count' => $presentedRuns->count()],
            ],
            'brand_profile' => [
                'cards' => self::presentBrandProfile($organization, $organizationProfile),
            ],
            'personas' => [
                'count' => $presentedPersonas->count(),
                'cards' => $presentedPersonas->all(),
            ],
            'team' => [
                'count' => $presentedTeamMembers->count(),
                'cards' => $presentedTeamMembers->all(),
            ],
            'runs' => [
                'count' => $presentedRuns->count(),
                'pending_count' => $pendingRuns->count(),
                'cards' => $presentedRuns->all(),
            ],
        ];
    }

    /**
     * @return array{count:int,total:int}
     */
    private static function completionMetrics(?OrganizationProfile $profile): array
    {
        $count = collect(OrganizationProfile::SECTION_KEYS)
            ->filter(function (string $section) use ($profile): bool {
                $value = $profile?->getAttribute($section);

                return self::hasMeaningfulValue($value);
            })
            ->count();

        return [
            'count' => $count,
            'total' => count(OrganizationProfile::SECTION_KEYS),
        ];
    }

    /**
     * @param  Collection<int,Persona>  $personas
     * @param  Collection<int,TeamMember>  $teamMembers
     * @return array{label:string,description:string,tone:string,icon:string}
     */
    private static function workspaceStatus(
        ?OrganizationProfile $organizationProfile,
        Collection $personas,
        Collection $teamMembers,
        Collection $pendingRuns,
        ?EnrichmentRun $latestRun
    ): array {
        if ($pendingRuns->isNotEmpty()) {
            return [
                'label' => 'Needs review',
                'description' => sprintf('%d enrichment %s ready for review.', $pendingRuns->count(), Str::plural('proposal', $pendingRuns->count())),
                'tone' => 'amber',
                'icon' => 'sparkles',
            ];
        }

        if ($organizationProfile || $personas->isNotEmpty() || $teamMembers->isNotEmpty()) {
            return [
                'label' => 'Approved',
                'description' => $latestRun?->approved_at
                    ? 'Latest approved update ' . $latestRun->approved_at->diffForHumans() . '.'
                    : 'Approved intelligence is available for downstream workflows.',
                'tone' => 'emerald',
                'icon' => 'badge-check',
            ];
        }

        return [
            'label' => 'Draft',
            'description' => 'Start with a brand, persona, or team enrichment run to build reusable context.',
                'tone' => 'slate',
                'icon' => 'file-pen',
            ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function presentBrandProfile(Organization $organization, ?OrganizationProfile $profile): array
    {
        $audiences = collect((array) ($profile?->audience_profiles ?? []))
            ->map(function ($audience): string {
                $name = trim((string) data_get($audience, 'name', ''));
                $summary = trim((string) data_get($audience, 'summary', ''));
                $goals = self::normalizeList(data_get($audience, 'goals', []));
                $painPoints = self::normalizeList(data_get($audience, 'pain_points', []));

                return collect([
                    trim($name . ($summary !== '' ? ': ' . $summary : '')),
                    $goals !== [] ? 'Goals: ' . implode(', ', $goals) : null,
                    $painPoints !== [] ? 'Frustrations: ' . implode(', ', $painPoints) : null,
                ])->filter()->implode(' ');
            })
            ->filter()
            ->values()
            ->all();

        $visualDirection = array_values(array_filter([
            trim((string) data_get($profile?->visual_direction, 'style_summary', '')),
            ...array_map(fn (string $color): string => 'Color: ' . $color, self::normalizeList(data_get($profile?->visual_direction, 'colors', []))),
            ...array_map(fn (string $cue): string => 'Design cue: ' . $cue, self::normalizeList(data_get($profile?->visual_direction, 'design_cues', []))),
        ]));

        return [
            [
                'title' => 'Summary',
                'icon' => 'file-text',
                'text' => trim((string) ($profile?->brand_summary ?: 'No approved summary yet. Run enrichment or edit the profile to define a core positioning statement.')),
            ],
            [
                'title' => 'Tone of Voice',
                'icon' => 'message-square',
                'items' => self::splitTextToBullets((string) ($profile?->tone_of_voice ?? '')),
                'empty' => 'No approved tone guidance yet.',
            ],
            [
                'title' => 'Differentiation',
                'icon' => 'scan-search',
                'items' => self::normalizeList($profile?->differentiators ?? []),
                'empty' => 'No differentiators approved yet.',
            ],
            [
                'title' => 'Value Proposition',
                'icon' => 'target',
                'items' => self::normalizeList($profile?->offerings ?? []),
                'empty' => 'No approved value propositions yet.',
            ],
            [
                'title' => 'Audience',
                'icon' => 'users',
                'items' => $audiences,
                'empty' => 'No audience profiles approved yet.',
            ],
            [
                'title' => 'Search Positioning',
                'icon' => 'search',
                'groups' => array_values(array_filter([
                    self::makeGroup('Strategic topics', self::normalizeList($profile?->strategic_topics ?? [])),
                    self::makeGroup('SEO topics', self::normalizeList($profile?->seo_topics ?? [])),
                ])),
                'empty' => 'No search or SEO topics approved yet.',
            ],
            [
                'title' => 'Visual Direction',
                'icon' => 'palette',
                'items' => $visualDirection,
                'empty' => 'No visual direction approved yet.',
            ],
            [
                'title' => 'Workspace Context',
                'icon' => 'building',
                'items' => array_values(array_filter([
                    trim((string) $organization->name),
                    trim((string) ($organization->industry ?? '')),
                    trim((string) ($organization->positioning_statement ?? '')),
                ])),
                'empty' => 'No base organization context available yet.',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function presentPersona(Persona $persona): array
    {
        $profile = (array) ($persona->profile_data ?? []);

        return [
            'id' => (string) $persona->id,
            'name' => (string) $persona->name,
            'role' => Str::headline((string) $persona->type),
            'status' => self::statusBadge((string) $persona->status),
            'summary' => trim((string) ($profile['summary'] ?? '')),
            'sections' => array_values(array_filter([
                self::makeGroup('Goals', self::normalizeList($profile['goals'] ?? [])),
                self::makeGroup('Frustrations', self::normalizeList($profile['pain_points'] ?? [])),
                self::makeGroup('Jobs to be done', self::normalizeList($profile['jobs_to_be_done'] ?? ($profile['buying_triggers'] ?? []))),
                self::makeGroup('Content needs', self::normalizeList($profile['content_needs'] ?? [])),
            ])),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function presentTeamMember(TeamMember $member): array
    {
        $profile = (array) ($member->profile_data ?? []);

        return [
            'id' => (string) $member->id,
            'name' => (string) $member->name,
            'role' => trim((string) ($member->title ?: $member->role ?: 'No role set')),
            'status' => $member->is_active
                ? ['label' => 'Active', 'tone' => 'emerald', 'icon' => 'badge-check']
                : ['label' => 'Inactive', 'tone' => 'slate', 'icon' => 'pause-circle'],
            'summary' => trim((string) ($profile['expert_summary'] ?? $member->expertise ?? '')),
            'sections' => array_values(array_filter([
                self::makeGroup('Responsibilities', self::normalizeList($profile['responsibilities'] ?? ($profile['expertise_areas'] ?? self::splitTextToBullets((string) $member->expertise)))),
                self::makeGroup('Tools', self::normalizeList($profile['tools'] ?? [])),
                self::makeGroup('Workflows', self::normalizeList($profile['workflows'] ?? ($profile['content_angles'] ?? []))),
                self::makeGroup('Bottlenecks', self::normalizeList($profile['bottlenecks'] ?? [])),
                self::makeGroup('Tone traits', self::normalizeList($profile['tone_traits'] ?? self::splitTextToBullets((string) $member->personality_traits))),
                self::makeGroup('Point of view', self::splitTextToBullets((string) ($profile['point_of_view'] ?? $member->writing_perspective))),
            ])),
            'bio' => trim((string) ($profile['author_bio'] ?? $member->bio_source_text ?? '')),
        ];
    }

    /**
     * @param  Collection<int,Persona>  $personas
     * @param  Collection<string,TeamMember>  $teamMemberLookup
     * @return array<string,mixed>
     */
    private static function presentRun(
        EnrichmentRun $run,
        ?OrganizationProfile $organizationProfile,
        Collection $personas,
        Collection $teamMemberLookup
    ): array {
        $type = match ($run->enrichment_type) {
            EnrichmentRun::TYPE_ORGANIZATION => 'Brand profile run',
            EnrichmentRun::TYPE_BUYER_PERSONA => 'Persona run',
            EnrichmentRun::TYPE_TEAM_MEMBER_PERSONA => 'Team profile run',
            default => Str::headline((string) $run->enrichment_type),
        };

        $sourceSummary = self::presentSourceSummary((string) $run->source_type, (array) ($run->source_payload ?? []));
        $proposal = self::presentRunProposal($run, $organizationProfile, $personas, $teamMemberLookup);

        return [
            'id' => (string) $run->id,
            'type_label' => $type,
            'status' => self::statusBadge((string) $run->status),
            'source_type_label' => self::sourceTypeLabel((string) $run->source_type),
            'source_summary' => $sourceSummary,
            'created_at_label' => $run->created_at ? self::formatTimestamp($run->created_at) : 'Unknown',
            'created_at_human' => $run->created_at?->diffForHumans(),
            'approved_at_label' => $run->approved_at ? self::formatTimestamp($run->approved_at) : null,
            'progress_label' => round(((float) $run->progress) * 100) . '%',
            'error_message' => trim((string) ($run->error_message ?? '')),
            'is_actionable' => in_array($run->status, [EnrichmentRun::STATUS_DRAFT, EnrichmentRun::STATUS_REVIEWED], true),
            'proposal' => $proposal,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<int,string>
     */
    private static function presentSourceSummary(string $sourceType, array $payload): array
    {
        return array_values(array_filter(match ($sourceType) {
            'website_url' => [
                trim((string) ($payload['website_url'] ?? '')),
                trim((string) ($payload['input_text'] ?? '')),
            ],
            'company_name_and_industry' => [
                trim((string) ($payload['company_name'] ?? '')),
                trim((string) ($payload['industry'] ?? '')),
            ],
            'linkedin_reference_url' => [
                trim((string) ($payload['linkedin_reference_url'] ?? '')),
            ],
            'pasted_profile_text', 'uploaded_bio_text' => [
                Str::limit(trim((string) ($payload['pasted_profile_text'] ?? $payload['uploaded_bio_text'] ?? '')), 180),
            ],
            default => [
                Str::limit(trim((string) ($payload['manual_text'] ?? $payload['input_text'] ?? '')), 180),
            ],
        }));
    }

    /**
     * @param  Collection<int,Persona>  $personas
     * @param  Collection<string,TeamMember>  $teamMemberLookup
     * @return array<string,mixed>
     */
    private static function presentRunProposal(
        EnrichmentRun $run,
        ?OrganizationProfile $organizationProfile,
        Collection $personas,
        Collection $teamMemberLookup
    ): array {
        if ($run->enrichment_type === EnrichmentRun::TYPE_ORGANIZATION) {
            $items = collect(OrganizationProfile::SECTION_KEYS)
                ->map(function (string $section) use ($run, $organizationProfile): ?array {
                    $proposed = data_get($run->ai_payload, $section);
                    if (! self::hasMeaningfulValue($proposed)) {
                        return null;
                    }

                    return [
                        'key' => $section,
                        'label' => Str::headline($section),
                        'current' => self::valueSummary($organizationProfile?->getAttribute($section)),
                        'proposed' => self::valueSummary($proposed),
                    ];
                })
                ->filter()
                ->values()
                ->all();

            return [
                'kind' => 'organization',
                'summary' => sprintf('%d proposed %s', count($items), Str::plural('section', count($items))),
                'items' => $items,
            ];
        }

        if ($run->enrichment_type === EnrichmentRun::TYPE_BUYER_PERSONA) {
            $items = collect((array) data_get($run->ai_payload, 'personas', []))
                ->values()
                ->map(function ($persona, int $index) use ($personas): array {
                    $profile = (array) $persona;

                    return [
                        'key' => (string) $index,
                        'label' => trim((string) ($profile['name'] ?? 'Untitled persona')),
                        'meta' => Str::headline((string) ($profile['type'] ?? Persona::TYPE_BUYER)),
                        'current' => $personas->isEmpty()
                            ? ['text' => 'No approved personas yet.', 'items' => []]
                            : ['text' => $personas->count() . ' approved persona' . ($personas->count() === 1 ? '' : 's') . ' already in workspace.', 'items' => $personas->pluck('name')->map(fn ($name): string => (string) $name)->take(4)->values()->all()],
                        'proposed' => [
                            'text' => trim((string) ($profile['summary'] ?? '')),
                            'items' => array_values(array_filter(array_merge(
                                self::prefixItems('Goals', self::normalizeList($profile['goals'] ?? [])),
                                self::prefixItems('Frustrations', self::normalizeList($profile['pain_points'] ?? [])),
                                self::prefixItems('Content needs', self::normalizeList($profile['content_needs'] ?? [])),
                            ))),
                        ],
                    ];
                })
                ->all();

            return [
                'kind' => 'personas',
                'summary' => sprintf('%d proposed %s', count($items), Str::plural('persona', count($items))),
                'items' => $items,
            ];
        }

        $teamMember = $teamMemberLookup->get((string) $run->enrichable_id);
        $currentProfile = (array) ($teamMember?->profile_data ?? []);
        $proposedProfile = (array) data_get($run->ai_payload, 'profile_data', []);
        $items = collect($proposedProfile)
            ->map(function ($value, string $key) use ($currentProfile): ?array {
                if (! self::hasMeaningfulValue($value)) {
                    return null;
                }

                return [
                    'key' => $key,
                    'label' => Str::headline($key),
                    'current' => self::valueSummary($currentProfile[$key] ?? null),
                    'proposed' => self::valueSummary($value),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'kind' => 'team',
            'summary' => sprintf('%d proposed %s for %s', count($items), Str::plural('section', count($items)), $teamMember?->name ?? 'team member'),
            'items' => $items,
            'subject_name' => $teamMember?->name ?? 'Unknown team member',
        ];
    }

    /**
     * @return array{label:string,tone:string,icon:string}
     */
    private static function statusBadge(string $status): array
    {
        return match ($status) {
            EnrichmentRun::STATUS_APPROVED, Persona::STATUS_APPROVED, TeamMember::STATUS_APPROVED => ['label' => 'Approved', 'tone' => 'emerald', 'icon' => 'badge-check'],
            EnrichmentRun::STATUS_DRAFT, Persona::STATUS_DRAFT, TeamMember::STATUS_DRAFT => ['label' => 'Draft', 'tone' => 'slate', 'icon' => 'file-pen'],
            EnrichmentRun::STATUS_REVIEWED, Persona::STATUS_REVIEWED, TeamMember::STATUS_REVIEWED => ['label' => 'Needs review', 'tone' => 'amber', 'icon' => 'sparkles'],
            EnrichmentRun::STATUS_QUEUED => ['label' => 'Queued', 'tone' => 'slate', 'icon' => 'clock-3'],
            EnrichmentRun::STATUS_RUNNING => ['label' => 'Running', 'tone' => 'blue', 'icon' => 'loader-circle'],
            EnrichmentRun::STATUS_REJECTED, Persona::STATUS_REJECTED, TeamMember::STATUS_REJECTED => ['label' => 'Rejected', 'tone' => 'rose', 'icon' => 'x-circle'],
            EnrichmentRun::STATUS_FAILED => ['label' => 'Failed', 'tone' => 'rose', 'icon' => 'alert-triangle'],
            default => ['label' => Str::headline($status), 'tone' => 'slate', 'icon' => 'circle'],
        };
    }

    private static function sourceTypeLabel(string $sourceType): string
    {
        return match ($sourceType) {
            'website_url' => 'Website URL',
            'company_name_and_industry' => 'Company + industry',
            'manual_text' => 'Manual notes',
            'linkedin_reference_url' => 'LinkedIn URL',
            'pasted_profile_text' => 'Pasted profile text',
            'uploaded_bio_text' => 'Uploaded bio text',
            default => Str::headline($sourceType),
        };
    }

    /**
     * @return array{label:string,items:array<int,string>}|null
     */
    private static function makeGroup(string $label, array $items): ?array
    {
        $items = self::normalizeList($items);

        if ($items === []) {
            return null;
        }

        return [
            'label' => $label,
            'items' => $items,
        ];
    }

    /**
     * @return array{text:string,items:array<int,string>}
     */
    private static function valueSummary(mixed $value): array
    {
        if (is_array($value)) {
            if (self::isAssoc($value)) {
                $items = collect($value)
                    ->flatMap(function ($nestedValue, $nestedKey): array {
                        $parts = self::normalizeList($nestedValue);

                        if ($parts === []) {
                            $text = trim((string) $nestedValue);

                            return $text !== '' ? [Str::headline((string) $nestedKey) . ': ' . $text] : [];
                        }

                        return array_map(
                            fn (string $part): string => Str::headline((string) $nestedKey) . ': ' . $part,
                            $parts
                        );
                    })
                    ->values()
                    ->all();

                return [
                    'text' => '',
                    'items' => $items,
                ];
            }

            return [
                'text' => '',
                'items' => self::normalizeList($value),
            ];
        }

        return [
            'text' => trim((string) $value),
            'items' => [],
        ];
    }

    /**
     * @return array<int,string>
     */
    private static function normalizeList(mixed $items): array
    {
        if (is_string($items)) {
            return self::splitTextToBullets($items);
        }

        if (! is_iterable($items)) {
            $text = trim((string) $items);

            return $text === '' ? [] : [$text];
        }

        return collect($items)
            ->flatMap(function ($item): array {
                if (is_array($item)) {
                    if (self::isAssoc($item)) {
                        return array_values(array_filter([
                            trim((string) ($item['name'] ?? '')),
                            trim((string) ($item['summary'] ?? '')),
                        ]));
                    }

                    return self::normalizeList($item);
                }

                $text = trim((string) $item);

                return $text === '' ? [] : [$text];
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private static function splitTextToBullets(string $value): array
    {
        $text = trim($value);
        if ($text === '') {
            return [];
        }

        return collect(preg_split('/[\r\n]+|(?<=[.!?])\s+|,\s+/', $text) ?: [])
            ->map(fn ($item): string => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $items
     * @return array<int,string>
     */
    private static function prefixItems(string $prefix, array $items): array
    {
        return array_map(fn (string $item): string => $prefix . ': ' . $item, $items);
    }

    private static function hasMeaningfulValue(mixed $value): bool
    {
        if (is_array($value)) {
            return collect($value)->contains(fn ($item): bool => self::hasMeaningfulValue($item));
        }

        return trim((string) $value) !== '';
    }

    private static function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private static function formatTimestamp(Carbon $timestamp): string
    {
        return $timestamp->format('M j, Y H:i');
    }
}
