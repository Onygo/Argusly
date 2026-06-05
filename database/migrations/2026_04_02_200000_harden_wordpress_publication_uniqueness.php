<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_publications')) {
            return;
        }

        $this->backfillPublicationLocales();
        $this->deduplicatePublicationMappings();

        Schema::table('content_publications', function (Blueprint $table): void {
            $table->dropUnique('publications_content_destination_unique');
            $table->unique(['content_id', 'destination_id', 'locale'], 'publications_content_destination_locale_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('content_publications')) {
            return;
        }

        Schema::table('content_publications', function (Blueprint $table): void {
            $table->dropUnique('publications_content_destination_locale_unique');
            $table->unique(['content_id', 'destination_id'], 'publications_content_destination_unique');
        });
    }

    private function backfillPublicationLocales(): void
    {
        $contentLocales = DB::table('contents')->pluck('language', 'id');

        DB::table('content_publications')
            ->whereNull('locale')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($contentLocales): void {
                foreach ($rows as $row) {
                    $locale = strtolower(trim((string) ($contentLocales[$row->content_id] ?? '')));
                    if ($locale === '') {
                        continue;
                    }

                    DB::table('content_publications')
                        ->where('id', $row->id)
                        ->update(['locale' => $locale]);
                }
            }, 'id', 'id');
    }

    private function deduplicatePublicationMappings(): void
    {
        $publications = DB::table('content_publications')
            ->orderByDesc(DB::raw('CASE WHEN remote_id IS NULL OR remote_id = "" THEN 0 ELSE 1 END'))
            ->orderByDesc(DB::raw("CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END"))
            ->orderByDesc('last_delivered_at')
            ->orderByDesc('updated_at')
            ->orderBy('created_at')
            ->get();

        $groups = [];

        foreach ($publications as $publication) {
            $locale = strtolower(trim((string) ($publication->locale ?? '')));
            $key = implode('|', [
                (string) $publication->content_id,
                (string) $publication->provider,
                (string) ($publication->destination_id ?? ''),
                (string) ($publication->client_site_id ?? ''),
                $locale,
            ]);

            $groups[$key] ??= [];
            $groups[$key][] = $publication;
        }

        foreach ($groups as $duplicates) {
            if (count($duplicates) <= 1) {
                continue;
            }

            $canonical = array_shift($duplicates);
            $canonicalMeta = $this->decodeMeta($canonical->meta ?? null);
            $previousRemoteIds = is_array($canonicalMeta['previous_remote_ids'] ?? null)
                ? $canonicalMeta['previous_remote_ids']
                : [];

            foreach ($duplicates as $duplicate) {
                if (trim((string) ($duplicate->remote_id ?? '')) !== '') {
                    $previousRemoteIds[] = trim((string) $duplicate->remote_id);
                }

                $duplicateMeta = $this->decodeMeta($duplicate->meta ?? null);
                $previousRemoteIds = array_merge(
                    $previousRemoteIds,
                    is_array($duplicateMeta['previous_remote_ids'] ?? null) ? $duplicateMeta['previous_remote_ids'] : []
                );

                DB::table('content_delivery_events')
                    ->where('content_publication_id', $duplicate->id)
                    ->update(['content_publication_id' => $canonical->id]);

                DB::table('content_publications')
                    ->where('id', $duplicate->id)
                    ->delete();

                if (trim((string) ($canonical->remote_id ?? '')) === '' && trim((string) ($duplicate->remote_id ?? '')) !== '') {
                    $canonical->remote_id = $duplicate->remote_id;
                    $canonical->remote_url = $duplicate->remote_url ?: $canonical->remote_url;
                    $canonical->remote_type = $duplicate->remote_type ?: $canonical->remote_type;
                    $canonical->remote_status = $duplicate->remote_status ?: $canonical->remote_status;
                    $canonical->delivery_status = $duplicate->delivery_status ?: $canonical->delivery_status;
                    $canonical->payload_checksum = $duplicate->payload_checksum ?: $canonical->payload_checksum;
                    $canonical->last_delivered_at = $duplicate->last_delivered_at ?: $canonical->last_delivered_at;
                }
            }

            $canonicalMeta['previous_remote_ids'] = array_values(array_unique(array_filter(array_map(
                static fn ($value) => trim((string) $value),
                $previousRemoteIds
            ))));

            DB::table('content_publications')
                ->where('id', $canonical->id)
                ->update([
                    'meta' => $canonicalMeta !== [] ? json_encode($canonicalMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    'remote_id' => $canonical->remote_id,
                    'remote_url' => $canonical->remote_url,
                    'remote_type' => $canonical->remote_type,
                    'remote_status' => $canonical->remote_status,
                    'delivery_status' => $canonical->delivery_status,
                    'payload_checksum' => $canonical->payload_checksum,
                    'last_delivered_at' => $canonical->last_delivered_at,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }

        $decoded = json_decode((string) $meta, true);

        return is_array($decoded) ? $decoded : [];
    }
};
