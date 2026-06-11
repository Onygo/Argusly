<?php

namespace App\Services\WorkspaceIntelligence;

use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\LlmManager;
use Illuminate\Support\Facades\Log;

class AIAnalysisService
{
    public function __construct(
        private readonly LlmManager $llmManager,
    ) {
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function generateBrandProfile(array $context): array
    {
        $systemPrompt = <<<'PROMPT'
Return strict JSON only.
You are creating an editable workspace intelligence proposal for a B2B organization.
Use only the supplied material. Do not invent hidden facts. Keep every field concise and practical.

Return:
{
  "brand_summary": "string",
  "tone_of_voice": "string",
  "audience_profiles": [{"name":"string","summary":"string","goals":["string"],"pain_points":["string"]}],
  "offerings": ["string"],
  "differentiators": ["string"],
  "strategic_topics": ["string"],
  "seo_topics": ["string"],
  "visual_direction": {
    "style_summary": "string",
    "colors": ["string"],
    "design_cues": ["string"]
  }
}
PROMPT;

        return $this->callJson($systemPrompt, $this->buildContextPrompt($context), 'brand_profile_generation');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function generateBuyerPersonas(array $context): array
    {
        $systemPrompt = <<<'PROMPT'
Return strict JSON only.
You are creating editable buyer persona proposals for a B2B organization.
Use the organization context and source material. Prefer concrete personas over generic audience labels.

Return:
{
  "personas": [
    {
      "type": "buyer|user|influencer|decision_maker",
      "name": "string",
      "summary": "string",
      "goals": ["string"],
      "pain_points": ["string"],
      "buying_triggers": ["string"],
      "content_needs": ["string"]
    }
  ]
}
PROMPT;

        return $this->callJson($systemPrompt, $this->buildContextPrompt($context), 'buyer_persona_generation');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function generateTeamMemberExpertPersona(array $context): array
    {
        $systemPrompt = <<<'PROMPT'
Return strict JSON only.
You are creating an editable expert persona proposal for a team member or author.
Use the supplied biography or profile text. LinkedIn URLs are reference fields only, not scraped evidence.

Return:
{
  "profile_data": {
    "expert_summary": "string",
    "expertise_areas": ["string"],
    "tone_traits": ["string"],
    "point_of_view": "string",
    "credibility_markers": ["string"],
    "content_angles": ["string"],
    "author_bio": "string"
  }
}
PROMPT;

        return $this->callJson($systemPrompt, $this->buildContextPrompt($context), 'team_member_persona_generation');
    }

    /**
     * Generate complete brand context including company profile, brand voices, buyer personas, and team personas.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function generateBrandContext(array $context): array
    {
        $result = $this->generateBrandContextDetailed($context);

        return (array) ($result['payload'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{payload:array<string,mixed>,provider:?string,model:?string,request_id:?string,raw_response_length:int,parser_error:?string}
     */
    public function generateBrandContextDetailed(array $context): array
    {
        $requestedSections = $context['requested_sections'] ?? ['company_profile', 'brand_voices', 'buyer_personas', 'team_personas'];
        $generationMode = $context['generation_mode'] ?? 'full';

        $systemPrompt = <<<'PROMPT'
Return strict JSON only.
You are creating a complete brand setup for a B2B organization.
Use only the supplied material. Do not invent hidden facts. Keep every field concise and practical.

Generate the following sections based on the source material:
- company_profile: Company identity and positioning
- brand_voices: 2-4 distinct writing voices/styles
- buyer_personas: 2-5 buyer/user personas
- team_personas: 2-4 suggested team member/author personas

Return:
{
  "company_profile": {
    "company_name": "string",
    "industry": "string",
    "short_description": "string",
    "long_description": "string",
    "value_proposition": "string",
    "key_services": ["string"],
    "value_propositions": ["string"],
    "proof_points": ["string"],
    "target_audience": "string",
    "mission": "string",
    "vision": "string"
  },
  "brand_voices": [
    {
      "name": "string (e.g., Professional & Authoritative)",
      "tone_of_voice": "string",
      "writing_style": "string",
      "do_rules": ["string"],
      "dont_rules": ["string"],
      "description": "string",
      "example_paragraph": "string"
    }
  ],
  "buyer_personas": [
    {
      "type": "buyer|user|influencer|decision_maker",
      "name": "string (e.g., Operations Manager Olivia)",
      "summary": "string",
      "role": "string",
      "goals": ["string"],
      "pain_points": ["string"],
      "buying_triggers": ["string"],
      "objections": ["string"],
      "content_preferences": ["string"]
    }
  ],
  "team_personas": [
    {
      "name": "string (role-based suggestion, e.g., Founder)",
      "title": "string",
      "role": "string",
      "writing_perspective": "string",
      "expertise_areas": ["string"],
      "tone_traits": ["string"],
      "use_as_writing_persona": true,
      "link_to_real_team_member_later": true
    }
  ]
}
PROMPT;

        $userPrompt = $this->buildBrandContextPrompt($context, $requestedSections, $generationMode);

        try {
            $response = $this->llmManager->generateJson(
                new LlmRequest(
                    messages: [
                        new LlmMessage('system', $systemPrompt),
                        new LlmMessage('user', $userPrompt),
                    ],
                    temperature: 0.2,
                    maxTokens: 4000,
                    metadata: [
                        'feature' => 'workspace_intelligence',
                        'sub_feature' => 'brand_context_generation',
                    ],
                ),
                null,
                ['feature' => 'workspace_intelligence']
            );

            $payload = $this->normalizeBrandContextPayload(
                $response->json ?? $this->recoverJsonPayload($response->text ?? null)
            );

            return [
                'payload' => $payload ?? [],
                'provider' => $response->providerName ?: null,
                'model' => $response->modelUsed ?: null,
                'request_id' => $response->requestId,
                'raw_response_length' => mb_strlen($response->text ?? ''),
                'parser_error' => $payload === null ? 'json_decode_failed' : null,
            ];
        } catch (\Throwable $e) {
            Log::error('Workspace intelligence AI analysis failed', [
                'sub_feature' => 'brand_context_generation',
                'error' => $e->getMessage(),
            ]);

            return [
                'payload' => [],
                'provider' => null,
                'model' => null,
                'request_id' => null,
                'raw_response_length' => 0,
                'parser_error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate brand voices based on organization context.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function generateBrandVoices(array $context): array
    {
        $systemPrompt = <<<'PROMPT'
Return strict JSON only.
You are creating 2-4 distinct brand voice definitions for a B2B organization.
Each voice should have a clear purpose and be usable for different content types.

Common voice archetypes to consider:
- Professional & Authoritative (thought leadership, whitepapers)
- Practical & No-nonsense (how-to guides, documentation)
- Friendly & Accessible (social media, blog posts)
- Technical & Detailed (product specs, developer docs)

Return:
{
  "voices": [
    {
      "name": "string",
      "tone_of_voice": "string",
      "writing_style": "string",
      "do_rules": ["string (max 5 rules)"],
      "dont_rules": ["string (max 5 rules)"],
      "description": "string",
      "example_paragraph": "string (50-100 words demonstrating this voice)"
    }
  ]
}
PROMPT;

        return $this->callJson($systemPrompt, $this->buildContextPrompt($context), 'brand_voices_generation', 3000);
    }

    /**
     * Transform a field value using AI (improve, shorten, make technical, make commercial).
     *
     * @param  array<string, mixed>  $context
     */
    public function transformField(string $value, string $action, ?string $fieldContext = null, array $context = []): string
    {
        $actionPrompts = [
            'improve' => 'Make this text clearer, more impactful, and better written while preserving the core meaning.',
            'shorten' => 'Make this text more concise while preserving the essential meaning. Remove filler words and redundancy.',
            'make_technical' => 'Add more technical depth and specificity. Use industry terminology appropriately.',
            'make_commercial' => 'Make this more persuasive and benefit-focused. Emphasize value and outcomes.',
        ];

        $actionInstruction = $actionPrompts[$action] ?? $actionPrompts['improve'];

        $systemPrompt = <<<PROMPT
You are a professional B2B content editor.
Your task: {$actionInstruction}

Rules:
- Return ONLY the transformed text, nothing else
- Do not add explanations or meta-commentary
- Preserve the original language (if input is Dutch, output should be Dutch)
- Keep the same general length unless shortening
- Maintain professional tone appropriate for B2B content
PROMPT;

        $userPrompt = "Field context: " . ($fieldContext ?? 'General brand content') . "\n\n";
        $userPrompt .= "Text to transform:\n" . $value;

        if (! empty($context['organization'])) {
            $userPrompt .= "\n\nOrganization context:\n" . json_encode($context['organization'], JSON_PRETTY_PRINT);
        }

        try {
            $response = $this->llmManager->generateText(
                new LlmRequest(
                    messages: [
                        new LlmMessage('system', $systemPrompt),
                        new LlmMessage('user', $userPrompt),
                    ],
                    temperature: 0.3,
                    maxTokens: 1000,
                    metadata: [
                        'feature' => 'brand_context',
                        'sub_feature' => 'field_transformation',
                        'action' => $action,
                    ],
                ),
                null,
                ['feature' => 'brand_context']
            );

            return trim($response->text ?? $value);
        } catch (\Throwable $e) {
            Log::error('Field transformation failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return $value;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, string>  $requestedSections
     */
    private function buildBrandContextPrompt(array $context, array $requestedSections, string $generationMode): string
    {
        $parts = [
            'Organization context:',
            json_encode($context['organization'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            '',
        ];

        if ($generationMode === 'missing_only') {
            $parts[] = 'IMPORTANT: Only generate content for empty/missing fields. Existing data should be preserved.';
            $parts[] = '';
        }

        if (! empty($context['existing_profile'])) {
            $parts[] = 'Existing company profile:';
            $parts[] = json_encode($context['existing_profile'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $parts[] = '';
        }

        if (! empty($context['existing_voices'])) {
            $parts[] = 'Existing brand voices:';
            $parts[] = json_encode($context['existing_voices'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $parts[] = '';
        }

        if (! empty($context['existing_personas'])) {
            $parts[] = 'Existing buyer personas:';
            $parts[] = json_encode($context['existing_personas'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $parts[] = '';
        }

        if (! empty($context['existing_team_members'])) {
            $parts[] = 'Existing team members:';
            $parts[] = json_encode($context['existing_team_members'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $parts[] = '';
        }

        $parts[] = 'Source material:';
        $parts[] = (string) ($context['source_text'] ?? '');
        $parts[] = '';
        $parts[] = 'Requested sections to generate: ' . implode(', ', $requestedSections);

        return implode("\n", $parts);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function buildContextPrompt(array $context): string
    {
        return collect([
            'Organization context:',
            json_encode($context['organization'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            '',
            'Existing approved profile:',
            json_encode($context['approved_profile'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            '',
            'Existing personas:',
            json_encode($context['approved_personas'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            '',
            'Team member context:',
            json_encode($context['team_member'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            '',
            'Source material:',
            (string) ($context['source_text'] ?? ''),
            '',
            'Source metadata:',
            json_encode($context['source_metadata'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ])->filter(fn ($line) => $line !== null)->implode("\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function callJson(string $systemPrompt, string $userPrompt, string $subFeature, int $maxTokens = 2200): array
    {
        try {
            $response = $this->llmManager->generateJson(
                new LlmRequest(
                    messages: [
                        new LlmMessage('system', $systemPrompt),
                        new LlmMessage('user', $userPrompt),
                    ],
                    temperature: 0.2,
                    maxTokens: $maxTokens,
                    metadata: [
                        'feature' => 'workspace_intelligence',
                        'sub_feature' => $subFeature,
                    ],
                ),
                null,
                ['feature' => 'workspace_intelligence']
            );

            return $response->json ?? [];
        } catch (\Throwable $e) {
            Log::error('Workspace intelligence AI analysis failed', [
                'sub_feature' => $subFeature,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function recoverJsonPayload(?string $text): ?array
    {
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        foreach ($this->jsonCandidates($text) as $candidate) {
            $decoded = json_decode($candidate, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            if (json_last_error() === JSON_ERROR_NONE && is_string($decoded)) {
                $nested = $this->recoverJsonPayload($decoded);

                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>|null
     */
    private function normalizeBrandContextPayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        foreach (['payload', 'brand_setup', 'brand_context', 'sections', 'data', 'result'] as $key) {
            $nested = $payload[$key] ?? null;

            if (is_array($nested) && $this->containsBrandSections($nested)) {
                return $nested;
            }

            if (is_string($nested)) {
                $decoded = $this->recoverJsonPayload($nested);

                if ($decoded !== null && $this->containsBrandSections($decoded)) {
                    return $decoded;
                }
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function containsBrandSections(array $payload): bool
    {
        foreach (['company_profile', 'brand_voices', 'buyer_personas', 'team_personas'] as $section) {
            if (array_key_exists($section, $payload)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function jsonCandidates(string $text): array
    {
        $candidates = [$text];

        if (preg_match_all('/```(?:json)?\s*(.*?)```/is', $text, $matches)) {
            foreach ($matches[1] as $match) {
                $candidates[] = trim($match);
            }
        }

        $object = $this->extractBalancedJson($text, '{', '}');
        if ($object !== null) {
            $candidates[] = $object;
        }

        $array = $this->extractBalancedJson($text, '[', ']');
        if ($array !== null) {
            $candidates[] = $array;
        }

        return array_values(array_unique(array_filter(
            $candidates,
            static fn (string $candidate): bool => trim($candidate) !== ''
        )));
    }

    private function extractBalancedJson(string $text, string $open, string $close): ?string
    {
        $start = strpos($text, $open);

        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);

        for ($index = $start; $index < $length; $index++) {
            $char = $text[$index];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;

                if ($depth === 0) {
                    return substr($text, $start, $index - $start + 1);
                }
            }
        }

        return null;
    }
}
