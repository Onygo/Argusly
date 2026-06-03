<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Audience;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Briefing;
use App\Models\ContentAsset;
use App\Models\IntelligenceSignal;
use App\Models\MarketingObjective;
use App\Models\MarketingTask;
use App\Models\Newsletter;
use App\Models\NewsletterSend;
use App\Models\PublishingChannel;
use App\Models\Recommendation;
use App\Models\SocialPost;
use App\Models\SocialProfile;
use App\Services\Signals\SignalManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RecommendationEngineService
{
    public function __construct(private readonly EvidenceService $evidence) {}

    /**
     * @return Collection<int, Recommendation>
     */
    public function generateForSignal(IntelligenceSignal $signal): Collection
    {
        return collect($this->rulesFor($signal))
            ->map(fn (array $attributes) => $this->store($signal, $attributes))
            ->values();
    }

    /**
     * @return Collection<int, Recommendation>
     */
    public function generateDistributionRecommendations(Account $account, Brand $brand): Collection
    {
        if ($brand->account_id !== $account->id) {
            return collect();
        }

        $signals = collect()
            ->merge($this->contentDistributionSignals($account, $brand))
            ->merge($this->campaignDistributionSignals($account, $brand))
            ->merge($this->connectorDistributionSignals($account, $brand))
            ->merge($this->linkedInDistributionSignals($account, $brand))
            ->merge($this->marketingOsSignals($account, $brand));

        return $signals
            ->flatMap(fn (IntelligenceSignal $signal) => $this->generateForSignal($signal))
            ->values();
    }

    /**
     * @return Collection<int, Recommendation>
     */
    public function generateMarketingOsRecommendations(Account $account, Brand $brand): Collection
    {
        if ($brand->account_id !== $account->id) {
            return collect();
        }

        return $this->marketingOsSignals($account, $brand)
            ->flatMap(fn (IntelligenceSignal $signal) => $this->generateForSignal($signal))
            ->values();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rulesFor(IntelligenceSignal $signal): array
    {
        $payload = $signal->payload ?? [];
        $score = (int) ($payload['score'] ?? $payload['health_score'] ?? $signal->impact_score ?? 0);
        $contentAssetId = $payload['content_asset_id'] ?? null;

        return match (true) {
            $signal->type === 'narrative_gap_detected' => [
                $this->recommendation(
                    'Create content to close narrative gap',
                    'The detected market narrative differs from the desired brand narrative.',
                    'Create content that clearly reinforces the desired narrative.',
                    $signal,
                    ['impact_score' => 84, 'confidence_score' => 88, 'action_type' => 'create_content'],
                ),
                $this->recommendation(
                    'Refresh positioning',
                    'Brand positioning may need clearer language for AI systems, search engines and media surfaces.',
                    'Refresh positioning so the desired narrative is explicit and reusable across channels.',
                    $signal,
                    ['impact_score' => 82, 'confidence_score' => 86, 'action_type' => 'refresh_positioning'],
                ),
                $this->recommendation(
                    'Launch narrative campaign',
                    'A campaign can help shift repeated descriptions toward the desired narrative.',
                    'Launch a campaign focused on the desired narrative and supporting proof points.',
                    $signal,
                    ['impact_score' => 78, 'confidence_score' => 82, 'action_type' => 'launch_campaign'],
                ),
                $this->recommendation(
                    'Improve citations',
                    'AI visibility depends on sources that describe the brand accurately.',
                    'Improve citations and source coverage that support the desired narrative.',
                    $signal,
                    ['impact_score' => 80, 'confidence_score' => 84, 'action_type' => 'improve_citations'],
                ),
            ],
            ($payload['distribution_rule'] ?? null) === 'approved_not_published' => [
                $this->recommendation(
                    'Publish this content to a connected website',
                    'This content asset is approved but has not been published to the website yet.',
                    'Publish this content to a connected website.',
                    $signal,
                    ['content_asset_id' => $contentAssetId, 'impact_score' => 74, 'confidence_score' => 94],
                ),
            ],
            ($payload['distribution_rule'] ?? null) === 'published_missing_linkedin' => [
                $this->recommendation(
                    'Create a LinkedIn post from this article',
                    'This content asset is published but does not have a LinkedIn distribution post yet.',
                    'Create a LinkedIn post from this article.',
                    $signal,
                    ['content_asset_id' => $contentAssetId, 'impact_score' => 68, 'confidence_score' => 92],
                ),
            ],
            ($payload['distribution_rule'] ?? null) === 'published_missing_languages' => [
                $this->recommendation(
                    'Translate this content to missing languages',
                    'This content is published in one language but is missing enabled brand languages.',
                    'Translate this content to missing languages.',
                    $signal,
                    ['content_asset_id' => $contentAssetId, 'impact_score' => 72, 'confidence_score' => 90],
                ),
            ],
            ($payload['distribution_rule'] ?? null) === 'active_campaign_missing_social' => [
                $this->recommendation(
                    'Schedule campaign social distribution',
                    'This active campaign has no scheduled social distribution.',
                    'Schedule campaign social distribution.',
                    $signal,
                    ['impact_score' => 78, 'confidence_score' => 90],
                ),
            ],
            ($payload['distribution_rule'] ?? null) === 'connector_disconnected' => [
                $this->recommendation(
                    'Reconnect connector',
                    'The publishing channel connector is disconnected or unavailable.',
                    'Reconnect connector.',
                    $signal,
                    ['content_asset_id' => $contentAssetId, 'impact_score' => 88, 'confidence_score' => 96],
                ),
            ],
            ($payload['distribution_rule'] ?? null) === 'linkedin_token_expired' => [
                $this->recommendation(
                    'Reconnect LinkedIn profile',
                    'The LinkedIn profile token is expired and may block social distribution.',
                    'Reconnect LinkedIn profile.',
                    $signal,
                    ['content_asset_id' => $contentAssetId, 'impact_score' => 92, 'confidence_score' => 96],
                ),
            ],
            ($payload['marketing_os_rule'] ?? null) === 'active_campaign_no_tasks' => [
                $this->recommendation(
                    'Create campaign task plan',
                    'This active campaign has no Marketing OS tasks yet.',
                    'Create a starter task plan for this campaign.',
                    $signal,
                    [
                        'impact_score' => 82,
                        'confidence_score' => 92,
                        'action_type' => 'create_campaign_task_plan',
                        'action_payload' => ['campaign_id' => $payload['campaign_id'] ?? null],
                    ],
                ),
            ],
            ($payload['marketing_os_rule'] ?? null) === 'active_campaign_no_briefing' => [
                $this->recommendation(
                    'Create campaign briefing',
                    'This active campaign does not have a strategic briefing yet.',
                    'Create a draft campaign briefing to guide content and channel execution.',
                    $signal,
                    [
                        'impact_score' => 80,
                        'confidence_score' => 90,
                        'action_type' => 'create_campaign_briefing',
                        'action_payload' => ['campaign_id' => $payload['campaign_id'] ?? null],
                    ],
                ),
            ],
            ($payload['marketing_os_rule'] ?? null) === 'campaign_assets_no_newsletter' => [
                $this->recommendation(
                    'Create a newsletter digest',
                    'This campaign has multiple published content assets but no newsletter digest yet.',
                    'Create a newsletter digest from the published campaign assets.',
                    $signal,
                    [
                        'impact_score' => 74,
                        'confidence_score' => 90,
                        'action_type' => 'create_newsletter_digest',
                        'action_payload' => [
                            'campaign_id' => $payload['campaign_id'] ?? null,
                            'content_asset_ids' => $payload['content_asset_ids'] ?? [],
                        ],
                    ],
                ),
            ],
            ($payload['marketing_os_rule'] ?? null) === 'newsletter_not_approved' => [
                $this->recommendation(
                    'Submit newsletter for approval',
                    'This newsletter is drafted but has not been approved yet.',
                    'Submit the newsletter for approval before scheduling or sending.',
                    $signal,
                    [
                        'impact_score' => 68,
                        'confidence_score' => 92,
                        'action_type' => 'submit_newsletter_for_approval',
                        'action_payload' => ['newsletter_id' => $payload['newsletter_id'] ?? null],
                    ],
                ),
            ],
            ($payload['marketing_os_rule'] ?? null) === 'newsletter_approved_not_scheduled' => [
                $this->recommendation(
                    'Schedule newsletter',
                    'This newsletter is approved but has not been scheduled or sent.',
                    'Create a scheduling task for the approved newsletter.',
                    $signal,
                    [
                        'impact_score' => 70,
                        'confidence_score' => 92,
                        'action_type' => 'schedule_newsletter',
                        'action_payload' => ['newsletter_id' => $payload['newsletter_id'] ?? null],
                    ],
                ),
            ],
            ($payload['marketing_os_rule'] ?? null) === 'audience_no_recent_newsletter' => [
                $this->recommendation(
                    'Create newsletter for this audience',
                    'This audience has no recent newsletter activity.',
                    'Create a newsletter draft for this audience.',
                    $signal,
                    [
                        'impact_score' => 72,
                        'confidence_score' => 88,
                        'action_type' => 'create_audience_newsletter',
                        'action_payload' => ['audience_id' => $payload['audience_id'] ?? null],
                    ],
                ),
            ],
            ($payload['marketing_os_rule'] ?? null) === 'approved_content_no_campaign' => [
                $this->recommendation(
                    'Attach content to campaign',
                    'This approved content asset is not connected to a campaign.',
                    'Create a task to attach this content asset to the best matching active campaign.',
                    $signal,
                    [
                        'impact_score' => 66,
                        'confidence_score' => 86,
                        'action_type' => 'attach_content_to_campaign',
                        'action_payload' => [
                            'content_asset_id' => $payload['content_asset_id'] ?? null,
                            'campaign_id' => $payload['campaign_id'] ?? null,
                        ],
                    ],
                ),
            ],
            ($payload['marketing_os_rule'] ?? null) === 'scheduled_social_no_campaign' => [
                $this->recommendation(
                    'Attach social post to campaign',
                    'This scheduled social post is not connected to a campaign.',
                    'Create a task to attach this social post to the best matching active campaign.',
                    $signal,
                    [
                        'impact_score' => 64,
                        'confidence_score' => 86,
                        'action_type' => 'attach_social_post_to_campaign',
                        'action_payload' => [
                            'social_post_id' => $payload['social_post_id'] ?? null,
                            'campaign_id' => $payload['campaign_id'] ?? null,
                        ],
                    ],
                ),
            ],
            ($payload['marketing_os_rule'] ?? null) === 'objective_no_activities' => [
                $this->recommendation(
                    'Create actions for this objective',
                    'This Marketing OS objective has no linked actions or campaign activity yet.',
                    'Create a starter action task for this objective.',
                    $signal,
                    [
                        'impact_score' => 76,
                        'confidence_score' => 88,
                        'action_type' => 'create_objective_actions',
                        'action_payload' => [
                            'marketing_objective_id' => $payload['marketing_objective_id'] ?? null,
                            'campaign_id' => $payload['campaign_id'] ?? null,
                        ],
                    ],
                ),
            ],
            $signal->type === 'content_opportunity',
            $signal->type === 'lifecycle_score_degraded' => $this->contentOpportunityRecommendations($signal, $payload, $contentAssetId, $score),
            $signal->category === 'social' && $signal->type === 'publishing_failed' => [
                $this->recommendation(
                    'Reconnect LinkedIn profile',
                    'A social post failed and the connected profile may need attention before retrying.',
                    'Reconnect the LinkedIn profile, confirm permissions and retry the failed social post.',
                    $signal,
                    ['content_asset_id' => $contentAssetId, 'impact_score' => 92, 'confidence_score' => 96],
                ),
            ],
            $signal->category === 'social' && $signal->type === 'publishing_completed' => [
                $this->recommendation(
                    'Monitor social engagement',
                    'Published social content should be monitored for engagement and follow-up opportunities.',
                    'Review the published post performance and schedule a follow-up if it supports the campaign.',
                    $signal,
                    ['content_asset_id' => $contentAssetId, 'impact_score' => 45, 'confidence_score' => 86],
                ),
            ],
            $signal->type === 'content_audit_completed' => $this->contentAuditRecommendations($signal, $payload),
            $signal->type === 'content_event',
            $signal->type === 'publishing_completed',
            $signal->type === 'integration_event' && ($payload['event'] ?? null) === 'publishing_completed' => [
                $this->recommendation(
                    'Generate LinkedIn post',
                    'Newly published content can be repurposed into a social distribution asset.',
                    'Generate a LinkedIn post that summarizes the strongest point and links back to the published asset.',
                    $signal,
                    ['content_asset_id' => $contentAssetId, 'impact_score' => 45, 'confidence_score' => 86],
                ),
            ],
            $signal->category === 'visibility',
            $signal->type === 'visibility_change' => [
                $this->recommendation(
                    'Connect Search Console',
                    'Visibility signals become more actionable when Search Console data is available for the brand.',
                    'Connect Search Console to enrich visibility changes with query, page and indexing data.',
                    $signal,
                    ['impact_score' => 70, 'confidence_score' => 82],
                ),
            ],
            $signal->type === 'generation_completed' => [
                $this->recommendation(
                    'Create Answer Block',
                    'Generated content can be converted into a reusable answer asset for AI and search surfaces.',
                    'Extract the clearest direct answer from the generation and save it as an answer block.',
                    $signal,
                    ['content_asset_id' => $contentAssetId, 'impact_score' => 50, 'confidence_score' => 84],
                ),
            ],
            $signal->type === 'publishing_failed' => [
                $this->recommendation(
                    'Review publishing integration',
                    'Publishing failed and may block distribution until the channel or integration is fixed.',
                    'Check the publishing channel, integration permissions and error payload before retrying.',
                    $signal,
                    ['content_asset_id' => $contentAssetId, 'impact_score' => 90, 'confidence_score' => 96],
                ),
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function contentOpportunityRecommendations(IntelligenceSignal $signal, array $payload, mixed $contentAssetId, int $score): array
    {
        if (($payload['domain_event_type'] ?? null) === 'ContentAssetMissingSocialDistribution') {
            return [
                $this->recommendation(
                    'Create LinkedIn post',
                    'This content asset has no social distribution yet.',
                    'Create a LinkedIn post from this article and schedule it for the active brand profile.',
                    $signal,
                    ['content_asset_id' => $contentAssetId, 'impact_score' => 68, 'confidence_score' => 90],
                ),
            ];
        }

        if (($payload['domain_event_type'] ?? null) === 'CampaignMissingScheduledSocialPosts') {
            return [
                $this->recommendation(
                    'Schedule social distribution',
                    'This campaign has content but no scheduled social posts.',
                    'Schedule social distribution for the campaign so its content has a clear publishing path.',
                    $signal,
                    ['impact_score' => 78, 'confidence_score' => 90],
                ),
            ];
        }

        return [
            $this->recommendation(
                'Refresh article',
                'The source signal indicates this article is stale, degraded or underperforming.',
                'Refresh the article with current evidence, clearer structure and updated answer-focused sections.',
                $signal,
                ['content_asset_id' => $contentAssetId],
            ),
            $this->recommendation(
                'Run content audit',
                'A fresh audit will reveal the highest-impact visibility and structure fixes before editing.',
                'Run a content audit and use the findings to prioritize the refresh plan.',
                $signal,
                ['content_asset_id' => $contentAssetId, 'impact_score' => min(100, max(55, $score + 10))],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function contentAuditRecommendations(IntelligenceSignal $signal, array $payload): array
    {
        $recommendations = [
            $this->recommendation(
                'Create FAQ',
                'The audit found opportunities to answer common questions more directly.',
                'Create an FAQ section that covers the most important customer questions surfaced by the audit.',
                $signal,
                ['content_asset_id' => $payload['content_asset_id'] ?? null, 'impact_score' => 62, 'confidence_score' => 84],
            ),
            $this->recommendation(
                'Create Answer Block',
                'Answer-shaped content can help the brand appear in AI and search answer surfaces.',
                'Create an answer block from the clearest recommendation in the audit.',
                $signal,
                ['content_asset_id' => $payload['content_asset_id'] ?? null, 'impact_score' => 58, 'confidence_score' => 86],
            ),
        ];

        if ((int) ($payload['score'] ?? 100) < 70) {
            $recommendations[] = $this->recommendation(
                'Refresh article',
                'The audit score suggests the article needs editorial and structural improvements.',
                'Refresh the article using the audit issues and recommendations as the editing checklist.',
                $signal,
                ['content_asset_id' => $payload['content_asset_id'] ?? null],
            );
        }

        return $recommendations;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function recommendation(string $title, string $summary, string $action, IntelligenceSignal $signal, array $overrides = []): array
    {
        $payload = $signal->payload ?? [];

        return [
            'title' => $title,
            'summary' => $summary,
            'recommended_action' => $action,
            'action_type' => $overrides['action_type'] ?? $this->inferActionType($title, $payload),
            'action_payload' => $overrides['action_payload'] ?? $this->inferActionPayload($signal, $payload),
            'impact_score' => $overrides['impact_score'] ?? $signal->impact_score,
            'confidence_score' => $overrides['confidence_score'] ?? $signal->confidence_score,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function inferActionType(string $title, array $payload): ?string
    {
        $normalized = str($title)->lower()->toString();

        return match (true) {
            str_contains($normalized, 'audit') => 'run_content_audit',
            str_contains($normalized, 'refresh article'),
            str_contains($normalized, 'refresh this content') => 'refresh_content',
            str_contains($normalized, 'answer block'),
            str_contains($normalized, 'faq') => 'create_answer_block',
            str_contains($normalized, 'translate') => 'translate_content',
            str_contains($normalized, 'linkedin post'),
            str_contains($normalized, 'social distribution'),
            str_contains($normalized, 'social post') => 'create_social_post',
            str_contains($normalized, 'reconnect') => 'reconnect_integration',
            ($payload['provider'] ?? null) || ($payload['query'] ?? null) => 'run_visibility_check',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function inferActionPayload(IntelligenceSignal $signal, array $payload): ?array
    {
        $actionPayload = array_filter([
            'content_asset_id' => $payload['content_asset_id'] ?? null,
            'campaign_id' => $payload['campaign_id'] ?? null,
            'provider' => $payload['provider'] ?? null,
            'query' => $payload['query'] ?? null,
            'integration_connection_id' => $payload['integration_connection_id'] ?? null,
        ], fn (mixed $value) => $value !== null);

        if (isset($payload['missing_languages'])) {
            $actionPayload['target_languages'] = $payload['missing_languages'];
        }

        if (isset($payload['translation_coverage']['missing_languages'])) {
            $actionPayload['target_languages'] = $payload['translation_coverage']['missing_languages'];
        }

        return $actionPayload === [] ? null : $actionPayload;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function store(IntelligenceSignal $signal, array $attributes): Recommendation
    {
        $recommendation = Recommendation::query()->updateOrCreate(
            [
                'account_id' => $signal->account_id,
                'signal_id' => $signal->id,
                'title' => $attributes['title'],
            ],
            [
                ...$attributes,
                'brand_id' => $signal->brand_id,
                'status' => Recommendation::query()
                    ->where('account_id', $signal->account_id)
                    ->where('signal_id', $signal->id)
                    ->where('title', $attributes['title'])
                    ->value('status') ?? 'new',
            ],
        );

        if (! $recommendation->evidenceItems()->exists()) {
            $this->evidence->copyBetweenSubjects($signal, $recommendation);
        }

        if ($recommendation->wasRecentlyCreated) {
            app(DomainEventService::class)->recordForSubject('RecommendationCreated', $recommendation, null, [
                'title' => $recommendation->title,
                'signal_id' => $signal->id,
                'impact_score' => $recommendation->impact_score,
                'confidence_score' => $recommendation->confidence_score,
                'action_type' => $recommendation->action_type,
            ], $recommendation->created_at);
        }

        return $recommendation;
    }

    /**
     * @return Collection<int, IntelligenceSignal>
     */
    private function contentDistributionSignals(Account $account, Brand $brand): Collection
    {
        $assets = ContentAsset::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->with(['sourceTranslations.translatedContentAsset', 'socialPosts'])
            ->get();

        return $assets->flatMap(function (ContentAsset $asset) use ($account, $brand): array {
            $signals = [];

            if ($asset->status === 'approved') {
                $signals[] = $this->distributionSignal($account, $brand, [
                    'rule' => 'approved_not_published',
                    'dedupe' => "distribution:approved-not-published:{$asset->id}",
                    'title' => 'Approved content is not published',
                    'summary' => "{$asset->title} is approved but not published.",
                    'priority' => 'medium',
                    'impact_score' => 74,
                    'payload' => [
                        'content_asset_id' => $asset->id,
                        'content_status' => $asset->status,
                    ],
                ]);
            }

            if ($asset->status === 'published' && ! $this->hasLinkedInPost($asset)) {
                $signals[] = $this->distributionSignal($account, $brand, [
                    'rule' => 'published_missing_linkedin',
                    'dedupe' => "distribution:published-missing-linkedin:{$asset->id}",
                    'title' => 'Published content has no LinkedIn post',
                    'summary' => "{$asset->title} is published but has no LinkedIn post.",
                    'priority' => 'medium',
                    'impact_score' => 68,
                    'category' => 'social',
                    'payload' => [
                        'content_asset_id' => $asset->id,
                        'content_status' => $asset->status,
                    ],
                ]);
            }

            $missingLanguages = $this->missingLanguages($asset, $brand);

            if ($asset->status === 'published' && $missingLanguages !== []) {
                $signals[] = $this->distributionSignal($account, $brand, [
                    'rule' => 'published_missing_languages',
                    'dedupe' => "distribution:published-missing-languages:{$asset->id}",
                    'title' => 'Published content is missing enabled languages',
                    'summary' => "{$asset->title} is missing ".implode(', ', $missingLanguages).'.',
                    'priority' => 'medium',
                    'impact_score' => 72,
                    'payload' => [
                        'content_asset_id' => $asset->id,
                        'content_status' => $asset->status,
                        'source_language' => $asset->language,
                        'missing_languages' => $missingLanguages,
                    ],
                ]);
            }

            return $signals;
        })->values();
    }

    /**
     * @return Collection<int, IntelligenceSignal>
     */
    private function campaignDistributionSignals(Account $account, Brand $brand): Collection
    {
        return Campaign::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where('status', 'active')
            ->with('contentAssets')
            ->get()
            ->filter(fn (Campaign $campaign) => ! SocialPost::query()
                ->where('account_id', $account->id)
                ->where('brand_id', $brand->id)
                ->where('campaign_id', $campaign->id)
                ->whereIn('status', ['scheduled', 'queued', 'publishing', 'published'])
                ->whereNotNull('scheduled_at')
                ->exists())
            ->map(fn (Campaign $campaign) => $this->distributionSignal($account, $brand, [
                'rule' => 'active_campaign_missing_social',
                'dedupe' => "distribution:active-campaign-missing-social:{$campaign->id}",
                'title' => 'Active campaign has no scheduled social posts',
                'summary' => "{$campaign->name} is active but has no scheduled social distribution.",
                'priority' => 'high',
                'impact_score' => 78,
                'category' => 'social',
                'payload' => [
                    'campaign_id' => $campaign->id,
                    'campaign_status' => $campaign->status,
                    'content_asset_ids' => $campaign->contentAssets->pluck('id')->values()->all(),
                ],
            ]))
            ->values();
    }

    /**
     * @return Collection<int, IntelligenceSignal>
     */
    private function connectorDistributionSignals(Account $account, Brand $brand): Collection
    {
        return PublishingChannel::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->with(['connectorInstallation', 'contentAssets'])
            ->get()
            ->filter(function (PublishingChannel $channel): bool {
                $connector = $channel->connectorInstallation;

                return ! $connector
                    || $connector->status !== 'active'
                    || $connector->revoked_at !== null;
            })
            ->flatMap(fn (PublishingChannel $channel) => $channel->contentAssets->map(fn (ContentAsset $asset) => $this->distributionSignal($account, $brand, [
                'rule' => 'connector_disconnected',
                'dedupe' => "distribution:connector-disconnected:{$channel->id}:{$asset->id}",
                'title' => 'Publishing channel connector is disconnected',
                'summary' => "{$channel->name} cannot publish {$asset->title} until the connector is reconnected.",
                'priority' => 'critical',
                'impact_score' => 88,
                'category' => 'integration',
                'type' => 'integration_event',
                'payload' => [
                    'content_asset_id' => $asset->id,
                    'publishing_channel_id' => $channel->id,
                    'connector_installation_id' => $channel->connector_installation_id,
                    'connector_status' => $channel->connectorInstallation?->status ?? 'missing',
                ],
            ])))
            ->values();
    }

    /**
     * @return Collection<int, IntelligenceSignal>
     */
    private function linkedInDistributionSignals(Account $account, Brand $brand): Collection
    {
        return SocialProfile::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where('provider', 'linkedin')
            ->where(function ($query): void {
                $query->where('status', 'expired')
                    ->orWhereHas('integrationConnection', fn ($connection) => $connection
                        ->where('status', 'expired')
                        ->orWhere(fn ($expires) => $expires
                            ->whereNotNull('token_expires_at')
                            ->where('token_expires_at', '<=', now())));
            })
            ->with(['integrationConnection', 'socialPosts.contentAsset'])
            ->get()
            ->flatMap(fn (SocialProfile $profile) => $profile->socialPosts
                ->filter(fn (SocialPost $post) => $post->content_asset_id !== null)
                ->map(fn (SocialPost $post) => $this->distributionSignal($account, $brand, [
                    'rule' => 'linkedin_token_expired',
                    'dedupe' => "distribution:linkedin-token-expired:{$profile->id}:{$post->content_asset_id}",
                    'title' => 'LinkedIn profile token expired',
                    'summary' => "{$profile->display_name} needs to be reconnected before LinkedIn distribution can continue.",
                    'priority' => 'critical',
                    'impact_score' => 92,
                    'category' => 'social',
                    'type' => 'publishing_failed',
                    'payload' => [
                        'content_asset_id' => $post->content_asset_id,
                        'social_profile_id' => $profile->id,
                        'integration_connection_id' => $profile->integration_connection_id,
                        'social_post_id' => $post->id,
                        'provider' => 'linkedin',
                        'connection_status' => $profile->integrationConnection?->status,
                        'profile_status' => $profile->status,
                    ],
                ])))
            ->values();
    }

    /**
     * @return Collection<int, IntelligenceSignal>
     */
    private function marketingOsSignals(Account $account, Brand $brand): Collection
    {
        $activeCampaigns = Campaign::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where('status', 'active')
            ->get();
        $defaultCampaign = $activeCampaigns->first();

        return collect()
            ->merge($activeCampaigns
                ->filter(fn (Campaign $campaign) => ! MarketingTask::query()
                    ->where('account_id', $account->id)
                    ->where('brand_id', $brand->id)
                    ->where('campaign_id', $campaign->id)
                    ->exists())
                ->map(fn (Campaign $campaign) => $this->marketingOsSignal($account, $brand, [
                    'rule' => 'active_campaign_no_tasks',
                    'dedupe' => "marketing-os:campaign-no-tasks:{$campaign->id}",
                    'title' => 'Active campaign has no tasks',
                    'summary' => "{$campaign->name} is active but has no Marketing OS tasks.",
                    'priority' => 'high',
                    'impact_score' => 82,
                    'payload' => ['campaign_id' => $campaign->id],
                ])))
            ->merge($activeCampaigns
                ->filter(fn (Campaign $campaign) => ! Briefing::query()
                    ->where('account_id', $account->id)
                    ->where('brand_id', $brand->id)
                    ->where('campaign_id', $campaign->id)
                    ->exists())
                ->map(fn (Campaign $campaign) => $this->marketingOsSignal($account, $brand, [
                    'rule' => 'active_campaign_no_briefing',
                    'dedupe' => "marketing-os:campaign-no-briefing:{$campaign->id}",
                    'title' => 'Active campaign has no briefing',
                    'summary' => "{$campaign->name} is active but has no campaign briefing.",
                    'priority' => 'high',
                    'impact_score' => 80,
                    'payload' => ['campaign_id' => $campaign->id],
                ])))
            ->merge($activeCampaigns
                ->load('contentAssets')
                ->filter(fn (Campaign $campaign) => $campaign->contentAssets
                    ->where('status', 'published')
                    ->count() >= 2
                    && ! Newsletter::query()
                        ->where('account_id', $account->id)
                        ->where('brand_id', $brand->id)
                        ->where('campaign_id', $campaign->id)
                        ->exists())
                ->map(fn (Campaign $campaign) => $this->marketingOsSignal($account, $brand, [
                    'rule' => 'campaign_assets_no_newsletter',
                    'dedupe' => "marketing-os:campaign-assets-no-newsletter:{$campaign->id}",
                    'title' => 'Campaign has published assets but no newsletter',
                    'summary' => "{$campaign->name} has multiple published assets but no newsletter digest.",
                    'priority' => 'medium',
                    'impact_score' => 74,
                    'payload' => [
                        'campaign_id' => $campaign->id,
                        'content_asset_ids' => $campaign->contentAssets
                            ->where('status', 'published')
                            ->pluck('id')
                            ->values()
                            ->all(),
                    ],
                ])))
            ->merge(Newsletter::query()
                ->where('account_id', $account->id)
                ->where('brand_id', $brand->id)
                ->whereIn('status', ['draft', 'review'])
                ->get()
                ->map(fn (Newsletter $newsletter) => $this->marketingOsSignal($account, $brand, [
                    'rule' => 'newsletter_not_approved',
                    'dedupe' => "marketing-os:newsletter-not-approved:{$newsletter->id}",
                    'title' => 'Newsletter is drafted but not approved',
                    'summary' => "{$newsletter->title} is drafted but not approved.",
                    'priority' => 'medium',
                    'impact_score' => 68,
                    'payload' => [
                        'newsletter_id' => $newsletter->id,
                        'campaign_id' => $newsletter->campaign_id,
                    ],
                ])))
            ->merge(Newsletter::query()
                ->where('account_id', $account->id)
                ->where('brand_id', $brand->id)
                ->where('status', 'approved')
                ->whereNull('scheduled_at')
                ->whereNull('sent_at')
                ->get()
                ->map(fn (Newsletter $newsletter) => $this->marketingOsSignal($account, $brand, [
                    'rule' => 'newsletter_approved_not_scheduled',
                    'dedupe' => "marketing-os:newsletter-approved-not-scheduled:{$newsletter->id}",
                    'title' => 'Newsletter is approved but not scheduled',
                    'summary' => "{$newsletter->title} is approved but has no schedule.",
                    'priority' => 'medium',
                    'impact_score' => 70,
                    'payload' => [
                        'newsletter_id' => $newsletter->id,
                        'campaign_id' => $newsletter->campaign_id,
                    ],
                ])))
            ->merge(Audience::query()
                ->where('account_id', $account->id)
                ->where(fn (Builder $query) => $query
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $brand->id))
                ->where('status', 'active')
                ->get()
                ->filter(fn (Audience $audience) => ! NewsletterSend::query()
                    ->where('account_id', $account->id)
                    ->where('brand_id', $brand->id)
                    ->where('audience_id', $audience->id)
                    ->whereIn('status', ['queued', 'sending', 'sent'])
                    ->where('created_at', '>=', now()->subDays(30))
                    ->exists())
                ->map(fn (Audience $audience) => $this->marketingOsSignal($account, $brand, [
                    'rule' => 'audience_no_recent_newsletter',
                    'dedupe' => "marketing-os:audience-no-recent-newsletter:{$audience->id}",
                    'title' => 'Audience has no recent newsletter',
                    'summary' => "{$audience->name} has no recent newsletter activity.",
                    'priority' => 'medium',
                    'impact_score' => 72,
                    'payload' => ['audience_id' => $audience->id],
                ])))
            ->merge(ContentAsset::query()
                ->where('account_id', $account->id)
                ->where('brand_id', $brand->id)
                ->where('status', 'approved')
                ->whereDoesntHave('campaigns')
                ->get()
                ->when($defaultCampaign !== null, fn (Collection $assets) => $assets)
                ->map(fn (ContentAsset $asset) => $defaultCampaign ? $this->marketingOsSignal($account, $brand, [
                    'rule' => 'approved_content_no_campaign',
                    'dedupe' => "marketing-os:approved-content-no-campaign:{$asset->id}",
                    'title' => 'Approved content is not assigned to a campaign',
                    'summary' => "{$asset->title} is approved but not assigned to a campaign.",
                    'priority' => 'medium',
                    'impact_score' => 66,
                    'payload' => ['content_asset_id' => $asset->id, 'campaign_id' => $defaultCampaign->id],
                ]) : null)
                ->filter())
            ->merge(SocialPost::query()
                ->where('account_id', $account->id)
                ->where('brand_id', $brand->id)
                ->whereNull('campaign_id')
                ->whereNotNull('scheduled_at')
                ->get()
                ->map(fn (SocialPost $post) => $defaultCampaign ? $this->marketingOsSignal($account, $brand, [
                    'rule' => 'scheduled_social_no_campaign',
                    'dedupe' => "marketing-os:scheduled-social-no-campaign:{$post->id}",
                    'title' => 'Scheduled social post is not assigned to a campaign',
                    'summary' => 'A scheduled social post is missing campaign context.',
                    'priority' => 'medium',
                    'impact_score' => 64,
                    'category' => 'social',
                    'payload' => ['social_post_id' => $post->id, 'campaign_id' => $defaultCampaign->id],
                ]) : null)
                ->filter())
            ->merge(MarketingObjective::query()
                ->where('account_id', $account->id)
                ->where('brand_id', $brand->id)
                ->get()
                ->filter(fn (MarketingObjective $objective) => ! MarketingTask::query()
                    ->where('account_id', $account->id)
                    ->where('marketing_objective_id', $objective->id)
                    ->exists()
                    && ($objective->campaign_id === null || ! MarketingTask::query()->where('account_id', $account->id)->where('campaign_id', $objective->campaign_id)->exists()))
                ->map(fn (MarketingObjective $objective) => $this->marketingOsSignal($account, $brand, [
                    'rule' => 'objective_no_activities',
                    'dedupe' => "marketing-os:objective-no-activities:{$objective->id}",
                    'title' => 'Objective has no linked activities',
                    'summary' => "{$objective->name} has no linked Marketing OS activities.",
                    'priority' => 'high',
                    'impact_score' => 76,
                    'payload' => [
                        'marketing_objective_id' => $objective->id,
                        'campaign_id' => $objective->campaign_id,
                    ],
                ])))
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function marketingOsSignal(Account $account, Brand $brand, array $attributes): IntelligenceSignal
    {
        return app(SignalManager::class)->record($account, [
            'source' => 'marketing_os',
            'type' => 'content_opportunity',
            'category' => $attributes['category'] ?? 'content',
            'priority' => $attributes['priority'],
            'title' => $attributes['title'],
            'summary' => $attributes['summary'],
            'impact_score' => $attributes['impact_score'],
            'confidence_score' => $attributes['confidence_score'] ?? 90,
            'dedupe_key' => $attributes['dedupe'],
            'status' => 'new',
            'payload' => [
                ...($attributes['payload'] ?? []),
                'marketing_os_rule' => $attributes['rule'],
            ],
        ], $brand, generateRecommendations: false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function distributionSignal(Account $account, Brand $brand, array $attributes): IntelligenceSignal
    {
        return app(SignalManager::class)->record($account, [
            'source' => 'distribution_hub',
            'type' => $attributes['type'] ?? 'content_opportunity',
            'category' => $attributes['category'] ?? 'content',
            'priority' => $attributes['priority'],
            'title' => $attributes['title'],
            'summary' => $attributes['summary'],
            'impact_score' => $attributes['impact_score'],
            'confidence_score' => $attributes['confidence_score'] ?? 92,
            'recommended_action' => $attributes['recommended_action'] ?? null,
            'dedupe_key' => $attributes['dedupe'],
            'status' => 'new',
            'payload' => [
                ...($attributes['payload'] ?? []),
                'distribution_rule' => $attributes['rule'],
            ],
        ], $brand, generateRecommendations: false);
    }

    private function hasLinkedInPost(ContentAsset $asset): bool
    {
        return $asset->socialPosts->contains(fn (SocialPost $post) => $post->provider === 'linkedin');
    }

    /**
     * @return array<int, string>
     */
    private function missingLanguages(ContentAsset $asset, Brand $brand): array
    {
        $enabled = app(ContentLanguageService::class)->enabledCodesForBrand($brand);
        $existing = $asset->sourceTranslations
            ->pluck('translatedContentAsset.language')
            ->filter()
            ->push($asset->language)
            ->unique()
            ->values()
            ->all();

        return array_values(array_diff($enabled, $existing));
    }
}
