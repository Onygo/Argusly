<?php

namespace App\Services;

use App\Exceptions\InsufficientCreditsException;
use App\Models\BrandVoice;
use App\Models\Content;
use App\Models\Draft;
use App\Models\SiteCompetitor;
use App\Models\TeamMember;
use App\Models\Workspace;
use App\Models\WriterProfile;
use App\Services\Credits\GenerationPricing;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\Exceptions\LlmException;
use App\Services\Llm\LlmManager;
use App\Services\WriterProfiles\WriterProfilePromptTemplates;
use App\Services\Entitlements\FeatureGate;
use App\Support\DescriptionSanitizer;
use App\Support\DutchTextCasingNormalizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DraftGenerationService
{
    public function __construct(
        protected CreditWalletService $credits,
        protected LlmManager $llmManager,
        protected GenerationPricing $pricing,
    ) {}

    public function generateAndBill(Draft $draft, ?string $userId = null, int $maxPasses = 2): array
    {
        // Optioneel, maar sterk aan te raden: claim de draft zodat maar 1 worker hem pakt
        $claimed = Draft::query()
            ->whereKey($draft->id)
            ->whereIn('status', ['ready', 'queued'])
            ->update(['status' => 'processing']);

        if ($claimed === 0 && $draft->status !== 'processing') {
            $draft->refresh();

            // Als hij niet geclaimed is, returnen we de normale generate output is niet correct.
            // We geven een duidelijke fout zodat de caller dit kan afhandelen.
            throw new RuntimeException('Draft is already being processed or is not in a processable state.');
        }

        $draft->refresh();

        try {
            // 1) Credits reserveren (idempotent)
            $this->credits->ensureReservedForDraft($draft, $userId);

            // 2) Genereren met jouw bestaande repair flow
            $result = $this->generateWithRepair($draft, $maxPasses);

            // 3) Credits committen (idempotent)
            $this->credits->ensureCommittedForDraft($draft, $userId);

            // 4) Draft status afronden + delivery klaarzetten
            $draft->status = 'ready';
            $draft->last_error = null;

            // delivery status hoort nu klaar te staan, maar alleen als nog leeg
            if (!$draft->delivery_status) {
                $draft->delivery_status = 'pending';
            }

            $draft->save();

            return $result;
        } catch (InsufficientCreditsException $e) {
            $draft->status = 'failed';
            $draft->credit_status = 'failed';
            $draft->last_error = $e->getMessage();
            $draft->save();

            throw $e;
        } catch (Throwable $e) {
            // Release alleen als er gereserveerd was (idempotent)
            $this->credits->ensureReleasedForDraft($draft, $userId);

            $draft->status = 'failed';
            $draft->last_error = $e->getMessage();
            $draft->save();

            throw $e;
        }
    }

    public function generate(Draft $draft): array
    {
        $payload = $this->buildLlmPayload($draft);
        $resolvedProvider = trim((string) ($payload['provider'] ?? '')) ?: (string) config('llm.default_provider', 'openai');
        $maxOutputTokens = $this->resolveRequestedMaxOutputTokens($draft, $payload);
        $baseMessages = [
            new LlmMessage('system', (string) ($payload['system'] ?? '')),
            new LlmMessage('user', (string) ($payload['user'] ?? '')),
        ];
        $baseMetadata = [
            'provider' => $resolvedProvider,
            'feature' => 'draft_generation',
            'modality' => 'text',
            'workspaceId' => (string) ($payload['workspace_id'] ?? ''),
            'siteId' => (string) ($draft->client_site_id ?? ''),
            'draftId' => (string) ($draft->id ?? ''),
            'contentId' => (string) ($draft->content_id ?? ''),
            'credits' => (float) ($draft->credit_cost ?? 0),
            'trigger' => 'draft_generation_service',
            'requested_max_output_tokens' => $maxOutputTokens,
        ];

        $response = $this->generateJsonWithTokenFallback(
            request: new LlmRequest(
                messages: $baseMessages,
                model: $payload['model'] ?: null,
                temperature: (float) config('llm.defaults.temperature', 0.3),
                maxTokens: $maxOutputTokens,
                responseFormat: 'json',
                metadata: $baseMetadata,
            ),
            payload: $payload,
            schemaOrExpectation: $this->jsonSchemaDescription(),
        );

        $text = trim((string) $response->text);
        if ($text === '') {
            throw new RuntimeException('LLM returned an empty response.');
        }

        $result = $response->json;
        if (! is_array($result)) {
            try {
                $result = $this->parseResult($text);
            } catch (RuntimeException) {
                $meta = is_array($draft->meta) ? $draft->meta : [];
                $meta['repair_hint'] = 'json_repair';
                $meta['repair_requested_at'] = now()->toIso8601String();
                $draft->meta = $meta;
                $draft->save();

                $repairResponse = $this->generateJsonWithTokenFallback(
                    request: new LlmRequest(
                        messages: array_merge($baseMessages, [
                            new LlmMessage('user', 'Your previous response was invalid JSON. Return strict JSON only, no markdown, no commentary.'),
                        ]),
                        model: $payload['model'] ?: null,
                        temperature: 0.0,
                        maxTokens: $maxOutputTokens,
                        responseFormat: 'json',
                        metadata: array_merge($baseMetadata, [
                            'trigger' => 'draft_generation_json_repair',
                        ]),
                    ),
                    payload: $payload,
                    schemaOrExpectation: $this->jsonSchemaDescription(),
                );

                $repairText = trim((string) $repairResponse->text);
                if ($repairText === '') {
                    throw new RuntimeException('LLM returned an empty response.');
                }

                $result = $repairResponse->json ?? $this->parseResult($repairText);
                $response = $repairResponse;
            }
        }

        $result = $this->normalizeGeneratedSeoMetadata($result);
        $result = $this->normalizeGeneratedDutchCasing($draft, $result);

        $this->validateResult($result);

        $normalized = $this->normalizeResult($draft, $result);
        $normalized['usage'] = $response->usage->toArray();
        $normalized['provider'] = $response->providerName;
        $normalized['model'] = $response->modelUsed;
        $normalized['request_id'] = $response->requestId;
        $normalized['requested_max_output_tokens'] = $maxOutputTokens;
        $normalized['required_credits'] = (int) data_get($draft->meta, 'required_credits', (int) ($draft->credit_cost ?? 0));
        $normalized['charged_credits'] = (int) ($draft->credit_cost ?? 0);
        $normalized['model_used'] = (string) $response->modelUsed;

        return $normalized;
    }

    private function generateJsonWithTokenFallback(
        LlmRequest $request,
        array $payload,
        array|string|null $schemaOrExpectation = null,
    ): \App\Services\Llm\Data\LlmResponse {
        try {
            return $this->llmManager->generateJson($request, $schemaOrExpectation);
        } catch (Throwable $exception) {
            if (! $this->isMaxTokenError($exception)) {
                throw $exception;
            }

            $fallbackMax = $this->safeModelOutputCap($payload, (int) $request->maxTokens);
            if ($fallbackMax <= 0 || $fallbackMax >= (int) $request->maxTokens) {
                throw $exception;
            }

            $retryRequest = new LlmRequest(
                messages: $request->messages,
                model: $request->model,
                temperature: $request->temperature,
                maxTokens: $fallbackMax,
                topP: $request->topP,
                responseFormat: $request->responseFormat,
                metadata: array_merge($request->metadata, [
                    'max_output_tokens_fallback' => $fallbackMax,
                    'max_output_tokens_fallback_reason' => 'provider_limit',
                ]),
            );

            return $this->llmManager->generateJson($retryRequest, $schemaOrExpectation);
        }
    }

    private function resolveRequestedMaxOutputTokens(Draft $draft, array $payload): int
    {
        $generationType = (string) data_get($draft->meta, 'generation_type', 'article');
        $requested = data_get($draft->meta, 'requested_max_output_tokens');
        $normalized = $this->pricing->normalizeRequestedMaxOutputTokens(
            $generationType,
            is_numeric($requested) ? (int) $requested : null
        );
        $modelCap = $this->safeModelOutputCap($payload, $normalized);

        return $modelCap > 0 ? min($normalized, $modelCap) : $normalized;
    }

    private function safeModelOutputCap(array $payload, int $fallback): int
    {
        $provider = trim((string) ($payload['provider'] ?? ''));
        if ($provider === '') {
            $provider = (string) config('llm.default_provider', 'openai');
        }
        $model = (string) ($payload['model'] ?? '');
        $cap = $this->pricing->modelOutputCap($provider, $model);
        if ($cap <= 0) {
            $providerKey = strtolower(trim($provider));
            if (str_contains($providerKey, 'openai')) {
                $cap = (int) config('credits.llm_output_caps.openai.default', 0);
            } elseif (str_contains($providerKey, 'anthropic')) {
                $cap = (int) config('credits.llm_output_caps.anthropic.default', 0);
            } elseif (str_contains($providerKey, 'gemini')) {
                $cap = (int) config('credits.llm_output_caps.gemini.default', 0);
            } elseif (str_contains($providerKey, 'mistral')) {
                $cap = (int) config('credits.llm_output_caps.mistral.default', 0);
            }
        }

        return $cap > 0 ? $cap : $fallback;
    }

    private function isMaxTokenError(Throwable $exception): bool
    {
        if ($exception instanceof LlmException && in_array((int) ($exception->statusCode ?? 0), [400, 413, 422], true)) {
            $message = strtolower($exception->getMessage());
            if (
                str_contains($message, 'max_output_tokens')
                || str_contains($message, 'maximum context length')
                || str_contains($message, 'too many tokens')
                || str_contains($message, 'token limit')
            ) {
                return true;
            }
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'max_output_tokens')
            || str_contains($message, 'maximum context length')
            || str_contains($message, 'too many tokens')
            || str_contains($message, 'token limit');
    }

    private function buildLlmPayload(Draft $draft): array
    {
        $brief = method_exists($draft, 'brief') ? $draft->brief : null;

        $topic = $draft->title ?: (data_get($brief, 'title') ?? 'Untitled');
        $outputType = $draft->output_type ?: 'kb_article';

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $links = is_array($draft->links) ? $draft->links : [];

        $language = $this->stringValue($meta['language'] ?? 'nl');
        $tone = (string)($meta['tone'] ?? '');
        $content = null;
        if ($draft->content_id) {
            $content = $draft->content()->with(['workspace.organization', 'brandVoice', 'teamMember', 'writerProfile'])->first();
        }
        $lengthProfile = $this->resolveLengthProfile($draft, $content);
        $length = sprintf('%d-%d words (stay within 10%% deviation)', $lengthProfile['min_words'], $lengthProfile['max_words']);

        $structure = $meta['structure'] ?? [
            'Opening',
            'Main section',
            'Practical examples',
            'Conclusion',
        ];

        $primaryKeyword = (string)($meta['primary_keyword'] ?? '');
        $secondaryKeywords = $meta['secondary_keywords'] ?? [];
        $intentKeys = array_values(array_filter(array_map('strval', (array) ($meta['intent_keys'] ?? []))));

        $clientContext = $this->buildClientContext($draft, $meta);

        $workspace = $this->resolveWorkspaceForDraft($draft);
        $brandVoice = $this->resolveBrandVoiceForGeneration($draft, $content, $workspace);
        if ($tone === '') {
            $tone = (string) (
                $brandVoice?->tone_of_voice
                ?: $brandVoice?->default_tone
                ?: 'Professional, clear, structured, confident.'
            );
        }
        if ($language === '') {
            $language = $this->stringValue($brandVoice?->default_language ?? 'en');
        }

        $context = $content ? $this->buildGenerationContext($content, $draft) : $this->defaultSystemContext();
        $context = $this->appendWriterProfileContext($context, $draft, $content, $workspace, $outputType);
        $context = $this->appendCompetitorContext($context, $draft);

        $requestedProviderOverride = $this->resolveProviderName((string) (
            $meta['generation_provider_override']
            ?? data_get($meta, 'generation.provider_override')
            ?? ''
        ));
        $requestedModelOverride = $this->resolveModelName((string) (
            $meta['generation_model_override']
            ?? data_get($meta, 'generation.model_override')
            ?? ''
        ));

        $resolvedProvider = $requestedProviderOverride !== ''
            ? $requestedProviderOverride
            : $this->resolveProviderName($brandVoice?->ai_provider_override ?? null);
        $resolvedModel = $requestedModelOverride !== ''
            ? $requestedModelOverride
            : $this->resolveModelName($brandVoice?->ai_model_override);

        $customSystemPrompt = trim((string) ($meta['generation_custom_system_prompt'] ?? ''));
        $customUserPrompt = trim((string) ($meta['generation_custom_user_prompt'] ?? ''));
        $system = $customSystemPrompt !== '' ? $customSystemPrompt : $this->systemPrompt($context);

        if (config('app.debug')) {
            $debugMeta = is_array($draft->meta) ? $draft->meta : [];
            $debugMeta['generation_debug'] = [
                'context' => $context,
                'brand_voice_id' => $brandVoice?->id,
                'content_id' => $content?->id,
                'writer_profile_id' => $this->resolveWriterProfileForGeneration($draft, $content, $workspace)?->id,
                'requested_provider_override' => $requestedProviderOverride,
                'brand_voice_provider_override' => $brandVoice?->ai_provider_override ?? null,
                'resolved_provider' => $resolvedProvider,
                'requested_model_override' => $requestedModelOverride,
                'brand_voice_model_override' => $brandVoice?->ai_model_override,
                'resolved_model' => $resolvedModel,
                'preferred_length' => $lengthProfile['key'],
                'min_words' => $lengthProfile['min_words'],
                'max_words' => $lengthProfile['max_words'],
                'captured_at' => now()->toIso8601String(),
            ];
            $draft->meta = $debugMeta;
            $draft->save();
        }

        $user = $customUserPrompt !== '' ? $customUserPrompt : $this->userPrompt(
            topic: $topic,
            outputType: $outputType,
            language: $language,
            tone: $tone,
            length: $length,
            structure: $structure,
            primaryKeyword: $primaryKeyword,
            intentKeys: $intentKeys,
            secondaryKeywords: $secondaryKeywords,
            links: $links,
            clientContext: $clientContext
        );

        return [
            'provider' => $resolvedProvider,
            'model' => $resolvedModel,
            'system' => $system,
            'user' => $user,
            'workspace_id' => $workspace?->id,
        ];
    }

    /**
     * Exposes the normalized payload used by draft generation so downstream
     * services (for example draft compare prompt snapshots) can audit inputs
     * without re-implementing prompt assembly rules.
     *
     * @return array{provider:string,model:string,system:string,user:string,workspace_id:?string}
     */
    public function buildGenerationPayloadForDraft(Draft $draft): array
    {
        return $this->buildLlmPayload($draft);
    }

    private function systemPrompt(?string $context = null): string
    {
        $parts = [
            'You are an expert B2B content writer and editor.',
            'Write factual, structured, SEO friendly content.',
            'Avoid hype and avoid unverifiable claims.',
            'Output must follow the requested JSON schema only.',
            'Do not include markdown fences and do not include commentary.',
            'HTML inside the JSON must be valid and use h2, h3, p, ul, ol, li, strong, em, a.',
            'Do not include html, head, or body tags.',
            'Do not create a generic "Related reading" paragraph or placeholder links.',
            'Internal links will be injected by the application after generation.',
        ];

        if ($context && trim($context) !== '') {
            array_unshift($parts, trim($context));
        }

        return implode("\n", $parts);
    }

    public function buildSystemContextForDraft(Draft $draft): string
    {
        $content = $draft->content()->with(['workspace.organization', 'brandVoice', 'teamMember', 'writerProfile'])->first();
        if (! $content) {
            return $this->appendCompetitorContext($this->defaultSystemContext(), $draft);
        }

        return $this->appendCompetitorContext($this->buildGenerationContext($content, $draft), $draft);
    }

    public function buildGenerationContext(Content $content, ?Draft $draft = null): string
    {
        $content->loadMissing([
            'workspace.organization',
            'workspace.organization.organizationProfile',
            'workspace.companyProfile',
            'workspace.brandVoices',
            'brandVoice',
            'teamMember',
            'writerProfile',
        ]);

        $organization = $content->workspace?->organization;
        $organizationProfile = $organization?->organizationProfile;
        $companyProfile = $content->workspace?->companyProfile;
        $brandVoice = $this->resolveBrandVoiceForContent($content);
        $teamMember = $this->resolveTeamMemberForContent($content);
        $teamMemberProfile = (array) ($teamMember?->profile_data ?? []);
        $lengthProfile = $this->resolveLengthProfileForContent($content);
        $toneDefaults = is_array($organization?->tone_defaults) ? $organization->tone_defaults : [];

        $companyDescription =
            trim((string) ($organizationProfile?->brand_summary ?? '')) !== ''
                ? (string) $organizationProfile?->brand_summary
                : (trim((string) ($organization?->company_description ?? '')) !== ''
                ? (string) $organization?->company_description
                : (string) ($companyProfile?->company_name ?: 'B2B company focused on practical business outcomes.'));

        $positioning =
            ! empty($organizationProfile?->differentiators)
                ? implode(', ', array_slice((array) $organizationProfile->differentiators, 0, 4))
                : (trim((string) ($organization?->positioning_statement ?? '')) !== ''
                ? (string) $organization?->positioning_statement
                : 'Clear, structured, authority-driven communication with no fluff.');

        $targetAudience =
            ! empty($organizationProfile?->audience_profiles)
                ? collect((array) $organizationProfile->audience_profiles)
                    ->map(fn ($audience) => trim((string) data_get($audience, 'name', '')))
                    ->filter()
                    ->implode(', ')
                : (trim((string) ($organization?->target_audience ?? '')) !== ''
                ? (string) $organization?->target_audience
                : (string) ($companyProfile?->target_audience ?: 'Business and technical decision makers.'));

        $industry =
            trim((string) ($organization?->industry ?? '')) !== ''
                ? (string) $organization?->industry
                : (string) ($companyProfile?->industry ?: 'B2B');

        $voiceTone = (string) (
            $brandVoice?->tone_of_voice
            ?: $organizationProfile?->tone_of_voice
            ?: $brandVoice?->default_tone
            ?: ($toneDefaults['tone'] ?? null)
            ?: 'Professional, clear, structured, confident.'
        );
        $voiceStyle = (string) (
            $brandVoice?->writing_style
            ?: $brandVoice?->style_guide
            ?: ($toneDefaults['style'] ?? null)
            ?: 'Use concise paragraphs, practical examples, and direct business relevance.'
        );
        $voiceDo = (string) (
            $brandVoice?->do_rules
            ?: ($toneDefaults['do'] ?? null)
            ?: 'Be explicit, concrete, and outcome-oriented.'
        );
        $voiceDont = (string) (
            $brandVoice?->dont_rules
            ?: ($toneDefaults['dont'] ?? null)
            ?: 'Do not use fluff, vague claims, or hype language.'
        );
        $voiceVocabulary = (string) (
            $brandVoice?->vocabulary_guidelines
            ?: ($toneDefaults['vocabulary'] ?? null)
            ?: implode(', ', $brandVoice?->preferredTerminologyArray() ?? [])
            ?: 'Use domain-accurate terminology and plain professional language.'
        );

        $lines = [
            'SYSTEM CONTEXT',
            '',
            'You are writing on behalf of:',
            '',
            'Company:',
            $companyDescription,
            '',
            'Positioning:',
            $positioning,
            '',
            'Target audience:',
            $targetAudience,
            '',
            'Industry:',
            $industry,
            '',
            'Brand voice:',
            'Tone: ' . $voiceTone,
            'Style: ' . $voiceStyle,
            'Do:',
            $voiceDo,
            'Do not:',
            $voiceDont,
            'Vocabulary:',
            $voiceVocabulary,
        ];

        if ($teamMember) {
            $lines = array_merge($lines, [
                '',
                'Author persona:',
                'Name: ' . $teamMember->name,
                'Role: ' . ((string) ($teamMember->title ?: $teamMember->role) ?: 'Contributor'),
                'Expertise: ' . ((string) $teamMember->expertise ?: implode(', ', (array) ($teamMemberProfile['expertise_areas'] ?? [])) ?: 'Domain expertise'),
                'Perspective: ' . ((string) $teamMember->writing_perspective ?: (string) ($teamMemberProfile['point_of_view'] ?? '') ?: 'Company perspective'),
                'Traits: ' . ((string) $teamMember->personality_traits ?: implode(', ', (array) ($teamMemberProfile['tone_traits'] ?? [])) ?: 'Professional and pragmatic'),
            ]);
        } else {
            $lines = array_merge($lines, [
                '',
                'Author persona:',
                'Write from the company perspective.',
            ]);
        }

        $lines = array_merge($lines, [
            '',
            'Article length requirement:',
            sprintf(
                'Write between %d and %d words.',
                $lengthProfile['min_words'],
                $lengthProfile['max_words']
            ),
            'Stay within 10 percent deviation.',
        ]);

        return implode("\n", $lines);
    }

    private function appendWriterProfileContext(string $context, Draft $draft, ?Content $content, ?Workspace $workspace, ?string $channel = null): string
    {
        $profile = $this->resolveWriterProfileForGeneration($draft, $content, $workspace);
        if (! $profile) {
            return $context;
        }

        return $context."\n\n".WriterProfilePromptTemplates::applySystemInstruction($profile, $channel);
    }

    private function appendCompetitorContext(string $context, Draft $draft): string
    {
        if (! $this->shouldInjectCompetitorContext($draft)) {
            return $context;
        }

        $competitors = SiteCompetitor::query()
            ->where('workspace_id', $draft->clientSite?->workspace_id)
            ->where('client_site_id', $draft->client_site_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(25)
            ->get(['name', 'domain', 'notes']);

        if ($competitors->isEmpty()) {
            return $context;
        }

        $lines = [
            '',
            'Competitive context (site-level):',
            'Use this context to position clearly against alternatives. Do not fabricate claims.',
        ];

        foreach ($competitors as $competitor) {
            $line = sprintf('- %s (%s)', (string) $competitor->name, (string) $competitor->domain);
            if ($competitor->notes) {
                $line .= ' - ' . (string) $competitor->notes;
            }
            $lines[] = $line;
        }

        return $context . "\n" . implode("\n", $lines);
    }

    private function shouldInjectCompetitorContext(Draft $draft): bool
    {
        $draft->loadMissing('clientSite.workspace');
        $site = $draft->clientSite;

        if (! $site?->workspace) {
            return false;
        }

        $enabledForSite = (bool) data_get($site->capabilities, 'competitor_context_enabled', false);
        if (! $enabledForSite) {
            return false;
        }

        return app(FeatureGate::class)->can($site->workspace, 'link_intelligence');
    }

    private function defaultSystemContext(): string
    {
        return implode("\n", [
            'SYSTEM CONTEXT',
            '',
            'You are writing on behalf of a B2B company.',
            'Use a clear, structured, authority-driven style.',
            'Avoid fluff and vague claims.',
            'Article length requirement:',
            'Write between 900 and 1200 words.',
            'Stay within 10 percent deviation.',
        ]);
    }

    private function resolveWorkspaceForDraft(Draft $draft): ?Workspace
    {
        $draft->loadMissing('clientSite.workspace.companyProfile', 'clientSite.workspace.brandVoices');

        return $draft->clientSite?->workspace;
    }

    private function resolveBrandVoiceForDraft(Draft $draft, ?Workspace $workspace): ?BrandVoice
    {
        if (! $workspace) {
            return null;
        }

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $overrideId = (string) ($meta['brand_voice_id'] ?? '');
        if ($overrideId !== '') {
            $voice = $workspace->brandVoices->firstWhere('id', $overrideId);
            if ($voice instanceof BrandVoice) {
                return $voice;
            }
        }

        $default = $workspace->brandVoices->firstWhere('is_default', true);
        return $default instanceof BrandVoice ? $default : null;
    }

    private function resolveBrandVoiceForGeneration(Draft $draft, ?Content $content, ?Workspace $workspace): ?BrandVoice
    {
        if ($content) {
            $brandVoice = $this->resolveBrandVoiceForContent($content);
            if ($brandVoice) {
                return $brandVoice;
            }
        }

        return $this->resolveBrandVoiceForDraft($draft, $workspace);
    }

    private function resolveWriterProfileForGeneration(Draft $draft, ?Content $content, ?Workspace $workspace): ?WriterProfile
    {
        if ($content) {
            $content->loadMissing('writerProfile');
            if ($content->writerProfile instanceof WriterProfile && $content->writerProfile->status === WriterProfile::STATUS_ACTIVE) {
                return $content->writerProfile;
            }
        }

        if (! $workspace) {
            return null;
        }

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $overrideId = (string) ($meta['writer_profile_id'] ?? '');
        if ($overrideId !== '') {
            $profile = WriterProfile::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', WriterProfile::STATUS_ACTIVE)
                ->find($overrideId);

            if ($profile instanceof WriterProfile) {
                return $profile;
            }
        }

        $channel = strtolower((string) ($draft->output_type ?: 'blog'));

        return WriterProfile::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', WriterProfile::STATUS_ACTIVE)
            ->where(function ($query) use ($channel): void {
                $query->where("channel_defaults->{$channel}", true)
                    ->orWhere('channel_defaults->blog', true);
            })
            ->orderByDesc('confidence_score')
            ->first();
    }

    private function resolveBrandVoiceForContent(Content $content): ?BrandVoice
    {
        $content->loadMissing('workspace.organization.brandVoices', 'workspace.brandVoices', 'brandVoice');

        if ($content->brandVoice instanceof BrandVoice) {
            return $content->brandVoice;
        }

        $organizationDefault = $content->workspace?->organization?->brandVoices?->firstWhere('is_default', true);
        if ($organizationDefault instanceof BrandVoice) {
            return $organizationDefault;
        }

        $workspaceDefault = $content->workspace?->brandVoices?->firstWhere('is_default', true);
        return $workspaceDefault instanceof BrandVoice ? $workspaceDefault : null;
    }

    private function resolveTeamMemberForContent(Content $content): ?TeamMember
    {
        $content->loadMissing('teamMember');

        if (! ($content->teamMember instanceof TeamMember)) {
            return null;
        }

        return $content->teamMember->is_active ? $content->teamMember : null;
    }

    /**
     * @return array{key:string,min_words:int,max_words:int}
     */
    private function resolveLengthProfile(Draft $draft, ?Content $content): array
    {
        if ($content) {
            return $this->resolveLengthProfileForContent($content);
        }

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $preferred = strtolower(trim((string) ($meta['preferred_length'] ?? '')));
        return $this->lengthProfile($preferred);
    }

    /**
     * @return array{key:string,min_words:int,max_words:int}
     */
    private function resolveLengthProfileForContent(Content $content): array
    {
        $preferred = strtolower(trim((string) ($content->preferred_length ?? '')));
        return $this->lengthProfile($preferred);
    }

    /**
     * @return array{key:string,min_words:int,max_words:int}
     */
    private function lengthProfile(string $key): array
    {
        return match ($key) {
            'short' => ['key' => 'short', 'min_words' => 600, 'max_words' => 800],
            'long' => ['key' => 'long', 'min_words' => 1400, 'max_words' => 1800],
            'pillar' => ['key' => 'pillar', 'min_words' => 2200, 'max_words' => 3000],
            default => ['key' => 'medium', 'min_words' => 900, 'max_words' => 1200],
        };
    }

    /**
     * @param array<int, string> $items
     */
    private function formatBulletLines(array $items): string
    {
        return collect($items)
            ->map(fn ($item) => '- ' . trim((string) $item))
            ->implode("\n");
    }

    private function userPrompt(
        string $topic,
        string $outputType,
        string $language,
        string $tone,
        string $length,
        array $structure,
        string $primaryKeyword,
        array $intentKeys,
        array $secondaryKeywords,
        array $links,
        array $clientContext
    ): string {
        $schema = $this->jsonSchemaDescription();

        $structureLines = array_map(
            fn ($s) => is_string($s) ? $s : json_encode($s),
            $structure
        );

        $kw2 = array_values(array_filter(array_map('strval', $secondaryKeywords)));
        $intentInstruction = $this->intentInstruction($intentKeys);

        return implode("\n", array_filter([
            "Task: Generate a {$outputType}.",
            "Language: {$language}.",
            $this->dutchCasingInstruction($language),
            "Tone: {$tone}.",
            "Target length: {$length}.",
            "Topic: {$topic}.",
            $intentInstruction,
            $primaryKeyword !== '' ? "Primary keyword: {$primaryKeyword}." : null,
            !empty($kw2) ? 'Secondary keywords: ' . implode(', ', $kw2) . '.' : null,
            '',
            'Requested structure:',
            '- ' . implode("\n- ", $structureLines),
            '',
            !empty($links) ? 'Related topic hints (context only): ' . json_encode($links, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            !empty($links) ? 'Use related articles only as topical context. Do not output labels like "Related article 1". Do not list related links at the end. Mention related topics naturally only where they support the narrative.' : null,
            'Write natural paragraphs with enough topical context so relevant internal links can be inserted later.',
            '',
            'Client context (do not copy into the article): ' . json_encode($clientContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            '',
            'Return JSON only, exactly matching this schema description:',
            $schema,
        ]));
    }

    /**
     * @param  array<int, string>  $intentKeys
     */
    private function intentInstruction(array $intentKeys): ?string
    {
        $intentKeys = array_values(array_filter(array_map('strval', $intentKeys)));

        if ($intentKeys === []) {
            return null;
        }

        $guidance = [];

        if (array_intersect($intentKeys, ['educate', 'explain', 'guide', 'inform']) !== []) {
            $guidance[] = 'Start with clear definitions, direct answers, and structured explanations.';
        }

        if (in_array('compare', $intentKeys, true)) {
            $guidance[] = 'Include a comparison section or decision criteria table when useful.';
        }

        if (array_intersect($intentKeys, ['commercial', 'convert', 'persuade', 'solution']) !== []) {
            $guidance[] = 'Connect the article to solution fit, evaluation signals, and a natural CTA.';
        }

        if (in_array('process', $intentKeys, true)) {
            $guidance[] = 'Use step-based structure, checklists, or implementation flow where relevant.';
        }

        if (in_array('strategic', $intentKeys, true)) {
            $guidance[] = 'Frame decisions around business impact, tradeoffs, and strategic outcomes.';
        }

        $instruction = 'Content intent: ' . implode(', ', $intentKeys) . '. Write the article aligned with these intents.';

        if ($guidance !== []) {
            $instruction .= ' ' . implode(' ', $guidance);
        }

        return $instruction;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function buildClientContext(Draft $draft, array $meta): array
    {
        $comparisonId = trim((string) data_get($meta, 'draft_compare.comparison_id', (string) ($draft->draft_comparison_id ?? '')));
        if ($comparisonId !== '') {
            return [
                'client_site_id' => (string) $draft->client_site_id,
                'brief_id' => (string) $draft->brief_id,
                'draft_comparison_id' => $comparisonId,
            ];
        }

        return [
            'client_site_id' => (string) $draft->client_site_id,
            'brief_id' => (string) $draft->brief_id,
            'draft_id' => (string) $draft->id,
        ];
    }

    private function jsonSchemaDescription(): string
    {
        return implode("\n", [
            '{',
            '  "title": "string",',
            '  "meta": {',
            '    "description": "string (max 155 chars)",',
            '    "keywords": ["string", "..."]',
            '  },',
            '  "sections": [',
            '    { "heading": "string", "html": "string (valid HTML fragment)" }',
            '  ],',
            '  "links": [',
            '    { "href": "string", "anchor": "string", "rel": "string|null" }',
            '  ]',
            '}',
        ]);
    }

    private function resolveModelName(?string $override): string
    {
        $candidate = trim((string) $override);

        if ($candidate === '') {
            return '';
        }

        // Keep provider model ids compact and machine-readable.
        if (strlen($candidate) > 120 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $candidate)) {
            return '';
        }

        return $candidate;
    }

    private function resolveProviderName(?string $override): string
    {
        $candidate = strtolower(trim((string) $override));
        if ($candidate === '') {
            return '';
        }

        $configuredProviders = array_keys((array) config('llm.providers', []));
        if (in_array($candidate, $configuredProviders, true)) {
            return $candidate;
        }

        return '';
    }

    private function parseResult(string $text): array
    {
        $clean = $this->stripCodeFences($text);

        $decoded = json_decode($clean, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $maybeJson = $this->extractFirstJsonObject($clean);
        if ($maybeJson !== null) {
            $decoded2 = json_decode($maybeJson, true);
            if (is_array($decoded2)) {
                return $decoded2;
            }
        }

        throw new RuntimeException('Response was not valid JSON.');
    }

    private function stripCodeFences(string $text): string
    {
        $t = trim($text);
        $t = preg_replace('/^```(json)?\s*/i', '', $t) ?? $t;
        $t = preg_replace('/\s*```$/', '', $t) ?? $t;
        return trim($t);
    }

    private function extractFirstJsonObject(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;

        $len = strlen($text);
        for ($i = $start; $i < $len; $i++) {
            $ch = $text[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($ch === '\\') {
                $escape = true;
                continue;
            }

            if ($ch === '"') {
                $inString = !$inString;
                continue;
            }

            if ($inString) {
                continue;
            }

            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function validateResult(array $result): void
    {
        $title = (string)Arr::get($result, 'title', '');
        if (trim($title) === '') {
            throw new RuntimeException('Missing title in result JSON.');
        }

        $sections = Arr::get($result, 'sections', []);
        if (!is_array($sections) || count($sections) === 0) {
            throw new RuntimeException('Missing sections in result JSON.');
        }

        $combinedHtml = '';
        foreach ($sections as $section) {
            $heading = (string)Arr::get($section, 'heading', '');
            $html = (string)Arr::get($section, 'html', '');
            if (trim($heading) === '' || trim($html) === '') {
                throw new RuntimeException('Section is missing heading or html.');
            }
            $combinedHtml .= "\n" . $html;
        }

        if (Str::length(strip_tags($combinedHtml)) < 400) {
            throw new RuntimeException('Generated content seems too short.');
        }

        $metaDesc = (string)Arr::get($result, 'meta.description', '');
        if ($metaDesc !== '' && Str::length($metaDesc) > 155) {
            throw new RuntimeException('Meta description exceeds 155 characters.');
        }
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function normalizeGeneratedSeoMetadata(array $result): array
    {
        $meta = Arr::get($result, 'meta', []);
        if (! is_array($meta)) {
            return $result;
        }

        $description = (string) Arr::get($meta, 'description', '');
        if ($description === '') {
            return $result;
        }

        $normalized = DescriptionSanitizer::normalizeMetaDescription($description, maxLength: 155);
        if ($normalized !== $description) {
            Log::notice('draft_generation.meta_description_sanitized', [
                'original_length' => Str::length($description),
                'persisted_length' => Str::length($normalized),
                'was_truncated' => Str::length($description) > Str::length($normalized),
            ]);
        }

        $meta['description'] = $normalized;
        Arr::set($result, 'meta', $meta);

        return $result;
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function normalizeGeneratedDutchCasing(Draft $draft, array $result): array
    {
        if ($this->draftLanguage($draft) !== 'nl') {
            return $result;
        }

        foreach (['title'] as $key) {
            if (Arr::has($result, $key)) {
                Arr::set($result, $key, DutchTextCasingNormalizer::normalizeText((string) Arr::get($result, $key)));
            }
        }

        foreach (['meta.description', 'meta.title', 'meta.og_title', 'meta.twitter_title'] as $key) {
            if (Arr::has($result, $key)) {
                Arr::set($result, $key, DutchTextCasingNormalizer::normalizeText((string) Arr::get($result, $key)));
            }
        }

        $sections = Arr::get($result, 'sections', []);
        if (! is_array($sections)) {
            return $result;
        }

        foreach ($sections as $index => $section) {
            if (! is_array($section)) {
                continue;
            }

            if (array_key_exists('heading', $section)) {
                $sections[$index]['heading'] = DutchTextCasingNormalizer::normalizeText((string) $section['heading']);
            }

            if (array_key_exists('html', $section)) {
                $sections[$index]['html'] = $this->normalizeDutchHtmlHeadingCasing((string) $section['html']);
            }
        }

        Arr::set($result, 'sections', $sections);

        return $result;
    }

    private function normalizeDutchHtmlHeadingCasing(string $html): string
    {
        return preg_replace_callback('/(<h[1-6]\b[^>]*>)(.*?)(<\/h[1-6]>)/is', function (array $matches): string {
            $inner = (string) $matches[2];
            if ($inner !== strip_tags($inner)) {
                return (string) $matches[0];
            }

            return $matches[1].DutchTextCasingNormalizer::normalizeText($inner).$matches[3];
        }, $html) ?? $html;
    }

    private function draftLanguage(Draft $draft): string
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $language = strtolower(str_replace('_', '-', trim($this->stringValue(
            $draft->language ?: data_get($meta, 'language', '')
        ))));

        return str_starts_with($language, 'nl') ? 'nl' : $language;
    }

    private function stringValue(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }

    private function dutchCasingInstruction(string $language): ?string
    {
        return strtolower(trim($language)) === 'nl'
            ? 'Dutch casing: use normal Dutch sentence case for titles, headings, SEO fields, and body copy; do not use English Title Case. Keep proper nouns, brand names, and acronyms uppercase.'
            : null;
    }

    private function normalizeResult(Draft $draft, array $result): array
    {
        $sections = Arr::get($result, 'sections', []);
        $htmlParts = [];

        foreach ($sections as $index => $section) {
            $heading = trim((string)Arr::get($section, 'heading', ''));
            $html = trim((string)Arr::get($section, 'html', ''));
            $html = $this->stripDuplicateLeadingHeading($heading, $html);

            if ($index === 0 && $this->isIntroHeading($heading)) {
                $htmlParts[] = $html;
                continue;
            }

            $htmlParts[] = '<h2>' . e($heading) . '</h2>' . "\n" . $html;
        }

        $contentHtml = trim(implode("\n\n", $htmlParts));

        $meta = Arr::get($result, 'meta', []);
        if (!is_array($meta)) {
            $meta = [];
        }

        $links = Arr::get($result, 'links', []);
        if (!is_array($links)) {
            $links = [];
        }

        $actualWordCount = str_word_count(strip_tags($contentHtml));
        if ($draft->content_id) {
            Content::query()
                ->whereKey($draft->content_id)
                ->update(['actual_word_count' => $actualWordCount]);
        }

        return [
            'title' => (string)Arr::get($result, 'title', $draft->title),
            'content_html' => $contentHtml,
            'meta' => $meta,
            'links' => $links,
        ];
    }

    public function generateWithRepair(Draft $draft, int $maxPasses = 2): array
    {
        $lastError = null;

        for ($i = 0; $i < $maxPasses; $i++) {
            try {
                return $this->generate($draft);
            } catch (Throwable $e) {
                $lastError = $e;

                $meta = is_array($draft->meta) ? $draft->meta : [];
                $meta['repair'] = [
                    'pass' => $i + 1,
                    'error' => Str::limit($e->getMessage(), 800),
                    'at' => now()->toIso8601String(),
                ];
                $draft->meta = $meta;
                $draft->save();

                if ($i < $maxPasses - 1) {
                    $this->addRepairHint($draft, $e->getMessage());
                }
            }
        }

        throw new RuntimeException($lastError ? $lastError->getMessage() : 'Generation failed.');
    }

    private function stripDuplicateLeadingHeading(string $heading, string $html): string
    {
        if ($heading === '' || $html === '') {
            return $html;
        }

        if (! preg_match('/^\s*<h[1-6][^>]*>(.*?)<\/h[1-6]>\s*/is', $html, $matches)) {
            return $html;
        }

        $firstHeadingText = trim(strip_tags((string) ($matches[1] ?? '')));
        if ($this->normalizeHeadingText($firstHeadingText) !== $this->normalizeHeadingText($heading)) {
            return $html;
        }

        return ltrim((string) preg_replace('/^\s*<h[1-6][^>]*>.*?<\/h[1-6]>\s*/is', '', $html, 1));
    }

    private function normalizeHeadingText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return mb_strtolower($value);
    }

    private function isIntroHeading(string $heading): bool
    {
        $normalized = $this->normalizeHeadingText($heading);

        return in_array($normalized, ['opening', 'intro', 'introduction', 'inleiding'], true);
    }

    private function addRepairHint(Draft $draft, string $error): void
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['repair_hint'] = 'Fix the previous output to satisfy the JSON schema and validation rules. Error: ' . Str::limit($error, 600);
        $draft->meta = $meta;
        $draft->save();
    }
}
