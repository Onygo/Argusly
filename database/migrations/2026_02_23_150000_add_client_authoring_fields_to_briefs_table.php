<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('briefs', function (Blueprint $table): void {
            if (! Schema::hasColumn('briefs', 'source')) {
                $table->string('source', 32)->nullable()->after('status');
            }
            if (! Schema::hasColumn('briefs', 'created_by_user_id')) {
                $table->unsignedBigInteger('created_by_user_id')->nullable()->after('client_site_id')->index();
            }
            if (! Schema::hasColumn('briefs', 'wp_site_id')) {
                $table->string('wp_site_id')->nullable()->after('wp_post_id');
            }
            if (! Schema::hasColumn('briefs', 'wp_remote_ref')) {
                $table->string('wp_remote_ref')->nullable()->after('wp_site_id');
            }
            if (! Schema::hasColumn('briefs', 'content_type')) {
                $table->string('content_type', 32)->nullable()->after('output_type');
            }
            if (! Schema::hasColumn('briefs', 'secondary_keywords')) {
                $table->json('secondary_keywords')->nullable()->after('primary_keyword');
            }
            if (! Schema::hasColumn('briefs', 'target_audience')) {
                $table->text('target_audience')->nullable()->after('audience');
            }
            if (! Schema::hasColumn('briefs', 'funnel_stage')) {
                $table->string('funnel_stage', 32)->nullable()->after('target_audience');
            }
            if (! Schema::hasColumn('briefs', 'search_intent')) {
                $table->string('search_intent', 32)->nullable()->after('funnel_stage');
            }
            if (! Schema::hasColumn('briefs', 'tone_of_voice')) {
                $table->text('tone_of_voice')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('briefs', 'unique_angle')) {
                $table->text('unique_angle')->nullable()->after('tone_of_voice');
            }
            if (! Schema::hasColumn('briefs', 'key_points')) {
                $table->json('key_points')->nullable()->after('unique_angle');
            }
            if (! Schema::hasColumn('briefs', 'call_to_action')) {
                $table->text('call_to_action')->nullable()->after('key_points');
            }
            if (! Schema::hasColumn('briefs', 'desired_length_min')) {
                $table->unsignedSmallInteger('desired_length_min')->nullable()->after('call_to_action');
            }
            if (! Schema::hasColumn('briefs', 'desired_length_max')) {
                $table->unsignedSmallInteger('desired_length_max')->nullable()->after('desired_length_min');
            }
        });

        DB::table('briefs')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $refs = json_decode((string) ($row->client_refs ?? ''), true);
                $refs = is_array($refs) ? $refs : [];

                $status = trim((string) ($row->status ?? ''));
                if ($status === '') {
                    $status = 'queued';
                }

                $source = trim((string) ($row->source ?? ''));
                if ($source === '') {
                    $clientType = strtolower(trim((string) ($refs['client_type'] ?? '')));
                    $source = ($clientType === 'wordpress' || ! empty($row->wp_brief_id)) ? 'wp_plugin' : 'api';
                }

                $contentType = trim((string) ($row->content_type ?? ''));
                if ($contentType === '') {
                    $outputType = strtolower(trim((string) ($row->output_type ?? 'blog')));
                    $contentType = match ($outputType) {
                        'seo_page', 'landing', 'landing_page' => 'landing',
                        'linkedin_post', 'linkedin' => 'linkedin',
                        'email', 'email_sequence' => 'email',
                        default => 'blog',
                    };
                }

                $targetAudience = trim((string) ($row->target_audience ?? ''));
                if ($targetAudience === '') {
                    $targetAudience = trim((string) ($row->audience ?? ''));
                }

                $wpSiteId = trim((string) ($row->wp_site_id ?? ''));
                if ($wpSiteId === '') {
                    $wpSiteId = trim((string) ($row->client_site_id ?? ''));
                }

                $wpRemoteRef = trim((string) ($row->wp_remote_ref ?? ''));
                if ($wpRemoteRef === '') {
                    $wpRemoteRef = trim((string) ($row->wp_brief_id ?? ''));
                }
                if ($wpRemoteRef === '') {
                    $wpRemoteRef = trim((string) ($row->wp_post_id ?? ''));
                }

                $secondaryKeywords = $row->secondary_keywords ?? null;
                if ($secondaryKeywords === null && ! empty($refs['secondary_keywords'])) {
                    $secondaryKeywords = is_array($refs['secondary_keywords'])
                        ? json_encode(array_values($refs['secondary_keywords']))
                        : json_encode(array_values(array_filter(array_map('trim', explode(',', (string) $refs['secondary_keywords'])))));
                }

                $keyPoints = $row->key_points ?? null;
                if ($keyPoints === null && ! empty($refs['key_points'])) {
                    $keyPoints = is_array($refs['key_points'])
                        ? json_encode(array_values($refs['key_points']))
                        : json_encode(array_values(array_filter(array_map('trim', explode("\n", (string) $refs['key_points'])))));
                }

                $updates = [
                    'status' => $status,
                    'source' => $source,
                    'content_type' => $contentType,
                    'target_audience' => $targetAudience !== '' ? $targetAudience : null,
                    'wp_site_id' => $wpSiteId !== '' ? $wpSiteId : null,
                    'wp_remote_ref' => $wpRemoteRef !== '' ? $wpRemoteRef : null,
                    'tone_of_voice' => trim((string) ($row->tone_of_voice ?? $refs['tone_of_voice'] ?? '')) ?: null,
                    'unique_angle' => trim((string) ($row->unique_angle ?? $refs['unique_angle'] ?? '')) ?: null,
                    'call_to_action' => trim((string) ($row->call_to_action ?? $refs['call_to_action'] ?? '')) ?: null,
                    'secondary_keywords' => $secondaryKeywords,
                    'key_points' => $keyPoints,
                ];

                if (! $row->desired_length_min || ! $row->desired_length_max) {
                    $preferred = strtolower(trim((string) ($refs['preferred_length'] ?? '')));
                    [$minWords, $maxWords] = match ($preferred) {
                        'short' => [600, 800],
                        'long' => [1400, 1800],
                        'pillar' => [2200, 3000],
                        default => [900, 1200],
                    };

                    if (! $row->desired_length_min) {
                        $updates['desired_length_min'] = $minWords;
                    }
                    if (! $row->desired_length_max) {
                        $updates['desired_length_max'] = $maxWords;
                    }
                }

                DB::table('briefs')->where('id', $row->id)->update($updates);
            }
        });

        Schema::table('briefs', function (Blueprint $table): void {
            $table->index(['client_site_id', 'source'], 'briefs_site_source_idx');
            $table->index(['status', 'language', 'content_type'], 'briefs_status_lang_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('briefs', function (Blueprint $table): void {
            if (Schema::hasColumn('briefs', 'source')) {
                $table->dropIndex('briefs_site_source_idx');
                $table->dropIndex('briefs_status_lang_type_idx');
            }

            $drop = [];
            foreach ([
                'source',
                'created_by_user_id',
                'wp_site_id',
                'wp_remote_ref',
                'content_type',
                'secondary_keywords',
                'target_audience',
                'funnel_stage',
                'search_intent',
                'tone_of_voice',
                'unique_angle',
                'key_points',
                'call_to_action',
                'desired_length_min',
                'desired_length_max',
            ] as $column) {
                if (Schema::hasColumn('briefs', $column)) {
                    $drop[] = $column;
                }
            }

            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
