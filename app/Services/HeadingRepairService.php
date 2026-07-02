<?php

namespace App\Services;

use App\Models\Draft;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\LlmManager;
use App\Support\HeadingQualityEvaluator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class HeadingRepairService
{
    public function __construct(
        private readonly LlmManager $llmManager,
        private readonly HeadingQualityEvaluator $headingQualityEvaluator,
    ) {}

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $context
     * @param array<string,mixed> $llmOptions
     * @return array<string,mixed>
     */
    public function repair(Draft $draft, array $result, array $context = [], array $llmOptions = [], int $maxIterations = 3): array
    {
        $iterations = [];
        $repairedCount = 0;

        for ($iteration = 1; $iteration <= $maxIterations; $iteration++) {
            $evaluation = $this->headingQualityEvaluator->evaluateResult($result, $context);
            $targets = $this->repairableTargets($evaluation, $result);

            if ((bool) $evaluation['passed']) {
                return $this->withRepairMeta($result, [
                    'status' => $repairedCount > 0 ? 'repaired' : 'not_needed',
                    'iterations' => $iterations,
                    'repaired_headings' => $repairedCount,
                    'remaining_issues' => [],
                ]);
            }

            if ($targets === []) {
                return $this->withRepairMeta($result, [
                    'status' => 'needs_editorial_review',
                    'iterations' => $iterations,
                    'repaired_headings' => $repairedCount,
                    'remaining_issues' => $evaluation['issues'],
                    'reason' => 'No repairable section headings remained below the threshold.',
                ]);
            }

            $rewrites = $this->requestRewrites($draft, $result, $targets, $context, $llmOptions, $iteration);
            $applied = $this->applyRewrites($result, $rewrites);
            $result = $applied['result'];
            $repairedCount += $applied['count'];

            $iterations[] = [
                'iteration' => $iteration,
                'requested' => count($targets),
                'applied' => $applied['count'],
            ];

            if ($applied['count'] === 0) {
                return $this->withRepairMeta($result, [
                    'status' => 'needs_editorial_review',
                    'iterations' => $iterations,
                    'repaired_headings' => $repairedCount,
                    'remaining_issues' => $evaluation['issues'],
                    'reason' => 'Heading repair response did not include applicable rewrites.',
                ]);
            }
        }

        $evaluation = $this->headingQualityEvaluator->evaluateResult($result, $context);

        return $this->withRepairMeta($result, [
            'status' => (bool) $evaluation['passed'] ? 'repaired' : 'needs_editorial_review',
            'iterations' => $iterations,
            'repaired_headings' => $repairedCount,
            'remaining_issues' => $evaluation['issues'],
            'reason' => (bool) $evaluation['passed'] ? null : 'Heading repair attempts were exhausted.',
        ]);
    }

    /**
     * @param array<string,mixed> $evaluation
     * @param array<string,mixed> $result
     * @return array<int,array<string,mixed>>
     */
    private function repairableTargets(array $evaluation, array $result): array
    {
        return collect((array) ($evaluation['headings'] ?? []))
            ->filter(fn (array $heading): bool => ! (bool) ($heading['passed'] ?? false))
            ->map(function (array $heading) use ($result): ?array {
                $source = (string) ($heading['source'] ?? '');
                if (! preg_match('/^sections\.([0-9]+)\.heading$/', $source, $matches)) {
                    return null;
                }

                $index = (int) $matches[1];
                if ($index === 0 && $this->isIntroHeading((string) ($heading['text'] ?? ''))) {
                    return null;
                }

                $section = Arr::get($result, 'sections.' . $index);
                if (! is_array($section)) {
                    return null;
                }

                return [
                    'index' => $index,
                    'current_heading' => (string) ($heading['text'] ?? ''),
                    'score' => (int) ($heading['score'] ?? 0),
                    'issue' => $heading['issue'] ?? 'low editorial quality score',
                    'section_excerpt' => Str::limit(trim(strip_tags((string) ($section['html'] ?? ''))), 500),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $result
     * @param array<int,array<string,mixed>> $targets
     * @param array<string,mixed> $context
     * @param array<string,mixed> $llmOptions
     * @return array<int,array{index:int,heading:string}>
     */
    private function requestRewrites(Draft $draft, array $result, array $targets, array $context, array $llmOptions, int $iteration): array
    {
        $response = $this->llmManager->generateJson(
            new LlmRequest(
                messages: [
                    new LlmMessage('system', $this->systemPrompt()),
                    new LlmMessage('user', $this->userPrompt($draft, $result, $targets, $context, $iteration)),
                ],
                model: trim((string) ($llmOptions['model'] ?? '')) ?: null,
                temperature: 0.2,
                maxTokens: 1200,
                responseFormat: 'json',
                metadata: [
                    'provider' => trim((string) ($llmOptions['provider'] ?? '')) ?: null,
                    'feature' => 'draft_heading_repair',
                    'modality' => 'text',
                    'workspaceId' => (string) data_get($llmOptions, 'workspace_id', ''),
                    'siteId' => (string) ($draft->client_site_id ?? ''),
                    'draftId' => (string) ($draft->id ?? ''),
                    'contentId' => (string) ($draft->content_id ?? ''),
                    'trigger' => 'heading_quality_repair',
                    'repair_iteration' => $iteration,
                ],
            ),
            [
                'type' => 'object',
                'required' => ['headings'],
                'properties' => [
                    'headings' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['index', 'heading'],
                            'properties' => [
                                'index' => ['type' => 'integer'],
                                'heading' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        );

        $headings = data_get($response->json, 'headings', []);
        if (! is_array($headings)) {
            throw new RuntimeException('Heading repair response was missing headings.');
        }

        return collect($headings)
            ->map(fn (mixed $item): array => [
                'index' => (int) data_get($item, 'index', -1),
                'heading' => trim((string) data_get($item, 'heading', '')),
            ])
            ->filter(fn (array $item): bool => $item['index'] >= 0 && $item['heading'] !== '')
            ->values()
            ->all();
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'You repair article section headings only.',
            'Rewrite weak headings into editorial, specific, natural headings.',
            'Do not rewrite body HTML. Do not invent facts. No clickbait.',
            'Prefer 40-70 characters when natural. Include the SEO keyword only when it fits.',
            'Return strict JSON only.',
        ]);
    }

    /**
     * @param array<string,mixed> $result
     * @param array<int,array<string,mixed>> $targets
     * @param array<string,mixed> $context
     */
    private function userPrompt(Draft $draft, array $result, array $targets, array $context, int $iteration): string
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $language = $this->stringValue($meta['language'] ?? $draft->language ?? 'en');

        return implode("\n", [
            'Repair the listed section headings.',
            'Language: ' . $language,
            'Article title: ' . (string) data_get($result, 'title', $draft->title),
            'Primary keyword: ' . (string) ($context['primary_keyword'] ?? ''),
            'Secondary keywords: ' . implode(', ', array_filter(array_map('strval', (array) ($context['secondary_keywords'] ?? [])))),
            'Intent keys: ' . implode(', ', array_filter(array_map('strval', (array) ($context['intent_keys'] ?? [])))),
            'Repair iteration: ' . $iteration,
            '',
            'Headings to repair:',
            json_encode($targets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            '',
            'Return JSON in this exact shape:',
            '{"headings":[{"index":0,"heading":"New heading"}]}',
        ]);
    }

    /**
     * @param array<string,mixed> $result
     * @param array<int,array{index:int,heading:string}> $rewrites
     * @return array{result:array<string,mixed>,count:int}
     */
    private function applyRewrites(array $result, array $rewrites): array
    {
        $sections = Arr::get($result, 'sections', []);
        if (! is_array($sections)) {
            return ['result' => $result, 'count' => 0];
        }

        $count = 0;
        foreach ($rewrites as $rewrite) {
            $index = $rewrite['index'];
            if (! isset($sections[$index]) || ! is_array($sections[$index])) {
                continue;
            }

            $heading = trim($rewrite['heading']);
            if ($heading === '') {
                continue;
            }

            $sections[$index]['heading'] = $heading;
            $count++;
        }

        Arr::set($result, 'sections', $sections);

        return ['result' => $result, 'count' => $count];
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $repair
     * @return array<string,mixed>
     */
    private function withRepairMeta(array $result, array $repair): array
    {
        $meta = Arr::get($result, 'meta', []);
        if (! is_array($meta)) {
            $meta = [];
        }

        $repair = array_filter($repair, fn (mixed $value): bool => $value !== null);
        $repair['version'] = 'heading_repair_v1';
        $repair['repaired_at'] = now()->toIso8601String();

        $meta['heading_quality_repair'] = $repair;
        Arr::set($result, 'meta', $meta);

        return $result;
    }

    private function isIntroHeading(string $heading): bool
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', html_entity_decode($heading, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $heading));

        return in_array($normalized, ['opening', 'intro'], true);
    }

    private function stringValue(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }
}
