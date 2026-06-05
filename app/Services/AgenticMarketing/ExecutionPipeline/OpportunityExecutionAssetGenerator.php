<?php

namespace App\Services\AgenticMarketing\ExecutionPipeline;

use App\Models\AgenticMarketingExecutionAsset;
use App\Models\AgenticMarketingExecutionPipeline;
use App\Models\AgenticMarketingOpportunity;
use App\Models\Brief;
use App\Models\Content;
use App\Models\Draft;
use App\Services\AgenticMarketing\CampaignOrchestration\AutonomousCampaignOrchestrationEngine;
use App\Services\AgenticMarketing\StrategicPlanning\StrategicPlanningEngine;
use App\Services\AgenticMarketing\VisibilityScoring\AIVisibilityScoringEngine;
use Illuminate\Support\Str;

class OpportunityExecutionAssetGenerator
{
    public function __construct(
        private readonly StrategicPlanningEngine $strategicPlanningEngine,
        private readonly AIVisibilityScoringEngine $visibilityScoringEngine,
        private readonly AutonomousCampaignOrchestrationEngine $campaignOrchestrationEngine,
    ) {}

    /**
     * @return array<int, AgenticMarketingExecutionAsset>
     */
    public function generate(AgenticMarketingExecutionPipeline $pipeline): array
    {
        $opportunity = $pipeline->opportunity()->with(['objective', 'content'])->firstOrFail();
        $assets = [];

        $brief = $this->createBrief($opportunity);
        $assets[] = $this->asset($pipeline, 'content_brief', 'Content brief', $this->briefPayload($opportunity), $brief);

        $draft = $this->createDraft($opportunity, $brief);
        $assets[] = $this->asset($pipeline, 'draft_content', 'Draft content', $this->draftPayload($opportunity), $draft);

        foreach ($this->proposalAssets($opportunity) as $proposal) {
            $assets[] = $this->asset($pipeline, $proposal['type'], $proposal['title'], $proposal['payload']);
        }

        return $assets;
    }

    private function createBrief(AgenticMarketingOpportunity $opportunity): ?Brief
    {
        $siteId = $opportunity->objective?->client_site_id ?: data_get($opportunity->payload, 'client_site_id');
        if (! $siteId) {
            return null;
        }

        return Brief::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => (string) $siteId,
            'content_id' => $opportunity->content_id,
            'status' => 'draft',
            'source' => 'agentic_marketing_execution',
            'progress' => 0,
            'title' => $opportunity->title,
            'language' => (string) ($opportunity->objective?->locale ?: data_get($opportunity->payload, 'locale', 'en')),
            'content_type' => 'blog',
            'intent' => (string) data_get($opportunity->payload, 'primary_search_intent', data_get($opportunity->payload, 'intent', 'informational')),
            'funnel_stage' => (string) data_get($opportunity->payload, 'funnel_stage', 'consideration'),
            'search_intent' => (string) data_get($opportunity->payload, 'primary_search_intent', 'informational'),
            'primary_keyword' => (string) data_get($opportunity->payload, 'primary_keyword', data_get($opportunity->payload, 'topic', '')),
            'audience' => (string) data_get($opportunity->payload, 'target_audience', $opportunity->objective?->audience ?: ''),
            'unique_angle' => (string) data_get($opportunity->payload, 'angle', data_get($opportunity->payload, 'recommendation', '')),
            'call_to_action' => (string) data_get($opportunity->payload, 'suggested_cta', 'Explore Argusly'),
            'client_refs' => [
                'source' => 'agentic_marketing_execution_pipeline',
                'opportunity_id' => (string) $opportunity->id,
                'review_required' => true,
            ],
        ]);
    }

    private function createDraft(AgenticMarketingOpportunity $opportunity, ?Brief $brief): ?Draft
    {
        $siteId = $brief?->client_site_id ?: $opportunity->objective?->client_site_id;
        if (! $siteId || ! $brief) {
            return null;
        }

        $title = (string) $opportunity->title;

        return Draft::query()->create([
            'id' => (string) Str::uuid(),
            'brief_id' => (string) $brief->id,
            'content_id' => $opportunity->content_id,
            'client_site_id' => (string) $siteId,
            'status' => 'generated',
            'title' => $title,
            'seo_title' => Str::limit($title, 68, ''),
            'seo_meta_description' => Str::limit($this->summary($opportunity), 158, ''),
            'seo_h1' => $title,
            'schema_type' => (string) data_get($opportunity->payload, 'suggested_schema', 'Article'),
            'output_type' => 'kb_article',
            'language' => (string) ($opportunity->objective?->locale ?: 'en'),
            'content_html' => '<h1>'.e($title).'</h1><p>'.$this->summary($opportunity).'</p><h2>Recommended angle</h2><p>'.e((string) data_get($opportunity->payload, 'angle', 'Review and expand this opportunity before handing it off for publication.')).'</p>',
            'delivery_status' => 'pending',
            'meta' => [
                'source' => 'agentic_marketing_execution_pipeline',
                'opportunity_id' => (string) $opportunity->id,
                'review_required' => true,
                'publishing_readiness' => 'needs_review',
            ],
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function proposalAssets(AgenticMarketingOpportunity $opportunity): array
    {
        $context = $this->executionContext($opportunity);
        $title = $context['title'];
        $topic = $context['topic'];

        return [
            ['type' => 'strategic_cluster_proposal', 'title' => 'Strategic cluster proposal', 'payload' => $this->strategicPlanningEngine->clusterProposalForOpportunity($opportunity)],
            ['type' => 'autonomous_campaign_plan', 'title' => 'Autonomous campaign plan', 'payload' => $this->campaignOrchestrationEngine->planForOpportunity($opportunity)],
            ['type' => 'ai_visibility_scorecard', 'title' => 'AI visibility scorecard', 'payload' => $this->visibilityScoringEngine->scoreOpportunity($opportunity)],
            ['type' => 'execution_graph', 'title' => 'Execution graph', 'payload' => $this->executionGraph($context)],
            ['type' => 'answer_blocks', 'title' => 'Answer block set', 'payload' => [
                'blocks' => $this->answerBlocks($context),
            ]],
            ['type' => 'faq_set', 'title' => 'FAQ set', 'payload' => [
                'faqs' => $this->faqSet($context),
            ]],
            ['type' => 'structured_summary', 'title' => 'Structured summaries', 'payload' => [
                'summaries' => $this->structuredSummaries($context),
            ]],
            ['type' => 'content_diff_preview', 'title' => 'Content diff preview', 'payload' => $this->contentDiffPreview($opportunity, $context)],
            ['type' => 'internal_link_suggestions', 'title' => 'Internal link suggestions', 'payload' => [
                'links' => $this->internalLinkSuggestions($opportunity, $context),
            ]],
            ['type' => 'schema_markup', 'title' => 'Schema markup', 'payload' => [
                'schema' => $this->schemaMarkup($context),
            ]],
            ['type' => 'metadata', 'title' => 'SEO metadata', 'payload' => [
                'seo_title' => Str::limit($title, 68, ''),
                'seo_meta_description' => Str::limit($context['summary'], 158, ''),
            ]],
            ['type' => 'cta_suggestions', 'title' => 'CTA suggestions', 'payload' => [
                'suggestions' => $this->ctaSuggestions($context),
            ]],
            ['type' => 'linkedin_post', 'title' => 'LinkedIn handoff copy', 'payload' => $this->linkedinPost($context)],
            ['type' => 'reviewer_flow', 'title' => 'Reviewer flow', 'payload' => [
                'required_role' => 'editor',
                'checklist' => ['Brief approved', 'Draft reviewed', 'Metadata checked', 'Publishing destination confirmed'],
            ]],
            ['type' => 'campaign_task', 'title' => 'Campaign task', 'payload' => [
                'task' => 'Prepare campaign support for '.$title,
                'status' => 'pending',
            ]],
            ['type' => 'automation_schedule', 'title' => 'Automation schedule', 'payload' => [
                'recommended' => true,
                'frequency' => 'weekly',
                'mode' => 'draft_only',
            ]],
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function executionGraph(array $context): array
    {
        $topic = (string) $context['topic'];

        $nodes = [
            $this->graphNode('generate_answer_blocks', 'Generate answer blocks', 'content_generation', 'completed', [], ['answer_blocks'], 'Create direct answer blocks for the highest-intent questions around '.$topic.'.'),
            $this->graphNode('add_faq_schema', 'Add FAQ schema', 'technical_seo', 'completed', ['generate_answer_blocks'], ['faq_set', 'schema_markup'], 'Turn the answer set into reviewable FAQ content and structured data.'),
            $this->graphNode('add_internal_links', 'Add internal links', 'content_optimization', 'completed', ['generate_answer_blocks'], ['internal_link_suggestions'], 'Connect the asset to supporting pages and authority-building context.'),
            $this->graphNode('add_cta', 'Add CTA', 'conversion', 'completed', ['generate_answer_blocks'], ['cta_suggestions'], 'Place conversion prompts after the reader has enough context.'),
            $this->graphNode('prepare_linkedin_handoff', 'Prepare LinkedIn handoff copy', 'social_handoff', 'completed', ['generate_answer_blocks'], ['linkedin_post'], 'Create copy-ready social content from the same answer-ready angle for use in an external publishing tool.'),
            $this->graphNode('refresh_metadata', 'Refresh metadata', 'technical_seo', 'completed', ['generate_answer_blocks'], ['metadata'], 'Update title and meta description to match the new answer intent.'),
            $this->graphNode('queue_republish', 'Queue republish', 'publishing', 'pending_approval', ['add_faq_schema', 'add_internal_links', 'add_cta', 'refresh_metadata'], ['content_diff_preview'], 'Prepare the content change for republishing after approval.'),
            $this->graphNode('schedule_lifecycle_review', 'Schedule lifecycle review', 'governance', 'pending_approval', ['queue_republish'], ['automation_schedule'], 'Schedule a follow-up review so the content stays fresh after publication.'),
        ];

        return [
            'opportunity' => (string) $context['title'],
            'goal' => (string) $context['objective_goal'],
            'graph_type' => 'agentic_execution',
            'nodes' => $nodes,
            'edges' => $this->graphEdges($nodes),
            'critical_path' => ['generate_answer_blocks', 'add_faq_schema', 'refresh_metadata', 'queue_republish', 'schedule_lifecycle_review'],
            'approval_gates' => ['queue_republish', 'schedule_lifecycle_review'],
        ];
    }

    /**
     * @param array<int,string> $dependsOn
     * @param array<int,string> $produces
     * @return array<string,mixed>
     */
    private function graphNode(string $id, string $label, string $stage, string $status, array $dependsOn, array $produces, string $description): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'stage' => $stage,
            'status' => $status,
            'depends_on' => $dependsOn,
            'produces' => $produces,
            'description' => $description,
            'requires_approval' => in_array($status, ['pending_approval', 'ready_for_approval'], true),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $nodes
     * @return array<int,array{from:string,to:string}>
     */
    private function graphEdges(array $nodes): array
    {
        $edges = [];
        foreach ($nodes as $node) {
            foreach ((array) ($node['depends_on'] ?? []) as $dependency) {
                $edges[] = ['from' => (string) $dependency, 'to' => (string) $node['id']];
            }
        }

        return $edges;
    }

    /**
     * @return array<string,mixed>
     */
    private function executionContext(AgenticMarketingOpportunity $opportunity): array
    {
        $title = trim((string) $opportunity->title);
        $topic = trim((string) data_get($opportunity->payload, 'topic', $title));
        $topic = $topic !== '' ? $topic : $title;
        $entities = array_values(array_filter(array_map(
            fn (mixed $entity): string => trim((string) $entity),
            (array) data_get($opportunity->payload, 'related_entities', [])
        )));
        $audience = trim((string) data_get($opportunity->payload, 'target_audience', $opportunity->objective?->audience ?: 'the target audience'));
        $intent = trim((string) data_get($opportunity->payload, 'primary_search_intent', data_get($opportunity->payload, 'intent', 'informational')));
        $angle = trim((string) data_get($opportunity->payload, 'angle', data_get($opportunity->payload, 'recommendation', '')));
        $summary = trim($this->summary($opportunity));
        $objectiveGoal = trim((string) ($opportunity->objective?->goal ?: ''));
        $fullName = $this->fullNameForTopic($topic, $entities);

        return [
            'title' => $title,
            'topic' => $topic,
            'topic_label' => $fullName && ! str_contains(Str::lower($topic), Str::lower($fullName))
                ? $fullName.' ('.$topic.')'
                : $topic,
            'full_name' => $fullName,
            'entities' => $entities,
            'audience' => $audience,
            'intent' => $intent,
            'angle' => $angle,
            'summary' => $summary !== '' ? $summary : $this->definitionSentence($topic, $fullName, $entities),
            'objective_goal' => $objectiveGoal,
            'cta' => trim((string) data_get($opportunity->payload, 'suggested_cta', 'Explore Argusly')),
            'schema_type' => trim((string) data_get($opportunity->payload, 'suggested_schema', 'Article')) ?: 'Article',
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    private function answerBlocks(array $context): array
    {
        $topic = (string) $context['topic'];
        $audience = (string) $context['audience'];
        $entities = (array) $context['entities'];

        return [
            [
                'question' => 'What is '.$topic.' and why does it matter?',
                'answer' => $this->definitionAnswer($context),
                'entities' => $entities,
                'intent' => 'definition',
            ],
            [
                'question' => 'How does '.$topic.' help '.$audience.'?',
                'answer' => $this->benefitAnswer($context),
                'entities' => $entities,
                'intent' => 'benefit',
            ],
            [
                'question' => 'What should a team include in a '.$topic.' workflow?',
                'answer' => $this->workflowAnswer($context),
                'entities' => $entities,
                'intent' => 'implementation',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,array<string,string>>
     */
    private function faqSet(array $context): array
    {
        $topic = (string) $context['topic'];

        return [
            ['question' => 'What is '.$topic.'?', 'answer' => $this->definitionAnswer($context)],
            ['question' => 'Why is '.$topic.' important now?', 'answer' => $this->whyNowAnswer($context)],
            ['question' => 'How do you start with '.$topic.'?', 'answer' => $this->workflowAnswer($context)],
            ['question' => 'How should '.$topic.' be measured?', 'answer' => $this->measurementAnswer($context)],
            ['question' => 'What is the next best action for '.$topic.'?', 'answer' => $this->nextActionAnswer($context)],
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function structuredSummaries(array $context): array
    {
        $topic = (string) $context['topic'];
        $entities = array_slice((array) $context['entities'], 0, 6);

        return [
            'one_sentence' => $this->definitionAnswer($context),
            'executive_summary' => $this->benefitAnswer($context).' '.$this->workflowAnswer($context),
            'answer_ready_summary' => $this->definitionSentence($topic, $context['full_name'] ?: null, $entities),
            'key_takeaways' => [
                $topic.' should answer the core question directly before adding nuance.',
                'Use structured context, entities, internal links, and schema so answer engines can interpret the page.',
                'Connect the content to a clear CTA and measurable business outcome.',
            ],
            'entities_to_cover' => $entities,
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,array<string,string>>
     */
    private function ctaSuggestions(array $context): array
    {
        $topic = (string) $context['topic'];
        $audience = (string) $context['audience'];
        $baseCta = (string) $context['cta'];

        return [
            [
                'label' => $baseCta,
                'placement' => 'after_intro_answer_block',
                'rationale' => 'Use this after the first definition answer while intent is still active.',
            ],
            [
                'label' => 'See how Argusly supports '.$topic,
                'placement' => 'mid_article',
                'rationale' => 'Connect the education section to a product-led next step for '.$audience.'.',
            ],
            [
                'label' => 'Turn this '.$topic.' plan into a governed content workflow',
                'placement' => 'bottom',
                'rationale' => 'Use this after the implementation summary when the reader is ready to act.',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function linkedinPost(array $context): array
    {
        $topic = (string) $context['topic'];
        $summary = (string) $context['summary'];
        $cta = (string) $context['cta'];

        return [
            'format' => 'linkedin_post',
            'publication_mode' => 'external_tool_handoff',
            'handoff_note' => 'Prepared by Argusly for copy/export. Publish through the configured external social publishing workflow.',
            'hook' => $topic.' is becoming a visibility problem, not just a content topic.',
            'body' => [
                $summary,
                'The teams that win AI discovery will make their content easier to quote, summarize, connect, and trust.',
                'That means direct answer blocks, FAQ schema, internal links, metadata alignment, and a clear next step on the page.',
            ],
            'cta' => $cta,
            'hashtags' => ['#AIVisibility', '#AEO', '#ContentStrategy'],
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    private function internalLinkSuggestions(AgenticMarketingOpportunity $opportunity, array $context): array
    {
        $links = [];
        foreach ((array) data_get($opportunity->payload, 'recommended_internal_links', data_get($opportunity->payload, 'supporting_existing_content', [])) as $link) {
            if (is_array($link)) {
                $links[] = array_filter([
                    'title' => trim((string) ($link['title'] ?? $link['label'] ?? '')),
                    'url' => trim((string) ($link['url'] ?? $link['href'] ?? '')),
                    'anchor_text' => trim((string) ($link['anchor_text'] ?? $link['anchor'] ?? $link['title'] ?? '')),
                    'reason' => trim((string) ($link['reason'] ?? 'Supports the execution topic.')),
                    'source' => 'opportunity_payload',
                ]);
            } elseif (trim((string) $link) !== '') {
                $links[] = [
                    'title' => trim((string) $link),
                    'anchor_text' => trim((string) $link),
                    'reason' => 'Supports the execution topic.',
                    'source' => 'opportunity_payload',
                ];
            }
        }

        $workspaceId = $opportunity->objective?->workspace_id;
        if ($workspaceId) {
            Content::query()
                ->where('workspace_id', $workspaceId)
                ->when($opportunity->content_id, fn ($query) => $query->whereKeyNot($opportunity->content_id))
                ->orderByDesc('updated_at')
                ->limit(12)
                ->get(['id', 'title', 'published_url', 'seo_title', 'primary_keyword'])
                ->map(fn (Content $content): array => [
                    'title' => $content->seo_title ?: $content->title,
                    'url' => $content->published_url,
                    'anchor_text' => $this->anchorText($content, $context),
                    'reason' => $this->linkReason($content, $context),
                    'content_id' => (string) $content->id,
                    'source' => 'workspace_content',
                ])
                ->filter(fn (array $link): bool => $this->linkMatchesContext($link, $context))
                ->take(5)
                ->each(function (array $link) use (&$links): void {
                    $links[] = array_filter($link, fn (mixed $value): bool => $value !== null && $value !== '');
                });
        }

        if ($links === []) {
            $links[] = [
                'title' => (string) $context['topic'].' pillar page',
                'anchor_text' => Str::lower((string) $context['topic']).' strategy',
                'reason' => 'Create or select a pillar page that explains the broader topic before publishing this asset.',
                'source' => 'generated_gap',
            ];
        }

        return array_values(array_slice($links, 0, 8));
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function schemaMarkup(array $context): array
    {
        $faqs = $this->faqSet($context);

        return [
            '@context' => 'https://schema.org',
            '@type' => (string) $context['schema_type'],
            'headline' => (string) $context['title'],
            'about' => (string) $context['topic'],
            'audience' => (string) $context['audience'],
            'mainEntity' => array_map(fn (array $faq): array => [
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $faq['answer'],
                ],
            ], array_slice($faqs, 0, 4)),
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function contentDiffPreview(AgenticMarketingOpportunity $opportunity, array $context): array
    {
        $beforeHtml = $this->beforeContentHtml($opportunity);
        $afterHtml = $this->afterContentHtml($beforeHtml, $context, $this->internalLinkSuggestions($opportunity, $context));
        $beforeLines = $this->htmlPreviewLines($beforeHtml);
        $afterLines = $this->htmlPreviewLines($afterHtml);
        $lineDiff = $this->lineDiff($beforeLines, $afterLines);

        return [
            'summary' => 'AI content git diff for reviewer approval.',
            'before' => [
                'label' => $beforeHtml !== '' ? 'Existing content' : 'No existing content',
                'html' => $beforeHtml,
                'preview_lines' => $beforeLines,
            ],
            'after' => [
                'label' => 'Proposed content',
                'html' => $afterHtml,
                'preview_lines' => $afterLines,
            ],
            'diff' => [
                'format' => 'line_based',
                'lines' => $lineDiff,
                'text' => implode("\n", array_map(
                    fn (array $line): string => ($line['type'] === 'added' ? '+ ' : ($line['type'] === 'removed' ? '- ' : '  ')).$line['text'],
                    $lineDiff
                )),
            ],
            'highlights' => [
                ['type' => 'added_answer_block', 'label' => 'Added answer block', 'count' => count($this->answerBlocks($context)), 'status' => 'added'],
                ['type' => 'inserted_faq', 'label' => 'Inserted FAQ', 'count' => count($this->faqSet($context)), 'status' => 'inserted'],
                ['type' => 'added_schema', 'label' => 'Added schema', 'schema_type' => (string) $context['schema_type'], 'status' => 'added'],
                ['type' => 'inserted_internal_links', 'label' => 'Inserted internal links', 'count' => count($this->internalLinkSuggestions($opportunity, $context)), 'status' => 'inserted'],
            ],
        ];
    }

    private function beforeContentHtml(AgenticMarketingOpportunity $opportunity): string
    {
        $content = $opportunity->content;
        if (! $content) {
            return '';
        }

        $content->loadMissing(['currentRevision', 'currentVersion']);

        return trim((string) (
            $content->currentRevision?->content_html
            ?: $content->currentVersion?->body
            ?: data_get($content->internal_links_meta, 'content_html')
            ?: ''
        ));
    }

    /**
     * @param array<string,mixed> $context
     * @param array<int,array<string,mixed>> $links
     */
    private function afterContentHtml(string $beforeHtml, array $context, array $links): string
    {
        $html = trim($beforeHtml);
        if ($html === '') {
            $html = '<h1>'.e((string) $context['title']).'</h1>'
                .'<p>'.e((string) $context['summary']).'</p>';
        }

        $html .= "\n\n".$this->answerBlockHtml($context);
        $html .= "\n\n".$this->faqHtml($context);
        $html .= "\n\n".$this->internalLinksHtml($links);
        $html .= "\n\n".$this->ctaHtml($context);
        $html .= "\n\n".$this->schemaHtml($context);

        return $html;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function answerBlockHtml(array $context): string
    {
        $items = collect($this->answerBlocks($context))
            ->map(fn (array $block): string => '<div class="answer-block"><h3>'.e($block['question']).'</h3><p>'.e($block['answer']).'</p></div>')
            ->implode("\n");

        return '<section data-pl-change="added_answer_block"><h2>Answer blocks</h2>'.$items.'</section>';
    }

    /**
     * @param array<string,mixed> $context
     */
    private function faqHtml(array $context): string
    {
        $items = collect($this->faqSet($context))
            ->map(fn (array $faq): string => '<details><summary>'.e($faq['question']).'</summary><p>'.e($faq['answer']).'</p></details>')
            ->implode("\n");

        return '<section data-pl-change="inserted_faq"><h2>FAQ</h2>'.$items.'</section>';
    }

    /**
     * @param array<int,array<string,mixed>> $links
     */
    private function internalLinksHtml(array $links): string
    {
        $items = collect($links)
            ->map(function (array $link): string {
                $anchor = e((string) ($link['anchor_text'] ?? $link['title'] ?? 'Related content'));
                $url = trim((string) ($link['url'] ?? ''));

                return '<li>'.($url !== '' ? '<a href="'.e($url).'">'.$anchor.'</a>' : $anchor).'</li>';
            })
            ->implode("\n");

        return '<section data-pl-change="inserted_internal_links"><h2>Related reading</h2><ul>'.$items.'</ul></section>';
    }

    /**
     * @param array<string,mixed> $context
     */
    private function ctaHtml(array $context): string
    {
        $cta = $this->ctaSuggestions($context)[0]['label'] ?? (string) $context['cta'];

        return '<section data-pl-change="inserted_cta"><p><strong>'.e($cta).'</strong></p></section>';
    }

    /**
     * @param array<string,mixed> $context
     */
    private function schemaHtml(array $context): string
    {
        return '<script type="application/ld+json" data-pl-change="added_schema">'
            .json_encode($this->schemaMarkup($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            .'</script>';
    }

    /**
     * @return array<int,string>
     */
    private function htmlPreviewLines(string $html): array
    {
        $html = preg_replace('/<(h[1-6]|p|li|summary|script|section|details|div)\b[^>]*>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\/(h[1-6]|p|li|summary|script|section|details|div)>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        return array_values(array_filter(array_map(
            fn (string $line): string => trim(preg_replace('/\s+/', ' ', $line) ?? $line),
            preg_split('/\R+/', $text) ?: []
        ), fn (string $line): bool => $line !== ''));
    }

    /**
     * @param array<int,string> $before
     * @param array<int,string> $after
     * @return array<int,array{type:string,text:string}>
     */
    private function lineDiff(array $before, array $after): array
    {
        $m = count($before);
        $n = count($after);
        $lcs = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = $m - 1; $i >= 0; $i--) {
            for ($j = $n - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $before[$i] === $after[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $diff = [];
        $i = 0;
        $j = 0;
        while ($i < $m && $j < $n) {
            if ($before[$i] === $after[$j]) {
                $diff[] = ['type' => 'context', 'text' => $before[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $diff[] = ['type' => 'removed', 'text' => $before[$i]];
                $i++;
            } else {
                $diff[] = ['type' => 'added', 'text' => $after[$j]];
                $j++;
            }
        }

        while ($i < $m) {
            $diff[] = ['type' => 'removed', 'text' => $before[$i]];
            $i++;
        }

        while ($j < $n) {
            $diff[] = ['type' => 'added', 'text' => $after[$j]];
            $j++;
        }

        return $diff;
    }

    private function asset(AgenticMarketingExecutionPipeline $pipeline, string $type, string $title, array $payload, mixed $model = null): AgenticMarketingExecutionAsset
    {
        return AgenticMarketingExecutionAsset::query()->create([
            'pipeline_id' => (string) $pipeline->id,
            'objective_id' => (string) $pipeline->objective_id,
            'opportunity_id' => (string) $pipeline->opportunity_id,
            'type' => $type,
            'status' => 'generated',
            'title' => $title,
            'payload' => $payload,
            'assetable_type' => $model ? $model->getMorphClass() : null,
            'assetable_id' => $model?->getKey(),
            'requires_approval' => true,
        ]);
    }

    private function briefPayload(AgenticMarketingOpportunity $opportunity): array
    {
        return [
            'title' => $opportunity->title,
            'reasoning' => data_get($opportunity->payload, 'reasoning', data_get($opportunity->payload, 'reason')),
            'audience' => data_get($opportunity->payload, 'target_audience', $opportunity->objective?->audience),
            'funnel_stage' => data_get($opportunity->payload, 'funnel_stage'),
            'intent' => data_get($opportunity->payload, 'primary_search_intent'),
            'angle' => data_get($opportunity->payload, 'angle'),
        ];
    }

    private function draftPayload(AgenticMarketingOpportunity $opportunity): array
    {
        return [
            'title' => $opportunity->title,
            'outline' => ['Introduction', 'Why this matters', 'Implementation path', 'FAQ', 'CTA'],
            'review_required' => true,
        ];
    }

    private function summary(AgenticMarketingOpportunity $opportunity): string
    {
        return (string) (data_get($opportunity->payload, 'why_this_matters')
            ?: data_get($opportunity->payload, 'reasoning')
            ?: data_get($opportunity->payload, 'reason')
            ?: 'Prepared from an Agentic Marketing opportunity for editorial review.');
    }

    /**
     * @param array<int,string> $entities
     */
    private function fullNameForTopic(string $topic, array $entities): ?string
    {
        foreach ($entities as $entity) {
            if (preg_match('/\b[A-Z]{2,}\b/', $topic) === 1
                && preg_match('/\b'.preg_quote($topic, '/').'\b/i', $entity) !== 1
                && str_contains($entity, ' ')) {
                return $entity;
            }
        }

        return match (Str::upper($topic)) {
            'GEO' => 'Generative Engine Optimization',
            'AEO' => 'Answer Engine Optimization',
            'SEO' => 'Search Engine Optimization',
            default => null,
        };
    }

    /**
     * @param array<string,mixed> $context
     */
    private function definitionAnswer(array $context): string
    {
        $topic = (string) $context['topic'];
        $fullName = $context['full_name'] ?: null;
        $entities = (array) $context['entities'];

        return $this->definitionSentence($topic, $fullName, $entities);
    }

    /**
     * @param array<int,string> $entities
     */
    private function definitionSentence(string $topic, ?string $fullName, array $entities): string
    {
        $label = $fullName ? $fullName.' ('.$topic.')' : $topic;
        $entityText = $entities !== []
            ? ' across '.implode(', ', array_slice($entities, 0, 4))
            : ' across the topic, audience, and source context';

        return $label.' helps content become easier to discover, interpret, and reuse inside AI generated answers by improving structured context, semantic relevance, answer readiness, and clear source signals'.$entityText.'.';
    }

    /**
     * @param array<string,mixed> $context
     */
    private function benefitAnswer(array $context): string
    {
        $topic = (string) $context['topic'];
        $audience = (string) $context['audience'];
        $goal = trim((string) $context['objective_goal']);

        $outcome = $goal !== ''
            ? ' It supports the goal: '.$goal
            : ' It turns topic coverage into measurable visibility, engagement, and conversion signals.';

        return $topic.' helps '.$audience.' move from isolated content production to an answer-ready system with clearer entities, stronger internal context, and better-fit calls to action.'.$outcome;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function workflowAnswer(array $context): string
    {
        $topic = (string) $context['topic'];
        $angle = trim((string) $context['angle']);
        $angleSentence = $angle !== '' ? ' The recommended angle is: '.$angle : '';

        return 'A strong '.$topic.' workflow defines the target question, maps related entities, writes a direct answer first, expands with evidence and examples, adds FAQ and schema markup, links to supporting content, and places a relevant CTA after the reader has enough context.'.$angleSentence;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function whyNowAnswer(array $context): string
    {
        $topic = (string) $context['topic'];
        $summary = (string) $context['summary'];

        return $topic.' matters now because AI search and answer engines increasingly select content that is explicit, structured, entity-rich, and easy to summarize. '.$summary;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function measurementAnswer(array $context): string
    {
        $topic = (string) $context['topic'];

        return 'Measure '.$topic.' with a mix of answer visibility, rankings for high-intent questions, structured answer coverage, internal link depth, assisted conversions, demo or signup intent, and refresh performance after publication.';
    }

    /**
     * @param array<string,mixed> $context
     */
    private function nextActionAnswer(array $context): string
    {
        $topic = (string) $context['topic'];
        $cta = (string) $context['cta'];

        return 'The next best action is to publish a focused '.$topic.' asset with answer blocks, FAQ schema, internal links, metadata, and a clear CTA such as "'.$cta.'".';
    }

    /**
     * @param array<string,mixed> $context
     */
    private function anchorText(Content $content, array $context): string
    {
        $keyword = trim((string) $content->primary_keyword);

        return $keyword !== '' ? $keyword : Str::lower((string) ($content->seo_title ?: $content->title));
    }

    /**
     * @param array<string,mixed> $context
     */
    private function linkReason(Content $content, array $context): string
    {
        $topic = (string) $context['topic'];

        return 'Supports '.$topic.' by giving readers a related page for background, proof, or implementation detail.';
    }

    /**
     * @param array<string,mixed> $link
     * @param array<string,mixed> $context
     */
    private function linkMatchesContext(array $link, array $context): bool
    {
        $haystack = Str::lower(implode(' ', array_filter([
            $link['title'] ?? '',
            $link['anchor_text'] ?? '',
            $link['reason'] ?? '',
        ])));
        $needles = array_filter(array_merge(
            explode(' ', Str::lower((string) $context['topic'])),
            array_map(fn (string $entity): string => Str::lower($entity), (array) $context['entities'])
        ));

        foreach ($needles as $needle) {
            $needle = trim($needle);
            if (mb_strlen($needle) >= 4 && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return ($link['source'] ?? null) === 'workspace_content';
    }
}
