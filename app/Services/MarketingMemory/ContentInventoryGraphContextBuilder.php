<?php

namespace App\Services\MarketingMemory;

use App\Models\Content;
use App\Models\ContentPageLink;
use App\Models\MonitoredPage;
use App\Models\PageAlert;
use App\Models\RecommendedAction;
use App\Support\Intelligence\IntelligenceGraphEdge;
use App\Support\Intelligence\IntelligenceGraphEdgeType;
use App\Support\Intelligence\InMemoryIntelligenceGraphProjector;
use App\Support\Intelligence\IntelligenceGraphNode;
use App\Support\Intelligence\IntelligenceGraphProjector;
use App\Support\Intelligence\IntelligenceGraphReference;

class ContentInventoryGraphContextBuilder
{
    /**
     * @return array{nodes:array<int,array<string,mixed>>,edges:array<int,array<string,mixed>>}
     */
    public function graphForPage(MonitoredPage $page): array
    {
        return $this->projectPage($page, new InMemoryIntelligenceGraphProjector())->graph();
    }

    public function projectPage(MonitoredPage $page, IntelligenceGraphProjector $projector): IntelligenceGraphProjector
    {
        $page->loadMissing(['contentPageLinks.content.pageLinks.monitoredPage']);

        $pageRef = $this->pageReference($page);
        $projector->projectNode(new IntelligenceGraphNode($pageRef, metadata: [
            'workspace_id' => $page->workspace_id,
            'client_site_id' => $page->client_site_id,
            'domain' => $page->domain,
            'source_type' => $page->source_type,
        ]));

        foreach ($page->contentPageLinks as $link) {
            if ($link instanceof ContentPageLink && $link->content instanceof Content) {
                $this->projectContent($link->content, $projector);
            }
        }

        $this->projectPageAlertActions([(string) $page->id], $projector);

        return $projector;
    }

    /**
     * @return array{nodes:array<int,array<string,mixed>>,edges:array<int,array<string,mixed>>}
     */
    public function graphForContent(Content $content): array
    {
        return $this->projectContent($content, new InMemoryIntelligenceGraphProjector())->graph();
    }

    public function projectContent(Content $content, IntelligenceGraphProjector $projector): IntelligenceGraphProjector
    {
        $content->loadMissing(['pageLinks.monitoredPage']);

        $contentRef = $this->contentReference($content);
        $projector->projectNode(new IntelligenceGraphNode($contentRef, metadata: [
            'workspace_id' => $content->workspace_id,
            'client_site_id' => $content->client_site_id,
            'lifecycle_stage' => $this->enumValue($content->lifecycle_stage),
            'status' => $content->status,
        ]));

        $pageIds = [];

        foreach ($content->pageLinks as $link) {
            if (! $link instanceof ContentPageLink || ! $link->monitoredPage instanceof MonitoredPage) {
                continue;
            }

            $page = $link->monitoredPage;
            $pageIds[] = (string) $page->id;
            $pageRef = $this->pageReference($page);

            $projector
                ->projectNode(new IntelligenceGraphNode($pageRef, metadata: [
                    'workspace_id' => $page->workspace_id,
                    'client_site_id' => $page->client_site_id,
                    'domain' => $page->domain,
                    'source_type' => $page->source_type,
                ]))
                ->projectEdge(new IntelligenceGraphEdge(
                    IntelligenceGraphEdgeType::DERIVES_FROM,
                    $contentRef,
                    $pageRef,
                    confidence: $this->confidence($link),
                    metadata: [
                        'link_type' => $this->enumValue($link->link_type),
                        'is_primary' => $link->is_primary,
                    ],
                    provenance: [
                        'source' => 'content_page_link',
                        'content_page_link_id' => $link->id,
                    ],
                ));
        }

        $this->projectPageAlertActions(array_values(array_unique($pageIds)), $projector);

        return $projector;
    }

    /**
     * @param  array<int,string>  $pageIds
     */
    private function projectPageAlertActions(array $pageIds, IntelligenceGraphProjector $projector): void
    {
        if ($pageIds === []) {
            return;
        }

        PageAlert::query()
            ->whereIn('monitored_page_id', $pageIds)
            ->with(['page:id,title_current,canonical_url,domain', 'recommendedAction'])
            ->latest('fired_at')
            ->limit(25)
            ->get()
            ->each(function (PageAlert $alert) use ($projector): void {
                $page = $alert->page;
                if (! $page instanceof MonitoredPage) {
                    return;
                }

                $pageRef = $this->pageReference($page);
                $alertRef = IntelligenceGraphReference::make(
                    'page_alert',
                    $alert->id,
                    $alert->title,
                    $alert->id,
                    PageAlert::class,
                    [
                        'trigger' => $alert->trigger,
                        'severity' => $alert->severity,
                        'status' => $alert->status,
                    ],
                );

                $projector
                    ->projectNode(new IntelligenceGraphNode($alertRef))
                    ->projectEdge(new IntelligenceGraphEdge(
                        IntelligenceGraphEdgeType::EVIDENCES,
                        $alertRef,
                        $pageRef,
                        metadata: [
                            'trigger' => $alert->trigger,
                            'severity' => $alert->severity,
                        ],
                        provenance: [
                            'source' => 'page_alert',
                            'page_alert_id' => $alert->id,
                        ],
                    ));

                if ($alert->recommendedAction instanceof RecommendedAction) {
                    $this->projectRecommendedAction($alert, $alert->recommendedAction, $pageRef, $alertRef, $projector);
                }
            });
    }

    private function projectRecommendedAction(
        PageAlert $alert,
        RecommendedAction $action,
        IntelligenceGraphReference $pageRef,
        IntelligenceGraphReference $alertRef,
        IntelligenceGraphProjector $projector,
    ): void {
        $actionRef = IntelligenceGraphReference::action(
            $action->id,
            $action->title,
            [
                'source_group' => $action->source_group,
                'action_type' => $action->action_type,
                'status' => $action->status,
            ],
        );

        $projector
            ->projectNode(new IntelligenceGraphNode($actionRef))
            ->projectEdge(new IntelligenceGraphEdge(
                IntelligenceGraphEdgeType::DERIVES_FROM,
                $actionRef,
                $alertRef,
                confidence: $this->scoreToRatio($action->confidence_score),
                metadata: [
                    'source_signature' => $action->source_signature,
                    'action_category' => data_get($action->metadata, 'action_category'),
                ],
                provenance: [
                    'source' => 'recommended_action',
                    'recommended_action_id' => $action->id,
                    'page_alert_id' => $alert->id,
                ],
            ))
            ->projectEdge(new IntelligenceGraphEdge(
                IntelligenceGraphEdgeType::ACTS_ON,
                $actionRef,
                $pageRef,
                confidence: $this->scoreToRatio($action->confidence_score),
                metadata: [
                    'recommended_next_step' => data_get($action->metadata, 'recommended_next_step'),
                    'priority_label' => $action->priority_label,
                ],
                provenance: [
                    'source' => 'recommended_action',
                    'recommended_action_id' => $action->id,
                ],
            ));
    }

    private function contentReference(Content $content): IntelligenceGraphReference
    {
        return IntelligenceGraphReference::make(
            'content',
            $content->id,
            $content->title,
            $content->id,
            Content::class,
            [
                'published_url' => $content->published_url,
                'canonical_url' => $content->canonical_url,
            ],
        );
    }

    private function pageReference(MonitoredPage $page): IntelligenceGraphReference
    {
        return IntelligenceGraphReference::page($page->id, $page->title_current ?: $page->canonical_url, [
            'canonical_url' => $page->canonical_url,
            'first_seen_url' => $page->first_seen_url,
        ]);
    }

    private function confidence(ContentPageLink $link): ?float
    {
        return $this->scoreToRatio($link->confidence_score);
    }

    private function scoreToRatio(mixed $score): ?float
    {
        if (! is_numeric($score)) {
            return null;
        }

        return max(0.0, min(1.0, ((float) $score) / 100));
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
