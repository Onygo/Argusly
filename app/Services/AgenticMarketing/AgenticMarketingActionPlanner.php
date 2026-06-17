<?php

namespace App\Services\AgenticMarketing;

use App\Enums\AgenticMarketingActionType;
use App\Enums\AgenticMarketingApprovalMode;
use App\Enums\AgenticMarketingOpportunityStatus;
use App\Enums\AgenticMarketingOpportunityType;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\AgenticMarketingRun;
use App\Models\AgenticMarketingRunItem;
use App\Models\Content;
use App\Models\WriterProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AgenticMarketingActionPlanner
{
    public function __construct(
        private readonly ?AgenticMarketingApprovalPolicyEngine $approvalPolicyEngine = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function planForObjective(AgenticMarketingObjective $objective): array
    {
        $run = AgenticMarketingRun::query()->create([
            'objective_id' => $objective->id,
            'status' => AgenticMarketingRun::STATUS_QUEUED,
            'payload' => ['type' => 'action_planning'],
        ]);
        $run->markRunning();
        app(AgenticMarketingAuditLogger::class)->record($run->loadMissing('objective'), 'run.started', null, $run->attributesToArray());

        $summary = [
            'objective_id' => (string) $objective->id,
            'run_id' => (string) $run->id,
            'opportunities' => 0,
            'created' => 0,
            'reused' => 0,
            'skipped' => 0,
            'action_ids' => [],
        ];

        $objective->opportunities()
            ->where('status', AgenticMarketingOpportunityStatus::Open->value)
            ->orderByDesc('priority_score')
            ->chunkById(50, function (Collection $opportunities) use (&$summary, $run): void {
                foreach ($opportunities as $opportunity) {
                    $result = $this->planForOpportunity($opportunity, $run);
                    $summary['opportunities']++;
                    $summary['created'] += (int) $result['created'];
                    $summary['reused'] += (int) $result['reused'];
                    $summary['skipped'] += (int) $result['skipped'];
                    $summary['action_ids'] = array_values(array_unique(array_merge($summary['action_ids'], $result['action_ids'])));
                }
            });

        $run->markCompleted($summary);
        app(AgenticMarketingAuditLogger::class)->record($run->loadMissing('objective'), 'run.completed', null, $summary);

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    public function planForOpportunity(AgenticMarketingOpportunity $opportunity, ?AgenticMarketingRun $run = null): array
    {
        $opportunity->loadMissing(['objective', 'content']);
        $ownsRun = false;
        if (! $run) {
            $run = AgenticMarketingRun::query()->create([
                'objective_id' => $opportunity->objective_id,
                'status' => AgenticMarketingRun::STATUS_QUEUED,
                'payload' => ['type' => 'action_planning', 'opportunity_id' => (string) $opportunity->id],
            ]);
            $run->markRunning();
            $ownsRun = true;
            app(AgenticMarketingAuditLogger::class)->record($run->loadMissing('objective'), 'run.started', null, $run->attributesToArray());
        }

        $item = AgenticMarketingRunItem::query()->create([
            'run_id' => $run->id,
            'objective_id' => $opportunity->objective_id,
            'opportunity_id' => $opportunity->id,
            'type' => AgenticMarketingRunItem::TYPE_PLANNING,
            'name' => 'Plan actions',
            'status' => AgenticMarketingRunItem::STATUS_QUEUED,
            'payload' => [
                'opportunity_type' => (string) $opportunity->type,
                'priority_score' => (int) $opportunity->priority_score,
            ],
        ]);
        $item->markRunning();

        $summary = [
            'opportunity_id' => (string) $opportunity->id,
            'run_id' => (string) $run->id,
            'created' => 0,
            'reused' => 0,
            'skipped' => 0,
            'action_ids' => [],
        ];

        foreach ($this->plannedActions($opportunity) as $plan) {
            if (! (bool) data_get($plan, 'prerequisites.met', false)) {
                $summary['skipped']++;
                continue;
            }

            $action = AgenticMarketingAction::createOrReuseOpen([
                'objective_id' => (string) $opportunity->objective_id,
                'opportunity_id' => (string) $opportunity->id,
                'content_id' => $plan['content_id'] ?? $opportunity->content_id,
                'action_type' => $plan['action_type'],
                'status' => AgenticMarketingAction::STATUS_PROPOSED,
                'estimated_credits' => $plan['estimated_credits'],
                'run_id' => (string) $run->id,
                'payload' => array_replace_recursive($plan['payload'], [
                    'planning' => ['run_id' => (string) $run->id],
                ]),
            ]);

            if ($action->wasRecentlyCreated) {
                $summary['created']++;
            } else {
                $summary['reused']++;
                $this->refreshReusableAction($action, $plan);
            }

            $summary['action_ids'][] = (string) $action->id;
        }

        $summary['action_ids'] = array_values(array_unique($summary['action_ids']));
        $item->markCompleted($summary);

        if ($ownsRun) {
            $run->markCompleted($summary);
            app(AgenticMarketingAuditLogger::class)->record($run->loadMissing('objective'), 'run.completed', null, $summary);
        }

        return $summary;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function plannedActions(AgenticMarketingOpportunity $opportunity): array
    {
        $type = AgenticMarketingOpportunityType::tryFrom((string) $opportunity->type);
        $plans = match ($type) {
            AgenticMarketingOpportunityType::Refresh => [
                $this->plan($opportunity, AgenticMarketingActionType::RefreshArticle),
            ],
            AgenticMarketingOpportunityType::AnswerCoverage => [
                $this->plan($opportunity, AgenticMarketingActionType::AddAnswerBlock),
            ],
            AgenticMarketingOpportunityType::InternalLinks => [
                $this->plan($opportunity, AgenticMarketingActionType::ImproveInternalLinks),
            ],
            AgenticMarketingOpportunityType::LocaleExpansion => $this->localeVariantPlans($opportunity),
            AgenticMarketingOpportunityType::Metadata => [
                $this->plan($opportunity, AgenticMarketingActionType::UpdateMeta),
            ],
            AgenticMarketingOpportunityType::Schema => [
                $this->plan($opportunity, AgenticMarketingActionType::AddSchema),
            ],
            AgenticMarketingOpportunityType::SeoIndexability => $this->seoPlans($opportunity),
            AgenticMarketingOpportunityType::NewArticle,
            AgenticMarketingOpportunityType::ContentNetwork => [
                $this->plan($opportunity, AgenticMarketingActionType::CreateArticle),
            ],
            AgenticMarketingOpportunityType::AiVisibility => [
                $this->plan($opportunity, AgenticMarketingActionType::AddAnswerBlock),
                $this->plan($opportunity, AgenticMarketingActionType::UpdateMeta),
            ],
            default => [],
        };

        return array_values(array_filter($plans));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function localeVariantPlans(AgenticMarketingOpportunity $opportunity): array
    {
        $missingLocales = collect((array) data_get($opportunity->payload, 'signals.missing_locales', []))
            ->map(fn (mixed $locale): string => trim((string) $locale))
            ->filter()
            ->unique()
            ->values();

        if ($missingLocales->isEmpty()) {
            return [$this->plan($opportunity, AgenticMarketingActionType::CreateLocaleVariant)];
        }

        return $missingLocales
            ->map(fn (string $locale): array => $this->plan($opportunity, AgenticMarketingActionType::CreateLocaleVariant, [
                'target_locale' => $locale,
                'locale' => $locale,
            ]))
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function seoPlans(AgenticMarketingOpportunity $opportunity): array
    {
        $issues = (array) data_get($opportunity->payload, 'signals.issues', []);
        $plans = [];

        if (array_intersect($issues, ['missing_seo_title', 'missing_meta_description', 'canonical_not_accepted', 'not_indexed', 'crawled_not_indexed', 'robots_noindex'])) {
            $plans[] = $this->plan($opportunity, AgenticMarketingActionType::UpdateMeta);
        }

        if (in_array('missing_schema_type', $issues, true) || trim((string) data_get($opportunity->payload, 'signals.schema_type', '')) === '') {
            $plans[] = $this->plan($opportunity, AgenticMarketingActionType::AddSchema);
        }

        return $plans !== [] ? $plans : [$this->plan($opportunity, AgenticMarketingActionType::UpdateMeta)];
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function plan(AgenticMarketingOpportunity $opportunity, AgenticMarketingActionType $actionType, array $overrides = []): array
    {
        $objective = $opportunity->objective;
        $payload = (array) ($opportunity->payload ?? []);
        $signals = (array) data_get($payload, 'signals', []);
        $contentId = (string) ($opportunity->content_id ?: data_get($payload, 'content_id', ''));
        $cost = $this->costEstimate($actionType, $signals);
        $risk = $this->riskLevel($actionType, $signals);
        $approval = $this->approvalPolicyEngine()->planningPolicy($objective?->approval_mode, $actionType, $risk);
        $prerequisites = $this->prerequisites($opportunity, $actionType, $overrides);
        $writerProfile = $this->recommendedWriterProfile((string) $objective?->workspace_id, (string) data_get($payload, 'content_type', 'blog'));

        $actionPayload = array_filter(array_merge([
            'content_id' => $contentId !== '' ? $contentId : null,
            'workspace_id' => $objective?->workspace_id,
            'client_site_id' => $objective?->client_site_id ?: data_get($payload, 'client_site_id') ?: $opportunity->content?->client_site_id,
            'locale' => data_get($payload, 'locale') ?: $objective?->locale,
            'title' => $this->actionTitle($opportunity, $actionType, $overrides),
            'primary_keyword' => data_get($payload, 'primary_keyword') ?: data_get($signals, 'topic_keyword'),
            'target_audience' => data_get($payload, 'target_audience') ?: $objective?->audience,
            'funnel_stage' => data_get($payload, 'funnel_stage') ?: data_get($signals, 'funnel_stage'),
            'search_intent' => data_get($payload, 'search_intent') ?: data_get($payload, 'primary_search_intent') ?: data_get($signals, 'search_intent'),
            'content_type' => data_get($payload, 'content_type', 'blog'),
            'angle' => data_get($payload, 'angle'),
            'suggested_cta' => data_get($payload, 'suggested_cta'),
            'suggested_schema' => data_get($payload, 'suggested_schema') ?: data_get($signals, 'suggested_schema'),
            'recommendation' => $this->recommendation($opportunity, $actionType),
            'writer_profile_id' => $writerProfile?->id,
            'writer_profile_recommendation' => $writerProfile
                ? 'Gebruik Writer Profile '.$writerProfile->name.' als stijlrichting voor deze reeks. Het profiel mag strategie, feiten, persona of merkpositionering niet overschrijven.'
                : 'Overweeg een nieuw writer profile te maken op basis van best presterende artikelen als stijlconsistentie belangrijk is.',
            'writer_profile_suggestions' => $this->writerProfileSuggestions($writerProfile),
            'reason' => data_get($payload, 'score_explanation.summary') ?: $opportunity->title,
        ], $overrides), fn (mixed $value): bool => $value !== null && $value !== '');

        $actionPayload['planning'] = [
            'planner' => self::class,
            'version' => 'deterministic_v1',
            'estimated_credits' => $cost,
            'risk_level' => $risk,
            'approval_mode' => $objective?->approval_mode ?: AgenticMarketingApprovalMode::Manual->value,
            'approval_required' => $approval['required'],
            'approval_reason' => $approval['reason'],
            'autonomy' => $approval['autonomy'],
            'prerequisites' => $prerequisites,
            'source_opportunity_type' => (string) $opportunity->type,
            'source_priority_score' => (int) $opportunity->priority_score,
        ];
        $actionPayload['proposal_details'] = $this->proposalDetails($opportunity, $actionType, $cost, $risk, $overrides);

        return [
            'action_type' => $actionType->value,
            'content_id' => $contentId !== '' ? $contentId : null,
            'estimated_credits' => $cost,
            'risk_level' => $risk,
            'approval_required' => $approval['required'],
            'prerequisites' => $prerequisites,
            'payload' => $actionPayload,
        ];
    }

    /**
     * @return array{met:bool,checks:array<int,array<string,mixed>>}
     */
    private function prerequisites(AgenticMarketingOpportunity $opportunity, AgenticMarketingActionType $actionType, array $overrides): array
    {
        $objective = $opportunity->objective;
        $contentId = (string) ($opportunity->content_id ?: data_get($opportunity->payload ?? [], 'content_id', ''));
        $checks = [
            [
                'key' => 'objective_workspace',
                'met' => (bool) $objective?->workspace_id,
                'label' => 'Objective has a workspace.',
            ],
        ];

        if ($this->requiresContent($actionType) && ! $this->allowsClusterLevelAnswerBlockProposal($opportunity, $actionType)) {
            $checks[] = [
                'key' => 'source_content',
                'met' => $contentId !== '' && Content::query()
                    ->whereKey($contentId)
                    ->where('workspace_id', $objective?->workspace_id)
                    ->exists(),
                'label' => 'Source content exists in the objective workspace.',
            ];
        }

        if ($actionType === AgenticMarketingActionType::CreateLocaleVariant) {
            $checks[] = [
                'key' => 'target_locale',
                'met' => trim((string) ($overrides['target_locale'] ?? data_get($opportunity->payload, 'signals.missing_locales.0', ''))) !== '',
                'label' => 'A target locale is available.',
            ];
        }

        if ($actionType === AgenticMarketingActionType::CreateArticle) {
            $checks[] = [
                'key' => 'article_title',
                'met' => trim($this->actionTitle($opportunity, $actionType, $overrides)) !== '',
                'label' => 'A draft title can be derived from the opportunity.',
            ];
        }

        return [
            'met' => collect($checks)->every(fn (array $check): bool => (bool) $check['met']),
            'checks' => $checks,
        ];
    }

    private function requiresContent(AgenticMarketingActionType $actionType): bool
    {
        return in_array($actionType, [
            AgenticMarketingActionType::RefreshArticle,
            AgenticMarketingActionType::AddAnswerBlock,
            AgenticMarketingActionType::ImproveInternalLinks,
            AgenticMarketingActionType::CreateLocaleVariant,
            AgenticMarketingActionType::UpdateMeta,
            AgenticMarketingActionType::AddSchema,
        ], true);
    }

    private function allowsClusterLevelAnswerBlockProposal(AgenticMarketingOpportunity $opportunity, AgenticMarketingActionType $actionType): bool
    {
        return $actionType === AgenticMarketingActionType::AddAnswerBlock
            && data_get($opportunity->payload, 'detector') === 'campaign_cluster_action_materializer'
            && data_get($opportunity->payload, 'signals.asset_kind') === 'content_enhancement';
    }

    private function costEstimate(AgenticMarketingActionType $actionType, array $signals): int
    {
        if ($actionType === AgenticMarketingActionType::AddSchema) {
            return 2;
        }

        $base = match ($actionType) {
            AgenticMarketingActionType::ImproveInternalLinks => 3,
            AgenticMarketingActionType::UpdateMeta => 4,
            AgenticMarketingActionType::AddAnswerBlock => 6,
            AgenticMarketingActionType::RefreshArticle => 12,
            AgenticMarketingActionType::CreateLocaleVariant => 18,
            AgenticMarketingActionType::CreateArticle => 24,
            AgenticMarketingActionType::AddSchema => 2,
        };

        $base += min(6, (int) ($signals['suggested_link_count'] ?? 0));
        $base += min(6, count((array) ($signals['issues'] ?? [])));

        return max(1, $base);
    }

    private function riskLevel(AgenticMarketingActionType $actionType, array $signals): string
    {
        $risk = match ($actionType) {
            AgenticMarketingActionType::ImproveInternalLinks,
            AgenticMarketingActionType::UpdateMeta,
            AgenticMarketingActionType::AddSchema => 1,
            AgenticMarketingActionType::AddAnswerBlock => 2,
            AgenticMarketingActionType::RefreshArticle,
            AgenticMarketingActionType::CreateLocaleVariant => 3,
            AgenticMarketingActionType::CreateArticle => 4,
        };

        $risk += in_array('robots_noindex', (array) ($signals['issues'] ?? []), true) ? 1 : 0;
        $risk += (($signals['gap_type'] ?? null) === 'missing_pillar') ? 1 : 0;

        return match (true) {
            $risk >= 5 => 'high',
            $risk >= 3 => 'medium',
            default => 'low',
        };
    }

    private function actionTitle(AgenticMarketingOpportunity $opportunity, AgenticMarketingActionType $actionType, array $overrides): string
    {
        if (! empty($overrides['target_locale'])) {
            return sprintf('%s: %s', strtoupper((string) $overrides['target_locale']), (string) $opportunity->title);
        }

        return match ($actionType) {
            AgenticMarketingActionType::CreateArticle => $this->suggestedArticleTitle($opportunity),
            default => (string) $opportunity->title,
        };
    }

    private function suggestedArticleTitle(AgenticMarketingOpportunity $opportunity): string
    {
        return (string) (
            data_get($opportunity->payload, 'signals.suggested_title')
            ?: data_get($opportunity->payload, 'signals.topic_keyword')
            ?: $opportunity->title
        );
    }

    private function recommendation(AgenticMarketingOpportunity $opportunity, AgenticMarketingActionType $actionType): string
    {
        $summary = (string) data_get($opportunity->payload, 'score_explanation.summary', '');

        return trim(match ($actionType) {
            AgenticMarketingActionType::RefreshArticle => 'Prepare a supervised refresh draft. ' . $summary,
            AgenticMarketingActionType::AddAnswerBlock => 'Draft structured answer blocks for review. ' . $summary,
            AgenticMarketingActionType::ImproveInternalLinks => 'Prepare internal link suggestions for review. ' . $summary,
            AgenticMarketingActionType::CreateLocaleVariant => 'Prepare a locale variant request for review. ' . $summary,
            AgenticMarketingActionType::UpdateMeta => 'Prepare metadata improvements for review. ' . $summary,
            AgenticMarketingActionType::AddSchema => 'Prepare schema markup recommendations for review. ' . $summary,
            AgenticMarketingActionType::CreateArticle => 'Create a draft article for review. ' . $summary,
        });
    }

    private function recommendedWriterProfile(string $workspaceId, string $contentType): ?WriterProfile
    {
        if ($workspaceId === '') {
            return null;
        }

        $channel = str_contains(strtolower($contentType), 'social') ? 'linkedin' : 'blog';

        return WriterProfile::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', WriterProfile::STATUS_ACTIVE)
            ->where(function ($query) use ($channel): void {
                $query->where("channel_defaults->{$channel}", true)
                    ->orWhere('channel_defaults->blog', true);
            })
            ->orderByDesc('confidence_score')
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function writerProfileSuggestions(?WriterProfile $writerProfile): array
    {
        if (! $writerProfile) {
            return [
                'Maak een nieuw writer profile op basis van best presterende artikelen.',
            ];
        }

        return [
            'Gebruik Writer Profile '.$writerProfile->name.' voor deze reeks.',
            'Controleer na generatie of de content afwijkt van de gewenste schrijfstijl.',
            'Vergelijk LinkedIn posts met '.$writerProfile->name.' als stijlanker voordat je varianten goedkeurt.',
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function proposalDetails(AgenticMarketingOpportunity $opportunity, AgenticMarketingActionType $actionType, int $cost, string $risk, array $overrides = []): array
    {
        $payload = (array) ($opportunity->payload ?? []);
        $signals = (array) data_get($payload, 'signals', []);
        $topic = trim((string) (data_get($payload, 'topic') ?: data_get($signals, 'topic_keyword') ?: $opportunity->title));
        $topic = $topic !== '' ? $topic : 'this topic';
        $title = $this->actionTitle($opportunity, $actionType, $overrides);
        $question = trim((string) (data_get($signals, 'questions.0') ?: data_get($payload, 'question') ?: 'What should buyers know about '.$topic.'?'));
        $summary = trim((string) (data_get($payload, 'score_explanation.summary') ?: $opportunity->title));
        $entities = $this->semanticEntities($opportunity, $topic);
        $impact = $this->estimatedImpact($opportunity, $signals, $risk);
        $links = $this->proposalLinks($opportunity, $topic);

        $answer = sprintf(
            '%s helps %s understand %s by making the content answer-ready, semantically explicit, and easier for AI systems to cite in generated answers.',
            Str::headline($topic),
            trim((string) ($opportunity->objective?->audience ?: data_get($payload, 'target_audience', 'buyers'))),
            Str::lower($topic)
        );

        return [
            'schema' => 'agentic_marketing.action_proposal_details.v1',
            'generated_at_planning_time' => true,
            'topic' => $topic,
            'action_type' => $actionType->value,
            'estimated_impact' => $impact,
            'items' => [
                [
                    'type' => 'generated_answer_block',
                    'question' => $question,
                    'answer' => $answer,
                    'text' => $answer,
                    'review_required' => true,
                ],
                [
                    'type' => 'generated_schema',
                    'schema' => [
                        '@type' => $actionType === AgenticMarketingActionType::AddAnswerBlock ? 'FAQPage' : (string) data_get($signals, 'schema_type', data_get($payload, 'suggested_schema', 'Article')),
                        'headline' => $title,
                        'about' => $topic,
                        'mainEntity' => [
                            'name' => $question,
                            'acceptedAnswer' => ['text' => $answer],
                        ],
                    ],
                    'review_required' => true,
                ],
                [
                    'type' => 'generated_cta',
                    'label' => 'Explore a GEO content plan',
                    'placement' => 'After the answer block and before related resources',
                    'reason' => 'The reader has just received a direct answer and is ready for a next-step offer.',
                    'review_required' => true,
                ],
                [
                    'type' => 'suggested_links',
                    'links' => $links,
                    'reason' => 'Build topical authority by connecting this page to related supporting assets.',
                    'review_required' => true,
                ],
                [
                    'type' => 'semantic_entities',
                    'entities' => $entities,
                    'reason' => 'These entities should be reinforced in headings, answers, schema, and nearby internal links.',
                ],
                [
                    'type' => 'visibility_reasoning',
                    'reason' => $summary !== '' ? $summary : 'The opportunity can improve AI answer extraction and topical authority.',
                    'signals' => array_values(array_filter([
                        'priority_score: '.(int) $opportunity->priority_score,
                        'risk_level: '.$risk,
                        'estimated_credits: '.$cost,
                        data_get($signals, 'gap_type') ? 'gap_type: '.data_get($signals, 'gap_type') : null,
                    ])),
                ],
                [
                    'type' => 'estimated_impact',
                    'impact' => $impact,
                    'priority' => (int) max(1, min(5, ceil((100 - (int) $opportunity->priority_score) / 20) + 1)),
                    'reason' => $impact === 'High'
                        ? 'High-priority AI visibility work with clear answer, schema, and internal-link leverage.'
                        : 'Useful optimization with bounded execution risk and reviewable output.',
                ],
            ],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function semanticEntities(AgenticMarketingOpportunity $opportunity, string $topic): array
    {
        $payload = (array) ($opportunity->payload ?? []);
        $entities = array_merge(
            [$topic],
            (array) data_get($payload, 'related_entities', []),
            (array) data_get($payload, 'signals.entities', []),
            (array) ($opportunity->objective?->competitors ?? []),
            (array) ($opportunity->objective?->languages ?? [])
        );

        return collect($entities)
            ->map(fn (mixed $entity): string => trim((string) $entity))
            ->filter()
            ->unique(fn (string $entity): string => Str::lower($entity))
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function proposalLinks(AgenticMarketingOpportunity $opportunity, string $topic): array
    {
        $signalLinks = collect((array) data_get($opportunity->payload, 'signals.link_opportunities', []))
            ->map(function (mixed $link) use ($topic): array {
                $link = (array) $link;

                return [
                    'target' => (string) ($link['target_title'] ?? $link['target_content_id'] ?? 'Related '.$topic.' resource'),
                    'anchor_text' => (string) ($link['anchor_text_suggestion'] ?? $link['anchor_text'] ?? Str::lower($topic)),
                    'reason' => (string) ($link['reason'] ?? 'Connect related topical context.'),
                ];
            })
            ->filter(fn (array $link): bool => trim($link['target']) !== '')
            ->take(3)
            ->values();

        if ($signalLinks->isNotEmpty()) {
            return $signalLinks->all();
        }

        return Content::query()
            ->where('workspace_id', $opportunity->objective?->workspace_id)
            ->when($opportunity->content_id, fn ($query) => $query->whereKeyNot($opportunity->content_id))
            ->orderByDesc('updated_at')
            ->limit(3)
            ->get(['id', 'title'])
            ->map(fn (Content $content): array => [
                'target' => (string) ($content->title ?: $content->id),
                'anchor_text' => Str::lower($topic),
                'reason' => 'Nearby workspace content can strengthen the authority path.',
            ])
            ->values()
            ->all();
    }

    private function estimatedImpact(AgenticMarketingOpportunity $opportunity, array $signals, string $risk): string
    {
        $score = (int) $opportunity->priority_score;
        $boost = count((array) ($signals['issues'] ?? [])) + count((array) ($signals['link_opportunities'] ?? []));

        return match (true) {
            $score >= 75 || $boost >= 3 => 'High',
            $score >= 45 || $risk === 'medium' => 'Medium',
            default => 'Low',
        };
    }

    /**
     * @param array<string,mixed> $plan
     */
    private function refreshReusableAction(AgenticMarketingAction $action, array $plan): void
    {
        $updates = [];

        if ((int) ($action->estimated_credits ?? 0) !== (int) $plan['estimated_credits']) {
            $updates['estimated_credits'] = $plan['estimated_credits'];
        }

        if (($action->payload ?? []) !== $plan['payload']) {
            $updates['payload'] = $plan['payload'];
        }

        if ($updates !== []) {
            $action->forceFill($updates)->save();
        }
    }

    private function approvalPolicyEngine(): AgenticMarketingApprovalPolicyEngine
    {
        return $this->approvalPolicyEngine ?? app(AgenticMarketingApprovalPolicyEngine::class);
    }
}
