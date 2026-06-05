<?php

namespace App\Services\WordPress;

use App\Enums\SupportedLanguage;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublishTarget;
use App\Models\Draft;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WordPressLanguageSyncService
{
    public function getOrCreatePublishTarget(
        Content $content,
        ClientSite $clientSite,
        SupportedLanguage $language
    ): ContentPublishTarget {
        $existing = ContentPublishTarget::query()
            ->where('content_id', $content->id)
            ->where('client_site_id', $clientSite->id)
            ->where('target_type', 'wp')
            ->where('language', $language->value)
            ->first();

        if ($existing) {
            return $existing;
        }

        return ContentPublishTarget::create([
            'id' => (string) Str::uuid(),
            'content_id' => $content->id,
            'client_site_id' => $clientSite->id,
            'target_type' => 'wp',
            'language' => $language->value,
            'sync_status' => 'pending',
        ]);
    }

    public function findPublishTargetForLanguage(
        Content $content,
        SupportedLanguage $language
    ): ?ContentPublishTarget {
        return ContentPublishTarget::query()
            ->where('content_id', $content->id)
            ->where('language', $language->value)
            ->where('target_type', 'wp')
            ->first();
    }

    public function findPublishTargetByWpPostId(
        ClientSite $clientSite,
        string $wpPostId,
        ?SupportedLanguage $language = null
    ): ?ContentPublishTarget {
        $query = ContentPublishTarget::query()
            ->where('client_site_id', $clientSite->id)
            ->where('wp_post_id', $wpPostId)
            ->where('target_type', 'wp');

        if ($language) {
            $query->where('language', $language->value);
        }

        return $query->first();
    }

    public function updatePublishTargetAfterSync(
        ContentPublishTarget $target,
        array $syncResult
    ): void {
        $updateData = [
            'sync_status' => $syncResult['ok'] ? 'synced' : 'failed',
            'last_synced_at' => $syncResult['ok'] ? now() : $target->last_synced_at,
        ];

        if (isset($syncResult['wp_post_id'])) {
            $updateData['wp_post_id'] = $syncResult['wp_post_id'];
        }

        if (isset($syncResult['remote_permalink'])) {
            $updateData['remote_permalink'] = $syncResult['remote_permalink'];
        }

        if (isset($syncResult['remote_edit_link'])) {
            $updateData['remote_edit_link'] = $syncResult['remote_edit_link'];
        }

        if (isset($syncResult['external_key'])) {
            $updateData['external_key'] = $syncResult['external_key'];
        }

        $existingMeta = is_array($target->meta) ? $target->meta : [];
        $updateData['meta'] = array_merge($existingMeta, [
            'last_sync_result' => [
                'ok' => $syncResult['ok'] ?? false,
                'status' => $syncResult['status'] ?? null,
                'error' => $syncResult['error'] ?? null,
                'synced_at' => now()->toIso8601String(),
            ],
        ]);

        $target->update($updateData);
    }

    public function addLanguageToPayload(array $payload, Draft $draft): array
    {
        $language = $draft->language;

        $payload['language'] = $language->value;
        $payload['language_code'] = $language->value;
        $payload['language_name'] = $language->englishLabel();
        $payload['language_native_name'] = $language->nativeLabel();

        if ($draft->isTranslation() && $draft->source_draft_id) {
            $payload['is_translation'] = true;
            $payload['source_draft_id'] = $draft->source_draft_id;
            $payload['translation_source_language'] = $draft->translation_source_language;
        } else {
            $payload['is_translation'] = false;
        }

        $payload['meta'] = array_merge(
            is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            [
                '_publishlayer_language' => $language->value,
                '_publishlayer_is_translation' => $draft->isTranslation(),
            ]
        );

        return $payload;
    }

    public function getLanguagePluginInfo(ClientSite $clientSite): array
    {
        $capabilities = is_array($clientSite->capabilities) ? $clientSite->capabilities : [];

        $polylangActive = (bool) ($capabilities['polylang'] ?? false);
        $wpmlActive = (bool) ($capabilities['wpml'] ?? false);

        if ($polylangActive) {
            return [
                'plugin' => 'polylang',
                'active' => true,
                'can_link_translations' => true,
            ];
        }

        if ($wpmlActive) {
            return [
                'plugin' => 'wpml',
                'active' => true,
                'can_link_translations' => true,
            ];
        }

        return [
            'plugin' => null,
            'active' => false,
            'can_link_translations' => false,
        ];
    }

    public function prepareLanguageLinkingPayload(
        Draft $translatedDraft,
        Draft $sourceDraft,
        ClientSite $clientSite
    ): ?array {
        $pluginInfo = $this->getLanguagePluginInfo($clientSite);

        if (! $pluginInfo['can_link_translations']) {
            return null;
        }

        $sourcePublishTarget = $this->findPublishTargetForLanguage(
            $sourceDraft->content,
            $sourceDraft->language
        );

        if (! $sourcePublishTarget || ! $sourcePublishTarget->wp_post_id) {
            Log::info('Cannot link translations: source draft has no WP post ID', [
                'source_draft_id' => $sourceDraft->id,
                'translated_draft_id' => $translatedDraft->id,
            ]);
            return null;
        }

        return [
            'plugin' => $pluginInfo['plugin'],
            'source_post_id' => $sourcePublishTarget->wp_post_id,
            'source_language' => $sourceDraft->language->value,
            'target_language' => $translatedDraft->language->value,
            'link_translations' => true,
        ];
    }

    public function recordLanguageLinking(
        ContentPublishTarget $target,
        string $plugin,
        ?string $linkedPostId,
        ?string $languageTermId
    ): void {
        $target->update([
            'wp_language_plugin' => $plugin,
            'wp_language_term_id' => $languageTermId,
            'meta' => array_merge(
                is_array($target->meta) ? $target->meta : [],
                [
                    'language_linking' => [
                        'plugin' => $plugin,
                        'linked_post_id' => $linkedPostId,
                        'language_term_id' => $languageTermId,
                        'linked_at' => now()->toIso8601String(),
                    ],
                ]
            ),
        ]);
    }

    public function getAllLanguageVersions(Content $content): array
    {
        $publishTargets = ContentPublishTarget::query()
            ->where('content_id', $content->id)
            ->where('target_type', 'wp')
            ->get();

        $versions = [];
        foreach ($publishTargets as $target) {
            // $target->language is already a SupportedLanguage enum due to the cast/accessor
            $language = $target->language;

            $versions[$language->value] = [
                'language' => $language,
                'publish_target' => $target,
                'wp_post_id' => $target->wp_post_id,
                'sync_status' => $target->sync_status,
                'last_synced_at' => $target->last_synced_at,
                'remote_permalink' => $target->remote_permalink,
            ];
        }

        return $versions;
    }

    public function getMissingSyncLanguages(Content $content, array $enabledLanguages): array
    {
        $existingTargets = ContentPublishTarget::query()
            ->where('content_id', $content->id)
            ->where('target_type', 'wp')
            ->whereNotNull('wp_post_id')
            ->pluck('language')
            ->toArray();

        return array_filter(
            $enabledLanguages,
            fn (SupportedLanguage $lang) => ! in_array($lang->value, $existingTargets, true)
        );
    }
}
