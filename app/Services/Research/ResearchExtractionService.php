<?php

namespace App\Services\Research;

use App\Enums\ResearchFindingType;
use App\Models\ResearchFinding;
use App\Models\ResearchSource;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\LlmManager;
use Illuminate\Support\Arr;
use Throwable;

class ResearchExtractionService
{
    private const EXTRACTION_VERSION = 'research_extraction_v1';

    public function __construct(
        private readonly LlmManager $llmManager,
    ) {
    }

    public function extractFromSource(ResearchSource $source, bool $force = false): int
    {
        $source->loadMissing('project');

        if (trim((string) ($source->content_text ?? '')) === '') {
            return 0;
        }

        $project = $source->project;
        if (! $project) {
            return 0;
        }

        $content = trim((string) $source->content_text);
        $contentHash = sha1($content);
        $meta = is_array($source->meta) ? $source->meta : [];

        $extractionMeta = (array) data_get($meta, 'extraction', []);
        $existingHash = (string) ($extractionMeta['content_hash'] ?? '');
        $existingStatus = (string) ($extractionMeta['status'] ?? '');

        if (! $force && $existingHash === $contentHash && $existingStatus === 'succeeded') {
            return (int) $source->findings()->count();
        }

        $source->update([
            'meta' => array_replace_recursive($meta, [
                'extraction' => [
                    'status' => 'processing',
                    'started_at' => now()->toIso8601String(),
                    'content_hash' => $contentHash,
                    'version' => self::EXTRACTION_VERSION,
                ],
            ]),
        ]);

        try {
            $payload = $this->extractStructuredPayload($source);

            ResearchFinding::query()
                ->where('research_source_id', $source->id)
                ->delete();

            $created = 0;
            $created += $this->storeFindingSet($source, ResearchFindingType::INSIGHT, (array) ($payload['insights'] ?? []));
            $created += $this->storeFindingSet($source, ResearchFindingType::STATISTIC, (array) ($payload['statistics'] ?? []));
            $created += $this->storeFindingSet($source, ResearchFindingType::QUOTE, (array) ($payload['quotes'] ?? []));
            $created += $this->storeFindingSet($source, ResearchFindingType::ENTITY, (array) ($payload['entities'] ?? []));
            $created += $this->storeFindingSet($source, ResearchFindingType::QUESTION, (array) ($payload['questions'] ?? []));

            $source->update([
                'meta' => array_replace_recursive(is_array($source->meta) ? $source->meta : [], [
                    'extraction' => [
                        'status' => 'succeeded',
                        'completed_at' => now()->toIso8601String(),
                        'findings_count' => $created,
                        'provider' => (string) ($payload['_provider'] ?? ''),
                        'model' => (string) ($payload['_model'] ?? ''),
                        'request_id' => (string) ($payload['_request_id'] ?? ''),
                        'content_hash' => $contentHash,
                        'version' => self::EXTRACTION_VERSION,
                    ],
                ]),
            ]);

            return $created;
        } catch (Throwable $exception) {
            $source->update([
                'meta' => array_replace_recursive(is_array($source->meta) ? $source->meta : [], [
                    'extraction' => [
                        'status' => 'failed',
                        'failed_at' => now()->toIso8601String(),
                        'error' => mb_substr($exception->getMessage(), 0, 1000),
                        'content_hash' => $contentHash,
                        'version' => self::EXTRACTION_VERSION,
                    ],
                ]),
            ]);

            return 0;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function extractStructuredPayload(ResearchSource $source): array
    {
        $project = $source->project;
        $maxChars = max(4000, (int) config('research.extraction.max_content_chars', 14000));
        $text = mb_substr(trim((string) $source->content_text), 0, $maxChars);

        $response = $this->llmManager->generateJson(
            new LlmRequest(
                messages: [
                    new LlmMessage('system', implode("\n", [
                        'You are a strict research extraction system.',
                        'Return JSON only.',
                        'Capture factual insights with concise wording.',
                        'Do not invent citations; only use source-derived evidence.',
                    ])),
                    new LlmMessage('user', implode("\n", [
                        'Extract findings for this source.',
                        'Provide arrays for insights, statistics, quotes, entities, questions.',
                        'Each item must include: text, confidence (0..1), citations (array of short evidence snippets).',
                        'Source URL: ' . (string) ($source->url ?? ''),
                        'Source title: ' . (string) ($source->title ?? ''),
                        'Source text:',
                        $text,
                    ])),
                ],
                temperature: 0.0,
                responseFormat: 'json',
                metadata: [
                    'feature' => 'research_extraction',
                    'modality' => 'text',
                    'workspaceId' => (string) ($project?->workspace_id ?? ''),
                    'siteId' => (string) ($project?->client_site_id ?? ''),
                    'researchProjectId' => (string) ($project?->id ?? ''),
                    'researchSourceId' => (string) $source->id,
                ],
            ),
            [
                'type' => 'object',
                'properties' => [
                    'insights' => ['type' => 'array'],
                    'statistics' => ['type' => 'array'],
                    'quotes' => ['type' => 'array'],
                    'entities' => ['type' => 'array'],
                    'questions' => ['type' => 'array'],
                ],
                'required' => ['insights', 'statistics', 'quotes', 'entities', 'questions'],
            ],
            [
                'feature' => 'research_extraction',
            ],
        );

        $json = is_array($response->json) ? $response->json : [];

        return array_merge($json, [
            '_provider' => $response->providerName,
            '_model' => $response->modelUsed,
            '_request_id' => $response->requestId,
        ]);
    }

    /**
     * @param array<int,mixed> $items
     */
    private function storeFindingSet(ResearchSource $source, ResearchFindingType $type, array $items): int
    {
        $project = $source->project;
        if (! $project) {
            return 0;
        }

        $count = 0;

        foreach ($items as $row) {
            $normalized = $this->normalizeFindingRow($row);
            $text = trim((string) ($normalized['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            ResearchFinding::query()->create([
                'research_project_id' => $project->id,
                'research_source_id' => $source->id,
                'finding_type' => $type,
                'finding_text' => mb_substr($text, 0, 10000),
                'citations' => $normalized['citations'],
                'confidence_score' => $normalized['confidence'],
                'is_selected' => false,
                'meta' => [
                    'version' => self::EXTRACTION_VERSION,
                    'source_url' => (string) ($source->url ?? ''),
                ],
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * @param mixed $row
     * @return array{text:string,confidence:float,citations:array<int,string>}
     */
    private function normalizeFindingRow(mixed $row): array
    {
        if (is_string($row)) {
            return [
                'text' => trim($row),
                'confidence' => 0.7,
                'citations' => [],
            ];
        }

        $text = trim((string) Arr::get((array) $row, 'text', Arr::get((array) $row, 'finding_text', '')));
        $confidence = (float) Arr::get((array) $row, 'confidence', Arr::get((array) $row, 'confidence_score', 0.7));
        $confidence = max(0.0, min(1.0, $confidence));

        $citations = collect((array) Arr::get((array) $row, 'citations', []))
            ->map(fn (mixed $citation): string => trim((string) $citation))
            ->filter()
            ->take(6)
            ->values()
            ->all();

        return [
            'text' => $text,
            'confidence' => $confidence,
            'citations' => $citations,
        ];
    }
}
