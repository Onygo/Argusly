<?php

namespace App\Services\CampaignPlanning;

use App\Enums\CampaignApprovalStatus;
use App\Enums\CampaignContentAssetType;
use App\Enums\CampaignStatus;
use App\Enums\DistributionChannelType;
use App\Enums\DistributionPlanStatus;
use App\Models\Campaign;
use App\Models\CampaignContent;
use App\Models\CampaignDistributionPlan;
use App\Models\DistributionChannel;
use App\Models\Opportunity;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CampaignPlannerService
{
    /**
     * @param  array<string,mixed>  $options
     */
    public function plan(Workspace $workspace, string $topic, array $options = []): Campaign
    {
        $topic = $this->normalizeTopic($topic);
        $goals = $this->normalizeList($options['goals'] ?? []);
        $audience = $this->audienceMapping($topic, $goals, (string) ($options['audience'] ?? ''));
        $opportunities = $this->matchingOpportunities($workspace, $topic);
        $assets = $this->assets($topic, $goals, $audience, $opportunities);
        $dependencyGraph = $this->dependencyGraph($assets);
        $publishingSchedule = $this->publishingSchedule($assets, $options['start_date'] ?? null);
        $internalLinks = $this->internalLinkingPlan($assets);
        $toneVariations = $this->toneVariations($topic, $audience);
        $repurposing = $this->repurposingRecommendations($assets);
        $visualMap = $this->visualCampaignMap($assets, $dependencyGraph, $publishingSchedule);
        $approvalCheckpoints = $this->approvalCheckpoints($assets);

        return DB::transaction(function () use (
            $workspace,
            $topic,
            $goals,
            $audience,
            $opportunities,
            $assets,
            $dependencyGraph,
            $publishingSchedule,
            $internalLinks,
            $toneVariations,
            $repurposing,
            $visualMap,
            $approvalCheckpoints,
            $options,
        ): Campaign {
            $campaign = Campaign::query()->create([
                'organization_id' => $workspace->organization_id,
                'workspace_id' => (string) $workspace->id,
                'client_site_id' => $options['client_site_id'] ?? null,
                'owner_user_id' => $options['owner_user_id'] ?? null,
                'name' => Str::title($topic).' Campaign',
                'slug' => Str::slug($topic).'-'.Str::lower(Str::random(6)),
                'objective' => $this->objective($topic, $goals, $opportunities),
                'status' => CampaignStatus::PLANNING,
                'approval_status' => CampaignApprovalStatus::REQUESTED,
                'planned_start_date' => data_get($publishingSchedule, '0.date'),
                'planned_end_date' => data_get($publishingSchedule, (count($publishingSchedule) - 1).'.date'),
                'submitted_for_approval_at' => now(),
                'audience' => $audience,
                'goals' => $goals,
                'kpis' => $this->kpis($goals),
                'channel_mix' => $this->channelMix($assets),
                'ai_planning_context' => [
                    'mode' => 'deterministic_assisted_planning',
                    'source_topic' => $topic,
                    'source_opportunity_ids' => $opportunities->pluck('id')->map(fn ($id): string => (string) $id)->all(),
                    'topic_clusters' => $this->topicClusters($topic, $assets, $opportunities),
                    'dependency_graph' => $dependencyGraph,
                    'publishing_schedule' => $publishingSchedule,
                    'visual_map' => $visualMap,
                    'approval_checkpoints' => $approvalCheckpoints,
                    'drag_drop_state' => [
                        'enabled' => true,
                        'ordering_key' => 'sequence_order',
                        'lanes' => ['foundation', 'supporting', 'distribution', 'conversion'],
                    ],
                ],
                'optimization_signals' => [
                    'opportunities' => $opportunities->map(fn (Opportunity $opportunity): array => [
                        'id' => (string) $opportunity->id,
                        'category' => $opportunity->category?->value ?? $opportunity->category,
                        'priority_score' => $opportunity->priority_score,
                        'confidence_score' => $opportunity->confidence_score,
                        'title' => $opportunity->title,
                    ])->values()->all(),
                    'repurposing_recommendations' => $repurposing,
                    'tone_variations' => $toneVariations,
                ],
                'internal_linking_strategy' => $internalLinks,
                'metadata' => [
                    'planner_version' => 'deterministic_v1',
                    'funnel_stage_map' => $this->funnelStageMap($assets),
                    'audience_map' => $audience,
                    'content_sequence' => $publishingSchedule,
                    'approval_required_before_execution' => true,
                    'autonomous_execution_enabled' => false,
                ],
                'last_planned_at' => now(),
            ]);

            $channels = $this->channelsFor($workspace);

            foreach ($assets as $asset) {
                $campaignContent = CampaignContent::query()->create([
                    'campaign_id' => (string) $campaign->id,
                    'asset_type' => $asset['asset_type'],
                    'status' => 'planned',
                    'approval_status' => CampaignApprovalStatus::REQUESTED,
                    'sequence_order' => $asset['sequence_order'],
                    'working_title' => $asset['title'],
                    'scheduled_for' => data_get($publishingSchedule, ($asset['sequence_order'] - 1).'.scheduled_for'),
                    'submitted_for_approval_at' => now(),
                    'brief' => [
                        'topic' => $topic,
                        'angle' => $asset['angle'],
                        'funnel_stage' => $asset['funnel_stage'],
                        'audience_segment' => $asset['audience_segment'],
                        'search_intent' => $asset['search_intent'],
                        'acceptance_criteria' => $asset['acceptance_criteria'],
                    ],
                    'channel_requirements' => $asset['channel_requirements'],
                    'ai_generation_context' => [
                        'deterministic_outline' => $asset['outline'],
                        'tone_variation' => $asset['tone_variation'],
                        'source_opportunity_ids' => $opportunities->pluck('id')->map(fn ($id): string => (string) $id)->all(),
                    ],
                    'optimization_notes' => $asset['optimization_notes'],
                    'internal_linking_targets' => data_get($internalLinks, 'by_asset.'.$asset['key'], []),
                    'metadata' => [
                        'planner_key' => $asset['key'],
                        'lane' => $asset['lane'],
                        'dependencies' => $dependencyGraph['edges_by_asset'][$asset['key']] ?? [],
                        'repurposing' => $repurposing[$asset['key']] ?? [],
                    ],
                ]);

                foreach ($this->distributionTargets($asset, $channels) as $target) {
                    CampaignDistributionPlan::query()->create([
                        'campaign_id' => (string) $campaign->id,
                        'campaign_content_id' => (string) $campaignContent->id,
                        'distribution_channel_id' => (string) $target['channel']->id,
                        'asset_type' => $asset['asset_type'],
                        'status' => DistributionPlanStatus::DRAFT,
                        'scheduled_for' => $campaignContent->scheduled_for,
                        'payload' => [
                            'planner_key' => $asset['key'],
                            'format' => $target['format'],
                            'channel_type' => $target['channel']->type?->value,
                        ],
                        'planning_notes' => [
                            'sequencing_reason' => data_get($publishingSchedule, ($asset['sequence_order'] - 1).'.reason'),
                            'approval_checkpoint' => $approvalCheckpoints[$asset['key']] ?? null,
                            'rate_limit_awareness' => 'Scheduling remains draft-only until a connected publisher validates platform limits.',
                        ],
                    ]);
                }
            }

            $opportunities->each(function (Opportunity $opportunity) use ($campaign): void {
                $opportunity->forceFill([
                    'campaign_id' => (string) $campaign->id,
                    'status' => 'planned',
                    'planned_at' => now(),
                ])->save();
            });

            return $campaign->load(['contents.distributionPlans.distributionChannel', 'opportunities']);
        });
    }

    private function normalizeTopic(string $topic): string
    {
        $topic = trim(preg_replace('/\s+/', ' ', $topic) ?: '');

        return $topic !== '' ? $topic : 'Strategic content campaign';
    }

    /**
     * @return Collection<int,Opportunity>
     */
    private function matchingOpportunities(Workspace $workspace, string $topic): Collection
    {
        $like = '%'.$topic.'%';

        return Opportunity::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query) use ($like): void {
                $query->where('topic', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('summary', 'like', $like);
            })
            ->orderByDesc('priority_score')
            ->limit(8)
            ->get();
    }

    /**
     * @param  list<string>  $goals
     * @param  Collection<int,Opportunity>  $opportunities
     * @return list<array<string,mixed>>
     */
    private function assets(string $topic, array $goals, array $audience, Collection $opportunities): array
    {
        $clusters = $this->clusterSeeds($topic, $opportunities);
        $primaryAudience = data_get($audience, 'primary.0', 'marketing leaders');
        $assets = [
            $this->asset('pillar_article', CampaignContentAssetType::ARTICLE, 1, 'foundation', 'Pillar article: '.Str::title($topic), 'Define the category, why it matters now, and the operating model.', 'awareness', $primaryAudience, 'informational', 'authoritative', $clusters),
            $this->asset('supporting_strategy', CampaignContentAssetType::ARTICLE, 2, 'supporting', Str::title($topic).' strategy guide', 'Translate the pillar concept into a strategy framework.', 'consideration', $primaryAudience, 'commercial_investigation', 'practical', $clusters),
            $this->asset('supporting_operations', CampaignContentAssetType::ARTICLE, 3, 'supporting', 'How to operationalize '.Str::title($topic), 'Show workflows, ownership, governance, and measurement.', 'consideration', 'marketing operations', 'how_to', 'technical', $clusters),
            $this->asset('supporting_measurement', CampaignContentAssetType::ARTICLE, 4, 'supporting', Str::title($topic).' metrics and reporting', 'Define KPIs, signal quality, and reporting cadence.', 'decision', 'growth leaders', 'comparison', 'analytical', $clusters),
            $this->asset('linkedin_thought_leadership', CampaignContentAssetType::LINKEDIN_POST, 5, 'distribution', 'LinkedIn thought leadership: '.Str::title($topic), 'Frame the market shift and invite discussion.', 'awareness', 'executives', 'social', 'thought_leadership', $clusters),
            $this->asset('founder_post', CampaignContentAssetType::FOUNDER_POST, 6, 'distribution', 'Founder post: why '.Str::title($topic).' matters', 'Founder perspective, belief, and strategic narrative.', 'awareness', 'founders', 'social', 'founder', $clusters),
            $this->asset('linkedin_technical_deep_dive', CampaignContentAssetType::LINKEDIN_POST, 7, 'distribution', 'LinkedIn technical deep dive: '.Str::title($topic), 'Operational detail from the supporting guide.', 'consideration', 'practitioners', 'social', 'technical', $clusters),
            $this->asset('faq_block', CampaignContentAssetType::FAQ_BLOCK, 8, 'conversion', Str::title($topic).' FAQ block', 'Answer high-friction questions from prospects and AI answer surfaces.', 'decision', $primaryAudience, 'question_answer', 'concise', $clusters),
            $this->asset('answer_block', CampaignContentAssetType::ANSWER_BLOCK, 9, 'conversion', 'Answer block: what is '.Str::title($topic).'?', 'Short answer for AI visibility and structured content reuse.', 'awareness', 'AI answer surfaces', 'question_answer', 'direct', $clusters),
            $this->asset('newsletter_snippet', CampaignContentAssetType::NEWSLETTER_SNIPPET, 10, 'distribution', 'Newsletter snippet: '.Str::title($topic), 'Summarize the pillar and point readers to the campaign path.', 'retention', 'subscribers', 'newsletter', 'editorial', $clusters),
        ];

        return collect($assets)
            ->map(function (array $asset) use ($goals): array {
                $asset['goals'] = $goals;

                return $asset;
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $clusters
     * @return array<string,mixed>
     */
    private function asset(string $key, CampaignContentAssetType $type, int $order, string $lane, string $title, string $angle, string $funnelStage, string $audienceSegment, string $searchIntent, string $tone, array $clusters): array
    {
        return [
            'key' => $key,
            'asset_type' => $type->value,
            'sequence_order' => $order,
            'lane' => $lane,
            'title' => $title,
            'angle' => $angle,
            'funnel_stage' => $funnelStage,
            'audience_segment' => $audienceSegment,
            'search_intent' => $searchIntent,
            'tone_variation' => $tone,
            'topic_cluster' => $clusters[min(count($clusters) - 1, max(0, $order % max(1, count($clusters))))] ?? $clusters[0] ?? 'core topic',
            'outline' => $this->outlineFor($type, $angle),
            'channel_requirements' => $this->channelRequirements($type),
            'optimization_notes' => [
                'funnel_stage' => $funnelStage,
                'audience_segment' => $audienceSegment,
                'entity_coverage' => ['primary_topic', 'problem', 'workflow', 'measurement', 'governance'],
                'ai_visibility' => ['clear definition', 'answer-first summary', 'FAQ coverage', 'internal links'],
            ],
            'acceptance_criteria' => [
                'Links back to the pillar or required parent asset.',
                'Uses assigned tone variation without changing strategic claims.',
                'Remains draft-only until checkpoint approval.',
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $assets
     * @return array<string,mixed>
     */
    private function dependencyGraph(array $assets): array
    {
        $edges = [];
        $edgesByAsset = [];

        foreach ($assets as $asset) {
            if ($asset['key'] === 'pillar_article') {
                continue;
            }

            $parent = in_array($asset['asset_type'], [CampaignContentAssetType::LINKEDIN_POST->value, CampaignContentAssetType::FOUNDER_POST->value, CampaignContentAssetType::NEWSLETTER_SNIPPET->value], true)
                ? 'supporting_strategy'
                : 'pillar_article';
            $edge = [
                'from' => $parent,
                'to' => $asset['key'],
                'type' => $asset['lane'] === 'distribution' ? 'repurposes' : 'supports',
                'reason' => $asset['lane'] === 'distribution' ? 'Distribution asset repurposes approved campaign source material.' : 'Supporting asset strengthens topical authority around the pillar.',
            ];

            $edges[] = $edge;
            $edgesByAsset[$asset['key']][] = $edge;
        }

        return [
            'nodes' => collect($assets)->map(fn (array $asset): array => [
                'id' => $asset['key'],
                'label' => $asset['title'],
                'type' => $asset['asset_type'],
                'lane' => $asset['lane'],
                'funnel_stage' => $asset['funnel_stage'],
            ])->values()->all(),
            'edges' => $edges,
            'edges_by_asset' => $edgesByAsset,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $assets
     * @return list<array<string,mixed>>
     */
    private function publishingSchedule(array $assets, mixed $startDate = null): array
    {
        $start = $startDate ? Carbon::parse($startDate)->startOfDay() : now()->addWeek()->startOfDay();

        return collect($assets)->map(function (array $asset) use ($start): array {
            $date = $start->copy()->addDays(($asset['sequence_order'] - 1) * 3);

            return [
                'asset_key' => $asset['key'],
                'sequence_order' => $asset['sequence_order'],
                'date' => $date->toDateString(),
                'scheduled_for' => $date->setTime(9, 0)->toDateTimeString(),
                'reason' => $asset['sequence_order'] === 1 ? 'Publish the pillar before derivative and supporting assets.' : 'Sequenced after upstream source material is available for review.',
            ];
        })->values()->all();
    }

    /**
     * @param  list<array<string,mixed>>  $assets
     * @return array<string,mixed>
     */
    private function internalLinkingPlan(array $assets): array
    {
        $byAsset = [];

        foreach ($assets as $asset) {
            $byAsset[$asset['key']] = $asset['key'] === 'pillar_article'
                ? collect($assets)->where('lane', 'supporting')->pluck('key')->values()->all()
                : ['pillar_article'];
        }

        return [
            'strategy' => 'Build a hub-and-spoke campaign path with supporting articles linking to the pillar and distribution assets referencing the approved source asset.',
            'by_asset' => $byAsset,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function audienceMapping(string $topic, array $goals, string $freeformAudience): array
    {
        $primary = $freeformAudience !== '' ? [$freeformAudience] : ['marketing leaders', 'founders', 'growth teams'];

        return [
            'primary' => $primary,
            'secondary' => ['marketing operations', 'content strategists', 'SEO and AI visibility owners'],
            'pain_points' => [
                'Need a structured plan for '.$topic,
                'Need governance before autonomous execution',
                'Need reusable assets across search, social, and email',
            ],
            'buying_context' => $goals ?: ['category education', 'pipeline influence', 'AI visibility improvement'],
        ];
    }

    /**
     * @param  Collection<int,Opportunity>  $opportunities
     * @return list<string>
     */
    private function clusterSeeds(string $topic, Collection $opportunities): array
    {
        return collect([$topic, $topic.' strategy', $topic.' operations', $topic.' measurement'])
            ->merge($opportunities->pluck('topic')->filter())
            ->map(fn (string $value): string => Str::of($value)->lower()->trim()->toString())
            ->unique()
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string,mixed>>  $assets
     * @param  Collection<int,Opportunity>  $opportunities
     * @return list<array<string,mixed>>
     */
    private function topicClusters(string $topic, array $assets, Collection $opportunities): array
    {
        return collect($this->clusterSeeds($topic, $opportunities))
            ->map(fn (string $cluster): array => [
                'topic' => $cluster,
                'asset_keys' => collect($assets)->where('topic_cluster', $cluster)->pluck('key')->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string,mixed>>  $assets
     * @param  array<string,mixed>  $dependencyGraph
     * @param  list<array<string,mixed>>  $publishingSchedule
     * @return array<string,mixed>
     */
    private function visualCampaignMap(array $assets, array $dependencyGraph, array $publishingSchedule): array
    {
        return [
            'lanes' => collect($assets)->groupBy('lane')->map(fn (Collection $laneAssets, string $lane): array => [
                'id' => $lane,
                'label' => Str::headline($lane),
                'asset_keys' => $laneAssets->pluck('key')->values()->all(),
            ])->values()->all(),
            'nodes' => $dependencyGraph['nodes'],
            'edges' => $dependencyGraph['edges'],
            'schedule' => $publishingSchedule,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $assets
     * @return array<string,array<string,mixed>>
     */
    private function approvalCheckpoints(array $assets): array
    {
        return collect($assets)->mapWithKeys(fn (array $asset): array => [
            $asset['key'] => [
                'status' => CampaignApprovalStatus::REQUESTED->value,
                'required_before' => $asset['lane'] === 'distribution' ? 'distribution_scheduling' : 'content_generation',
                'review_focus' => $asset['lane'] === 'distribution' ? 'Message accuracy and channel fit' : 'Strategic fit, outline quality, and internal link targets',
            ],
        ])->all();
    }

    /**
     * @param  list<array<string,mixed>>  $assets
     * @return array<string,list<array<string,string>>>
     */
    private function repurposingRecommendations(array $assets): array
    {
        return collect($assets)->mapWithKeys(function (array $asset): array {
            $recommendations = match ($asset['asset_type']) {
                CampaignContentAssetType::ARTICLE->value => [
                    ['target' => 'linkedin_post', 'reason' => 'Extract the strongest argument into a discussion post.'],
                    ['target' => 'newsletter_snippet', 'reason' => 'Summarize the article for subscribers.'],
                    ['target' => 'faq_block', 'reason' => 'Convert objections and definitions into structured questions.'],
                ],
                CampaignContentAssetType::FAQ_BLOCK->value, CampaignContentAssetType::ANSWER_BLOCK->value => [
                    ['target' => 'article_sections', 'reason' => 'Reuse concise answers inside related article sections.'],
                ],
                default => [
                    ['target' => 'campaign_recap', 'reason' => 'Collect social learnings for the next refresh.'],
                ],
            };

            return [$asset['key'] => $recommendations];
        })->all();
    }

    private function objective(string $topic, array $goals, Collection $opportunities): string
    {
        $goal = $goals[0] ?? 'Build topical authority and campaign-ready distribution around '.$topic;

        return $goal.' using '.count($opportunities).' linked opportunity signal(s).';
    }

    /**
     * @return list<string>
     */
    private function normalizeList(mixed $value): array
    {
        if (is_array($value)) {
            return collect($value)->map(fn ($item): string => trim((string) $item))->filter()->values()->all();
        }

        return collect(preg_split('/\r\n|\r|\n|,/', (string) $value) ?: [])
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string,string>
     */
    private function kpis(array $goals): array
    {
        return [
            'authority' => 'Improve topical coverage and internal link completeness',
            'distribution' => 'Produce approved assets for search, LinkedIn, and newsletter channels',
            'conversion' => 'Add FAQ and answer blocks for decision-stage questions',
            'goal_context' => implode('; ', $goals),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $assets
     * @return array<string,int>
     */
    private function channelMix(array $assets): array
    {
        return collect($assets)
            ->flatMap(fn (array $asset): array => $this->channelTypesForAsset($asset['asset_type']))
            ->countBy()
            ->all();
    }

    /**
     * @return array<string,string>
     */
    private function funnelStageMap(array $assets): array
    {
        return collect($assets)->mapWithKeys(fn (array $asset): array => [$asset['key'] => $asset['funnel_stage']])->all();
    }

    /**
     * @return list<string>
     */
    private function outlineFor(CampaignContentAssetType $type, string $angle): array
    {
        return match ($type) {
            CampaignContentAssetType::ARTICLE => ['Definition', 'Why now', 'Operating model', 'Implementation steps', 'Measurement', 'Next action'],
            CampaignContentAssetType::LINKEDIN_POST, CampaignContentAssetType::FOUNDER_POST => ['Hook', 'Point of view', 'Concrete example', 'Question or CTA'],
            CampaignContentAssetType::FAQ_BLOCK => ['Question', 'Direct answer', 'Decision context', 'Related internal link'],
            CampaignContentAssetType::ANSWER_BLOCK => ['One-sentence answer', 'Expanded answer', 'Entity/context notes'],
            CampaignContentAssetType::NEWSLETTER_SNIPPET => ['Opening insight', 'Campaign takeaway', 'Link CTA'],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function channelRequirements(CampaignContentAssetType $type): array
    {
        return [
            'channel_types' => $this->channelTypesForAsset($type->value),
            'approval_required' => true,
            'execution_mode' => 'draft_only',
        ];
    }

    /**
     * @return list<string>
     */
    private function channelTypesForAsset(string $assetType): array
    {
        return match ($assetType) {
            CampaignContentAssetType::ARTICLE->value, CampaignContentAssetType::FAQ_BLOCK->value, CampaignContentAssetType::ANSWER_BLOCK->value => [DistributionChannelType::WEBSITE->value],
            CampaignContentAssetType::LINKEDIN_POST->value, CampaignContentAssetType::FOUNDER_POST->value => [DistributionChannelType::LINKEDIN->value],
            CampaignContentAssetType::NEWSLETTER_SNIPPET->value => [DistributionChannelType::NEWSLETTER->value],
            default => [DistributionChannelType::MANUAL->value],
        };
    }

    /**
     * @return array<string,DistributionChannel>
     */
    private function channelsFor(Workspace $workspace): array
    {
        $required = [
            DistributionChannelType::WEBSITE->value => 'Website planning',
            DistributionChannelType::LINKEDIN->value => 'LinkedIn planning',
            DistributionChannelType::NEWSLETTER->value => 'Newsletter planning',
        ];

        return collect($required)->mapWithKeys(function (string $name, string $type) use ($workspace): array {
            $channel = DistributionChannel::query()->firstOrCreate(
                ['workspace_id' => (string) $workspace->id, 'type' => $type, 'name' => $name],
                [
                    'organization_id' => $workspace->organization_id,
                    'status' => DistributionChannel::STATUS_ACTIVE,
                    'environment' => 'planning',
                    'provider' => $type,
                    'capabilities' => ['planning', 'scheduling_recommendations'],
                    'planning_rules' => ['requires_connection_before_publish' => $type !== DistributionChannelType::WEBSITE->value],
                    'credentials_ref' => null,
                    'metadata' => ['created_by' => 'campaign_planner_service'],
                ]
            );

            return [$type => $channel];
        })->all();
    }

    /**
     * @param  array<string,mixed>  $asset
     * @param  array<string,DistributionChannel>  $channels
     * @return list<array{channel:DistributionChannel,format:string}>
     */
    private function distributionTargets(array $asset, array $channels): array
    {
        return collect($this->channelTypesForAsset($asset['asset_type']))
            ->map(fn (string $type): ?array => isset($channels[$type]) ? ['channel' => $channels[$type], 'format' => $asset['asset_type']] : null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function toneVariations(string $topic, array $audience): array
    {
        return [
            'authoritative' => ['use_for' => ['pillar_article'], 'note' => 'Define '.$topic.' with category-level confidence.'],
            'practical' => ['use_for' => ['supporting_strategy'], 'note' => 'Translate strategy into operating steps.'],
            'technical' => ['use_for' => ['supporting_operations', 'linkedin_technical_deep_dive'], 'note' => 'Use precise process language for practitioners.'],
            'founder' => ['use_for' => ['founder_post'], 'note' => 'Use a point-of-view narrative without unsupported claims.'],
            'concise' => ['use_for' => ['faq_block', 'answer_block', 'newsletter_snippet'], 'note' => 'Lead with the answer and keep claims reusable.'],
            'audience_context' => $audience,
        ];
    }
}
