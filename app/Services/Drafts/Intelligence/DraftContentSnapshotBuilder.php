<?php

namespace App\Services\Drafts\Intelligence;

use App\Models\BrandVoice;
use App\Models\Draft;

class DraftContentSnapshotBuilder
{
    public function __construct(
        private readonly DraftStructureParser $parser,
    ) {}

    public function freshDraft(Draft $draft): Draft
    {
        return Draft::query()
            ->with([
                'brief',
                'clientSite.workspace.companyProfile',
                'clientSite.workspace.defaultBrandVoice',
                'clientSite.workspace.brandVoices',
                'articleEntities',
            ])
            ->findOrFail($draft->getKey());
    }

    /**
     * @return array<string,mixed>
     */
    public function build(Draft $draft): array
    {
        $parsed = $this->parser->parse((string) ($draft->content_html ?? ''));
        $brief = $draft->brief;
        $workspace = $draft->clientSite?->workspace;
        $brandVoice = $this->resolveBrandVoice($draft);
        $companyProfile = $workspace?->companyProfile;

        return [
            'draft_id' => (string) $draft->id,
            'title' => (string) ($draft->title ?? ''),
            'content_html' => (string) ($draft->content_html ?? ''),
            'seo_title' => (string) ($draft->seo_title ?? ''),
            'seo_meta_description' => (string) ($draft->seo_meta_description ?? ''),
            'seo_h1' => (string) ($draft->seo_h1 ?? ''),
            'site_url' => (string) ($draft->clientSite?->site_url ?? ''),
            'primary_keyword' => (string) ($brief?->primary_keyword ?? ''),
            'secondary_keywords' => array_values(array_filter((array) ($brief?->secondary_keywords ?? []))),
            'expected_entities' => $this->expectedEntities($draft),
            'target_audience' => (string) ($brief?->target_audience ?: $brief?->audience ?: ''),
            'funnel_stage' => (string) ($brief?->funnel_stage ?? ''),
            'call_to_action' => (string) ($brief?->call_to_action ?? ''),
            'tone_of_voice' => (string) ($brief?->tone_of_voice ?? ''),
            'content_type' => (string) ($brief?->content_type ?? ''),
            'brand_voice' => [
                'name' => (string) ($brandVoice?->name ?? ''),
                'tone_of_voice' => (string) ($brandVoice?->tone_of_voice ?? ''),
                'writing_style' => (string) ($brandVoice?->writing_style ?? ''),
                'style_guide' => (string) ($brandVoice?->style_guide ?? ''),
                'preferred_terminology' => $brandVoice?->preferredTerminologyArray() ?? [],
                'disallowed_terminology' => $brandVoice?->disallowedTerminologyArray() ?? [],
            ],
            'company_profile' => [
                'company_name' => (string) ($companyProfile?->company_name ?? ''),
                'industry' => (string) ($companyProfile?->industry ?? ''),
                'target_audience' => (string) ($companyProfile?->target_audience ?? ''),
                'value_propositions' => $companyProfile?->valuePropositionsArray() ?? [],
                'proof_points' => $companyProfile?->proofPointsArray() ?? [],
                'banned_claims' => $this->splitLines((string) ($companyProfile?->banned_claims ?? '')),
                'compliance_rules' => $this->splitLines((string) ($companyProfile?->compliance_rules ?? '')),
            ],
            'plain_text' => (string) ($parsed['plain_text'] ?? ''),
            'intro' => (string) ($parsed['intro'] ?? ''),
            'conclusion' => (string) ($parsed['conclusion'] ?? ''),
            'headings' => (array) ($parsed['headings'] ?? []),
            'sections' => (array) ($parsed['sections'] ?? []),
            'paragraphs' => (array) ($parsed['paragraphs'] ?? []),
            'cta_candidate_blocks' => (array) ($parsed['cta_candidate_blocks'] ?? []),
            'summary_section_count' => (int) ($parsed['summary_section_count'] ?? 0),
            'faq_section_count' => (int) ($parsed['faq_section_count'] ?? 0),
            'comparison_section_count' => (int) ($parsed['comparison_section_count'] ?? 0),
            'step_section_count' => (int) ($parsed['step_section_count'] ?? 0),
            'definition_passages' => (array) ($parsed['definition_passages'] ?? []),
            'extractable_passages' => (array) ($parsed['extractable_passages'] ?? []),
            'list_count' => (int) ($parsed['list_count'] ?? 0),
            'heading_count' => (int) ($parsed['heading_count'] ?? 0),
            'sentence_count' => (int) ($parsed['sentence_count'] ?? 0),
            'word_count' => (int) ($parsed['word_count'] ?? 0),
            'detected_entities' => $draft->articleEntities->pluck('entity')->filter()->values()->take(20)->all(),
            'snapshot_signature' => $this->snapshotSignature($draft, $parsed),
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    public function snapshotSignatureForSnapshot(array $snapshot): string
    {
        return sha1(implode('|', [
            (string) ($snapshot['title'] ?? ''),
            (string) ($snapshot['seo_title'] ?? ''),
            (string) ($snapshot['seo_meta_description'] ?? ''),
            (string) ($snapshot['seo_h1'] ?? ''),
            (string) ($snapshot['content_html'] ?? ''),
            (string) ($snapshot['primary_keyword'] ?? ''),
            json_encode((array) ($snapshot['secondary_keywords'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
            (string) ($snapshot['call_to_action'] ?? ''),
            (string) ($snapshot['target_audience'] ?? ''),
            (string) ($snapshot['funnel_stage'] ?? ''),
            (string) ($snapshot['tone_of_voice'] ?? ''),
            json_encode((array) ($snapshot['brand_voice'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            json_encode((array) ($snapshot['company_profile'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        ]));
    }

    /**
     * @param array<string,mixed> $parsed
     */
    private function snapshotSignature(Draft $draft, array $parsed): string
    {
        return sha1(implode('|', [
            (string) $draft->title,
            (string) $draft->seo_title,
            (string) $draft->seo_meta_description,
            (string) $draft->seo_h1,
            (string) $draft->content_html,
            (string) ($draft->brief?->primary_keyword ?? ''),
            json_encode((array) ($draft->brief?->secondary_keywords ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
            (string) ($draft->brief?->call_to_action ?? ''),
            (string) ($draft->brief?->target_audience ?: $draft->brief?->audience ?: ''),
            (string) ($draft->brief?->funnel_stage ?? ''),
            (string) ($draft->brief?->tone_of_voice ?? ''),
            json_encode([
                'brand_voice' => [
                    'name' => (string) data_get($this->resolveBrandVoice($draft), 'name', ''),
                    'tone_of_voice' => (string) data_get($this->resolveBrandVoice($draft), 'tone_of_voice', ''),
                    'style_guide' => (string) data_get($this->resolveBrandVoice($draft), 'style_guide', ''),
                ],
                'company_profile' => [
                    'company_name' => (string) ($draft->clientSite?->workspace?->companyProfile?->company_name ?? ''),
                    'industry' => (string) ($draft->clientSite?->workspace?->companyProfile?->industry ?? ''),
                    'target_audience' => (string) ($draft->clientSite?->workspace?->companyProfile?->target_audience ?? ''),
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            (string) ($parsed['word_count'] ?? 0),
            (string) ($parsed['sentence_count'] ?? 0),
        ]));
    }

    /**
     * @return array<int,string>
     */
    private function expectedEntities(Draft $draft): array
    {
        $brief = $draft->brief;

        return collect([
            $brief?->primary_keyword,
            ...((array) ($brief?->secondary_keywords ?? [])),
            ...((array) ($brief?->key_points ?? [])),
            $brief?->unique_angle,
        ])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->take(20)
            ->all();
    }

    private function resolveBrandVoice(Draft $draft): ?BrandVoice
    {
        $workspace = $draft->clientSite?->workspace;
        if (! $workspace) {
            return null;
        }

        $brandVoiceId = trim((string) data_get($draft->meta, 'brand_voice_id', ''));
        if ($brandVoiceId !== '') {
            $selected = $workspace->brandVoices?->firstWhere('id', $brandVoiceId);
            if ($selected instanceof BrandVoice) {
                return $selected;
            }
        }

        $default = $workspace->defaultBrandVoice;

        return $default instanceof BrandVoice ? $default : null;
    }

    /**
     * @return array<int,string>
     */
    private function splitLines(string $value): array
    {
        return collect(preg_split('/\R+/', $value) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values()
            ->all();
    }
}
