<?php

namespace App\Http\Controllers;

use App\Support\LocalizedMarketingUrl;
use App\Support\MarketingRouteSegments;
use Illuminate\View\View;

class PublicAgenticMarketingController extends Controller
{
    public function __invoke(): View
    {
        $locale = (string) app()->getLocale();
        $copy = $this->copy($locale);
        $canonicalUrl = LocalizedMarketingUrl::route('public.agentic-marketing', [], $locale);

        return view('public.agentic-marketing', [
            'publicLang' => $locale,
            'metaTitle' => $copy['meta_title'],
            'metaDescription' => $copy['meta_description'],
            'ogTitle' => $copy['og_title'],
            'ogDescription' => $copy['og_description'],
            'canonicalUrl' => $canonicalUrl,
            'hreflangUrls' => collect(app(MarketingRouteSegments::class)->locales())
                ->mapWithKeys(fn (string $hreflang): array => [
                    $hreflang => LocalizedMarketingUrl::route('public.agentic-marketing', [], $hreflang),
                ])
                ->all(),
            'copy' => $copy,
            'faq' => $copy['faq'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function copy(string $locale): array
    {
        $nl = $locale === 'nl';

        if ($nl) {
            return [
                'meta_title' => 'Agentic Marketing infrastructuur voor AI visibility | PublishLayer',
                'meta_description' => 'PublishLayer helpt organisaties verschuiven van handmatige marketinguitvoering naar autonome AI-gedreven contentoperaties, AI visibility en continue optimalisatie.',
                'og_title' => 'Agentic Marketing infrastructuur | PublishLayer',
                'og_description' => 'Van AI-schrijftool naar autonome marketingoperaties voor AI visibility, semantic SEO en lifecycle management.',
                'badge' => 'Agentic Marketing infrastructuur',
                'h1' => 'Van handmatige marketinguitvoering naar autonome AI visibility-operaties.',
                'intro' => 'PublishLayer helpt marketingteams strategie, content, semantic SEO, AI search readiness, publishing en continue optimalisatie te orkestreren via doelgestuurde workflows. Het is geen simpele AI-schrijftool, maar een operationele laag voor teams die zich voorbereiden op AI-native discovery.',
                'primary_cta' => 'Vraag een vroege platform walkthrough aan',
                'secondary_cta' => 'Bekijk hoe PublishLayer AI visibility orkestreert',
                'hero_cards' => [
                    ['title' => 'AI visibility', 'text' => 'Ontworpen voor search, answer engines en LLM-discovery.'],
                    ['title' => 'Autonome lifecycle', 'text' => 'Plan, publiceer, refresh, lokaliseer en verbeter content continu.'],
                    ['title' => 'Human governed', 'text' => 'Agents werken binnen brand, approval en compliance kaders.'],
                ],
                'loop_title' => 'Autonomous operating loop',
                'loop_status' => 'Live systeem',
                'loop_steps' => [
                    ['Doel', 'Visibility targets, segmenten en entities'],
                    ['Signaleren', 'Search, LLM, analytics en content decay'],
                    ['Beslissen', 'Prioriteer fixes, topics en kanalen'],
                    ['Uitvoeren', 'Brief, draft, verrijk, publiceer en refresh'],
                    ['Leren', 'Meet, vergelijk en verbeter'],
                ],
                'section_nav' => [
                    ['label' => 'Shift', 'href' => '#shift'],
                    ['label' => 'Architectuur', 'href' => '#architecture'],
                    ['label' => 'Visibility', 'href' => '#visibility'],
                    ['label' => 'Lifecycle', 'href' => '#lifecycle'],
                    ['label' => 'FAQ', 'href' => '#faq'],
                ],
                'problem' => [
                    'eyebrow' => 'Het probleem',
                    'title' => 'Marketingteams gebruiken AI nog steeds met workflows uit het handmatige tijdperk.',
                    'text' => 'Veel teams hebben AI toegevoegd aan losse onderdelen zoals drafting, keyword clustering of samenvatten. De echte beperking blijft bestaan: strategie, prioritering, publishing, governance, meting, refreshes en kanaalcoördinatie draaien nog steeds op losse menselijke overdrachten.',
                    'cards' => [
                        ['Fragmented intelligence', 'SEO, GEO, analytics, contentkwaliteit, brand context en publishingdata zitten in aparte tools.'],
                        ['Statische contentprogramma’s', 'Pagina’s gaan live en verouderen terwijl teams wachten op handmatige audits of backlog reviews.'],
                        ['AI visibility blind spots', 'Merken optimaliseren voor rankings, maar missen hoe AI-systemen hen begrijpen, citeren of overslaan.'],
                        ['Automation zonder oordeel', 'Rule-based workflows verplaatsen taken, maar redeneren niet vanuit doelen of nieuwe signalen.'],
                    ],
                ],
                'what_is' => [
                    'eyebrow' => 'Wat is Agentic Marketing?',
                    'title' => 'Agentic marketing verandert doelen in governed action loops.',
                    'text' => 'Een agentic marketing systeem doet meer dan een geplande taak uitvoeren. Het begrijpt het doel, leest signalen, weegt opties af, start workflows, vraagt menselijke goedkeuring waar nodig en leert van uitkomsten.',
                    'columns' => [
                        ['Automation', 'Als deze trigger gebeurt, voer deze vooraf bepaalde actie uit.', 'Nuttig voor herhaalbare overdrachten en notificaties.'],
                        ['AI assistance', 'Genereer of analyseer een asset wanneer iemand daarom vraagt.', 'Nuttig voor losse taken zoals drafting of samenvatten.'],
                        ['Agentic operations', 'Werk richting een doel over systemen, signalen, constraints en feedback heen.', 'Nuttig voor continue visibility en content performance programma’s.'],
                    ],
                ],
                'fit' => [
                    'eyebrow' => 'Hoe PublishLayer past',
                    'title' => 'De infrastructuurlaag tussen strategie, content, search en AI-systemen.',
                    'paragraphs' => [
                        'PublishLayer verbindt onderdelen die normaal los blijven: brand context, personas, research, briefs, drafts, interne links, localization, publishing destinations, performance intelligence, AI visibility scoring en refresh workflows.',
                        'Daardoor ondersteunt het platform teams die meer nodig hebben dan contentgeneratie. AI agents coördineren het werk, terwijl mensen positionering, prioriteit, risico en eindbeslissingen bewaken.',
                    ],
                    'cards' => [
                        ['Goal-driven systems', 'Definieer businessdoelen, topics, audiences en AI visibility outcomes voordat contentwerk begint.'],
                        ['Semantic SEO en entities', 'Bouw entity-aware contentstructuren die zoekmachines en LLMs helpen brand authority te begrijpen.'],
                        ['Multi-system operations', 'Coördineer research, planning, review, publishing, analytics en refresh-signalen.'],
                        ['Governed autonomy', 'Laat agents aanbevelen en uitvoeren binnen approval rules, brand context en team controls.'],
                    ],
                ],
                'architecture' => [
                    'eyebrow' => 'Architectuur',
                    'title' => 'Een autonomous workflow diagram voor AI-native marketing operations.',
                    'text' => 'PublishLayer is ontworpen als control plane voor contentoperaties, niet als een single-purpose writing surface. Het systeem ontvangt doelen en signalen, maakt werk aan, publiceert met controls en gebruikt uitkomsten in de volgende besliscyclus.',
                    'steps' => [
                        ['Research', 'Market, SERP, LLM en source intelligence'],
                        ['Brief', 'Doelen, entities, vragen en proof points'],
                        ['Produce', 'Drafts, answer blocks, interne links en media'],
                        ['Publish', 'CMS delivery, markdown, APIs en localization'],
                        ['Optimize', 'AI visibility, decay, refresh en learning loops'],
                    ],
                    'panels' => [
                        ['Semantic entity network', 'Brand, product, category, competitor, pain point en solution entities sturen contentbeslissingen.'],
                        ['AI visibility ecosystem', 'Search engines, answer engines, LLMs, copilots en verticale discovery surfaces worden meetbare kanalen.'],
                        ['Multi-channel orchestration', 'Eén contentsysteem voedt web, blog, knowledge, email, social, sales enablement en partnerkanalen.'],
                    ],
                ],
                'features_title' => 'Gebouwd voor autonome content operations, niet voor losse asset creation.',
                'features' => [
                    ['AI visibility scoring', 'Meet of content klaar is voor answer selection, LLM readability, semantic clarity en citation potential.'],
                    ['Structured answer blocks', 'Zet belangrijke content om in compacte answer layers die AI-systemen kunnen lezen en hergebruiken.'],
                    ['Content lifecycle automation', 'Detecteer decay, trigger refreshes, monitor localization gaps en houd strategische pagina’s actueel.'],
                    ['Brief intelligence', 'Vertaal doelen, audiences, entities, SERP patronen en bewijs naar herhaalbare briefs.'],
                    ['Internal link intelligence', 'Versterk topical authority met context-aware links over clusters en entity-relaties.'],
                    ['Publishing orchestration', 'Breng goedgekeurde content naar gekoppelde destinations met traceability en delivery status.'],
                ],
                'visibility' => [
                    'eyebrow' => 'AI visibility',
                    'title' => 'AI visibility is de laag boven traditionele SEO.',
                    'text' => 'SEO vraagt of een pagina kan ranken en clicks kan trekken. AI visibility vraagt of je merk als autoriteit wordt begrepen, of je content als bron wordt geselecteerd, of antwoorden je correct representeren en of je kennis gestructureerd is voor retrieval.',
                    'block_title' => 'Structured answer block',
                    'block' => 'AI visibility is het vermogen van een merk om gevonden, begrepen, geciteerd en correct weergegeven te worden door AI-systemen zoals answer engines, LLMs, AI search overviews en copilots.',
                    'nodes' => [
                        ['Search engines', 'Rankings, snippets en crawl signals'],
                        ['Answer engines', 'Direct answers en source selection'],
                        ['LLMs', 'Entity understanding en generated summaries'],
                        ['Copilots', 'Workflows, recommendations en citations'],
                        ['Knowledge surfaces', 'Docs, hubs, llms.txt en markdown'],
                        ['Analytics', 'Performance signals en refresh triggers'],
                    ],
                ],
                'lifecycle' => [
                    'eyebrow' => 'Continue lifecycle',
                    'title' => 'Van campagnekalenders naar levende contentsystemen.',
                    'text' => 'Agentic content operations behandelen elke belangrijke pagina als managed asset. PublishLayer ondersteunt workflows voor creatie, verbetering, localization, interne links, channel packaging, AI visibility checks en performance-led refresh recommendations.',
                    'cards' => [
                        ['Autonomous optimization loops', 'Agents monitoren zwakke signalen, adviseren next actions en houden verbeterwerk in beweging.'],
                        ['Human + AI orchestration', 'Mensen bewaken positioning, review, risk en strategische tradeoffs terwijl agents throughput leveren.'],
                        ['Multi-system operations', 'Content kan verbinden met CMS destinations, analytics, search intelligence, research en knowledge layers.'],
                        ['Future-ready infrastructure', 'Teams bouwen richting AI-native marketing zonder hun hele stack ineens te vervangen.'],
                    ],
                    'loop_title' => 'Content lifecycle loop',
                    'loop' => ['Plan vanuit goals en entity gaps', 'Maak met bewijs en brand context', 'Publiceer naar gekoppelde destinations', 'Meet search, AI en engagement signals', 'Refresh, breid uit, lokaliseer of retire'],
                ],
                'future' => [
                    'eyebrow' => 'De toekomst van marketing',
                    'title' => 'Marketing verschuift van meer assets produceren naar slimmere systemen besturen.',
                    'paragraphs' => [
                        'Het volgende marketingvoordeel komt niet uit sneller losse drafts genereren. Het komt van teams die doelen helder definiëren, brand en market knowledge coderen, systemen verbinden, AI visibility meten en governed agents de content estate continu laten verbeteren.',
                        'PublishLayer positioneert die shift als infrastructuur: een operationele laag waarin AI werk over de content lifecycle coördineert terwijl marketing leaders strategische controle houden.',
                    ],
                ],
                'cta' => [
                    'eyebrow' => 'Agentic Marketing infrastructuur',
                    'title' => 'Bouw autonome content workflows voor AI visibility.',
                    'text' => 'Bekijk hoe PublishLayer goal-driven content operations, AI search optimization, semantic entity workflows en continuous lifecycle management ondersteunt.',
                    'primary' => 'Vraag een vroege platform walkthrough aan',
                    'secondary' => 'Bouw autonome content workflows',
                ],
                'seo' => [
                    'eyebrow' => 'SEO content blocks',
                    'title' => 'Answer-ready definities voor AI search en semantic discovery.',
                    'blocks' => [
                        ['Wat is agentic marketing?', 'Agentic marketing is een goal-driven marketing operating model waarin AI agents analyse, content workflows, optimalisatie en meting coördineren onder menselijke governance.'],
                        ['Wat zijn autonomous content operations?', 'Autonomous content operations is het continu plannen, creëren, publiceren, monitoren en verbeteren van content assets via verbonden AI workflows.'],
                        ['Wat is agentic marketing infrastructuur?', 'Agentic marketing infrastructuur is de softwarelaag die doelen, data, content, AI visibility, governance en publishing systemen verbindt zodat marketing als adaptive loops draait.'],
                    ],
                ],
                'faq_title' => 'Agentic Marketing FAQ',
                'faq' => [
                    ['question' => 'Wat is agentic marketing?', 'answer' => 'Agentic marketing is een operating model waarin AI agents marketingwerk plannen, monitoren, optimaliseren en coördineren rond gedefinieerde doelen, terwijl teams strategie, approval en governance leveren.'],
                    ['question' => 'Hoe verschilt agentic marketing van marketing automation?', 'answer' => 'Traditionele automation voert vooraf ingestelde regels uit. Agentic marketing systemen interpreteren doelen, beoordelen context, kiezen vervolgstappen, triggeren workflows en leren van performance-signalen binnen menselijke controls.'],
                    ['question' => 'Is AI visibility hetzelfde als SEO?', 'answer' => 'AI visibility breidt SEO uit. SEO richt zich op rankings en clicks; AI visibility meet ook of een merk, entity of bron wordt begrepen, geselecteerd, geciteerd en weergegeven in AI-antwoorden.'],
                    ['question' => 'Waar past PublishLayer in een enterprise marketing stack?', 'answer' => 'PublishLayer fungeert als agentic marketing infrastructuurlaag over strategie, content operations, semantic optimization, publishing, AI visibility tracking en lifecycle improvement workflows.'],
                    ['question' => 'Vervangt dit menselijke marketeers?', 'answer' => 'Nee. PublishLayer is ontworpen voor human and AI orchestration: teams bepalen positionering, prioriteiten, approvals, policies en doelen terwijl AI agents herhaalbare analyse, coördinatie en optimalisatieloops uitvoeren.'],
                ],
            ];
        }

        return [
            'meta_title' => 'Agentic Marketing Infrastructure for AI Visibility | PublishLayer',
            'meta_description' => 'PublishLayer helps organizations move from manual marketing execution to autonomous AI-driven content operations, AI visibility, and continuous optimization workflows.',
            'og_title' => 'Agentic Marketing Infrastructure | PublishLayer',
            'og_description' => 'Move from AI writing tools to autonomous marketing operations for AI visibility, semantic SEO, and content lifecycle management.',
            'badge' => 'Agentic Marketing Infrastructure',
            'h1' => 'Move from manual marketing execution to autonomous AI visibility operations.',
            'intro' => 'PublishLayer helps marketing teams orchestrate strategy, content, semantic SEO, AI search readiness, publishing, and continuous optimization through goal-driven workflows. It is not an AI writing shortcut. It is the operational layer for marketing teams preparing for AI-native discovery.',
            'primary_cta' => 'Request an early platform walkthrough',
            'secondary_cta' => 'See how PublishLayer orchestrates AI visibility',
            'hero_cards' => [
                ['title' => 'AI visibility', 'text' => 'Designed for search, answer engines, and LLM discovery surfaces.'],
                ['title' => 'Autonomous lifecycle', 'text' => 'Plan, publish, refresh, localize, and improve content continuously.'],
                ['title' => 'Human governed', 'text' => 'Agents execute inside brand, approval, and compliance constraints.'],
            ],
            'loop_title' => 'Autonomous operating loop',
            'loop_status' => 'Live system',
            'loop_steps' => [
                ['Goal', 'Visibility targets, segments, entities'],
                ['Sense', 'Search, LLM, analytics, content decay'],
                ['Decide', 'Prioritize fixes, topics, channels'],
                ['Act', 'Brief, draft, enrich, publish, refresh'],
                ['Learn', 'Measure, compare, and improve'],
            ],
            'section_nav' => [
                ['label' => 'Shift', 'href' => '#shift'],
                ['label' => 'Architecture', 'href' => '#architecture'],
                ['label' => 'Visibility', 'href' => '#visibility'],
                ['label' => 'Lifecycle', 'href' => '#lifecycle'],
                ['label' => 'FAQ', 'href' => '#faq'],
            ],
            'problem' => [
                'eyebrow' => 'The problem',
                'title' => 'Marketing teams are operating AI with manual-era workflows.',
                'text' => 'Most teams have added AI tools to the edges of their workflow: draft generation, keyword clustering, summarization, or repurposing. The deeper constraint remains unchanged. Strategy, prioritization, publishing, governance, measurement, refreshes, and cross-channel coordination still depend on fragmented human handoffs.',
                'cards' => [
                    ['Fragmented intelligence', 'SEO, GEO, analytics, content quality, brand context, and publishing data sit in separate tools.'],
                    ['Static content programs', 'Pages go live and decay while teams wait for quarterly audits or manual backlog reviews.'],
                    ['AI visibility blind spots', 'Brands optimize for rankings but miss how AI systems understand, cite, summarize, or omit them.'],
                    ['Automation without judgment', 'Rule-based workflows move tasks forward, but they do not reason from goals or adapt to new signals.'],
                ],
            ],
            'what_is' => [
                'eyebrow' => 'What is Agentic Marketing?',
                'title' => 'Agentic marketing turns goals into governed action loops.',
                'text' => 'An agentic marketing system does more than execute a scheduled task. It understands the objective, reads signals, evaluates options, initiates workflows, requests human approval where required, and improves from observed outcomes.',
                'columns' => [
                    ['Automation', 'If this event happens, perform this predefined action.', 'Useful for repeatable handoffs and notifications.'],
                    ['AI assistance', 'Generate or analyze an asset when a person asks.', 'Useful for isolated tasks such as drafting or summarizing.'],
                    ['Agentic operations', 'Pursue a goal across systems, signals, constraints, and feedback.', 'Useful for continuous visibility and content performance programs.'],
                ],
            ],
            'fit' => [
                'eyebrow' => 'How PublishLayer fits',
                'title' => 'The infrastructure layer between strategy, content, search, and AI systems.',
                'paragraphs' => [
                    'PublishLayer connects the operational pieces that usually stay disconnected: brand context, personas, source research, briefs, drafts, internal links, localization, publishing destinations, performance intelligence, AI visibility scoring, and refresh workflows.',
                    'That makes the platform useful for teams that need more than content generation. It supports a marketing operating model where AI agents coordinate the work while humans control positioning, priorities, risk, and final decisions.',
                ],
                'cards' => [
                    ['Goal-driven systems', 'Define business goals, topics, audiences, and AI visibility outcomes before content work begins.'],
                    ['Semantic SEO and entities', 'Build entity-aware content structures that help search engines and LLMs understand brand authority.'],
                    ['Multi-system operations', 'Coordinate research, planning, review, publishing, analytics, and refresh signals across the stack.'],
                    ['Governed autonomy', 'Let agents recommend and execute repeatable work within approval rules, brand context, and team controls.'],
                ],
            ],
            'architecture' => [
                'eyebrow' => 'Architecture',
                'title' => 'An autonomous workflow diagram for AI-native marketing operations.',
                'text' => 'PublishLayer is designed as a control plane for content operations, not a single-purpose writing surface. The system receives goals and signals, creates work, publishes with controls, and feeds outcomes back into the next decision cycle.',
                'steps' => [
                    ['Research', 'Market, SERP, LLM and source intelligence'],
                    ['Brief', 'Goals, entities, questions and proof points'],
                    ['Produce', 'Drafts, answer blocks, internal links, media'],
                    ['Publish', 'CMS delivery, markdown, APIs and localization'],
                    ['Optimize', 'AI visibility, decay, refresh and learning loops'],
                ],
                'panels' => [
                    ['Semantic entity network', 'Brand, product, category, competitor, pain point, and solution entities are mapped into content decisions.'],
                    ['AI visibility ecosystem', 'Search engines, answer engines, LLMs, copilots, and vertical discovery surfaces become measurable channels.'],
                    ['Multi-channel orchestration', 'One content system can feed web, blog, knowledge, email, social, sales enablement, and partner channels.'],
                ],
            ],
            'features_title' => 'Built for autonomous content operations, not one-off asset creation.',
            'features' => [
                ['AI visibility scoring', 'Track whether content is structured for answer selection, LLM readability, semantic clarity, and citation potential.'],
                ['Structured answer blocks', 'Convert important content into concise answer layers that AI systems can parse, summarize, and reuse.'],
                ['Content lifecycle automation', 'Detect decay, trigger refreshes, monitor localization gaps, and keep strategic pages current.'],
                ['Brief intelligence', 'Turn goals, audiences, entities, SERP patterns, and source evidence into repeatable production briefs.'],
                ['Internal link intelligence', 'Strengthen topical authority with context-aware links across content clusters and entity relationships.'],
                ['Publishing orchestration', 'Move approved content into connected destinations with traceability, review controls, and delivery status.'],
            ],
            'visibility' => [
                'eyebrow' => 'AI visibility',
                'title' => 'AI visibility is the next layer above traditional SEO.',
                'text' => 'Traditional SEO asks whether a page can rank and attract clicks. AI visibility asks whether your brand is recognized as an authoritative entity, whether your content is selected as source material, whether answers represent you accurately, and whether your knowledge is structured for retrieval.',
                'block_title' => 'Structured answer block',
                'block' => 'AI visibility is a brand\'s ability to be discovered, understood, cited, and accurately represented by AI systems such as answer engines, LLMs, AI search overviews, and copilots.',
                'nodes' => [
                    ['Search engines', 'Rankings, snippets, crawl signals'],
                    ['Answer engines', 'Direct answers and source selection'],
                    ['LLMs', 'Entity understanding and generated summaries'],
                    ['Copilots', 'Workflows, recommendations, and citations'],
                    ['Knowledge surfaces', 'Docs, hubs, llms.txt, markdown'],
                    ['Analytics', 'Performance signals and refresh triggers'],
                ],
            ],
            'lifecycle' => [
                'eyebrow' => 'Continuous lifecycle',
                'title' => 'From campaign calendars to living content systems.',
                'text' => 'Agentic content operations treat every important page as a managed asset. PublishLayer can support workflows for new creation, content improvement, localization, internal linking, channel packaging, AI visibility checks, and performance-led refresh recommendations.',
                'cards' => [
                    ['Autonomous optimization loops', 'Agents monitor weak signals, recommend next actions, and keep improvement work moving.'],
                    ['Human + AI orchestration', 'Humans own positioning, review, risk, and strategic tradeoffs while agents handle operational throughput.'],
                    ['Multi-system operations', 'Content can connect to CMS destinations, analytics, search intelligence, research, and knowledge layers.'],
                    ['Future-ready infrastructure', 'Teams can build toward AI-native marketing without replacing their entire stack at once.'],
                ],
                'loop_title' => 'Content lifecycle loop',
                'loop' => ['Plan from goals and entity gaps', 'Create with evidence and brand context', 'Publish to connected destinations', 'Measure search, AI, and engagement signals', 'Refresh, expand, localize, or retire'],
            ],
            'future' => [
                'eyebrow' => 'The future of marketing',
                'title' => 'Marketing shifts from producing more assets to governing smarter systems.',
                'paragraphs' => [
                    'The next marketing advantage will not come from generating isolated drafts faster. It will come from teams that can define goals clearly, encode brand and market knowledge, connect systems, measure AI visibility, and let governed agents keep the content estate improving over time.',
                    'PublishLayer positions that shift as infrastructure: an operational layer where AI can coordinate work across the content lifecycle while marketing leaders retain strategic control.',
                ],
            ],
            'cta' => [
                'eyebrow' => 'Agentic Marketing Infrastructure',
                'title' => 'Build autonomous content workflows for AI visibility.',
                'text' => 'See how PublishLayer can support goal-driven content operations, AI search optimization, semantic entity workflows, and continuous lifecycle management.',
                'primary' => 'Request an early platform walkthrough',
                'secondary' => 'Build autonomous content workflows',
            ],
            'seo' => [
                'eyebrow' => 'SEO content blocks',
                'title' => 'Answer-ready definitions for AI search and semantic discovery.',
                'blocks' => [
                    ['What is agentic marketing?', 'Agentic marketing is a goal-driven marketing operating model where AI agents coordinate analysis, content workflows, optimization, and measurement under human governance.'],
                    ['What is autonomous content operations?', 'Autonomous content operations is the continuous planning, creation, publishing, monitoring, and improvement of content assets through connected AI workflows.'],
                    ['What is agentic marketing infrastructure?', 'Agentic marketing infrastructure is the software layer that connects goals, data, content, AI visibility, governance, and publishing systems so marketing work can run as adaptive loops.'],
                ],
            ],
            'faq_title' => 'Agentic Marketing FAQ',
            'faq' => [
                ['question' => 'What is agentic marketing?', 'answer' => 'Agentic marketing is an operating model where AI agents plan, monitor, optimize, and coordinate marketing work against defined goals, while human teams provide strategy, approval, and governance.'],
                ['question' => 'How is agentic marketing different from marketing automation?', 'answer' => 'Traditional automation executes predefined rules. Agentic marketing systems can interpret goals, evaluate context, choose next actions, trigger workflows, and learn from performance signals under human-defined controls.'],
                ['question' => 'Is AI visibility the same as SEO?', 'answer' => 'AI visibility extends SEO. SEO focuses on search rankings and clicks, while AI visibility also measures whether a brand, entity, or source is understood, selected, cited, and represented inside AI-generated answers.'],
                ['question' => 'Where does PublishLayer fit in an enterprise marketing stack?', 'answer' => 'PublishLayer acts as an agentic marketing infrastructure layer across strategy, content operations, semantic optimization, publishing, AI visibility tracking, and lifecycle improvement workflows.'],
                ['question' => 'Does this replace human marketers?', 'answer' => 'No. PublishLayer is designed for human and AI orchestration: teams set positioning, priorities, approvals, policies, and goals while AI agents handle repeatable analysis, coordination, and optimization loops.'],
            ],
        ];
    }
}
