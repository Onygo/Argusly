<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Migrates legacy WordPress publication data into content_publications.
 *
 * Sources:
 * - contents.wp_post_id (primary legacy storage)
 * - content_publish_targets.wp_post_id (per-target tracking)
 *
 * This migration preserves backwards compatibility by keeping the original
 * columns intact. The delivery service will be refactored to write to both
 * locations during the transition period.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Migrate from content_publish_targets first (more detailed records)
        $this->migrateFromPublishTargets();

        // Then fill gaps from contents table for any content without a publication record
        $this->migrateFromContents();
    }

    /**
     * Migrate publications from content_publish_targets.
     * These have more metadata including client_site_id and sync status.
     */
    private function migrateFromPublishTargets(): void
    {
        $targets = DB::table('content_publish_targets')
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '!=', '')
            ->where('target_type', 'wp')
            ->orderBy('updated_at', 'desc')
            ->get();

        foreach ($targets as $target) {
            // Skip if publication already exists for this content + destination
            $existingByDestination = DB::table('content_publications')
                ->where('content_id', $target->content_id)
                ->where('destination_id', $target->content_destination_id)
                ->exists();

            $existingBySite = DB::table('content_publications')
                ->where('content_id', $target->content_id)
                ->where('client_site_id', $target->client_site_id)
                ->whereNull('destination_id')
                ->exists();

            if ($existingByDestination || $existingBySite) {
                continue;
            }

            $meta = is_string($target->meta) ? json_decode($target->meta, true) : [];
            $publishedUrl = $meta['published_url'] ?? null;
            $syncStatus = $meta['remote_sync_status'] ?? $target->sync_status;
            $lastSyncedAt = $target->last_synced_at;

            DB::table('content_publications')->insert([
                'id' => (string) Str::uuid(),
                'content_id' => $target->content_id,
                'destination_id' => $target->content_destination_id,
                'client_site_id' => $target->client_site_id,
                'provider' => 'wordpress',
                'remote_id' => $target->wp_post_id,
                'remote_type' => 'post',
                'remote_url' => $publishedUrl,
                'remote_status' => $this->mapSyncStatusToRemoteStatus($syncStatus),
                'delivery_status' => $this->mapSyncStatusToDeliveryStatus($syncStatus),
                'last_delivered_at' => $lastSyncedAt,
                'meta' => json_encode([
                    'migrated_from' => 'content_publish_targets',
                    'original_target_id' => $target->id,
                    'language' => $target->language,
                    'seo_sync_status' => $target->seo_sync_status,
                    'seo_sync_mode' => $target->seo_sync_mode,
                    'wp_featured_media_id' => $target->wp_featured_media_id,
                ]),
                'created_at' => $target->created_at ?? now(),
                'updated_at' => $target->updated_at ?? now(),
            ]);
        }
    }

    /**
     * Migrate publications from contents.wp_post_id for content not yet migrated.
     */
    private function migrateFromContents(): void
    {
        $contents = DB::table('contents')
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '!=', '')
            ->orderBy('updated_at', 'desc')
            ->get();

        foreach ($contents as $content) {
            // Skip if any publication already exists for this content
            $exists = DB::table('content_publications')
                ->where('content_id', $content->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $deliveryStatus = $this->mapContentStatusToDeliveryStatus($content->delivery_status);
            $remoteStatus = $this->mapPublishStatusToRemoteStatus($content->publish_status);

            DB::table('content_publications')->insert([
                'id' => (string) Str::uuid(),
                'content_id' => $content->id,
                'destination_id' => $content->content_destination_id,
                'client_site_id' => $content->client_site_id,
                'provider' => 'wordpress',
                'remote_id' => $content->wp_post_id,
                'remote_type' => 'post',
                'remote_url' => $content->published_url,
                'remote_status' => $remoteStatus,
                'delivery_status' => $deliveryStatus,
                'last_delivered_at' => $deliveryStatus === 'delivered' ? $content->updated_at : null,
                'meta' => json_encode([
                    'migrated_from' => 'contents',
                    'original_content_id' => $content->id,
                ]),
                'created_at' => $content->created_at ?? now(),
                'updated_at' => $content->updated_at ?? now(),
            ]);
        }
    }

    private function mapSyncStatusToDeliveryStatus(?string $syncStatus): string
    {
        return match ($syncStatus) {
            'synced' => 'delivered',
            'failed' => 'failed',
            'missing_remote' => 'missing_remote',
            default => 'pending',
        };
    }

    private function mapSyncStatusToRemoteStatus(?string $syncStatus): ?string
    {
        return match ($syncStatus) {
            'synced' => 'published',
            'missing_remote' => null,
            default => 'draft',
        };
    }

    private function mapContentStatusToDeliveryStatus(?string $deliveryStatus): string
    {
        return match ($deliveryStatus) {
            'delivered' => 'delivered',
            'failed' => 'failed',
            'missing_remote' => 'missing_remote',
            default => 'pending',
        };
    }

    private function mapPublishStatusToRemoteStatus(?string $publishStatus): ?string
    {
        return match ($publishStatus) {
            'published' => 'published',
            'scheduled' => 'scheduled',
            default => 'draft',
        };
    }

    public function down(): void
    {
        // Remove only migrated records (identified by meta.migrated_from)
        DB::table('content_publications')
            ->whereRaw("JSON_EXTRACT(meta, '$.migrated_from') IS NOT NULL")
            ->delete();
    }
};
