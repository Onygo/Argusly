<?php

namespace App\Services\SocialDistribution;

use App\Models\SocialPostVariant;
use App\Models\WriterProfile;
use App\Services\HumanSignals\HumanSignalContextBuilder;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\LlmManager;
use App\Services\WriterProfiles\WriterProfilePromptTemplates;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class SocialPostVariantGenerationProvider
{
    public function __construct(
        private readonly LlmManager $llm,
        private readonly SocialCopyLanguageAgent $languageAgent,
        private readonly HumanSignalContextBuilder $humanSignalContext,
    ) {}

    /**
     * @return array{
     *     hook:string,
     *     body:string,
     *     hashtags:array<int,string>,
     *     mentions:array<int,string>,
     *     quality_score:int|null,
     *     generation_model:string,
     *     generation_result:array<string,mixed>
     * }
     */
    public function generate(SocialPostVariant $variant): array
    {
        $variant->loadMissing(['campaign', 'campaignContent', 'workspace']);
        $writerProfile = $this->resolveWriterProfile($variant);

        $response = $this->llm->generateJson(
            new LlmRequest(
                messages: [
                    new LlmMessage('system', $this->systemPrompt($writerProfile)),
                    new LlmMessage('user', $this->userPrompt($variant)),
                ],
                temperature: 0.55,
                maxTokens: 900,
                responseFormat: 'json',
                metadata: [
                    'feature' => 'social_distribution',
                    'modality' => 'text',
                    'workspaceId' => (string) $variant->workspace_id,
                ],
            ),
            'Return a JSON object with string hook, string body, array hashtags, array mentions, and integer quality_score.',
        );

        $payload = $response->json ?: [];
        $requestedHashtags = Arr::wrap(data_get($variant->generation_prompt_context, 'hashtags', []));
        $language = (string) data_get($variant->generation_prompt_context, 'language', 'en');
        $languageReview = $this->languageAgent->review(
            (string) data_get($payload, 'hook', ''),
            (string) data_get($payload, 'body', ''),
            $language,
        );
        $hook = $languageReview['hook'];
        $body = $languageReview['body'];

        if ($hook === '' && $body === '') {
            throw new RuntimeException('The generation provider returned empty LinkedIn copy.');
        }

        return [
            'hook' => Str::limit($hook, 500, ''),
            'body' => Str::limit($body, 3000, ''),
            'hashtags' => $this->cleanList($requestedHashtags !== [] ? $requestedHashtags : Arr::wrap(data_get($payload, 'hashtags', [])), '#'),
            'mentions' => $this->cleanList(Arr::wrap(data_get($payload, 'mentions', [])), '@'),
            'quality_score' => $this->qualityScore(data_get($payload, 'quality_score')),
            'generation_model' => $response->providerName.':'.$response->modelUsed,
            'generation_result' => [
                'provider' => $response->providerName,
                'model' => $response->modelUsed,
                'request_id' => $response->requestId,
                'usage' => $response->usage->toArray(),
                'language_agent' => $languageReview['report'],
            ],
        ];
    }

    private function systemPrompt(?WriterProfile $writerProfile = null): string
    {
        $prompt = 'You write concise LinkedIn posts for B2B content teams. Return strict JSON only with hook, body, hashtags, mentions, and quality_score. Follow the requested language exactly. Do not invent statistics, customer names, URLs, hashtags, or external claims.';

        if ($writerProfile) {
            $prompt .= "\n\n".WriterProfilePromptTemplates::applySystemInstruction($writerProfile, 'linkedin');
        }

        return $prompt;
    }

    private function userPrompt(SocialPostVariant $variant): string
    {
        $postType = str((string) ($variant->post_type?->value ?? $variant->post_type))->replace('_', ' ')->title();
        $context = (array) $variant->generation_prompt_context;
        $language = match ((string) data_get($context, 'language', 'en')) {
            'nl' => 'Dutch (Nederlands)',
            default => 'English',
        };
        $sourceUrl = (string) data_get($context, 'source_url', '');
        $hashtags = collect((array) data_get($context, 'hashtags', []))->implode(' ');
        $targetAccount = (array) data_get($context, 'target_social_account', []);
        $targetAccountLine = $targetAccount !== []
            ? json_encode($targetAccount, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : 'Not assigned';
        $humanSignals = $this->humanSignalContext->forWorkspace($variant->workspace, 4);

        $lines = [
            'Create one LinkedIn variant.',
            'Language: '.$language,
            $language === 'Dutch (Nederlands)'
                ? 'Dutch casing: use normal Dutch sentence case, not English Title Case. Keep only proper nouns, brand names, and acronyms uppercase.'
                : 'Use natural casing for the requested language.',
            'Post type: '.$postType,
            'Variant number: '.$variant->variant_number,
            'Campaign: '.($variant->campaign?->name ?: 'Unlinked campaign'),
            'Campaign objective: '.$this->promptValue($variant->campaign?->objective ?: data_get($context, 'objective')),
            'Asset type: '.$this->promptValue(data_get($context, 'asset_type') ?: $variant->campaignContent?->asset_type?->value),
            'Internal linking strategy: '.$this->promptValue($variant->campaign?->internal_linking_strategy ?: data_get($context, 'internal_linking_strategy')),
            'Article URL to attach after the copy: '.($sourceUrl !== '' ? $sourceUrl : 'Not provided'),
            'Allowed hashtags: '.($hashtags !== '' ? $hashtags : 'Return only highly relevant hashtags if useful'),
            'Target publishing identity: '.$targetAccountLine,
            'Prompt context JSON: '.json_encode($context, JSON_UNESCAPED_SLASHES),
        ];

        if ($humanSignals !== '') {
            $lines[] = $humanSignals;
        }

        $lines[] = 'Write for a practical executive audience. Keep the hook under 160 characters and the body under 900 characters. Do not include the article URL inside hook or body; it is attached separately.';

        return trim(implode("\n", $lines));
    }

    private function promptValue(mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return 'Not provided';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'Not provided';
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return trim((string) $value) ?: 'Not provided';
    }

    /**
     * @param array<int,mixed> $items
     * @return array<int,string>
     */
    private function cleanList(array $items, string $prefix): array
    {
        return collect($items)
            ->map(fn ($item): string => trim((string) $item))
            ->filter()
            ->map(fn (string $item): string => Str::startsWith($item, $prefix) ? $item : $prefix.$item)
            ->unique()
            ->take(8)
            ->values()
            ->all();
    }

    private function qualityScore(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) $value));
    }

    private function resolveWriterProfile(SocialPostVariant $variant): ?WriterProfile
    {
        $profileId = (string) data_get($variant->generation_prompt_context, 'writer_profile_id', data_get($variant->metadata, 'writer_profile_id', ''));

        if ($profileId !== '') {
            return WriterProfile::query()
                ->where('workspace_id', $variant->workspace_id)
                ->where('status', WriterProfile::STATUS_ACTIVE)
                ->find($profileId);
        }

        return WriterProfile::query()
            ->where('workspace_id', $variant->workspace_id)
            ->where('status', WriterProfile::STATUS_ACTIVE)
            ->where('channel_defaults->linkedin', true)
            ->orderByDesc('confidence_score')
            ->first();
    }
}
