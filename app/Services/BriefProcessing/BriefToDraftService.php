<?php

namespace App\Services\BriefProcessing;

use App\Jobs\GenerateDraftJob;
use App\Models\Brief;
use App\Models\Draft;
use App\Services\Brief\BriefDefaultBuilder;
use App\Services\Briefs\BriefPromptBuilder;
use App\Services\Editorial\EditorialPlanningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BriefToDraftService
{
    public function __construct(
        private readonly BriefPromptBuilder $promptBuilder,
        private readonly BriefDefaultBuilder $briefDefaultBuilder,
        private readonly EditorialPlanningService $editorialPlanning,
    ) {}

    public function claimAndCreateDraft(string $briefId): ?Draft
    {
        return DB::transaction(function () use ($briefId) {

            $brief = Brief::query()
                ->where('id', $briefId)
                ->lockForUpdate()
                ->first();

            if (! $brief) {
                return null;
            }

            if (! in_array((string) $brief->status, ['queued', 'draft', 'ready_for_generation', 'done'], true)) {
                return null;
            }

            $existingDraft = Draft::query()
                ->where('brief_id', $brief->id)
                ->orderByDesc('created_at')
                ->first();

            if ($existingDraft) {
                $brief->status = 'done';
                $brief->progress = 1.0;
                $brief->save();

                if (
                    trim((string) ($existingDraft->content_html ?? '')) === '' &&
                    in_array((string) $existingDraft->status, ['ready', 'failed'], true)
                ) {
                    $existingDraft->status = 'queued';
                    $existingDraft->last_error = null;
                    $existingDraft->save();

                    GenerateDraftJob::dispatch((string) $existingDraft->id)->onQueue('generation')->afterCommit();
                }

                return $existingDraft;
            }

            $brief->status = 'processing';
            $brief->progress = 0.1;
            $brief->save();

            $draft = new Draft();
            $draft->id = (string) Str::uuid();
            $draft->brief_id = $brief->id;
            $draft->content_id = $brief->content_id;
            $draft->client_site_id = $brief->client_site_id;

            $draft->status = 'queued';
            $draft->attempts = 0;

            $draft->title = $brief->title;
            $draft->seo_title = $brief->title;
            $draft->seo_h1 = $brief->title;
            $draft->seo_canonical = (string) data_get($brief->client_refs, 'canonical_url', '') ?: null;
            $draft->robots_index = data_get($brief->client_refs, 'robots_index');
            $draft->robots_follow = data_get($brief->client_refs, 'robots_follow');
            $draft->schema_type = (string) data_get($brief->client_refs, 'schema_type', '') ?: null;
            $draft->output_type = $brief->output_type ?? 'kb_article';
            $draft->language = trim((string) (
                $brief->language
                ?: $brief->content?->localeCode()
                ?: $brief->clientSite?->workspace?->defaultContentLanguageCode()
                ?: 'en'
            ));

            $draft->content_html = null;

            $promptMeta = $this->promptBuilder->buildDraftMeta($brief);

            // Get brief defaults for filling in missing values
            $title = (string) ($brief->title ?: 'Untitled');
            $keyword = (string) ($brief->primary_keyword ?: $title);
            $briefDefaults = $this->briefDefaultBuilder->buildDraftMeta($title, $keyword, $brief->language);

            // Check if brief appears incomplete
            $intentKeys = (array) data_get($brief->client_refs, 'taxonomy.intent_keys', []);
            $briefIncomplete = empty($intentKeys) || empty($brief->audience);

            if ($briefIncomplete) {
                Log::info('Brief incomplete, applying defaults during draft creation', [
                    'brief_id' => (string) $brief->id,
                    'has_intent_keys' => ! empty($intentKeys),
                    'has_audience' => ! empty($brief->audience),
                ]);
            }

            $draft->meta = [
                'language' => $brief->language ?: $briefDefaults['language'],
                'intent' => $brief->intent ?: $briefDefaults['intent'],
                'intent_keys' => ! empty($intentKeys) ? $intentKeys : $briefDefaults['intent_keys'],
                'primary_keyword' => $brief->primary_keyword ?: $briefDefaults['primary_keyword'],
                'audience' => $brief->audience ?: $briefDefaults['audience'],
                'audience_tags' => (array) data_get($brief->client_refs, 'taxonomy.audience_keys', $briefDefaults['audience_tags']),
                'brand_voice_id' => data_get($brief->client_refs, 'brand_voice_id'),
                'buyer_persona_id' => data_get($brief->client_refs, 'buyer_persona_id'),
                'team_member_id' => data_get($brief->client_refs, 'team_member_id'),
                'preferred_length' => data_get($brief->client_refs, 'preferred_length', 'medium'),
                'notes' => $brief->notes,
                'secondary_keywords' => $brief->secondary_keywords,
                'robots_index' => data_get($brief->client_refs, 'robots_index'),
                'robots_follow' => data_get($brief->client_refs, 'robots_follow'),
                'schema_type' => (string) data_get($brief->client_refs, 'schema_type', '') ?: null,
                'tone' => $brief->tone_of_voice,
                'funnel_stage' => $brief->funnel_stage ?: $briefDefaults['funnel_stage'],
                'search_intent' => $brief->search_intent ?: $briefDefaults['search_intent'],
                'unique_angle' => $brief->unique_angle,
                'key_points' => $brief->key_points,
                'call_to_action' => $brief->call_to_action,
                'editorial_intentions' => $briefDefaults['editorial_intentions'],
                'client_refs' => $brief->client_refs ?? [],
                'source' => (string) ($brief->source ?: 'wp_plugin'),
                'brief_prompt' => $this->promptBuilder->buildPrompt($brief),
                'brief_defaults_applied' => $briefIncomplete,
            ];
            $draft->meta = array_replace_recursive((array) $draft->meta, $promptMeta);
            $draftMeta = is_array($draft->meta) ? $draft->meta : [];
            $draftMeta['editorial_plan'] = $this->editorialPlanning->createForBrief($brief, $draftMeta);
            $draft->meta = $draftMeta;
            $explicitPreferredLength = (string) data_get($brief->client_refs, 'preferred_length', '');
            if ($explicitPreferredLength !== '') {
                $meta = is_array($draft->meta) ? $draft->meta : [];
                $meta['preferred_length'] = $explicitPreferredLength;
                $draft->meta = $meta;
            }

            $draft->links = null;

            // Set credit_cost from brief.client_refs BEFORE dispatching the job
            // This prevents a race condition where the job starts before credit_cost is set
            $requiredCredits = (int) data_get($brief->client_refs, 'required_credits', 0);
            if ($requiredCredits <= 0) {
                // Fallback to config default if not set in client_refs
                $requiredCredits = max(1, (int) config('argusly.ai.drafts.credit_cost', 4));
            }
            $draft->credit_cost = $requiredCredits;

            $draft->save();

            $brief->status = 'done';
            $brief->progress = 1.0;
            $brief->save();

            GenerateDraftJob::dispatch((string) $draft->id)->onQueue('generation')->afterCommit();

            return $draft;
        });
    }
}
