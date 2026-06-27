<?php

namespace App\Http\Controllers;

use App\Models\MarketingPage;
use App\Support\LocalizedMarketingUrl;
use App\Support\MarketingRouteSegments;
use Illuminate\View\View;

class PublicAgenticMarketingOperatingSystemController extends Controller
{
    public function __invoke(): View
    {
        $locale = (string) app()->getLocale();
        $copy = $this->copy($locale);
        $canonicalUrl = LocalizedMarketingUrl::route('public.agentic-marketing-operating-system', [], $locale);

        return view('public.agentic-marketing-operating-system', [
            'publicLang' => $locale,
            'metaTitle' => $copy['meta_title'],
            'metaDescription' => $copy['meta_description'],
            'canonicalUrl' => $canonicalUrl,
            'hreflangUrls' => collect(app(MarketingRouteSegments::class)->locales())
                ->mapWithKeys(fn (string $hreflang): array => [
                    $hreflang => LocalizedMarketingUrl::route('public.agentic-marketing-operating-system', [], $hreflang),
                ])
                ->all(),
            'copy' => $copy,
            'links' => $this->links($locale),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function links(string $locale): array
    {
        $links = [
            'contact' => LocalizedMarketingUrl::route('public.company.contact', ['subject' => 'agentic-marketing-operating-system'], $locale) . '#contact-form',
            'ai_visibility_solution' => LocalizedMarketingUrl::route('public.solutions.ai-visibility', [], $locale),
            'opportunity_intelligence' => LocalizedMarketingUrl::route('public.solutions.opportunity-intelligence', [], $locale),
            'competitive_intelligence' => LocalizedMarketingUrl::route('public.solutions.competitive-intelligence', [], $locale),
            'autonomous_marketing' => LocalizedMarketingUrl::route('public.agentic-marketing', [], $locale),
            'platform_overview' => LocalizedMarketingUrl::route('public.product.platform', [], $locale),
            'how_it_works' => LocalizedMarketingUrl::route('landing', [], $locale) . '#how',
            'governance_security' => LocalizedMarketingUrl::route('public.product.platform', [], $locale) . '#governance',
            'integrations' => LocalizedMarketingUrl::route('public.product.platform', [], $locale) . '#capabilities',
            'blog' => LocalizedMarketingUrl::route('public.blog.index', [], $locale),
        ];

        if (LocalizedMarketingUrl::supportsRoute('pricing')) {
            $links['pricing'] = LocalizedMarketingUrl::route('pricing', [], $locale);
        }

        foreach ([
            'ai_visibility_agentic_marketing' => 'ai_visibility_agentic_marketing',
            'ai_search_geo' => 'ai_search',
        ] as $linkKey => $pageKey) {
            if (MarketingPage::query()->where('key', $pageKey)->exists()) {
                $links[$linkKey] = LocalizedMarketingUrl::page($pageKey, $locale);
            }
        }

        return $links;
    }

    /**
     * @return array<string, mixed>
     */
    private function copy(string $locale): array
    {
        if ($locale === 'nl') {
            return [
                'meta_title' => 'Agentic Marketing Operating System | Argusly',
                'meta_description' => 'Ontdek wat een Agentic Marketing Operating System is en hoe Argusly AI-zichtbaarheid, opportunity intelligence, campagneplanning, content governance, publicatie en leren verbindt.',
                'eyebrow' => 'Agentic Marketing Operating System',
                'h1' => 'Van marktintelligentie naar autonome marketinguitvoering.',
                'intro' => 'Een Agentic Marketing Operating System verbindt signalen, AI-zichtbaarheid, opportunity intelligence, campagneplanning, contentgeneratie, governance, publicatie en leren in één beheerde marketingworkflow.',
                'primary_cta' => 'Plan walkthrough',
                'secondary_cta' => 'Ontdek AI Visibility',
                'hero_stats' => [
                    ['Signalen', 'Markt, AI search, concurrenten en contentkwaliteit'],
                    ['Beslissingen', 'Prioriteiten, risico, effort en kanaalkeuzes'],
                    ['Uitvoering', 'Campagnes, content, approvals en publishing'],
                ],
                'definition' => [
                    'title' => 'Wat is een Agentic Marketing Operating System?',
                    'body' => 'Een Agentic Marketing Operating System is een governed marketingsysteem waarin AI-agents en workflows kansen ontdekken, werk prioriteren, campagnes plannen, content genereren, approvals ondersteunen, publiceren over kanalen en leren van resultaten.',
                    'distinction' => 'Het is niet alleen een visibility dashboard, contentgenerator, marketing automation tool, losse set AI-agents of copilot. Het is een operationele laag voor moderne marketinguitvoering.',
                    'items' => ['Geen los dashboard', 'Geen generieke AI-writer', 'Geen klassieke automation', 'Geen verzameling losse agents'],
                ],
                'automation' => [
                    'title' => 'Waarom traditionele marketing automation niet meer genoeg is',
                    'text' => 'Traditionele automation voert vooraf bepaalde flows uit. Moderne B2B-marketing vraagt om continue discovery, prioritering, contentadaptatie, AI-search-zichtbaarheid en governance. Teams hebben minder losse tools nodig en meer gecoördineerde uitvoering.',
                    'points' => [
                        'Predefined journeys missen nieuwe marktsignalen.',
                        'Content, visibility, competitors en publishing blijven vaak gescheiden.',
                        'Governance moet meebewegen met AI-ondersteunde uitvoering.',
                    ],
                ],
                'loop_title' => 'De Argusly Intelligence Loop',
                'loop_text' => 'Argusly maakt van deze loop een herhaalbaar operating model: signalen worden beslissingen, beslissingen worden governed uitvoering, en resultaten voeden de volgende cyclus.',
                'loop_steps' => ['Market Signals', 'AI Visibility', 'Opportunity Intelligence', 'Decision & Prioritization', 'Campaign Planning', 'Human Content Engine', 'Governance & Approval', 'Publishing', 'Measurement', 'Learning'],
                'capabilities_title' => 'Core capabilities',
                'capabilities' => [
                    ['AI Visibility', 'Monitor of je merk wordt gevonden, geciteerd en correct weergegeven in AI search en LLM-antwoorden.'],
                    ['Opportunity Intelligence', 'Vind content-, markt- en concurrentiegaps voordat ze gemiste kansen worden.'],
                    ['Autonomous Campaign Planning', 'Zet geprioriteerde kansen om in gestructureerde campagnes, taken, kanalen en assets.'],
                    ['Human Content Engine', 'Genereer content met expertise, nuance, voorbeelden en merkspecifiek oordeel in plaats van generieke AI-output.'],
                    ['Governance & Control', 'Houd approval flows, kwaliteitschecks, rollen, auditability, brand rules en publishingcontrole op hun plek.'],
                    ['Publishing & Learning', 'Beweeg van idee naar publicatie, meet uitkomsten en gebruik feedback om de volgende cyclus te verbeteren.'],
                ],
                'comparison_title' => 'Waarin het verschilt van AI-tools, copilots en marketing automation',
                'comparison_columns' => ['Categorie', 'Wat het doet', 'Beperking', 'Agentic Marketing Operating System'],
                'comparison_rows' => [
                    ['AI content generator', 'Maakt drafts of varianten.', 'Mist signalen, prioritering, governance en leren.', 'Verbindt contentcreatie met intelligence, approval, publishing en measurement.'],
                    ['AI copilot', 'Helpt een gebruiker met losse taken.', 'Wacht op menselijke prompts en coördineert geen operatie.', 'Zet doelen om in workflows en beslissingen met menselijk toezicht.'],
                    ['Marketing automation', 'Voert regels en journeys uit.', 'Werkt vooral binnen vooraf ontworpen paden.', 'Past uitvoering aan op nieuwe markt-, visibility- en performance-signalen.'],
                    ['SEO of AI visibility tool', 'Meet rankings, vindbaarheid of AI-antwoorden.', 'Stopt vaak bij insights.', 'Laat inzichten doorlopen naar campagnes, content, governance en publicatie.'],
                    ['Generic agent platform', 'Laat agents taken uitvoeren.', 'Mist marketingcontext, brand controls en publishingprocessen.', 'Biedt een B2B-marketing operating layer met ingebouwde controls.'],
                    ['Argusly', 'Combineert intelligence en uitvoering.', 'Niet bedoeld als losse writer of dashboard.', 'Verbindt AI Visibility, Opportunity Intelligence, planning, content, governance, publishing en learning.'],
                ],
                'implementation_title' => 'Hoe Argusly dit implementeert',
                'implementation_text' => 'Argusly brengt productcomponenten samen die marketingteams praktisch kunnen gebruiken: AI Visibility, Opportunity Intelligence, Competitive Intelligence, Autonomous Marketing, Human Content Engine, content governance, social en publishing workflows, connectors, integraties, measurement en learning.',
                'links_title' => 'Verder lezen binnen Argusly',
                'cta_title' => 'Bouw de operationele laag tussen marktintelligentie en marketinguitvoering.',
                'cta_text' => 'Bekijk hoe Argusly insights, beslissingen, contentproductie, governance en publishing in één gesloten marketingloop verbindt.',
            ];
        }

        return [
            'meta_title' => 'Agentic Marketing Operating System | Argusly',
            'meta_description' => 'Learn what an Agentic Marketing Operating System is and how Argusly connects AI visibility, opportunity intelligence, campaign planning, content governance, publishing, and learning.',
            'eyebrow' => 'Agentic Marketing Operating System',
            'h1' => 'From market intelligence to autonomous marketing execution.',
            'intro' => 'An Agentic Marketing Operating System connects signals, AI visibility, opportunity intelligence, campaign planning, content generation, governance, publishing, and learning in one controlled marketing workflow.',
            'primary_cta' => 'Plan walkthrough',
            'secondary_cta' => 'Explore AI Visibility',
            'hero_stats' => [
                ['Signals', 'Market, AI search, competitors, and content quality'],
                ['Decisions', 'Priority, risk, effort, and channel choices'],
                ['Execution', 'Campaigns, content, approvals, and publishing'],
            ],
            'definition' => [
                'title' => 'What is an Agentic Marketing Operating System?',
                'body' => 'An Agentic Marketing Operating System is a governed marketing system where AI agents and workflows discover opportunities, prioritize work, plan campaigns, generate content, support approvals, publish across channels, and learn from results.',
                'distinction' => 'It is not just a visibility dashboard, content generator, marketing automation tool, loose set of AI agents, or copilot. It is an operating layer for modern marketing execution.',
                'items' => ['Not a standalone dashboard', 'Not a generic AI writer', 'Not classic automation', 'Not a loose agent stack'],
            ],
            'automation' => [
                'title' => 'Why traditional marketing automation is no longer enough',
                'text' => 'Traditional automation executes predefined flows. Modern B2B marketing needs continuous discovery, prioritization, content adaptation, AI search visibility, and governance. Teams need fewer disconnected tools and more coordinated execution.',
                'points' => [
                    'Predefined journeys miss new market signals.',
                    'Content, visibility, competitors, and publishing often stay separated.',
                    'Governance has to move with AI-assisted execution.',
                ],
            ],
            'loop_title' => 'The Argusly Intelligence Loop',
            'loop_text' => 'Argusly turns this loop into a repeatable operating model: signals become decisions, decisions become governed execution, and results feed the next cycle.',
            'loop_steps' => ['Market Signals', 'AI Visibility', 'Opportunity Intelligence', 'Decision & Prioritization', 'Campaign Planning', 'Human Content Engine', 'Governance & Approval', 'Publishing', 'Measurement', 'Learning'],
            'capabilities_title' => 'Core capabilities',
            'capabilities' => [
                ['AI Visibility', 'Monitor whether your brand is found, cited, and represented correctly in AI search and LLM answers.'],
                ['Opportunity Intelligence', 'Find content, market, and competitor gaps before they become missed opportunities.'],
                ['Autonomous Campaign Planning', 'Turn prioritized opportunities into structured campaigns, tasks, channels, and assets.'],
                ['Human Content Engine', 'Generate content that carries expertise, nuance, examples, and brand-specific judgement instead of generic AI output.'],
                ['Governance & Control', 'Keep approval flows, quality checks, roles, auditability, brand rules, and publishing control in place.'],
                ['Publishing & Learning', 'Move from idea to publication, measure outcomes, and use the feedback to improve the next cycle.'],
            ],
            'comparison_title' => 'How it differs from AI tools, copilots, and marketing automation',
            'comparison_columns' => ['Category', 'What it does', 'Limitation', 'Agentic Marketing Operating System'],
            'comparison_rows' => [
                ['AI content generator', 'Creates drafts or variants.', 'Misses signals, prioritization, governance, and learning.', 'Connects content creation with intelligence, approval, publishing, and measurement.'],
                ['AI copilot', 'Helps a user complete isolated tasks.', 'Waits for human prompts and does not coordinate an operation.', 'Turns goals into workflows and decisions with human oversight.'],
                ['Marketing automation', 'Executes rules and journeys.', 'Works mostly inside predefined paths.', 'Adapts execution to new market, visibility, and performance signals.'],
                ['SEO or AI visibility tool', 'Measures rankings, findability, or AI answers.', 'Often stops at insights.', 'Carries insights into campaigns, content, governance, and publishing.'],
                ['Generic agent platform', 'Lets agents perform tasks.', 'Lacks marketing context, brand controls, and publishing process.', 'Provides a B2B marketing operating layer with built-in controls.'],
                ['Argusly', 'Combines intelligence and execution.', 'Not built as a standalone writer or dashboard.', 'Connects AI Visibility, Opportunity Intelligence, planning, content, governance, publishing, and learning.'],
            ],
            'implementation_title' => 'How Argusly implements this',
            'implementation_text' => 'Argusly brings practical product components together: AI Visibility, Opportunity Intelligence, Competitive Intelligence, Autonomous Marketing, Human Content Engine, content governance, social and publishing workflows, connectors, integrations, measurement, and learning.',
            'links_title' => 'Keep exploring Argusly',
            'cta_title' => 'Build the operating layer between market intelligence and marketing execution.',
            'cta_text' => 'See how Argusly connects insights, decisions, content production, governance, and publishing in one closed marketing loop.',
        ];
    }
}
