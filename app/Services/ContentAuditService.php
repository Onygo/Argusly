<?php

namespace App\Services;

use App\Jobs\RunContentAuditJob;
use App\Models\ContentAsset;
use App\Models\ContentAudit;
use App\Models\User;
use App\Services\Signals\SignalManager;

class ContentAuditService
{
    public function __construct(
        private readonly CreditService $credits,
        private readonly SignalManager $signals,
        private readonly EvidenceService $evidence,
    ) {}

    public function requestForContentAsset(ContentAsset $contentAsset, User $user): ContentAudit
    {
        $creditTransaction = $this->credits->consume(
            $contentAsset->account,
            $user,
            'content_audit',
            'Content audit requested.',
            $contentAsset,
            ['content_asset_id' => $contentAsset->id],
        );

        $audit = ContentAudit::query()->create([
            'account_id' => $contentAsset->account_id,
            'brand_id' => $contentAsset->brand_id,
            'content_asset_id' => $contentAsset->id,
            'language' => $contentAsset->language,
            'locale' => $contentAsset->locale,
            'status' => 'queued',
        ]);

        $creditTransaction->update([
            'subject_type' => $audit->getMorphClass(),
            'subject_id' => $audit->id,
        ]);

        RunContentAuditJob::dispatch($audit->id);

        return $audit;
    }

    public function run(ContentAudit $audit): ContentAudit
    {
        if (! in_array($audit->status, ['queued', 'processing'], true)) {
            return $audit;
        }

        $audit->forceFill(['status' => 'processing'])->save();

        $contentAsset = $audit->contentAsset;
        $analysis = $this->analyze($contentAsset);

        $audit->forceFill([
            ...$analysis,
            'status' => 'completed',
            'audited_at' => now(),
        ])->save();

        $this->evidence->createForSubject($audit, [
            'evidence_type' => 'manual_note',
            'title' => 'Content audit scoring inputs',
            'snippet' => $audit->summary,
            'raw_payload' => [
                'content_asset_id' => $contentAsset->id,
                'title' => $contentAsset->title,
                'language' => $contentAsset->language,
                'locale' => $contentAsset->locale,
                'excerpt_present' => filled($contentAsset->excerpt),
                'body_words' => str_word_count(strip_tags((string) $contentAsset->body)),
                'scores' => [
                    'overall' => $audit->score,
                    'seo' => $audit->seo_score,
                    'ai_visibility' => $audit->ai_visibility_score,
                    'readability' => $audit->readability_score,
                    'entities' => $audit->entity_score,
                    'answers' => $audit->answer_score,
                ],
                'issues' => $audit->issues,
                'recommendations' => $audit->recommendations,
            ],
            'confidence_score' => $audit->score,
            'captured_at' => $audit->audited_at,
        ]);

        app(DomainEventService::class)->recordForSubject('ContentAuditCompleted', $audit, null, [
            'content_asset_id' => $audit->content_asset_id,
            'language' => $audit->language,
            'locale' => $audit->locale,
            'score' => $audit->score,
            'seo_score' => $audit->seo_score,
            'ai_visibility_score' => $audit->ai_visibility_score,
            'readability_score' => $audit->readability_score,
            'issues_count' => count($audit->issues ?? []),
            'recommendations_count' => count($audit->recommendations ?? []),
        ], $audit->audited_at);

        $this->signals->produce($audit);

        return $audit->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function analyze(ContentAsset $contentAsset): array
    {
        $title = trim((string) $contentAsset->title);
        $excerpt = trim((string) $contentAsset->excerpt);
        $body = trim((string) $contentAsset->body);
        $wordCount = str_word_count(strip_tags($body));
        $hasHeadings = preg_match('/(^|\n)\s*(#{1,3}\s+|<h[1-3][\s>])/i', $body) === 1;
        $hasMetadata = filled($contentAsset->metadata);
        $hasSeoMetadata = filled($contentAsset->seo_metadata);
        $hasAnswerShape = str_contains($body, '?') || preg_match('/(^|\n)\s*(Q:|Question:|FAQ)/i', $body) === 1;

        $issues = [];
        $recommendations = [];

        $seoScore = 45;
        $seoScore += $title !== '' ? 15 : 0;
        $seoScore += strlen($title) >= 30 && strlen($title) <= 70 ? 15 : 0;
        $seoScore += $excerpt !== '' ? 10 : 0;
        $seoScore += $hasSeoMetadata ? 15 : 0;

        if (strlen($title) < 30 || strlen($title) > 70) {
            $issues[] = 'Title length is outside the preferred 30-70 character range.';
            $recommendations[] = 'Tune the title to fit a concise search and answer result format.';
        }

        if ($excerpt === '') {
            $issues[] = 'Excerpt is missing.';
            $recommendations[] = 'Add a short excerpt that summarizes the asset for previews and snippets.';
        }

        $readabilityScore = 45;
        $readabilityScore += $wordCount >= 80 ? 15 : 0;
        $readabilityScore += $wordCount <= 1200 ? 15 : 0;
        $readabilityScore += $hasHeadings ? 15 : 0;
        $readabilityScore += $excerpt !== '' ? 10 : 0;

        if ($wordCount < 80) {
            $issues[] = 'Body content is short for a reusable content asset.';
            $recommendations[] = 'Expand the body with specific proof points, examples and next steps.';
        }

        if (! $hasHeadings) {
            $issues[] = 'No headings were detected in the body.';
            $recommendations[] = 'Add clear H2 or H3 sections so readers and AI systems can parse the structure.';
        }

        $entityScore = 50;
        $entityScore += $hasMetadata ? 25 : 0;
        $entityScore += $hasSeoMetadata ? 15 : 0;
        $entityScore += $contentAsset->property_id !== null || $contentAsset->channel_id !== null ? 10 : 0;

        if (! $hasMetadata) {
            $issues[] = 'Metadata is missing.';
            $recommendations[] = 'Add structured metadata for topics, entities, campaign context or target surface.';
        }

        $answerScore = 45;
        $answerScore += $hasAnswerShape ? 20 : 0;
        $answerScore += $hasHeadings ? 15 : 0;
        $answerScore += $excerpt !== '' ? 10 : 0;
        $answerScore += $wordCount >= 80 ? 10 : 0;

        if (! $hasAnswerShape) {
            $recommendations[] = 'Add direct answer blocks or FAQ-style sections for AI answer extraction.';
        }

        $aiVisibilityScore = (int) round(($entityScore + $answerScore + $seoScore) / 3);
        $score = (int) round(($seoScore + $aiVisibilityScore + $readabilityScore + $entityScore + $answerScore) / 5);

        return [
            'score' => $this->bounded($score),
            'seo_score' => $this->bounded($seoScore),
            'ai_visibility_score' => $this->bounded($aiVisibilityScore),
            'readability_score' => $this->bounded($readabilityScore),
            'entity_score' => $this->bounded($entityScore),
            'answer_score' => $this->bounded($answerScore),
            'issues' => array_values(array_unique($issues)),
            'recommendations' => array_values(array_unique($recommendations)),
            'summary' => "Deterministic {$contentAsset->language} audit completed with {$wordCount} body words.",
        ];
    }

    private function bounded(int $score): int
    {
        return max(0, min(100, $score));
    }
}
