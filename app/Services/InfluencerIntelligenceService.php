<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Mention;
use App\Models\Relationship;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class InfluencerIntelligenceService
{
    public function __construct(
        private readonly RelationshipIntelligenceService $relationships,
        private readonly DomainEventService $events,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(Account $account, Brand $brand): array
    {
        $creators = $this->creators($account, $brand);
        $campaigns = $this->campaigns($account, $brand);
        $monitored = $creators->filter(fn (Contact $creator) => (bool) data_get($creator->metadata, 'monitoring.enabled'));

        return [
            'stats' => [
                'creators' => $creators->count(),
                'monitored' => $monitored->count(),
                'campaigns' => $campaigns->count(),
                'avg_performance_score' => (int) round($creators->avg(fn (Contact $creator) => (int) data_get($creator->metadata, 'performance.score', 0))),
                'media_value' => (int) round($creators->sum(fn (Contact $creator) => (float) data_get($creator->metadata, 'performance.media_value', 0))),
                'relationships' => Relationship::query()->where('account_id', $account->id)->where('relationship_type', 'influencer')->count(),
            ],
            'creators' => $creators,
            'discovery' => $this->discovery($account, $brand),
            'campaigns' => $campaigns,
            'performanceLeaders' => $creators
                ->sortByDesc(fn (Contact $creator) => (int) data_get($creator->metadata, 'performance.score', 0))
                ->take(8)
                ->values(),
            'crmPipeline' => $creators
                ->groupBy(fn (Contact $creator) => data_get($creator->metadata, 'crm.stage', 'discovered'))
                ->map->count(),
        ];
    }

    /**
     * @return EloquentCollection<int, Contact>
     */
    public function creators(Account $account, Brand $brand): EloquentCollection
    {
        return Contact::query()
            ->where('account_id', $account->id)
            ->where('metadata->creator_database', true)
            ->where('metadata->brand_id', $brand->id)
            ->orderBy('display_name')
            ->get();
    }

    /**
     * @return Collection<int, array{creator: Contact, score: int, reasons: array<int, string>}>
     */
    public function discovery(Account $account, Brand $brand): Collection
    {
        return Contact::query()
            ->where('account_id', $account->id)
            ->get()
            ->map(fn (Contact $contact) => [
                'creator' => $contact,
                'score' => $this->discoveryScore($contact, $brand),
                'reasons' => $this->discoveryReasons($contact, $brand),
            ])
            ->filter(fn (array $candidate) => $candidate['score'] >= 35)
            ->sortByDesc('score')
            ->take(10)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createCreator(Account $account, Brand $brand, User $user, array $attributes): Contact
    {
        $contact = $this->relationships->createContact($account, [
            'first_name' => $attributes['first_name'],
            'last_name' => $attributes['last_name'],
            'display_name' => $attributes['display_name'] ?? null,
            'email' => $attributes['email'] ?? null,
            'website' => $attributes['website'] ?? null,
            'linkedin_url' => $attributes['linkedin_url'] ?? null,
            'notes' => $attributes['notes'] ?? null,
            'metadata' => $this->creatorMetadata($brand, $attributes),
        ]);

        $this->events->recordForSubject('InfluencerCreatorCreated', $contact, $user, [
            'brand_id' => $brand->id,
            'stage' => data_get($contact->metadata, 'crm.stage'),
        ]);

        return $contact->refresh();
    }

    public function monitorCreator(Account $account, Brand $brand, Contact $creator, ?User $user = null): Contact
    {
        $this->assertCreator($account, $brand, $creator);

        $metadata = $creator->metadata ?? [];
        $mentions = $this->mentionsForCreator($account, $brand, $creator);
        $score = $this->performanceScore($creator, $mentions);
        $mediaValue = $this->mediaValue($creator, $mentions);

        $metadata['monitoring'] = [
            ...((array) ($metadata['monitoring'] ?? [])),
            'enabled' => true,
            'last_monitored_at' => now()->toDateTimeString(),
            'mentions' => $mentions->count(),
        ];
        $metadata['performance'] = [
            ...((array) ($metadata['performance'] ?? [])),
            'score' => $score,
            'media_value' => $mediaValue,
            'mention_impact' => (int) $mentions->sum('impact_score'),
            'updated_at' => now()->toDateTimeString(),
        ];

        $creator->forceFill(['metadata' => $metadata])->save();

        $this->events->recordForSubject('InfluencerCreatorMonitored', $creator->refresh(), $user, [
            'brand_id' => $brand->id,
            'score' => $score,
            'media_value' => $mediaValue,
            'mentions' => $mentions->count(),
        ]);

        return $creator->refresh();
    }

    public function attachCampaign(Account $account, Brand $brand, Contact $creator, Campaign $campaign, ?User $user = null): Contact
    {
        $this->assertCreator($account, $brand, $creator);

        if ($campaign->account_id !== $account->id || $campaign->brand_id !== $brand->id) {
            throw new InvalidArgumentException('Influencer campaign must belong to the current workspace and brand.');
        }

        $metadata = $creator->metadata ?? [];
        $campaigns = collect($metadata['campaigns'] ?? []);
        $campaigns->put((string) $campaign->id, [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status,
            'tracked_at' => now()->toDateTimeString(),
        ]);
        $metadata['campaigns'] = $campaigns->values()->all();
        $metadata['crm'] = [
            ...((array) ($metadata['crm'] ?? [])),
            'stage' => 'active_campaign',
            'next_action' => 'Review campaign performance',
            'updated_at' => now()->toDateTimeString(),
        ];

        $creator->forceFill(['metadata' => $metadata])->save();

        $this->events->recordForSubject('InfluencerCampaignTracked', $creator->refresh(), $user, [
            'brand_id' => $brand->id,
            'campaign_id' => $campaign->id,
        ]);

        return $this->monitorCreator($account, $brand, $creator->refresh(), $user);
    }

    public function updateCrm(Account $account, Brand $brand, Contact $creator, array $attributes, ?User $user = null): Contact
    {
        $this->assertCreator($account, $brand, $creator);

        $metadata = $creator->metadata ?? [];
        $metadata['crm'] = [
            ...((array) ($metadata['crm'] ?? [])),
            'stage' => $attributes['stage'],
            'next_action' => $attributes['next_action'] ?? null,
            'owner_notes' => $attributes['owner_notes'] ?? null,
            'updated_at' => now()->toDateTimeString(),
        ];

        $creator->forceFill(['metadata' => $metadata])->save();

        $this->events->recordForSubject('InfluencerCrmUpdated', $creator->refresh(), $user, [
            'brand_id' => $brand->id,
            'stage' => $attributes['stage'],
        ]);

        return $creator->refresh();
    }

    /**
     * @return EloquentCollection<int, Campaign>
     */
    private function campaigns(Account $account, Brand $brand): EloquentCollection
    {
        return Campaign::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where(function ($query): void {
                $query->where('metadata->type', 'influencer')
                    ->orWhere('name', 'like', '%influencer%')
                    ->orWhere('objective', 'like', '%creator%');
            })
            ->latest()
            ->limit(10)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function creatorMetadata(Brand $brand, array $attributes): array
    {
        $channels = collect(explode(',', (string) ($attributes['channels'] ?? '')))
            ->map(fn (string $channel) => trim(Str::lower($channel)))
            ->filter()
            ->values()
            ->all();

        return [
            'creator_database' => true,
            'relationship_lane' => 'influencer',
            'brand_id' => $brand->id,
            'category' => $attributes['category'] ?? null,
            'audience' => $attributes['audience'] ?? null,
            'channels' => $channels,
            'metrics' => [
                'followers' => (int) ($attributes['followers'] ?? 0),
                'engagement_rate' => (float) ($attributes['engagement_rate'] ?? 0),
                'avg_views' => (int) ($attributes['avg_views'] ?? 0),
            ],
            'monitoring' => [
                'enabled' => (bool) ($attributes['monitoring_enabled'] ?? false),
            ],
            'performance' => [
                'score' => 0,
                'media_value' => 0,
            ],
            'crm' => [
                'stage' => $attributes['stage'] ?? 'discovered',
                'next_action' => $attributes['next_action'] ?? null,
                'owner_notes' => $attributes['owner_notes'] ?? null,
            ],
        ];
    }

    /**
     * @return EloquentCollection<int, Mention>
     */
    private function mentionsForCreator(Account $account, Brand $brand, Contact $creator): EloquentCollection
    {
        $name = $creator->display_name;

        return Mention::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where(function ($query) use ($name): void {
                $query->where('author', $name)
                    ->orWhere('content', 'like', '%'.$name.'%')
                    ->orWhere('title', 'like', '%'.$name.'%');
            })
            ->recent()
            ->limit(50)
            ->get();
    }

    /**
     * @param  EloquentCollection<int, Mention>  $mentions
     */
    private function performanceScore(Contact $creator, EloquentCollection $mentions): int
    {
        $followers = (int) data_get($creator->metadata, 'metrics.followers', 0);
        $engagement = (float) data_get($creator->metadata, 'metrics.engagement_rate', 0);
        $views = (int) data_get($creator->metadata, 'metrics.avg_views', 0);

        $audienceScore = min(35, (int) floor(log(max(1, $followers), 10) * 8));
        $engagementScore = min(30, (int) round($engagement * 6));
        $viewScore = min(15, (int) floor(log(max(1, $views), 10) * 4));
        $mentionScore = min(20, (int) round($mentions->sum('impact_score') / 10));

        return min(100, $audienceScore + $engagementScore + $viewScore + $mentionScore);
    }

    /**
     * @param  EloquentCollection<int, Mention>  $mentions
     */
    private function mediaValue(Contact $creator, EloquentCollection $mentions): int
    {
        $followers = (int) data_get($creator->metadata, 'metrics.followers', 0);
        $engagement = (float) data_get($creator->metadata, 'metrics.engagement_rate', 0);
        $views = (int) data_get($creator->metadata, 'metrics.avg_views', 0);
        $mentionImpact = (int) $mentions->sum('impact_score');

        return (int) round(($followers * max(0.01, $engagement / 100) * 0.04) + ($views * 0.02) + ($mentionImpact * 18));
    }

    private function discoveryScore(Contact $contact, Brand $brand): int
    {
        $metadata = $contact->metadata ?? [];
        $score = 0;

        if ((bool) data_get($metadata, 'creator_database')) {
            $score += 30;
        }

        if ((int) data_get($metadata, 'brand_id') === $brand->id) {
            $score += 20;
        }

        $followers = (int) data_get($metadata, 'metrics.followers', 0);
        $engagement = (float) data_get($metadata, 'metrics.engagement_rate', 0);

        $score += min(25, (int) floor(log(max(1, $followers), 10) * 6));
        $score += min(25, (int) round($engagement * 5));

        return min(100, $score);
    }

    /**
     * @return array<int, string>
     */
    private function discoveryReasons(Contact $contact, Brand $brand): array
    {
        return array_values(array_filter([
            (bool) data_get($contact->metadata, 'creator_database') ? 'Creator profile' : null,
            (int) data_get($contact->metadata, 'brand_id') === $brand->id ? 'Brand fit' : null,
            (int) data_get($contact->metadata, 'metrics.followers', 0) > 0 ? 'Audience data' : null,
            (float) data_get($contact->metadata, 'metrics.engagement_rate', 0) > 0 ? 'Engagement data' : null,
        ]));
    }

    private function assertCreator(Account $account, Brand $brand, Contact $creator): void
    {
        if ($creator->account_id !== $account->id || ! (bool) data_get($creator->metadata, 'creator_database') || (int) data_get($creator->metadata, 'brand_id') !== $brand->id) {
            throw new InvalidArgumentException('Creator must belong to the current workspace and brand.');
        }
    }
}
