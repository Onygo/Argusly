<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\CompetitorSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CompetitorService
{
    /**
     * @param  array{name: string, website: string, industry?: string|null, status?: string|null}  $attributes
     */
    public function add(Account $account, Brand $brand, array $attributes): Competitor
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        $website = $this->normalizedWebsite($attributes['website']);
        $status = $attributes['status'] ?? 'active';

        if (! in_array($status, Competitor::STATUSES, true)) {
            throw new InvalidArgumentException("Invalid competitor status [{$status}].");
        }

        return Competitor::query()->updateOrCreate(
            [
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'website' => $website,
            ],
            [
                'name' => $attributes['name'],
                'industry' => $attributes['industry'] ?? null,
                'status' => $status,
            ],
        );
    }

    /**
     * @return Collection<int, Competitor>
     */
    public function list(Account $account, Brand $brand): Collection
    {
        return $this->tenantQuery($account, $brand)
            ->with(['latestSnapshot' => fn ($query) => $query->limit(1)])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array{competitors: Collection<int, Competitor>, leaders: array<string, Competitor|null>, averages: array<string, float|null>, tracking: array<int, array{key: string, label: string, status: string}>}
     */
    public function compare(Account $account, Brand $brand): array
    {
        $competitors = $this->list($account, $brand);
        $snapshots = $competitors
            ->map(fn (Competitor $competitor) => $competitor->latestSnapshot->first())
            ->filter();

        return [
            'competitors' => $competitors,
            'leaders' => [
                'visibility' => $this->leader($competitors, 'visibility_score'),
                'mentions' => $this->leader($competitors, 'mention_score'),
                'share_of_voice' => $this->leader($competitors, 'share_of_voice'),
            ],
            'averages' => [
                'visibility_score' => $snapshots->avg('visibility_score'),
                'mention_score' => $snapshots->avg('mention_score'),
                'share_of_voice' => $snapshots->avg('share_of_voice'),
            ],
            'tracking' => $this->trackingArchitecture(),
        ];
    }

    /**
     * @param  array{captured_at?: mixed, visibility_score?: int|null, mention_score?: int|null, share_of_voice?: int|null, metadata?: array<string, mixed>|null}  $attributes
     */
    public function captureSnapshot(Competitor $competitor, array $attributes = []): CompetitorSnapshot
    {
        foreach (['visibility_score', 'mention_score', 'share_of_voice'] as $score) {
            if (array_key_exists($score, $attributes) && $attributes[$score] !== null) {
                $attributes[$score] = max(0, min(100, (int) $attributes[$score]));
            }
        }

        return $competitor->snapshots()->create([
            'captured_at' => $attributes['captured_at'] ?? now(),
            'visibility_score' => $attributes['visibility_score'] ?? null,
            'mention_score' => $attributes['mention_score'] ?? null,
            'share_of_voice' => $attributes['share_of_voice'] ?? null,
            'metadata' => [
                'sources' => [
                    'ai_visibility' => 'planned',
                    'serp' => 'planned',
                    'mentions' => 'planned',
                    'brand_tracking' => 'planned',
                ],
                ...($attributes['metadata'] ?? []),
            ],
        ]);
    }

    private function tenantQuery(Account $account, Brand $brand): Builder
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return Competitor::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id);
    }

    private function ensureBrandBelongsToAccount(Account $account, Brand $brand): void
    {
        if ($brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Competitor brand must belong to the competitor account.');
        }
    }

    private function normalizedWebsite(string $website): string
    {
        $website = trim($website);
        $website = Str::startsWith($website, ['http://', 'https://']) ? $website : 'https://'.$website;

        return rtrim(Str::lower($website), '/');
    }

    private function leader(Collection $competitors, string $metric): ?Competitor
    {
        return $competitors
            ->filter(fn (Competitor $competitor) => $competitor->latestSnapshot->first()?->{$metric} !== null)
            ->sortByDesc(fn (Competitor $competitor) => $competitor->latestSnapshot->first()?->{$metric})
            ->first();
    }

    /**
     * @return array<int, array{key: string, label: string, status: string}>
     */
    private function trackingArchitecture(): array
    {
        return [
            ['key' => 'ai_visibility', 'label' => 'AI visibility tracking', 'status' => 'planned'],
            ['key' => 'serp', 'label' => 'SERP tracking', 'status' => 'planned'],
            ['key' => 'mentions', 'label' => 'Mention tracking', 'status' => 'planned'],
            ['key' => 'brand', 'label' => 'Brand tracking', 'status' => 'planned'],
        ];
    }
}
