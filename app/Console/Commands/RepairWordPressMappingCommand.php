<?php

namespace App\Console\Commands;

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublishTarget;
use App\Models\Draft;
use App\Models\SiteToken;
use App\Services\WordPress\Exceptions\WordPressConnectorException;
use App\Services\WordPress\WordPressConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RepairWordPressMappingCommand extends Command
{
    protected $signature = 'pl:wp:repair-mapping
        {--site= : Filter by client_site_id}
        {--limit=250 : Max drafts to process}
        {--dry-run : Show repair candidates only}';

    protected $description = 'Repairs WordPress wp_post_id mappings and published_url for drafts/contents with ambiguous IDs.';

    public function handle(): int
    {
        $siteId = trim((string) $this->option('site'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $query = Draft::query()
            ->with(['content.clientSite', 'content.publishTargets'])
            ->whereNotNull('content_id')
            ->whereHas('content.clientSite', fn ($builder) => $builder->where('type', ClientSite::TYPE_WORDPRESS))
            ->orderBy('updated_at');

        if ($siteId !== '') {
            $query->where('client_site_id', $siteId);
        }

        $drafts = $query->limit($limit)->get();

        $processed = 0;
        $repaired = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($drafts as $draft) {
            $content = $draft->content;
            if (! $content) {
                $skipped++;
                continue;
            }

            $candidateIds = $this->collectCandidatePostIds($content, $draft);
            if ($candidateIds === []) {
                $skipped++;
                continue;
            }

            $processed++;
            $resolution = $this->resolveBestMapping($content, $draft, $candidateIds);
            $resolvedWpPostId = trim((string) ($resolution['wp_post_id'] ?? ''));
            if ($resolvedWpPostId === '') {
                $failed++;
                continue;
            }

            $currentWpPostId = trim((string) ($content->wp_post_id ?? ''));
            $publishedUrl = trim((string) ($resolution['published_url'] ?? ''));
            $changed = $currentWpPostId === '' || $currentWpPostId !== $resolvedWpPostId;

            if ($dryRun) {
                $this->line(sprintf(
                    '- draft=%s content=%s site=%s current=%s resolved=%s source=%s candidates=%s',
                    (string) $draft->id,
                    (string) $content->id,
                    (string) ($content->client_site_id ?? ''),
                    $currentWpPostId !== '' ? $currentWpPostId : '(none)',
                    $resolvedWpPostId,
                    (string) ($resolution['source'] ?? 'unknown'),
                    implode(',', $candidateIds)
                ));
                continue;
            }

            $this->applyMappingRepair($content, $draft, $resolvedWpPostId, $publishedUrl, $candidateIds, (string) ($resolution['source'] ?? 'unknown'));

            if ($changed) {
                Log::warning('wp_mapping_repaired', [
                    'draft_id' => (string) $draft->id,
                    'content_id' => (string) $content->id,
                    'previous_wp_post_id' => $currentWpPostId,
                    'resolved_wp_post_id' => $resolvedWpPostId,
                    'source' => (string) ($resolution['source'] ?? 'unknown'),
                ]);
            }

            $repaired++;
        }

        $this->table(['metric', 'count'], [
            ['drafts_considered', $drafts->count()],
            ['processed', $processed],
            ['repaired', $repaired],
            ['skipped', $skipped],
            ['failed', $failed],
        ]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int,string>
     */
    private function collectCandidatePostIds(Content $content, Draft $draft): array
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $refs = is_array($meta['client_refs'] ?? null) ? $meta['client_refs'] : [];

        $target = ContentPublishTarget::query()
            ->where('content_id', $content->id)
            ->where('client_site_id', $content->client_site_id)
            ->where('target_type', 'wp')
            ->first();

        $metaCandidateIds = [];
        $previous = data_get($target?->meta, 'previous_wp_post_ids');
        if (is_array($previous)) {
            $metaCandidateIds = array_map(static fn ($value) => trim((string) $value), $previous);
        }

        $candidates = array_values(array_unique(array_filter([
            trim((string) ($content->wp_post_id ?? '')),
            trim((string) ($refs['wp_post_id'] ?? '')),
            trim((string) ($target?->wp_post_id ?? '')),
            trim((string) ($target?->target_identifier ?? '')),
            ...$metaCandidateIds,
        ], static fn ($value) => $value !== '')));

        return $candidates;
    }

    /**
     * @param array<int,string> $candidateIds
     * @return array{wp_post_id:?string,published_url:?string,source:string}
     */
    private function resolveBestMapping(Content $content, Draft $draft, array $candidateIds): array
    {
        $base = rtrim((string) ($content->clientSite?->base_url ?: $content->clientSite?->site_url), '/');
        $token = $this->resolveOutboundSiteToken((string) ($content->client_site_id ?? ''));

        $remoteSnapshots = [];
        if ($base !== '' && $token !== '') {
            foreach ($candidateIds as $candidateId) {
                $snapshot = $this->fetchRemotePostSnapshot($base, $token, $candidateId);
                if ($snapshot !== null) {
                    $remoteSnapshots[] = $snapshot;
                }
            }
        }

        if ($remoteSnapshots !== []) {
            $published = array_values(array_filter($remoteSnapshots, function (array $row): bool {
                $status = strtolower(trim((string) ($row['status'] ?? '')));
                return in_array($status, ['publish', 'published'], true);
            }));

            $pool = $published !== [] ? $published : $remoteSnapshots;
            usort($pool, function (array $a, array $b): int {
                return ((int) ($b['modified_ts'] ?? 0)) <=> ((int) ($a['modified_ts'] ?? 0));
            });

            $winner = $pool[0];

            return [
                'wp_post_id' => trim((string) ($winner['wp_post_id'] ?? '')),
                'published_url' => trim((string) ($winner['published_url'] ?? '')) ?: null,
                'source' => $published !== [] ? 'published_post' : 'latest_modified',
            ];
        }

        $fallback = trim((string) ($content->wp_post_id ?? ''));
        if ($fallback === '') {
            $fallback = $candidateIds[0] ?? '';
        }

        return [
            'wp_post_id' => $fallback !== '' ? $fallback : null,
            'published_url' => trim((string) ($content->published_url ?? '')) ?: null,
            'source' => 'local_fallback',
        ];
    }

    /**
     * @return array{wp_post_id:string,status:string,modified_ts:int,published_url:?string}|null
     */
    private function fetchRemotePostSnapshot(string $base, string $token, string $wpPostId): ?array
    {
        try {
            $post = app(WordPressConnector::class)
                ->forSite($base, $token)
                ->getPost($wpPostId);
        } catch (WordPressConnectorException) {
            return null;
        }

        return [
            'wp_post_id' => $post->id,
            'status' => (string) ($post->status ?? ''),
            'modified_ts' => $post->modifiedTs,
            'published_url' => $post->publishedUrl,
        ];
    }

    /**
     * @param array<int,string> $candidateIds
     */
    private function applyMappingRepair(
        Content $content,
        Draft $draft,
        string $wpPostId,
        string $publishedUrl,
        array $candidateIds,
        string $source
    ): void {
        DB::transaction(function () use ($content, $draft, $wpPostId, $publishedUrl, $candidateIds, $source): void {
            $contentUpdates = ['wp_post_id' => $wpPostId];
            if ($publishedUrl !== '') {
                $contentUpdates['published_url'] = $publishedUrl;
            }

            Content::query()->whereKey($content->id)->update($contentUpdates);

            $meta = is_array($draft->meta) ? $draft->meta : [];
            $refs = is_array($meta['client_refs'] ?? null) ? $meta['client_refs'] : [];
            $refs['wp_post_id'] = $wpPostId;
            $meta['client_refs'] = $refs;
            Draft::query()->whereKey($draft->id)->update(['meta' => $meta]);

            ContentPublishTarget::query()->updateOrCreate(
                [
                    'content_id' => $content->id,
                    'client_site_id' => $content->client_site_id,
                    'target_type' => 'wp',
                ],
                [
                    'target_identifier' => $wpPostId,
                    'wp_post_id' => $wpPostId,
                    'sync_status' => 'synced',
                    'last_synced_at' => now(),
                    'meta' => array_filter([
                        'wp_post_id' => $wpPostId,
                        'published_url' => $publishedUrl !== '' ? $publishedUrl : null,
                        'repair_source' => $source,
                        'previous_wp_post_ids' => $candidateIds,
                    ], static fn ($value) => $value !== null && $value !== ''),
                ]
            );
        });
    }

    private function resolveOutboundSiteToken(string $clientSiteId): string
    {
        if ($clientSiteId === '') {
            return '';
        }

        $tokens = SiteToken::query()
            ->where('client_site_id', $clientSiteId)
            ->where('revoked', false)
            ->whereNull('revoked_at')
            ->whereNotNull('token_encrypted')
            ->latest('created_at')
            ->get(['token_encrypted']);

        foreach ($tokens as $token) {
            try {
                $plain = trim((string) Crypt::decryptString((string) $token->token_encrypted));
                if ($plain !== '') {
                    return $plain;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return '';
    }
}
