<?php

namespace App\Services;

use App\Contracts\LlmClientInterface;
use App\Data\Llm\LlmRequest;
use App\Models\Account;
use App\Models\Audience;
use App\Models\Brand;
use App\Models\BrandNarrative;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class BrandSetupGenerationService
{
    public function __construct(
        private readonly LlmResolver $resolver,
        private readonly LlmClientInterface $llm,
        private readonly BrandKnowledgeCenterService $knowledgeCenter,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function generate(Account $account, Brand $brand, array $attributes): array
    {
        $sourceMaterial = $this->sourceMaterial($attributes);
        $sections = $attributes['sections'] ?? ['company_profile', 'brand_voices', 'buyer_personas', 'team_personas'];
        $runtime = $this->resolver->resolve($account, $brand);
        $fallback = $this->fallbackJson($brand, $sourceMaterial, $sections);

        $response = $this->llm->generate(new LlmRequest(
            provider: $runtime['provider']['provider'],
            model: $runtime['model']['model'],
            messages: [
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'brand' => ['name' => $brand->name, 'domain' => $brand->domain, 'website_url' => $brand->website_url],
                        'input_method' => $attributes['input_method'] ?? 'paste_text',
                        'sections' => $sections,
                        'source_material' => Str::limit($sourceMaterial, 18000, ''),
                    ], JSON_PRETTY_PRINT),
                ],
            ],
            systemPrompt: $this->systemPrompt(),
            temperature: 0.2,
            maxTokens: 4000,
            responseFormat: 'json_object',
            metadata: [
                'purpose' => 'brand_setup_generation',
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'input_method' => $attributes['input_method'] ?? 'paste_text',
                'sections' => $sections,
                'fake_content' => $fallback,
            ],
        ));

        $payload = $this->decodePayload($response->content);

        return $this->normalizePayload($payload, $sections) + [
            'sections' => $sections,
            'llm' => [
                'provider' => $response->provider,
                'model' => $response->model,
                'fake' => (bool) ($response->rawResponse['fake'] ?? false),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $sections
     * @return array{profile_updated: bool, narratives_created: int, audiences_created: int}
     */
    public function apply(Account $account, Brand $brand, array $payload, array $sections): array
    {
        return DB::transaction(function () use ($account, $brand, $payload, $sections): array {
            $result = ['profile_updated' => false, 'narratives_created' => 0, 'audiences_created' => 0];

            if (in_array('company_profile', $sections, true)) {
                $profile = Arr::only($payload['company_profile'] ?? [], [
                    'official_name',
                    'tagline',
                    'short_description',
                    'long_description',
                    'mission',
                    'vision',
                    'positioning',
                    'value_proposition',
                    'tone_of_voice',
                    'primary_audience',
                    'secondary_audience',
                    'website',
                ]);

                if ($profile !== []) {
                    $this->knowledgeCenter->updateProfile($account, $brand, array_filter($profile, fn (mixed $value): bool => filled($value)));
                    $result['profile_updated'] = true;
                }
            }

            if (in_array('brand_voices', $sections, true)) {
                $voices = $this->list($payload['brand_voices'] ?? []);
                $tone = collect($voices)
                    ->map(fn (array $voice): string => trim(($voice['name'] ?? 'Voice').': '.($voice['description'] ?? '')))
                    ->filter()
                    ->implode("\n");

                if ($tone !== '') {
                    $profile = $this->knowledgeCenter->profileForBrand($account, $brand);
                    $profile->forceFill(['tone_of_voice' => $tone])->save();
                    $result['profile_updated'] = true;
                }

                foreach ($voices as $voice) {
                    $title = 'Brand voice: '.Str::limit((string) ($voice['name'] ?? 'Generated voice'), 180, '');
                    $narrative = BrandNarrative::query()->firstOrCreate(
                        ['account_id' => $account->id, 'brand_id' => $brand->id, 'title' => $title],
                        [
                            'description' => trim(implode("\n", array_filter([
                                $voice['description'] ?? null,
                                isset($voice['do']) && is_array($voice['do']) ? 'Do: '.implode('; ', $voice['do']) : null,
                                isset($voice['dont']) && is_array($voice['dont']) ? "Don't: ".implode('; ', $voice['dont']) : null,
                            ]))),
                            'importance' => 'medium',
                            'status' => 'active',
                        ],
                    );

                    if ($narrative->wasRecentlyCreated) {
                        $result['narratives_created']++;
                    }
                }
            }

            foreach (['buyer_personas' => 'buyer_persona', 'team_personas' => 'team_persona'] as $section => $type) {
                if (! in_array($section, $sections, true)) {
                    continue;
                }

                foreach ($this->list($payload[$section] ?? []) as $persona) {
                    $name = Str::limit((string) ($persona['name'] ?? Str::headline($type)), 250, '');
                    $audience = Audience::query()->updateOrCreate(
                        ['account_id' => $account->id, 'brand_id' => $brand->id, 'name' => $name],
                        [
                            'description' => $this->personaDescription($persona),
                            'status' => 'active',
                            'metadata' => [
                                'source' => 'ai_brand_setup',
                                'persona_type' => $type,
                                'generated_payload' => $persona,
                            ],
                        ],
                    );

                    if ($audience->wasRecentlyCreated) {
                        $result['audiences_created']++;
                    }
                }
            }

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function sourceMaterial(array $attributes): string
    {
        return match ($attributes['input_method'] ?? 'paste_text') {
            'website_url' => $this->crawlUrl((string) ($attributes['website_url'] ?? '')),
            'guided_input' => $this->guidedInput($attributes),
            default => (string) ($attributes['source_text'] ?? ''),
        };
    }

    private function crawlUrl(string $url): string
    {
        $response = Http::timeout(12)->get($url);

        if ($response->failed()) {
            throw new RuntimeException('Website URL could not be fetched.');
        }

        $text = preg_replace('/\s+/', ' ', strip_tags($response->body()));

        return trim((string) $text);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function guidedInput(array $attributes): string
    {
        return collect([
            'Company' => $attributes['guided_company'] ?? null,
            'Offer' => $attributes['guided_offer'] ?? null,
            'Audience' => $attributes['guided_audience'] ?? null,
            'Positioning' => $attributes['guided_positioning'] ?? null,
            'Voice' => $attributes['guided_voice'] ?? null,
        ])
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value, string $label): string => "{$label}: {$value}")
            ->implode("\n\n");
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You generate Argusly brand setup data from source material. Return valid JSON only.
Schema:
{
  "company_profile": {
    "official_name": string, "tagline": string|null, "short_description": string|null,
    "long_description": string|null, "mission": string|null, "vision": string|null,
    "positioning": string|null, "value_proposition": string|null, "tone_of_voice": string|null,
    "primary_audience": string|null, "secondary_audience": string|null, "website": string|null
  },
  "brand_voices": [{"name": string, "description": string, "do": [string], "dont": [string]}],
  "buyer_personas": [{"name": string, "description": string, "needs": [string], "pain_points": [string]}],
  "team_personas": [{"name": string, "description": string, "role": string|null, "expertise": [string]}]
}
Be specific, avoid placeholder language, and infer only when the source gives enough signal.
PROMPT;
    }

    /**
     * @param  array<int, string>  $sections
     */
    private function fallbackJson(Brand $brand, string $sourceMaterial, array $sections): string
    {
        $description = Str::limit($sourceMaterial, 260, '') ?: "{$brand->name} brand setup generated from guided source material.";

        return json_encode([
            'company_profile' => [
                'official_name' => $brand->name,
                'tagline' => null,
                'short_description' => $description,
                'long_description' => $description,
                'mission' => null,
                'vision' => null,
                'positioning' => $description,
                'value_proposition' => $description,
                'tone_of_voice' => 'Clear, useful and evidence-led.',
                'primary_audience' => 'Marketing and communications teams.',
                'secondary_audience' => 'Leadership and specialist operators.',
                'website' => $brand->website_url ?: ($brand->domain ? 'https://'.$brand->domain : null),
            ],
            'brand_voices' => in_array('brand_voices', $sections, true) ? [
                ['name' => 'Expert operator', 'description' => 'Practical, specific and confident.', 'do' => ['Use concrete examples'], 'dont' => ['Use vague superlatives']],
            ] : [],
            'buyer_personas' => in_array('buyer_personas', $sections, true) ? [
                ['name' => 'Marketing leader', 'description' => 'Owns visibility, content quality and commercial outcomes.', 'needs' => ['Clear priorities'], 'pain_points' => ['Fragmented brand knowledge']],
            ] : [],
            'team_personas' => in_array('team_personas', $sections, true) ? [
                ['name' => 'Founder spokesperson', 'description' => 'Explains the company narrative and market point of view.', 'role' => 'Founder', 'expertise' => ['Strategy', 'Category narrative']],
            ] : [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $content): array
    {
        $payload = json_decode($content, true);

        if (! is_array($payload)) {
            throw new RuntimeException('AI response was not valid JSON.');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $sections
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload, array $sections): array
    {
        return [
            'company_profile' => in_array('company_profile', $sections, true) && is_array($payload['company_profile'] ?? null) ? $payload['company_profile'] : [],
            'brand_voices' => in_array('brand_voices', $sections, true) ? $this->list($payload['brand_voices'] ?? []) : [],
            'buyer_personas' => in_array('buyer_personas', $sections, true) ? $this->list($payload['buyer_personas'] ?? []) : [],
            'team_personas' => in_array('team_personas', $sections, true) ? $this->list($payload['team_personas'] ?? []) : [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function list(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $persona
     */
    private function personaDescription(array $persona): string
    {
        return trim(implode("\n", array_filter([
            $persona['description'] ?? null,
            isset($persona['needs']) && is_array($persona['needs']) ? 'Needs: '.implode('; ', $persona['needs']) : null,
            isset($persona['pain_points']) && is_array($persona['pain_points']) ? 'Pain points: '.implode('; ', $persona['pain_points']) : null,
            isset($persona['expertise']) && is_array($persona['expertise']) ? 'Expertise: '.implode('; ', $persona['expertise']) : null,
        ])));
    }
}
