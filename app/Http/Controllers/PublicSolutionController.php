<?php

namespace App\Http\Controllers;

use App\Support\LocalizedMarketingUrl;
use App\Support\MarketingRouteSegments;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicSolutionController extends Controller
{
    public function show(Request $request, string $solution): View
    {
        $locale = (string) app()->getLocale();
        $copy = $this->copy($locale);

        abort_unless(isset($copy[$solution]), 404);

        $routeName = 'public.solutions.' . $solution;
        $page = $copy[$solution];
        $allSolutions = collect($copy)
            ->map(fn (array $item, string $key): array => [
                'key' => $key,
                'label' => $item['nav_label'],
                'description' => $item['nav_description'],
                'url' => LocalizedMarketingUrl::route('public.solutions.' . $key, [], $locale),
            ])
            ->values()
            ->all();

        return view('public.solution', [
            'publicLang' => $locale,
            'metaTitle' => $page['meta_title'],
            'metaDescription' => $page['meta_description'],
            'canonicalUrl' => LocalizedMarketingUrl::route($routeName, [], $locale),
            'hreflangUrls' => collect(app(MarketingRouteSegments::class)->locales())
                ->mapWithKeys(fn (string $hreflang): array => [
                    $hreflang => LocalizedMarketingUrl::route($routeName, [], $hreflang),
                ])
                ->all(),
            'solutionKey' => $solution,
            'copy' => $page,
            'allSolutions' => $allSolutions,
            'contactCta' => LocalizedMarketingUrl::route('public.company.contact', [
                'subject' => $page['contact_subject'],
            ], $locale) . '#contact-form',
            'agenticUrl' => LocalizedMarketingUrl::route('public.agentic-marketing', [], $locale),
            'opportunityUrl' => LocalizedMarketingUrl::route('public.solutions.opportunity-intelligence', [], $locale),
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function copy(string $locale): array
    {
        if ($locale === 'nl') {
            return [
                'opportunity-intelligence' => [
                    'nav_label' => 'Opportunity Intelligence',
                    'nav_description' => 'Vind groei voordat concurrenten het doen.',
                    'meta_title' => 'Opportunity Intelligence | Argusly',
                    'meta_description' => 'Ontdek, prioriteer en activeer groeikansen vanuit search, AI visibility, concurrentie en content signalen.',
                    'contact_subject' => 'Opportunity Intelligence demo',
                    'eyebrow' => 'Opportunity Intelligence',
                    'h1' => 'Discover growth opportunities before competitors do.',
                    'intro' => 'Argusly helpt marketingleiders, founders en growth-teams zwakke signalen omzetten in een duidelijke kansenlijst: wat mist, wat beweegt, wat kan winnen en welke acties nu prioriteit verdienen.',
                    'primary_cta' => 'Ontdek groeikansen',
                    'secondary_cta' => 'Bekijk Agentic Marketing',
                    'hero_metrics' => [
                        ['label' => 'Signalen', 'value' => 'Zoekdata, AI, concurrenten'],
                        ['label' => 'Resultaat', 'value' => 'Geprioriteerde kansenlijst'],
                        ['label' => 'Proces', 'value' => 'Ontdekken, beslissen, uitvoeren'],
                    ],
                    'sections' => [
                        ['eyebrow' => 'Probleem', 'title' => 'Groei zit vaak verborgen tussen tools, dashboards en losse aannames.', 'text' => 'Teams zien rankings, analytics, contentkwaliteit en concurrentiebewegingen apart. Daardoor blijven nieuwe categorievragen, antwoordgaten en contentverval te lang onzichtbaar.', 'points' => ['Kansen verschijnen voordat zoekvolume volwassen is.', 'Concurrenten bouwen topicautoriteit op terwijl teams wachten op kwartaalplanning.', 'AI-antwoorden kunnen merken overslaan nog voordat verkeer daalt.']],
                        ['eyebrow' => 'Signalen', 'title' => 'Argusly leest signalen die samen een marktkans vormen.', 'text' => 'Het platform combineert AI-zichtbaarheid, contentgaten, concurrentiebewegingen, topic-eigenaarschap, verval en site-intelligentie zodat teams niet op een enkel datapunt hoeven te varen.', 'points' => ['Nieuwe en stijgende topics', 'Concurrentpagina\'s en positioneringsverschuivingen', 'AI-aandeel in antwoorden en citatiegaten', 'Ontbrekende entiteiten, interne links en antwoordblokken']],
                        ['eyebrow' => 'Kansen ontdekken', 'title' => 'Van ruis naar concrete kanskandidaten.', 'text' => 'Argusly vertaalt signalen naar kansen met een duidelijke reden, doelgroep, funnelfase en beoogde uitkomst.', 'points' => ['Nieuwe oplossing-, vergelijking- en educatiepagina\'s', 'Updates voor pagina\'s met dalende relevantie', 'Antwoordklare content voor AI-vindbaarheid', 'Clusteruitbreiding rond entiteiten waar autoriteit ontbreekt']],
                        ['eyebrow' => 'Prioriteren', 'title' => 'Prioriteer op impact, haalbaarheid en timing.', 'text' => 'Een kansenlijst helpt teams beslissen wat eerst moet: niet alles dat mogelijk is, maar wat nu verdedigbaar voordeel kan opleveren.', 'points' => ['Zakelijke impact en funnelfit', 'Competitieve urgentie', 'Contentinspanning en governance-risico', 'Potentieel voor AI-zichtbaarheid en topic-eigenaarschap']],
                        ['eyebrow' => 'Uitvoering', 'title' => 'Zet kansen om in briefs, workflows en publiceerbare assets.', 'text' => 'De beste kansen blijven niet in een dashboard hangen. Argusly verbindt discovery met briefs, contentworkflows, goedkeuring, publicatie en leerloops.', 'points' => ['Briefs met entiteiten, bewijs en invalshoek', 'Agentic workflows voor uitvoering', 'Goedkeuring en governance per team', 'Meten, leren en opnieuw prioriteren']],
                    ],
                    'cta_title' => 'Maak groei zichtbaar voordat het een backlog-item wordt.',
                    'cta_text' => 'Bekijk hoe Argusly opportunity discovery koppelt aan prioritering, governance en execution.',
                ],
                'ai-visibility' => [
                    'nav_label' => 'AI Visibility',
                    'nav_description' => 'Meet hoe AI-systemen je merk begrijpen.',
                    'meta_title' => 'AI Visibility | Argusly',
                    'meta_description' => 'Monitor of je merk wordt gevonden, begrepen, geciteerd en correct weergegeven in AI search en LLM-antwoorden.',
                    'contact_subject' => 'AI Visibility demo',
                    'eyebrow' => 'AI Visibility',
                    'h1' => 'Maak zichtbaar hoe AI-systemen je merk, content en categorie begrijpen.',
                    'intro' => 'Rankings blijven belangrijk, maar AI-vindbaarheid verschuift aandacht naar aandeel in antwoorden, citaties, entiteitsbegrip en gestructureerde content. Argusly maakt die laag meetbaar en actiegericht.',
                    'primary_cta' => 'Vraag een AI Visibility Scan aan',
                    'secondary_cta' => 'Ontdek groeikansen',
                    'hero_metrics' => [
                        ['label' => 'Monitor', 'value' => 'Antwoorden, entiteiten, citaties'],
                        ['label' => 'Diagnose', 'value' => 'Gaten en verkeerde weergave'],
                        ['label' => 'Verbetering', 'value' => 'Antwoordklare workflows'],
                    ],
                    'sections' => [
                        ['eyebrow' => 'Wat is AI Visibility', 'title' => 'AI Visibility is het vermogen om gevonden en correct gerepresenteerd te worden door AI-systemen.', 'text' => 'Het gaat niet alleen om klikken. Het gaat om of AI-antwoorden je merk noemen, je content gebruiken, je expertise begrijpen en je positie correct samenvatten.', 'points' => ['Merk- en entiteitsherkenning', 'Citatie- en bronselectie', 'Aandeel in AI-antwoorden', 'Nauwkeurigheid van gegenereerde samenvattingen']],
                        ['eyebrow' => 'Waarom rankings niet genoeg zijn', 'title' => 'AI-antwoorden kunnen vraag en keuze beïnvloeden zonder traditionele SERP-klik.', 'text' => 'Een pagina kan ranken maar toch ontbreken in AI-antwoorden. Of je merk kan genoemd worden met onvolledige context. Daarom moet zichtbaarheid breder worden gemeten dan positie alleen.', 'points' => ['Rankings tonen niet altijd opname in antwoorden', 'Zero-click-antwoorden veranderen kooponderzoek', 'LLM\'s hebben heldere entiteiten en bewijs nodig', 'Gestructureerde antwoorden verhogen herbruikbaarheid']],
                        ['eyebrow' => 'Hoe Argusly monitort', 'title' => 'Argusly verbindt prompts, topics, pagina\'s en antwoorduitkomsten.', 'text' => 'Teams kunnen zien waar ze verschijnen, waar concurrenten domineren, welke bronnen worden geciteerd en welke contentlaag ontbreekt.', 'points' => ['Monitoring van prompts en topics', 'Vergelijking van concurrenten in antwoorden', 'Analyse van citaties en bronnen', 'Checks op contentgereedheid']],
                        ['eyebrow' => 'Hoe kansen ontstaan', 'title' => 'Elk zichtbaarheidsprobleem wordt een uitvoerbare kans.', 'text' => 'Een gemiste citatie, zwak antwoordblok of ontbrekende entiteit kan leiden tot een update, nieuwe pagina, interne-linkactie of workflow voor gestructureerde antwoorden.', 'points' => ['Antwoordblokken maken', 'Entiteitsdekking verbeteren', 'Aanbevelingen voor updates en uitbreiding', 'Interne links en bronnen versterken']],
                    ],
                    'cta_title' => 'Stuur niet alleen op rankings. Stuur op aanwezigheid in AI-antwoorden.',
                    'cta_text' => 'Bekijk hoe Argusly AI visibility monitort en vertaalt naar kansen.',
                ],
                'competitive-intelligence' => [
                    'nav_label' => 'Competitive Intelligence',
                    'nav_description' => 'Volg concurrenten, topics en aandeel in AI-antwoorden.',
                    'meta_title' => 'Competitive Intelligence | Argusly',
                    'meta_description' => 'Volg concurrentiebewegingen, topic-eigenaarschap, aandeel in AI-antwoorden en contentgaten met Argusly.',
                    'contact_subject' => 'Competitive Intelligence demo',
                    'eyebrow' => 'Competitive Intelligence',
                    'h1' => 'Zie waar concurrenten marktaandacht winnen voordat je pipeline het merkt.',
                    'intro' => 'Argusly helpt teams concurrentiebewegingen, topic-eigenaarschap, aandeel in AI-antwoorden en contentgaten omzetten naar concrete acties in de kansenlijst.',
                    'primary_cta' => 'Bekijk je competitive gaps',
                    'secondary_cta' => 'Bekijk Opportunity Intelligence',
                    'hero_metrics' => [
                        ['label' => 'Volg', 'value' => 'Concurrentiebewegingen'],
                        ['label' => 'Vergelijk', 'value' => 'Topic- en antwoordaandeel'],
                        ['label' => 'Actie', 'value' => 'Kansenlijst'],
                    ],
                    'sections' => [
                        ['eyebrow' => 'Concurrentiebewegingen', 'title' => 'Volg wat concurrenten publiceren, verbeteren en claimen.', 'text' => 'Nieuwe pagina\'s, veranderende messaging, clusteruitbreiding en updates zijn signalen dat een concurrent aandacht koopt of autoriteit opbouwt.', 'points' => ['Nieuwe en aangepaste concurrentpagina\'s', 'Verschuivingen in positionering en aanbod', 'Veranderingen in SERP en AI-antwoorden', 'Uitbreiding van categorieën en use-cases']],
                        ['eyebrow' => 'Topic-eigenaarschap', 'title' => 'Meet wie de categorie uitlegt en waar je ontbreekt.', 'text' => 'Topic-eigenaarschap gaat over meer dan een ranking. Het gaat om dekking, interne links, entiteiten, bewijs en consistentie over het cluster.', 'points' => ['Dekking van pillar- en ondersteunende pagina\'s', 'Entiteitsdiepte en topicvolledigheid', 'Sterkte van interne links', 'Dekking in de beslisfase']],
                        ['eyebrow' => 'Aandeel in AI-antwoorden', 'title' => 'Vergelijk wie AI-systemen noemen en citeren.', 'text' => 'Als AI-antwoorden steeds dezelfde concurrenten gebruiken, is dat een strategisch signaal voor content, bewijs en autoriteitswerk.', 'points' => ['Merkvermeldingen in antwoorden', 'Citatieaandeel per concurrent', 'Promptclusters met zwakke aanwezigheid', 'Gaten in nauwkeurigheid en sentiment']],
                        ['eyebrow' => 'Contentgaten', 'title' => 'Vind ontbrekende pagina\'s, zwakke invalshoeken en onbeantwoorde vragen.', 'text' => 'Argusly vertaalt concurrentiedekking naar gaten die je kunt prioriteren in plaats van eindeloze spreadsheets.', 'points' => ['Ontbrekende vergelijkingspagina\'s', 'Zwakke dekking van oplossingen en use-cases', 'Onbeantwoorde buyer-vragen', 'Updatekandidaten voor verouderde assets']],
                        ['eyebrow' => 'Kansenlijst', 'title' => 'Maak competitive intelligence operationeel.', 'text' => 'De output is een lijst met kansen, redenen, prioriteit en uitvoeringspad: brief, update, antwoordblok, interne link of nieuwe pagina.', 'points' => ['Geprioriteerde concurrentiekansen', 'Aanbevolen contentacties', 'Governance-workflows', 'Leerloop na uitvoering']],
                    ],
                    'cta_title' => 'Laat concurrentiesignalen niet in reporting eindigen.',
                    'cta_text' => 'Zet competitive intelligence om in acties die search, AI-zichtbaarheid en pipeline ondersteunen.',
                ],
                'marketing-without-large-team' => [
                    'nav_label' => 'Marketing Without A Large Team',
                    'nav_description' => 'Schaal marketing zonder groot team.',
                    'meta_title' => 'Marketing Without A Large Team | Argusly',
                    'meta_description' => 'Gebruik autonome workflows, governance en schaalbare content operations zonder een groot marketingteam.',
                    'contact_subject' => 'Marketing Without A Large Team demo',
                    'eyebrow' => 'Marketing Without A Large Team',
                    'h1' => 'Draai meer strategisch marketingwerk zonder eerst een groot team te bouwen.',
                    'intro' => 'Founders en lean growth-teams hebben vaak genoeg ambitie, maar te weinig capaciteit voor research, briefs, publicatie, updates, AI-zichtbaarheid en governance. Argusly maakt die operatie lichter.',
                    'primary_cta' => 'Ontdek schaalbare marketingkansen',
                    'secondary_cta' => 'Bekijk Agentic Marketing',
                    'hero_metrics' => [
                        ['label' => 'Capaciteit', 'value' => 'Meer output'],
                        ['label' => 'Controle', 'value' => 'Beheerste workflows'],
                        ['label' => 'Schaal', 'value' => 'Herhaalbare operatie'],
                    ],
                    'sections' => [
                        ['eyebrow' => 'Capaciteitstekort', 'title' => 'Kleine teams verliezen momentum aan overdracht en handwerk.', 'text' => 'Research, briefing, review, publicatie en optimalisatie vragen meer discipline dan de meeste lean teams structureel kunnen dragen.', 'points' => ['Geen vaste rol voor contentoperations', 'Te weinig tijd voor updates en meting', 'Expertise verspreid over founders, freelancers en tools', 'Backlogs groeien sneller dan uitvoering']],
                        ['eyebrow' => 'Autonome workflows', 'title' => 'Laat workflows signalen lezen en volgende acties voorstellen.', 'text' => 'Argusly helpt kleine teams werk starten vanuit doelen, signalen en kansenlijsten in plaats van handmatige taakcreatie.', 'points' => ['Kansen ontdekken', 'Brief- en conceptworkflows', 'Aanbevelingen voor updates', 'Publicatie en levering volgen']],
                        ['eyebrow' => 'Governance', 'title' => 'Schaal uitvoering zonder controle te verliezen.', 'text' => 'Lean hoeft niet los te betekenen. Merkcontext, goedkeuringsstatussen en workspaceregels houden output consistent en reviewbaar.', 'points' => ['Merkstem en bedrijfscontext', 'Goedkeuring voor publicatie', 'Rollen en workspacecontrole', 'Traceerbare contentwijzigingen']],
                        ['eyebrow' => 'Schaalbaarheid', 'title' => 'Bouw een marketing operating layer voordat het team groot is.', 'text' => 'Een klein team kan eerder systematisch werken: kansen prioriteren, content uitvoeren, leren van signalen en herhalen.', 'points' => ['Herhaalbare contentsystemen', 'AI-zichtbaarheid ingebouwd in de workflow', 'Gekoppelde publicatiebestemmingen', 'Ruimte om later teamleden toe te voegen']],
                    ],
                    'cta_title' => 'Krijg de operating leverage van een groter team.',
                    'cta_text' => 'Bekijk hoe Argusly lean teams helpt prioriteren, uitvoeren en blijven leren.',
                ],
            ];
        }

        return [
            'opportunity-intelligence' => [
                'nav_label' => 'Opportunity Intelligence',
                'nav_description' => 'Find growth before competitors do.',
                'meta_title' => 'Opportunity Intelligence | Argusly',
                'meta_description' => 'Discover, prioritize, and execute growth opportunities from search, AI visibility, competitor, and content signals.',
                'contact_subject' => 'Opportunity Intelligence demo',
                'eyebrow' => 'Opportunity Intelligence',
                'h1' => 'Discover growth opportunities before competitors do.',
                'intro' => 'Argusly helps marketing leaders, founders, and growth teams turn weak signals into a clear opportunity queue: what is missing, what is moving, what can win, and which actions deserve priority now.',
                'primary_cta' => 'Discover growth opportunities',
                'secondary_cta' => 'See Agentic Marketing',
                'hero_metrics' => [
                    ['label' => 'Signals', 'value' => 'Search, AI, competitors'],
                    ['label' => 'Output', 'value' => 'Prioritized opportunity queue'],
                    ['label' => 'Motion', 'value' => 'Discover, decide, execute'],
                ],
                'sections' => [
                    ['eyebrow' => 'Problem', 'title' => 'Growth opportunities hide between tools, dashboards, and assumptions.', 'text' => 'Teams see rankings, analytics, content quality, and competitor movement separately. That means new category questions, answer gaps, and content decay stay invisible for too long.', 'points' => ['Opportunities appear before search volume matures.', 'Competitors build topical authority while teams wait for quarterly planning.', 'AI answers can skip brands before traffic drops.']],
                    ['eyebrow' => 'Signals', 'title' => 'Argusly reads the signals that form a market opportunity.', 'text' => 'The platform combines AI visibility, content gaps, competitor movement, topic ownership, decay, and site intelligence so teams do not have to bet on one datapoint.', 'points' => ['Emerging and rising topics', 'Competitor pages and positioning shifts', 'AI answer share and citation gaps', 'Missing entities, internal links, and answer blocks']],
                    ['eyebrow' => 'Opportunity discovery', 'title' => 'Move from noise to concrete opportunity candidates.', 'text' => 'Argusly translates signals into opportunities with a clear reason, audience, funnel stage, and intended outcome.', 'points' => ['New solution, comparison, and education pages', 'Refreshes for pages with declining relevance', 'Answer-ready content for AI discovery', 'Cluster expansion around entities where authority is missing']],
                    ['eyebrow' => 'Prioritization', 'title' => 'Prioritize by impact, feasibility, and timing.', 'text' => 'An opportunity queue helps teams decide what should happen first: not everything that is possible, but what can create defensible advantage now.', 'points' => ['Business impact and funnel fit', 'Competitive urgency', 'Content effort and governance risk', 'AI visibility and topic ownership potential']],
                    ['eyebrow' => 'Execution', 'title' => 'Turn opportunities into briefs, workflows, and publishable assets.', 'text' => 'The best opportunities should not sit in a dashboard. Argusly connects discovery to briefs, content workflows, approval, publishing, and learning loops.', 'points' => ['Briefs with entities, proof, and angle', 'Agentic workflows for execution', 'Approval and governance by team', 'Measure, learn, and reprioritize']],
                ],
                'cta_title' => 'Make growth visible before it becomes a backlog item.',
                'cta_text' => 'See how Argusly connects opportunity discovery with prioritization, governance, and execution.',
            ],
            'ai-visibility' => [
                'nav_label' => 'AI Visibility',
                'nav_description' => 'Measure how AI systems understand your brand.',
                'meta_title' => 'AI Visibility | Argusly',
                'meta_description' => 'Monitor whether your brand is found, understood, cited, and represented accurately in AI search and LLM answers.',
                'contact_subject' => 'AI Visibility demo',
                'eyebrow' => 'AI Visibility',
                'h1' => 'See how AI systems understand your brand, content, and category.',
                'intro' => 'Rankings still matter, but AI discovery shifts attention toward answer share, citations, entity understanding, and structured content. Argusly makes that layer measurable and actionable.',
                'primary_cta' => 'Request an AI Visibility Scan',
                'secondary_cta' => 'Discover growth opportunities',
                'hero_metrics' => [
                    ['label' => 'Monitor', 'value' => 'Answers, entities, citations'],
                    ['label' => 'Diagnose', 'value' => 'Gaps and misrepresentation'],
                    ['label' => 'Improve', 'value' => 'Answer-ready workflows'],
                ],
                'sections' => [
                    ['eyebrow' => 'What AI Visibility is', 'title' => 'AI Visibility is the ability to be found and represented correctly by AI systems.', 'text' => 'It is not only about clicks. It is whether AI answers mention your brand, use your content, understand your expertise, and summarize your position accurately.', 'points' => ['Brand and entity recognition', 'Citation and source selection', 'AI answer share', 'Accuracy of generated summaries']],
                    ['eyebrow' => 'Why rankings are not enough', 'title' => 'AI answers can shape research and choice without a traditional SERP click.', 'text' => 'A page can rank and still be absent from AI answers. Or your brand can be mentioned with incomplete context. Visibility needs to be measured beyond position alone.', 'points' => ['Rankings do not always show answer inclusion', 'Zero-click answers change buyer research', 'LLMs need clear entities and proof', 'Structured answers improve reuse']],
                    ['eyebrow' => 'How Argusly monitors', 'title' => 'Argusly connects prompts, topics, pages, and answer outcomes.', 'text' => 'Teams can see where they appear, where competitors dominate, which sources are cited, and which content layer is missing.', 'points' => ['Prompt and topic monitoring', 'Competitor answer comparison', 'Citation and source analysis', 'Content readiness checks']],
                    ['eyebrow' => 'How opportunities emerge', 'title' => 'Every visibility gap becomes an executable opportunity.', 'text' => 'A missed citation, weak answer block, or missing entity can become a refresh, new page, internal link action, or structured answer workflow.', 'points' => ['Answer block creation', 'Entity coverage improvement', 'Refresh and expansion recommendations', 'Internal link and source strengthening']],
                ],
                'cta_title' => 'Do not manage only rankings. Manage AI answer presence.',
                'cta_text' => 'See how Argusly monitors AI visibility and turns it into opportunities.',
            ],
            'competitive-intelligence' => [
                'nav_label' => 'Competitive Intelligence',
                'nav_description' => 'Track competitors, topics, and AI answer share.',
                'meta_title' => 'Competitive Intelligence | Argusly',
                'meta_description' => 'Track competitor movements, topic ownership, AI answer share, and content gaps with Argusly.',
                'contact_subject' => 'Competitive Intelligence demo',
                'eyebrow' => 'Competitive Intelligence',
                'h1' => 'See where competitors are winning market attention before your pipeline notices.',
                'intro' => 'Argusly helps teams turn competitor movement, topic ownership, AI answer share, and content gaps into concrete actions in the opportunity queue.',
                'primary_cta' => 'See your competitive gaps',
                'secondary_cta' => 'See Opportunity Intelligence',
                'hero_metrics' => [
                    ['label' => 'Track', 'value' => 'Competitor movements'],
                    ['label' => 'Compare', 'value' => 'Topic and answer share'],
                    ['label' => 'Act', 'value' => 'Opportunity queue'],
                ],
                'sections' => [
                    ['eyebrow' => 'Competitor movements', 'title' => 'Track what competitors publish, improve, and claim.', 'text' => 'New pages, changing messaging, cluster expansion, and refreshes are signals that a competitor is buying attention or building authority.', 'points' => ['New and updated competitor pages', 'Shifts in positioning and offers', 'SERP and AI answer changes', 'Category and use-case expansion']],
                    ['eyebrow' => 'Topic ownership', 'title' => 'Measure who explains the category and where you are absent.', 'text' => 'Topic ownership is more than a ranking. It includes coverage, internal links, entities, proof, and consistency across the cluster.', 'points' => ['Pillar and support page coverage', 'Entity depth and topical completeness', 'Internal link strength', 'Decision-stage coverage']],
                    ['eyebrow' => 'AI answer share', 'title' => 'Compare which brands AI systems mention and cite.', 'text' => 'If AI answers keep using the same competitors, that is a strategic signal for content, proof, and authority work.', 'points' => ['Brand mentions in answers', 'Citation share by competitor', 'Prompt clusters with weak presence', 'Accuracy and sentiment gaps']],
                    ['eyebrow' => 'Content gaps', 'title' => 'Find missing pages, weak angles, and unanswered buyer questions.', 'text' => 'Argusly translates competitor coverage into gaps you can prioritize instead of endless spreadsheets.', 'points' => ['Missing comparison pages', 'Weak solution and use-case coverage', 'Unanswered buyer questions', 'Refresh candidates for stale assets']],
                    ['eyebrow' => 'Opportunity queue', 'title' => 'Make competitive intelligence operational.', 'text' => 'The output is a queue with opportunities, reasons, priority, and execution path: brief, refresh, answer block, internal link, or new page.', 'points' => ['Prioritized competitive opportunities', 'Recommended content actions', 'Governed workflows', 'Learning loop after execution']],
                ],
                'cta_title' => 'Do not let competitor signals end in reporting.',
                'cta_text' => 'Turn competitive intelligence into actions that support search, AI visibility, and pipeline.',
            ],
            'marketing-without-large-team' => [
                'nav_label' => 'Marketing Without A Large Team',
                'nav_description' => 'Scale marketing without a large team.',
                'meta_title' => 'Marketing Without A Large Team | Argusly',
                'meta_description' => 'Use autonomous workflows, governance, and scalable content operations without a large marketing team.',
                'contact_subject' => 'Marketing Without A Large Team demo',
                'eyebrow' => 'Marketing Without A Large Team',
                'h1' => 'Run more strategic marketing work without building a large team first.',
                'intro' => 'Founders and lean growth teams often have enough ambition, but not enough capacity for research, briefs, publishing, refreshes, AI visibility, and governance. Argusly makes that operation lighter.',
                'primary_cta' => 'Discover scalable marketing opportunities',
                'secondary_cta' => 'See Agentic Marketing',
                'hero_metrics' => [
                    ['label' => 'Capacity', 'value' => 'More throughput'],
                    ['label' => 'Control', 'value' => 'Governed workflows'],
                    ['label' => 'Scale', 'value' => 'Repeatable operations'],
                ],
                'sections' => [
                    ['eyebrow' => 'Capacity shortage', 'title' => 'Small teams lose momentum to handoffs and manual work.', 'text' => 'Research, briefing, review, publishing, and optimization require more discipline than most lean teams can sustain manually.', 'points' => ['No dedicated content operations role', 'Too little time for refreshes and measurement', 'Expertise spread across founders, freelancers, and tools', 'Backlogs grow faster than execution']],
                    ['eyebrow' => 'Autonomous workflows', 'title' => 'Let workflows read signals and propose next actions.', 'text' => 'Argusly helps small teams start work from goals, signals, and opportunity queues instead of manual task creation.', 'points' => ['Opportunity discovery', 'Brief and draft workflows', 'Refresh recommendations', 'Publishing and delivery tracking']],
                    ['eyebrow' => 'Governance', 'title' => 'Scale execution without losing control.', 'text' => 'Lean does not have to mean loose. Brand context, approval states, and workspace rules keep output consistent and reviewable.', 'points' => ['Brand voice and company context', 'Approval before publishing', 'Roles and workspace controls', 'Traceable content changes']],
                    ['eyebrow' => 'Scalability', 'title' => 'Build a marketing operating layer before the team is large.', 'text' => 'A small team can work systematically earlier: prioritize opportunities, execute content, learn from signals, and repeat.', 'points' => ['Repeatable content systems', 'AI visibility built into workflow', 'Connected publishing destinations', 'Room to add teammates later']],
                ],
                'cta_title' => 'Get the operating leverage of a larger team.',
                'cta_text' => 'See how Argusly helps lean teams prioritize, execute, and keep learning.',
            ],
        ];
    }
}
