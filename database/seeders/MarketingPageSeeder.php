<?php

namespace Database\Seeders;

use App\Models\MarketingPage;
use Illuminate\Database\Seeder;

class MarketingPageSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->pages() as $pageKey => $payload) {
            $page = MarketingPage::query()->updateOrCreate(
                ['key' => $pageKey],
                [
                    'section' => $payload['section'] ?? null,
                    'template' => $payload['template'] ?? 'topic',
                    'is_active' => true,
                    'sort_order' => $payload['sort_order'] ?? 0,
                ]
            );

            foreach ((array) ($payload['translations'] ?? []) as $locale => $translation) {
                $page->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'title' => $translation['title'],
                        'slug' => $translation['slug'],
                        'seo_title' => $translation['seo_title'],
                        'meta_description' => $translation['meta_description'],
                        'canonical_path' => $translation['canonical_path'],
                        'content' => $translation['content'],
                    ]
                );
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function pages(): array
    {
        return [
            'ai_search' => [
                'sort_order' => 5,
                'translations' => [
                    'en' => [
                        'title' => 'Win AI Search with Answer Engine Optimization (AEO)',
                        'slug' => 'ai-search',
                        'seo_title' => 'Answer Engine Optimization (AEO) for AI search | Argusly',
                        'meta_description' => 'Learn what Answer Engine Optimization (AEO) is, how it differs from SEO, and how Argusly helps teams improve AI visibility with AEO Score and Structured Answer Blocks.',
                        'canonical_path' => '/en/ai-search',
                        'content' => [
                            'eyebrow' => 'AI Search & GEO',
                            'subheadline' => 'Don’t just rank in search engines. Become the answer in AI systems like ChatGPT and Google AI.',
                            'intro' => 'Argusly is evolving from an SEO tool into an AI visibility platform. The shift is simple: rank in search, but also become the answer in AI. This page explains Answer Engine Optimization (AEO), why it matters, and how Argusly turns AI-first content into an operational workflow.',
                            'hero_primary_label' => 'Get early access',
                            'hero_primary_route' => 'public.early-access.show',
                            'hero_primary_params' => ['intent' => 'early-access'],
                            'hero_secondary_label' => 'See how it works',
                            'hero_secondary_route' => 'public.product.platform',
                            'sections' => [
                                [
                                    'title' => 'What is Answer Engine Optimization (AEO)?',
                                    'intro' => 'Answer Engine Optimization (AEO) is the process of optimizing content so AI systems can use it as a direct answer.',
                                    'paragraphs' => [
                                        'Unlike traditional SEO, which focuses on rankings, AEO focuses on answer selection. It helps systems such as ChatGPT and Google AI understand what a page says, which entities it references, and whether the content is structured clearly enough to be surfaced as an answer.',
                                        'SEO still matters, because search engines remain a major source of discovery. AEO extends that work by optimizing for direct answers, question coverage, and structured knowledge that AI systems can parse with confidence.',
                                    ],
                                    'bullets' => [
                                        'Target answer clarity, not only keyword matching',
                                        'Structure content for questions, intent, and retrieval',
                                        'Support both classic search and AI-generated answers',
                                    ],
                                ],
                                [
                                    'title' => 'From SEO to AEO',
                                    'intro' => 'Search visibility is moving from rankings to answers.',
                                    'table' => [
                                        'headers' => ['SEO', 'AEO'],
                                        'rows' => [
                                            ['Rankings', 'Answers'],
                                            ['Clicks', 'Visibility in AI'],
                                            ['Keywords', 'Questions and intent'],
                                            ['Pages', 'Structured knowledge'],
                                        ],
                                    ],
                                ],
                                [
                                    'title' => 'Measure your AI visibility with AEO Score',
                                    'intro' => 'AEO Score helps teams understand whether content is ready for AI retrieval and answer systems.',
                                    'paragraphs' => [
                                        'Argusly scores content from 0 to 100 based on the signals that matter for answer-first discovery. That includes answer clarity, page structure, entity usage, semantic coverage, readability, and formatting that works well for LLMs.',
                                        'The goal is not a vanity metric. It is a practical signal that explains why AI systems may select, summarize, ignore, or misread a page.',
                                    ],
                                    'bullets' => [
                                        'Score answer clarity and directness',
                                        'Measure structure, entity usage, and LLM readability',
                                        'Know why AI selects or ignores your content',
                                    ],
                                ],
                                [
                                    'title' => 'Turn content into answers with Structured Answer Blocks',
                                    'intro' => 'Structured Answer Blocks convert long-form content into direct, reusable Q&A layers for AI systems.',
                                    'paragraphs' => [
                                        'Argusly can extract key user questions from an article and generate concise answers that start with a direct statement. Those blocks are optimized for AI consumption and can be exported through Markdown, API endpoints, and llms.txt-friendly discovery layers.',
                                        'This is how content moves from being a page that might rank to a source that is easier for AI systems to parse, retrieve, and cite.',
                                    ],
                                    'qa_blocks' => [
                                        [
                                            'question' => 'What is AEO?',
                                            'answer' => 'AEO is the process of optimizing content to become the direct answer in AI systems.',
                                        ],
                                    ],
                                ],
                                [
                                    'title' => 'Why this matters',
                                    'paragraphs' => [
                                        'Zero-click search is rising. In many journeys, users get the summary before they ever decide whether to click a source page. That means brand visibility is shifting toward AI surfaces, answer boxes, and generated overviews.',
                                        'If your content is not structured for AI interpretation, you can lose visibility even when your site already has topical authority.',
                                    ],
                                    'bullets' => [
                                        'Zero-click search is rising',
                                        'AI answers increasingly replace blue-link scanning',
                                        'Brand visibility now depends on answer selection as well as ranking',
                                    ],
                                ],
                                [
                                    'title' => 'Built for AI systems',
                                    'intro' => 'Argusly is designed for the platforms shaping AI discovery.',
                                    'paragraphs' => [
                                        'That includes ChatGPT, Google, and Microsoft ecosystems where answer-first interfaces reward structured, factual, and well-linked content.',
                                        'Argusly connects AEO positioning with markdown delivery, internal linking, llms.txt visibility, and API-friendly structured outputs so teams can build AI-first content operations early.',
                                    ],
                                    'cards' => [
                                        ['title' => 'ChatGPT', 'description' => 'Optimize for direct question answering and entity-rich responses.'],
                                        ['title' => 'Google AI', 'description' => 'Support answer overviews with clear structure and retrieval-friendly formatting.'],
                                        ['title' => 'Microsoft', 'description' => 'Prepare content for AI-assisted discovery across search and copilots.'],
                                    ],
                                ],
                            ],
                            'faq_title' => 'AEO FAQ',
                            'faq' => [
                                [
                                    'question' => 'What is AEO?',
                                    'answer' => 'AEO stands for Answer Engine Optimization. It focuses on making content usable as a direct answer in AI systems rather than only optimizing for blue-link rankings.',
                                ],
                                [
                                    'question' => 'How is AEO different from SEO?',
                                    'answer' => 'SEO focuses on rankings, crawlability, and click-through potential. AEO adds answer clarity, question coverage, entity usage, and structure that AI systems can summarize and cite.',
                                ],
                                [
                                    'question' => 'How do I optimize for ChatGPT?',
                                    'answer' => 'Optimize for ChatGPT by writing direct answers early, structuring pages with clear headings, using entities consistently, and exposing content in LLM-friendly formats such as markdown and structured Q&A.',
                                ],
                            ],
                            'related_page_keys' => ['seo', 'geo', 'ai_search_optimization'],
                            'platform_links' => [
                                ['label' => 'Platform overview', 'route' => 'public.product.platform'],
                                ['label' => 'Pricing', 'route' => 'pricing'],
                            ],
                            'cta' => [
                                'eyebrow' => 'Start building AI-first content',
                                'title' => 'Turn AEO strategy into publishable answer-first content',
                                'text' => 'Use Argusly to measure AI readiness, generate Structured Answer Blocks, and publish content that serves both search engines and AI systems.',
                                'primary_label' => 'Get early access',
                                'primary_route' => 'public.early-access.show',
                                'primary_params' => ['intent' => 'early-access'],
                                'secondary_label' => 'See how it works',
                                'secondary_route' => 'public.product.platform',
                            ],
                        ],
                    ],
                    'nl' => [
                        'title' => 'Win AI-zoekmachines met Answer Engine Optimization (AEO)',
                        'slug' => 'ai-zoekmachines',
                        'seo_title' => 'Answer Engine Optimization (AEO) voor AI-zoekmachines | Argusly',
                        'meta_description' => 'Ontdek wat Answer Engine Optimization (AEO) is, hoe het verschilt van SEO en hoe Argusly AI-zichtbaarheid verbetert met AEO-score en gestructureerde antwoordblokken.',
                        'canonical_path' => '/nl/ai-zoekmachines',
                        'content' => [
                            'eyebrow' => 'AI Search & GEO',
                            'subheadline' => 'Word niet alleen gevonden. Word het antwoord in AI-systemen zoals ChatGPT en Google.',
                            'intro' => 'Argusly ontwikkelt zich van SEO-tool naar AI visibility platform. De nieuwe kern is duidelijk: ranken in zoekmachines is niet genoeg, je moet ook het antwoord kunnen worden in AI. Op deze pagina leggen we uit wat Answer Engine Optimization (AEO) is en hoe Argusly dat operationeel maakt.',
                            'hero_primary_label' => 'Vraag early access aan',
                            'hero_primary_route' => 'public.early-access.show',
                            'hero_primary_params' => ['intent' => 'early-access'],
                            'hero_secondary_label' => 'Bekijk hoe het werkt',
                            'hero_secondary_route' => 'public.product.platform',
                            'sections' => [
                                [
                                    'title' => 'Wat is Answer Engine Optimization (AEO)?',
                                    'intro' => 'Answer Engine Optimization (AEO) is het optimaliseren van content zodat AI-systemen die als direct antwoord kunnen gebruiken.',
                                    'paragraphs' => [
                                        'Waar SEO vooral draait om rankings, draait AEO om antwoordselectie. Het helpt systemen zoals ChatGPT en Google om te begrijpen wat een pagina zegt, welke entiteiten belangrijk zijn en of de content duidelijk genoeg is om als antwoord te tonen.',
                                        'SEO blijft belangrijk, maar AEO breidt dat werk uit met focus op directe antwoorden, vragenstructuur, intent en gestructureerde kennis die AI-systemen betrouwbaar kunnen interpreteren.',
                                    ],
                                    'bullets' => [
                                        'Optimaliseer voor antwoordhelderheid, niet alleen voor keywords',
                                        'Structureer content rond vragen en intent',
                                        'Werk tegelijk voor zoekmachines en AI-antwoorden',
                                    ],
                                ],
                                [
                                    'title' => 'Van SEO naar AEO',
                                    'intro' => 'Zichtbaarheid verschuift van rankings naar antwoorden.',
                                    'table' => [
                                        'headers' => ['SEO', 'AEO'],
                                        'rows' => [
                                            ['Rankings', 'Antwoorden'],
                                            ['Clicks', 'Zichtbaarheid in AI'],
                                            ['Keywords', 'Vragen en intent'],
                                            ['Pagina’s', 'Gestructureerde kennis'],
                                        ],
                                    ],
                                ],
                                [
                                    'title' => 'Meet je AI-zichtbaarheid met AEO-score',
                                    'intro' => 'De AEO-score laat zien of content klaar is voor answer-first discovery.',
                                    'paragraphs' => [
                                        'Argusly scoort content van 0 tot 100 op signalen die belangrijk zijn voor AI-systemen. Denk aan answer clarity, structuur, entity usage, semantic coverage, readability en formatting die werkt voor LLM’s.',
                                        'Het doel is niet alleen meten, maar begrijpen waarom AI jouw content selecteert of juist overslaat.',
                                    ],
                                    'bullets' => [
                                        'Score op answer clarity en directheid',
                                        'Meet structuur, entity usage en LLM-leesbaarheid',
                                        'Begrijp waarom AI jouw content selecteert of negeert',
                                    ],
                                ],
                                [
                                    'title' => 'Structureer content met gestructureerde antwoordblokken',
                                    'intro' => 'Gestructureerde antwoordblokken zetten lange content om in directe Q&A voor AI-systemen.',
                                    'paragraphs' => [
                                        'Argusly kan kernvragen uit een artikel halen en daar beknopte antwoorden van maken die beginnen met een directe uitspraak. Daardoor ontstaat een extra laag die beter werkt voor AI-consumptie en beschikbaar is via Markdown, API en llms.txt-gerichte discovery.',
                                        'Zo verschuift content van een pagina die misschien rankt naar een bron die makkelijker door AI-systemen kan worden gelezen, samengevat en geciteerd.',
                                    ],
                                    'qa_blocks' => [
                                        [
                                            'question' => 'Wat is AEO?',
                                            'answer' => 'AEO is het proces van content optimaliseren zodat die het directe antwoord kan worden in AI-systemen.',
                                        ],
                                    ],
                                ],
                                [
                                    'title' => 'Waarom dit belangrijk is',
                                    'paragraphs' => [
                                        'Zero-click search groeit. In steeds meer journeys zien gebruikers eerst een AI-samenvatting en pas daarna beslissen ze of een bron nog aangeklikt wordt.',
                                        'Als je content niet gestructureerd is voor AI-interpretatie, kun je zichtbaarheid verliezen, zelfs wanneer je al autoriteit hebt in klassieke search.',
                                    ],
                                    'bullets' => [
                                        'Zero-click search neemt toe',
                                        'AI-antwoorden vervangen steeds vaker de klassieke resultatenlijst',
                                        'Merkzichtbaarheid verschuift naar AI-systemen',
                                    ],
                                ],
                                [
                                    'title' => 'Gebouwd voor AI-systemen',
                                    'intro' => 'Argusly ondersteunt de platformen die answer-first discovery vormgeven.',
                                    'paragraphs' => [
                                        'Dat geldt voor ChatGPT, Google en Microsoft-ecosystemen waar duidelijke, feitelijke en goed gestructureerde content voordeel heeft.',
                                        'Argusly verbindt AEO-positionering met markdown delivery, interne linking, llms.txt-zichtbaarheid en API-ready output zodat teams vroeg kunnen bouwen aan AI-first contentoperaties.',
                                    ],
                                    'cards' => [
                                        ['title' => 'ChatGPT', 'description' => 'Optimaliseer voor directe beantwoording en consistente entiteiten.'],
                                        ['title' => 'Google', 'description' => 'Ondersteun AI-overviews met duidelijke structuur en retrieval-vriendelijke content.'],
                                        ['title' => 'Microsoft', 'description' => 'Bereid content voor op AI-assisted discovery in search en copilots.'],
                                    ],
                                ],
                            ],
                            'faq_title' => 'AEO FAQ',
                            'faq' => [
                                [
                                    'question' => 'Wat is AEO?',
                                    'answer' => 'AEO staat voor Answer Engine Optimization. Het richt zich op content die niet alleen gevonden wordt, maar ook als direct antwoord gebruikt kan worden in AI-systemen.',
                                ],
                                [
                                    'question' => 'Wat is het verschil tussen SEO en AEO?',
                                    'answer' => 'SEO draait om rankings, crawlbaarheid en clicks. AEO voegt answer clarity, vraagstructuur, entity usage en AI-vriendelijke opbouw toe.',
                                ],
                                [
                                    'question' => 'Hoe optimaliseer je voor AI?',
                                    'answer' => 'Optimaliseer voor AI door vroeg een direct antwoord te geven, heldere H1-H3-structuur te gebruiken, entiteiten consistent te benoemen en content beschikbaar te maken in formats zoals markdown en gestructureerde Q&A.',
                                ],
                            ],
                            'related_page_keys' => ['seo', 'geo', 'ai_search_optimization'],
                            'platform_links' => [
                                ['label' => 'Platformoverzicht', 'route' => 'public.product.platform'],
                                ['label' => 'Prijzen', 'route' => 'pricing'],
                            ],
                            'cta' => [
                                'eyebrow' => 'Start met AI-first content',
                                'title' => 'Maak van AEO-strategie publiceerbare antwoordcontent',
                                'text' => 'Gebruik Argusly om AEO-score te meten, antwoordblokken te genereren en content te publiceren die werkt voor zoekmachines én AI-systemen.',
                                'primary_label' => 'Vraag early access aan',
                                'primary_route' => 'public.early-access.show',
                                'primary_params' => ['intent' => 'early-access'],
                                'secondary_label' => 'Bekijk hoe het werkt',
                                'secondary_route' => 'public.product.platform',
                            ],
                        ],
                    ],
                ],
            ],
            'ai_visibility_agentic_marketing' => [
                'sort_order' => 6,
                'translations' => [
                    'en' => [
                        'title' => 'AI Visibility & Agentic Marketing',
                        'slug' => 'ai-visibility-agentic-marketing',
                        'seo_title' => 'AI Visibility & Agentic Marketing | From AI Search Insights to Execution | Argusly',
                        'meta_description' => 'AI Visibility shows whether AI systems mention, cite and recommend your brand. Argusly goes further by explaining why visibility is missing, discovering opportunities, generating content and organizing marketing execution.',
                        'canonical_path' => '/en/ai-visibility-agentic-marketing',
                        'content' => [
                            'eyebrow' => 'AI Visibility & Agentic Marketing',
                            'subheadline' => 'AI Visibility is important. It is not enough.',
                            'intro' => 'AI Visibility tools show whether your brand appears in AI answers, AI search and LLM recommendations. Argusly helps you understand why visibility is missing, which opportunities competitors are winning, what content needs to be created and how to organize the work that improves visibility.',
                            'hero_primary_label' => 'Plan a platform walkthrough',
                            'hero_primary_route' => 'public.company.contact',
                            'hero_primary_params' => ['subject' => 'ai-visibility-agentic-marketing'],
                            'hero_secondary_label' => 'Explore Agentic Marketing',
                            'hero_secondary_route' => 'public.agentic-marketing',
                            'sections' => [
                                [
                                    'title' => 'What is AI Visibility?',
                                    'intro' => 'AI Visibility is the degree to which AI systems can find, understand, cite and recommend your brand, products, experts and content when people ask relevant questions.',
                                    'paragraphs' => [
                                        'In traditional search, visibility often means ranking on a search results page. In AI search, visibility is broader. Your brand may be mentioned in a generated answer, cited as a source, summarized as an option, compared with competitors or left out entirely.',
                                        'AI Visibility is not just the number of AI mentions your brand receives. It is the ability of AI systems to understand your relevance, trust your content and recommend you in the moments where buyers are researching options.',
                                    ],
                                    'bullets' => [
                                        'Whether your brand is mentioned in AI answers',
                                        'Whether your pages are cited as sources',
                                        'Whether AI systems understand your category and expertise',
                                        'Whether competitors are recommended instead',
                                        'Whether generated summaries represent your business accurately',
                                    ],
                                ],
                                [
                                    'title' => 'Why measurement alone is not enough',
                                    'intro' => 'Knowing that your brand is missing from ChatGPT, Gemini, Perplexity or AI search results is useful. But measurement alone does not tell a marketing team what to do next.',
                                    'paragraphs' => [
                                        'Most AI Visibility tools answer questions such as: are we mentioned, how often are we mentioned, which competitors appear more often, which prompts trigger our brand and which sources are cited.',
                                        'Those questions matter, but they do not complete the job. Teams also need to know why the brand is missing, which topics or pages are weak, which competitors are stronger, which buyer questions are unanswered and how execution should be organized.',
                                    ],
                                    'qa_blocks' => [
                                        [
                                            'question' => 'The practical difference',
                                            'answer' => 'AI Visibility tools tell you where you stand. Argusly tells you why you stand there and what work should happen next.',
                                        ],
                                    ],
                                ],
                                [
                                    'title' => 'Why organizations remain invisible in AI systems',
                                    'intro' => 'Organizations are not usually invisible because they lack one metric. They are invisible because AI systems do not have enough clear, useful and trustworthy information to work with.',
                                    'steps' => [
                                        ['title' => 'Missing topics', 'text' => 'Important buyer questions are not covered on the website.'],
                                        ['title' => 'Missing content formats', 'text' => 'The site lacks FAQ pages, comparison pages, sector pages, implementation guides, answer blocks or proof-led landing pages.'],
                                        ['title' => 'Weak authority signals', 'text' => 'The brand has limited topical depth, few internal links, weak entity coverage or thin supporting content.'],
                                        ['title' => 'Unclear positioning', 'text' => 'AI systems cannot easily understand who the company serves, what it offers, how it differs or when it should be recommended.'],
                                        ['title' => 'Stronger competitor content', 'text' => 'Competitors may have clearer pages, better proof, richer clusters, more complete answers or stronger citation patterns.'],
                                        ['title' => 'Proof is not extractable', 'text' => 'Case studies, expertise, service details, outcomes and differentiators may exist, but not in a format AI systems can easily use.'],
                                    ],
                                ],
                                [
                                    'title' => 'How AI systems select companies',
                                    'intro' => 'AI systems do not simply reproduce traditional search rankings. They generate answers from patterns, sources, content quality, entity understanding and available evidence.',
                                    'paragraphs' => [
                                        'When AI systems recommend a company, they often rely on clear category relevance, pages that directly answer the question, consistent brand and entity information, strong topical coverage, useful comparison content, trusted citations, concrete proof and content that is easy to summarize.',
                                        'This means AI Search Optimization and Generative Engine Optimization are not just technical tasks. They require content, structure, proof, authority and ongoing improvement.',
                                    ],
                                    'bullets' => [
                                        'Clear category relevance and entity consistency',
                                        'Pages that directly answer important buyer questions',
                                        'Useful decision-stage and comparison content',
                                        'Trusted sources, citations, proof and examples',
                                        'Fresh content that is easy to summarize and reuse',
                                    ],
                                ],
                                [
                                    'title' => 'Why competitors are recommended instead',
                                    'intro' => 'When a competitor is recommended and your brand is not, the reason is rarely random.',
                                    'paragraphs' => [
                                        'Competitors often win AI recommendations because they answer more specific buyer questions, publish clearer sector or use-case content, provide more extractable proof, own richer content clusters, have better internal linking or appear in more source material.',
                                        'The practical question is not only why they are visible. The better question is what work would make your brand easier to understand, trust and recommend.',
                                    ],
                                ],
                                [
                                    'title' => 'From visibility to action',
                                    'intro' => 'AI Visibility becomes valuable when it leads to better marketing decisions and execution.',
                                    'steps' => [
                                        ['title' => 'Measure visibility', 'text' => 'See where your brand appears, where it is missing and which competitors are recommended.'],
                                        ['title' => 'Diagnose causes', 'text' => 'Identify missing topics, weak pages, authority gaps, outdated pages and competitor advantages.'],
                                        ['title' => 'Discover opportunities', 'text' => 'Find new topics, markets, buyer questions, landing pages, FAQ sets, content clusters and campaign angles.'],
                                        ['title' => 'Decide what content is needed', 'text' => 'Determine whether the next step is a blog, FAQ, landing page, sector page, comparison page, campaign asset or refresh.'],
                                        ['title' => 'Generate and organize work', 'text' => 'Create briefs, drafts, answer blocks, metadata, internal link recommendations, social posts and publishing tasks.'],
                                        ['title' => 'Learn continuously', 'text' => 'Track which actions improve visibility, how AI systems change and what should happen next.'],
                                    ],
                                ],
                                [
                                    'title' => 'How Argusly turns AI Visibility into Agentic Marketing',
                                    'intro' => 'Argusly is designed for teams that need more than visibility reporting. It connects AI Visibility, root cause analysis, opportunity discovery, content intelligence and governed execution in one operating system.',
                                    'cards' => [
                                        ['title' => 'Measures visibility', 'description' => 'Understand where the brand is mentioned, missing, cited or outperformed by competitors.', 'page_key' => 'ai_search'],
                                        ['title' => 'Analyzes root causes', 'description' => 'Find missing topics, weak pages, authority gaps, unclear positioning and competitor-owned content.'],
                                        ['title' => 'Discovers opportunities', 'description' => 'Turn gaps into buyer questions, market pages, content clusters, comparison angles and campaign ideas.'],
                                        ['title' => 'Generates content', 'description' => 'Prepare blogs, FAQ sets, landing pages, sector pages, answer blocks, metadata and distribution assets.'],
                                        ['title' => 'Organizes execution', 'description' => 'Move actions into planning, review, publishing, monitoring and follow-up workflows.', 'page_key' => 'ai_search_optimization'],
                                    ],
                                ],
                                [
                                    'title' => 'Argusly vs traditional AI Visibility tools',
                                    'intro' => 'Traditional AI Visibility tools help teams see the visibility problem. Argusly helps teams diagnose the problem, decide what to do and coordinate the work needed to improve.',
                                    'table' => [
                                        'headers' => ['Capability', 'Traditional AI Visibility tools', 'Argusly'],
                                        'rows' => [
                                            ['Measures AI mentions', 'Yes', 'Yes'],
                                            ['Tracks AI search presence', 'Yes', 'Yes'],
                                            ['Compares competitors', 'Often', 'Yes'],
                                            ['Explains why visibility is missing', 'Limited', 'Yes'],
                                            ['Finds missing topics and content', 'Limited', 'Yes'],
                                            ['Discovers market opportunities', 'Rarely', 'Yes'],
                                            ['Generates briefs and content assets', 'Rarely', 'Yes'],
                                            ['Organizes execution workflows', 'No', 'Yes'],
                                            ['Learns from outcomes', 'Limited', 'Yes'],
                                        ],
                                    ],
                                ],
                            ],
                            'faq_title' => 'AI Visibility & Agentic Marketing FAQ',
                            'faq' => [
                                ['question' => 'What is AI Visibility?', 'answer' => 'AI Visibility is the ability of a brand to be found, understood, cited and recommended by AI systems when people ask relevant questions.'],
                                ['question' => 'Is AI Visibility the same as GEO?', 'answer' => 'Not exactly. GEO, or Generative Engine Optimization, focuses on improving content for generative AI systems. AI Visibility is the broader outcome: whether your brand appears, is cited and is represented correctly in AI answers.'],
                                ['question' => 'What is ChatGPT Visibility?', 'answer' => 'ChatGPT Visibility refers to whether and how your brand appears in ChatGPT responses for important buyer questions, category prompts and recommendation-style queries.'],
                                ['question' => 'Why is measuring AI mentions not enough?', 'answer' => 'Measuring mentions shows where your brand appears. It does not explain why visibility is missing, which content gaps matter, what competitors are doing better or what actions should happen next.'],
                                ['question' => 'How does Argusly differ from AI Visibility tools?', 'answer' => 'Argusly measures visibility, but also analyzes root causes, discovers opportunities, generates content assets and organizes execution. It is designed as an Agentic Marketing Operating System, not only a visibility dashboard.'],
                                ['question' => 'How does Argusly support AI Search Optimization?', 'answer' => 'Argusly helps identify the topics, pages, answer blocks, internal links, proof points and content structures needed to make a brand easier for AI search systems to understand and recommend.'],
                            ],
                            'related_page_keys' => ['ai_search', 'geo', 'ai_search_optimization', 'llm_visibility'],
                            'platform_links' => [
                                ['label' => 'Agentic Marketing', 'route' => 'public.agentic-marketing'],
                                ['label' => 'AI Visibility solution', 'route' => 'public.solutions.ai-visibility'],
                                ['label' => 'Opportunity Intelligence', 'route' => 'public.solutions.opportunity-intelligence'],
                                ['label' => 'Platform overview', 'route' => 'public.product.platform'],
                            ],
                            'cta' => [
                                'eyebrow' => 'Move beyond AI Visibility reporting',
                                'title' => 'Understand why your brand is missing and organize the work to improve it.',
                                'text' => 'Use Argusly to connect AI Visibility, root cause analysis, opportunity discovery, content generation and governed execution.',
                                'primary_label' => 'Plan a platform walkthrough',
                                'primary_route' => 'public.company.contact',
                                'primary_params' => ['subject' => 'ai-visibility-agentic-marketing'],
                                'secondary_label' => 'Explore Agentic Marketing',
                                'secondary_route' => 'public.agentic-marketing',
                            ],
                        ],
                    ],
                    'nl' => [
                        'title' => 'AI Visibility & Agentic Marketing',
                        'slug' => 'ai-visibility-agentic-marketing',
                        'seo_title' => 'AI Visibility & Agentic Marketing | Van AI Search inzichten naar uitvoering | Argusly',
                        'meta_description' => 'AI Visibility laat zien of AI-systemen je merk noemen, citeren en aanbevelen. Argusly gaat verder door oorzaken te verklaren, kansen te ontdekken, content te genereren en marketinguitvoering te organiseren.',
                        'canonical_path' => '/nl/ai-visibility-agentic-marketing',
                        'content' => [
                            'eyebrow' => 'AI Visibility & Agentic Marketing',
                            'subheadline' => 'AI Visibility is belangrijk. Het is niet genoeg.',
                            'intro' => 'AI Visibility tools laten zien of je merk verschijnt in AI-antwoorden, AI search en LLM-aanbevelingen. Argusly helpt begrijpen waarom zichtbaarheid ontbreekt, welke kansen concurrenten winnen, welke content nodig is en hoe je het werk organiseert dat zichtbaarheid verbetert.',
                            'hero_primary_label' => 'Plan een platform walkthrough',
                            'hero_primary_route' => 'public.company.contact',
                            'hero_primary_params' => ['subject' => 'ai-visibility-agentic-marketing'],
                            'hero_secondary_label' => 'Bekijk Agentic Marketing',
                            'hero_secondary_route' => 'public.agentic-marketing',
                            'sections' => [
                                [
                                    'title' => 'Wat is AI Visibility?',
                                    'intro' => 'AI Visibility is de mate waarin AI-systemen je merk, producten, experts en content kunnen vinden, begrijpen, citeren en aanbevelen wanneer mensen relevante vragen stellen.',
                                    'paragraphs' => [
                                        'In traditionele search betekent zichtbaarheid vaak ranken op een resultatenpagina. In AI search is zichtbaarheid breder. Je merk kan genoemd worden in een gegenereerd antwoord, geciteerd worden als bron, samengevat worden als optie, vergeleken worden met concurrenten of volledig ontbreken.',
                                        'AI Visibility is niet alleen het aantal AI mentions. Het is het vermogen van AI-systemen om je relevantie te begrijpen, je content te vertrouwen en je aan te bevelen op momenten waarop kopers opties onderzoeken.',
                                    ],
                                    'bullets' => [
                                        'Of je merk wordt genoemd in AI-antwoorden',
                                        'Of je pagina\'s worden geciteerd als bron',
                                        'Of AI-systemen je categorie en expertise begrijpen',
                                        'Of concurrenten in plaats van jou worden aanbevolen',
                                        'Of gegenereerde samenvattingen je bedrijf correct weergeven',
                                    ],
                                ],
                                [
                                    'title' => 'Waarom alleen meten niet genoeg is',
                                    'intro' => 'Weten dat je merk ontbreekt in ChatGPT, Gemini, Perplexity of AI search is nuttig. Maar meten vertelt een marketingteam nog niet wat het moet doen.',
                                    'paragraphs' => [
                                        'De meeste AI Visibility tools beantwoorden vragen zoals: worden we genoemd, hoe vaak worden we genoemd, welke concurrenten verschijnen vaker, welke prompts activeren ons merk en welke bronnen worden geciteerd.',
                                        'Die vragen zijn belangrijk, maar maken het werk niet af. Teams moeten ook weten waarom het merk ontbreekt, welke topics of pagina\'s zwak zijn, welke concurrenten sterker zijn, welke buyer questions onbeantwoord blijven en hoe uitvoering georganiseerd moet worden.',
                                    ],
                                    'qa_blocks' => [
                                        [
                                            'question' => 'Het praktische verschil',
                                            'answer' => 'AI Visibility tools vertellen waar je staat. Argusly vertelt waarom je daar staat en welk werk daarna moet gebeuren.',
                                        ],
                                    ],
                                ],
                                [
                                    'title' => 'Waarom organisaties onzichtbaar blijven in AI-systemen',
                                    'intro' => 'Organisaties zijn meestal niet onzichtbaar door één ontbrekende metric. Ze zijn onzichtbaar omdat AI-systemen te weinig duidelijke, bruikbare en betrouwbare informatie hebben.',
                                    'steps' => [
                                        ['title' => 'Ontbrekende onderwerpen', 'text' => 'Belangrijke buyer questions worden niet beantwoord op de website.'],
                                        ['title' => 'Ontbrekende contentformats', 'text' => 'De site mist FAQ-pagina\'s, comparison pages, sectorpagina\'s, implementatiegidsen, answer blocks of proof-led landing pages.'],
                                        ['title' => 'Zwakke autoriteitssignalen', 'text' => 'Het merk heeft beperkte topical depth, weinig interne links, zwakke entity coverage of dunne ondersteunende content.'],
                                        ['title' => 'Onduidelijke positionering', 'text' => 'AI-systemen begrijpen niet makkelijk voor wie het bedrijf is, wat het aanbiedt, hoe het verschilt of wanneer het aanbevolen moet worden.'],
                                        ['title' => 'Sterkere concurrentiecontent', 'text' => 'Concurrenten hebben duidelijkere pagina\'s, beter bewijs, rijkere clusters, completere antwoorden of sterkere citation patterns.'],
                                        ['title' => 'Bewijs is niet extractable', 'text' => 'Cases, expertise, servicedetails, outcomes en differentiators bestaan misschien wel, maar niet in een vorm die AI-systemen makkelijk kunnen gebruiken.'],
                                    ],
                                ],
                                [
                                    'title' => 'Hoe AI-systemen bedrijven selecteren',
                                    'intro' => 'AI-systemen reproduceren niet simpelweg traditionele rankings. Ze genereren antwoorden vanuit patronen, bronnen, contentkwaliteit, entity understanding en beschikbaar bewijs.',
                                    'paragraphs' => [
                                        'Wanneer AI-systemen een bedrijf aanbevelen, steunen ze vaak op heldere categorierelatie, pagina\'s die de vraag direct beantwoorden, consistente merk- en entity-informatie, sterke topical coverage, nuttige comparison content, betrouwbare citations, concreet bewijs en content die makkelijk samen te vatten is.',
                                        'Daarom zijn AI Search Optimization en Generative Engine Optimization niet alleen technische taken. Ze vragen om content, structuur, bewijs, autoriteit en voortdurende verbetering.',
                                    ],
                                    'bullets' => [
                                        'Duidelijke categorierelatie en consistente entities',
                                        'Pagina\'s die belangrijke buyer questions direct beantwoorden',
                                        'Nuttige decision-stage en comparison content',
                                        'Betrouwbare bronnen, citations, bewijs en voorbeelden',
                                        'Actuele content die makkelijk te samenvatten en hergebruiken is',
                                    ],
                                ],
                                [
                                    'title' => 'Waarom concurrenten wel worden aanbevolen',
                                    'intro' => 'Wanneer een concurrent wordt aanbevolen en jouw merk niet, is dat zelden willekeurig.',
                                    'paragraphs' => [
                                        'Concurrenten winnen AI recommendations vaak omdat ze specifiekere buyer questions beantwoorden, duidelijkere sector- of use-case content publiceren, meer extractable proof bieden, rijkere contentclusters bezitten, betere interne linking hebben of in meer bronmateriaal voorkomen.',
                                        'De praktische vraag is niet alleen waarom zij zichtbaar zijn. De betere vraag is welk werk jouw merk makkelijker te begrijpen, vertrouwen en aanbevelen maakt.',
                                    ],
                                ],
                                [
                                    'title' => 'Van zichtbaarheid naar actie',
                                    'intro' => 'AI Visibility wordt waardevol wanneer het leidt tot betere marketingbeslissingen en uitvoering.',
                                    'steps' => [
                                        ['title' => 'Meet zichtbaarheid', 'text' => 'Zie waar je merk verschijnt, waar het ontbreekt en welke concurrenten worden aanbevolen.'],
                                        ['title' => 'Diagnoseer oorzaken', 'text' => 'Vind ontbrekende onderwerpen, zwakke pagina\'s, authority gaps, verouderde pagina\'s en concurrentievoordelen.'],
                                        ['title' => 'Ontdek kansen', 'text' => 'Vind nieuwe topics, markten, buyer questions, landing pages, FAQ-sets, contentclusters en campagnehoeken.'],
                                        ['title' => 'Bepaal welke content nodig is', 'text' => 'Bepaal of de volgende stap een blog, FAQ, landing page, sectorpagina, comparison page, campagneasset of refresh is.'],
                                        ['title' => 'Genereer en organiseer werk', 'text' => 'Maak briefs, drafts, answer blocks, metadata, interne linkadviezen, social posts en publishingtaken.'],
                                        ['title' => 'Leer continu', 'text' => 'Volg welke acties zichtbaarheid verbeteren, hoe AI-systemen veranderen en wat daarna moet gebeuren.'],
                                    ],
                                ],
                                [
                                    'title' => 'Hoe Argusly AI Visibility omzet in Agentic Marketing',
                                    'intro' => 'Argusly is ontworpen voor teams die meer nodig hebben dan visibility reporting. Het verbindt AI Visibility, root cause analysis, opportunity discovery, content intelligence en gecontroleerde uitvoering in één operating system.',
                                    'cards' => [
                                        ['title' => 'Meet zichtbaarheid', 'description' => 'Begrijp waar het merk wordt genoemd, ontbreekt, geciteerd wordt of door concurrenten wordt overtroffen.', 'page_key' => 'ai_search'],
                                        ['title' => 'Analyseert oorzaken', 'description' => 'Vind ontbrekende topics, zwakke pagina\'s, authority gaps, onduidelijke positionering en competitor-owned content.'],
                                        ['title' => 'Ontdekt kansen', 'description' => 'Zet gaps om in buyer questions, marktpagina\'s, contentclusters, comparison angles en campagne-ideeen.'],
                                        ['title' => 'Genereert content', 'description' => 'Bereid blogs, FAQ-sets, landing pages, sectorpagina\'s, answer blocks, metadata en distributieassets voor.'],
                                        ['title' => 'Organiseert uitvoering', 'description' => 'Zet acties om in planning, review, publishing, monitoring en follow-up workflows.', 'page_key' => 'ai_search_optimization'],
                                    ],
                                ],
                                [
                                    'title' => 'Argusly versus traditionele AI Visibility tools',
                                    'intro' => 'Traditionele AI Visibility tools helpen teams het zichtbaarheidsprobleem zien. Argusly helpt teams het probleem diagnosticeren, bepalen wat ze moeten doen en het werk coordineren dat verbetering mogelijk maakt.',
                                    'table' => [
                                        'headers' => ['Mogelijkheid', 'Traditionele AI Visibility tools', 'Argusly'],
                                        'rows' => [
                                            ['Meet AI mentions', 'Ja', 'Ja'],
                                            ['Volgt AI search presence', 'Ja', 'Ja'],
                                            ['Vergelijkt concurrenten', 'Vaak', 'Ja'],
                                            ['Verklaart waarom zichtbaarheid ontbreekt', 'Beperkt', 'Ja'],
                                            ['Vindt ontbrekende topics en content', 'Beperkt', 'Ja'],
                                            ['Ontdekt marktkansen', 'Zelden', 'Ja'],
                                            ['Genereert briefs en contentassets', 'Zelden', 'Ja'],
                                            ['Organiseert execution workflows', 'Nee', 'Ja'],
                                            ['Leert van outcomes', 'Beperkt', 'Ja'],
                                        ],
                                    ],
                                ],
                            ],
                            'faq_title' => 'AI Visibility & Agentic Marketing FAQ',
                            'faq' => [
                                ['question' => 'Wat is AI Visibility?', 'answer' => 'AI Visibility is het vermogen van een merk om gevonden, begrepen, geciteerd en aanbevolen te worden door AI-systemen wanneer mensen relevante vragen stellen.'],
                                ['question' => 'Is AI Visibility hetzelfde als GEO?', 'answer' => 'Niet precies. GEO, of Generative Engine Optimization, richt zich op content verbeteren voor generatieve AI-systemen. AI Visibility is de bredere uitkomst: of je merk verschijnt, geciteerd wordt en correct wordt weergegeven in AI-antwoorden.'],
                                ['question' => 'Wat is ChatGPT Visibility?', 'answer' => 'ChatGPT Visibility verwijst naar of en hoe je merk verschijnt in ChatGPT-antwoorden voor belangrijke buyer questions, category prompts en recommendation-style queries.'],
                                ['question' => 'Waarom is AI mentions meten niet genoeg?', 'answer' => 'Mentions meten laat zien waar je merk verschijnt. Het verklaart niet waarom zichtbaarheid ontbreekt, welke contentgaps belangrijk zijn, wat concurrenten beter doen of welke acties daarna nodig zijn.'],
                                ['question' => 'Hoe verschilt Argusly van AI Visibility tools?', 'answer' => 'Argusly meet zichtbaarheid, maar analyseert ook oorzaken, ontdekt kansen, genereert contentassets en organiseert uitvoering. Het is ontworpen als Agentic Marketing Operating System, niet alleen als visibility dashboard.'],
                                ['question' => 'Hoe ondersteunt Argusly AI Search Optimization?', 'answer' => 'Argusly helpt topics, pagina\'s, answer blocks, interne links, proof points en contentstructuren identificeren die een merk makkelijker te begrijpen en aanbevelen maken voor AI search systemen.'],
                            ],
                            'related_page_keys' => ['ai_search', 'geo', 'ai_search_optimization', 'llm_visibility'],
                            'platform_links' => [
                                ['label' => 'Agentic Marketing', 'route' => 'public.agentic-marketing'],
                                ['label' => 'AI Visibility oplossing', 'route' => 'public.solutions.ai-visibility'],
                                ['label' => 'Opportunity Intelligence', 'route' => 'public.solutions.opportunity-intelligence'],
                                ['label' => 'Platformoverzicht', 'route' => 'public.product.platform'],
                            ],
                            'cta' => [
                                'eyebrow' => 'Ga verder dan AI Visibility reporting',
                                'title' => 'Begrijp waarom je merk ontbreekt en organiseer het werk dat zichtbaarheid verbetert.',
                                'text' => 'Gebruik Argusly om AI Visibility, root cause analysis, opportunity discovery, contentgeneratie en gecontroleerde uitvoering te verbinden.',
                                'primary_label' => 'Plan een platform walkthrough',
                                'primary_route' => 'public.company.contact',
                                'primary_params' => ['subject' => 'ai-visibility-agentic-marketing'],
                                'secondary_label' => 'Bekijk Agentic Marketing',
                                'secondary_route' => 'public.agentic-marketing',
                            ],
                        ],
                    ],
                ],
            ],
            'seo' => [
                'sort_order' => 10,
                'translations' => [
                    'en' => [
                        'title' => 'SEO explained: how search engines rank your content',
                        'slug' => 'seo',
                        'seo_title' => 'What is SEO and how does it work in 2026?',
                        'meta_description' => 'Learn how SEO works, how search engines rank content, and why SEO is changing in the age of AI.',
                        'canonical_path' => '/en/seo',
                        'content' => [
                            'eyebrow' => 'Traditional search visibility',
                            'subheadline' => 'SEO helps search engines understand, index, and rank your pages, and it now needs to support AI-driven discovery too.',
                            'intro' => 'Search Engine Optimization is still the foundation of digital discoverability. It covers the work that makes a page crawlable, indexable, relevant, and credible enough to earn visibility in Google and other classic search engines.',
                            'sections' => [
                                [
                                    'title' => 'What is changing in SEO',
                                    'intro' => 'Traditional SEO is not disappearing, but the environment around it is changing.',
                                    'paragraphs' => [
                                        'Google still sends high-value traffic, but search journeys no longer end on a results page. Users now scan AI Overviews, featured snippets, answer boxes, and chat interfaces before they decide which source to trust.',
                                        'That exposes the limits of old SEO habits. A page built around keyword repetition and shallow intent coverage may still get indexed, but it is less likely to rank well, earn clicks, or be reused inside AI-generated answers.',
                                    ],
                                ],
                                [
                                    'title' => 'What SEO means in practice',
                                    'intro' => 'SEO is the discipline of making information easy for search engines to access, interpret, and compare.',
                                    'paragraphs' => [
                                        'At page level, that means clear titles, headings, internal links, metadata, and copy that actually answers the search intent behind a query. At site level, it means strong information architecture, topic depth, and consistent internal linking across related pages.',
                                        'For example, if you publish a page about AI search optimization, strong SEO means the page explains the term clearly, targets the right intent, links to supporting pages such as GEO and LLM visibility, and sits inside a topic cluster that reinforces the subject.',
                                    ],
                                ],
                                [
                                    'title' => 'How SEO works',
                                    'intro' => 'Good SEO is a repeatable operating process, not a one-time checklist.',
                                    'steps' => [
                                        [
                                            'title' => 'Choose the search intent and primary keyword',
                                            'text' => 'Start with the question the page should answer and the phrase a decision maker would actually search for.',
                                        ],
                                        [
                                            'title' => 'Build the page around a clear answer',
                                            'text' => 'Use an explicit H1, useful subheadings, concrete examples, and concise sections so both people and crawlers can follow the logic.',
                                        ],
                                        [
                                            'title' => 'Add supporting search signals',
                                            'text' => 'Write strong meta data, keep the page indexable, and make sure canonical and locale signals are accurate.',
                                        ],
                                        [
                                            'title' => 'Connect the page to the rest of the topic',
                                            'text' => 'Internal links, supporting articles, and related pages help search engines understand topical authority rather than isolated content.',
                                        ],
                                        [
                                            'title' => 'Measure and refresh',
                                            'text' => 'Review ranking movement, clicks, coverage, and content gaps, then update weak pages instead of publishing once and forgetting them.',
                                        ],
                                    ],
                                ],
                                [
                                    'title' => 'Why SEO matters now',
                                    'intro' => 'The shift from search results to answers makes strong structure more valuable, not less.',
                                    'paragraphs' => [
                                        'When engines move from listing links to composing answers, they rely even more on pages that are explicit, well-structured, and topically complete. If your content is vague or fragmented, it becomes harder to rank and harder to reuse.',
                                        'That is why modern SEO now overlaps with AI search, LLM retrieval, and generative answer visibility. The same page may need to perform in a search result, an answer box, and an AI-generated summary.',
                                    ],
                                ],
                                [
                                    'title' => 'How Argusly supports SEO',
                                    'intro' => 'Argusly turns SEO from disconnected tasks into a structured content workflow.',
                                    'paragraphs' => [
                                        'Teams can build content chains around a topic, keep page structures consistent, and connect SEO work to GEO and LLM visibility instead of managing each in a separate system.',
                                        'Because Argusly works with structured content, internal linking, and LLM-ready outputs such as markdown and llms.txt, it helps teams publish pages that serve both classic search engines and answer-first environments.',
                                    ],
                                    'bullets' => [
                                        'Create content chains that expand topical authority instead of isolated articles',
                                        'Publish structured content with clear metadata, hierarchy, and internal linking',
                                        'Connect SEO and GEO in one workflow rather than separate reporting silos',
                                        'Support LLM-ready outputs through markdown delivery and llms.txt visibility',
                                    ],
                                ],
                                [
                                    'title' => 'Key takeaways',
                                    'bullets' => [
                                        'SEO still drives high-value discovery in traditional search engines',
                                        'Strong SEO now depends on clarity, structure, and topical coverage rather than keywords alone',
                                        'Pages need to work in rankings and in answer-driven interfaces',
                                        'Argusly helps teams operationalize SEO with structured content and internal linking',
                                    ],
                                ],
                            ],
                            'hub_page_key' => 'ai_search',
                            'related_page_keys' => ['ai_search', 'geo', 'seo_vs_geo'],
                            'platform_links' => [
                                ['label' => 'Platform overview', 'route' => 'public.product.platform'],
                                ['label' => 'Pricing', 'route' => 'pricing'],
                            ],
                            'cta' => [
                                'eyebrow' => 'See the workflow',
                                'title' => 'Turn SEO from a checklist into a publishing system',
                                'text' => 'Use Argusly to plan, structure, interlink, and publish pages that support both rankings and AI visibility.',
                                'primary_label' => 'Book a demo',
                                'primary_route' => 'public.early-access.show',
                                'primary_params' => ['intent' => 'demo'],
                                'secondary_label' => 'Contact team',
                                'secondary_route' => 'public.company.contact',
                            ],
                        ],
                    ],
                    'nl' => [
                        'title' => 'SEO uitgelegd: hoe je gevonden wordt in zoekmachines',
                        'slug' => 'seo',
                        'seo_title' => 'Wat is SEO en hoe werkt het in 2026?',
                        'meta_description' => 'Leer wat SEO is, hoe zoekmachines werken en waarom traditionele SEO verandert door AI en nieuwe zoekervaringen.',
                        'canonical_path' => '/nl/seo',
                        'content' => [
                            'eyebrow' => 'Traditionele zoekvindbaarheid',
                            'subheadline' => 'SEO helpt zoekmachines je pagina’s begrijpen, indexeren en ranken, en moet nu ook AI-gedreven discovery ondersteunen.',
                            'intro' => 'Search Engine Optimization blijft het fundament van digitale vindbaarheid. Het gaat om het werk dat een pagina crawlbaar, indexeerbaar, relevant en geloofwaardig maakt voor Google en andere klassieke zoekmachines.',
                            'sections' => [
                                [
                                    'title' => 'Wat er verandert in SEO',
                                    'intro' => 'Traditionele SEO verdwijnt niet, maar de omgeving eromheen verandert wel.',
                                    'paragraphs' => [
                                        'Google blijft waardevol verkeer sturen, maar zoekreizen eindigen niet meer alleen op een resultatenpagina. Gebruikers zien eerst AI Overviews, snippets, antwoordblokken en chatinterfaces voordat ze beslissen welke bron ze vertrouwen.',
                                        'Daardoor worden de beperkingen van oude SEO-gewoonten zichtbaar. Een pagina die vooral op keywordherhaling en dunne intentiedekking leunt, kan nog wel geïndexeerd worden, maar zal minder goed ranken, minder klikken krijgen en minder snel in AI-antwoorden terugkomen.',
                                    ],
                                ],
                                [
                                    'title' => 'Wat SEO in de praktijk betekent',
                                    'intro' => 'SEO is de discipline waarmee je informatie toegankelijk en begrijpelijk maakt voor zoekmachines.',
                                    'paragraphs' => [
                                        'Op paginaniveau betekent dat duidelijke titels, headings, interne links, metadata en copy die de zoekintentie echt beantwoordt. Op siteniveau gaat het om sterke informatiearchitectuur, topicdiepte en consistente interne links tussen verwante pagina’s.',
                                        'Als je bijvoorbeeld een pagina publiceert over AI zoekmachine optimalisatie, vraagt sterke SEO om een heldere uitleg, de juiste zoekintentie, interne links naar ondersteunende pagina’s zoals GEO en LLM-zichtbaarheid, en een topiccluster dat het onderwerp versterkt.',
                                    ],
                                ],
                                [
                                    'title' => 'Hoe SEO werkt',
                                    'intro' => 'Goede SEO is een herhaalbaar proces en geen eenmalige checklist.',
                                    'steps' => [
                                        [
                                            'title' => 'Kies zoekintentie en primair keyword',
                                            'text' => 'Begin met de vraag die de pagina moet beantwoorden en met de term die een beslisser daadwerkelijk zou zoeken.',
                                        ],
                                        [
                                            'title' => 'Bouw de pagina rond een duidelijk antwoord',
                                            'text' => 'Gebruik een expliciete H1, nuttige tussenkoppen, concrete voorbeelden en compacte secties zodat mensen en crawlers de logica snel kunnen volgen.',
                                        ],
                                        [
                                            'title' => 'Voeg ondersteunende zoeksignalen toe',
                                            'text' => 'Schrijf sterke metadata, houd de pagina indexeerbaar en zorg dat canonical- en taalsignalen kloppen.',
                                        ],
                                        [
                                            'title' => 'Verbind de pagina met het onderwerp',
                                            'text' => 'Interne links, ondersteunende artikelen en verwante pagina’s helpen zoekmachines topical authority te begrijpen in plaats van losse content.',
                                        ],
                                        [
                                            'title' => 'Meet en verbeter',
                                            'text' => 'Bekijk rankings, klikken, dekking en contentgaten, en vernieuw zwakke pagina’s actief in plaats van één keer te publiceren en te stoppen.',
                                        ],
                                    ],
                                ],
                                [
                                    'title' => 'Waarom SEO nu telt',
                                    'intro' => 'De verschuiving van zoekresultaten naar antwoorden maakt sterke structuur belangrijker, niet minder belangrijk.',
                                    'paragraphs' => [
                                        'Wanneer zoekmachines van linklijsten naar samengestelde antwoorden bewegen, steunen ze nog meer op pagina’s die expliciet, goed gestructureerd en inhoudelijk volledig zijn. Vage of versnipperde content wordt daardoor minder bruikbaar.',
                                        'Daarom overlapt moderne SEO steeds meer met AI search, LLM retrieval en zichtbaarheid in generatieve antwoorden. Dezelfde pagina moet kunnen presteren in rankings, snippets en AI-samenvattingen.',
                                    ],
                                ],
                                [
                                    'title' => 'Hoe Argusly SEO ondersteunt',
                                    'intro' => 'Argusly maakt van SEO een gestructureerde contentworkflow in plaats van losse taken.',
                                    'paragraphs' => [
                                        'Teams kunnen content chains rond een onderwerp opbouwen, paginastructuren consistent houden en SEO verbinden met GEO en LLM-zichtbaarheid in plaats van alles apart te beheren.',
                                        'Omdat Argusly werkt met gestructureerde content, interne links en LLM-ready output zoals markdown en llms.txt, helpt het teams pagina’s publiceren die werken voor klassieke zoekmachines én answer-first omgevingen.',
                                    ],
                                    'bullets' => [
                                        'Bouw content chains die topical authority versterken in plaats van losse artikelen',
                                        'Publiceer gestructureerde content met duidelijke metadata, hiërarchie en interne links',
                                        'Verbind SEO en GEO in één workflow in plaats van aparte rapportagesilo’s',
                                        'Ondersteun LLM-ready output via markdown delivery en llms.txt',
                                    ],
                                ],
                                [
                                    'title' => 'Belangrijkste punten',
                                    'bullets' => [
                                        'SEO blijft waardevolle discovery opleveren in traditionele zoekmachines',
                                        'Sterke SEO draait nu meer om helderheid, structuur en topicdekking dan om keywords alleen',
                                        'Pagina’s moeten werken in rankings én in answer-driven interfaces',
                                        'Argusly helpt teams SEO operationeel te maken met gestructureerde content en interne links',
                                    ],
                                ],
                            ],
                            'hub_page_key' => 'ai_search',
                            'related_page_keys' => ['ai_search', 'geo', 'seo_vs_geo'],
                            'platform_links' => [
                                ['label' => 'Platformoverzicht', 'route' => 'public.product.platform'],
                                ['label' => 'Prijzen', 'route' => 'pricing'],
                            ],
                            'cta' => [
                                'eyebrow' => 'Bekijk de workflow',
                                'title' => 'Maak van SEO een publicatiesysteem in plaats van een checklist',
                                'text' => 'Gebruik Argusly om pagina’s te plannen, structureren, intern te linken en te publiceren voor rankings én AI-zichtbaarheid.',
                                'primary_label' => 'Plan een demo',
                                'primary_route' => 'public.early-access.show',
                                'primary_params' => ['intent' => 'demo'],
                                'secondary_label' => 'Contact opnemen',
                                'secondary_route' => 'public.company.contact',
                            ],
                        ],
                    ],
                ],
            ],
            'geo' => [
                'sort_order' => 20,
                'translations' => [
                    'en' => [
                        'title' => 'GEO: optimizing for AI and generative search',
                        'slug' => 'geo',
                        'seo_title' => 'What is GEO (Generative Engine Optimization)?',
                        'meta_description' => 'Learn how to optimize your content for AI systems like ChatGPT and Gemini and appear in generated answers.',
                        'canonical_path' => '/en/geo',
                        'content' => [
                            'eyebrow' => 'Generative Engine Optimization',
                            'subheadline' => 'GEO is the practice of shaping content so AI systems can retrieve it, understand it, and use it inside generated answers.',
                            'intro' => 'Generative Engine Optimization focuses on visibility in answer-based systems. Instead of optimizing only for a click from a results page, GEO helps content perform when users ask AI tools for explanations, comparisons, and recommendations.',
                            'sections' => [
                                [
                                    'title' => 'What is broken in traditional optimization',
                                    'intro' => 'Many pages are still written for rankings alone.',
                                    'paragraphs' => [
                                        'Classic SEO often assumes success means appearing in a list of links and winning a click. But AI search products increasingly answer the question directly, summarize several sources, and cite only the pages they find clear enough to trust.',
                                        'That exposes a structural gap. A page can rank reasonably well while still being weak for AI use because the definition is buried, the headings are vague, the entities are unclear, or the page lacks supporting context from related content.',
                                    ],
                                ],
                                [
                                    'title' => 'What GEO means',
                                    'intro' => 'GEO stands for Generative Engine Optimization.',
                                    'paragraphs' => [
                                        'It is the practice of optimizing content for generative search systems that synthesize answers instead of only listing links. GEO focuses on retrieval, extractability, citation potential, and topical clarity.',
                                        'A simple example is a page that defines GEO in the first section, explains the concept with examples, compares it with SEO, and links to pages on LLM visibility and AI search optimization. That structure gives AI systems clear material to reuse.',
                                    ],
                                ],
                                [
                                    'title' => 'How GEO works',
                                    'intro' => 'GEO starts with understanding how answer systems select and reuse information.',
                                    'steps' => [
                                        [
                                            'title' => 'Map the prompts that matter',
                                            'text' => 'Identify the questions buyers, marketers, and teams are asking in AI tools, not just the keywords they type into Google.',
                                        ],
                                        [
                                            'title' => 'Publish explicit answers',
                                            'text' => 'Place definitions, comparisons, examples, and decision criteria in clear sections so an answer engine can extract them without guessing.',
                                        ],
                                        [
                                            'title' => 'Make the page structurally clean',
                                            'text' => 'Use precise headings, lists, and scoped sections so the page can be segmented and cited accurately.',
                                        ],
                                        [
                                            'title' => 'Strengthen the entity context',
                                            'text' => 'Mention products, concepts, and relationships clearly and reinforce them with internal links to related pages.',
                                        ],
                                        [
                                            'title' => 'Track what gets used',
                                            'text' => 'Review where your brand is cited or omitted in AI answers, then improve weak pages based on that evidence.',
                                        ],
                                    ],
                                ],
                                [
                                    'title' => 'Why GEO matters now',
                                    'intro' => 'Search is shifting from ranked lists to generated responses.',
                                    'paragraphs' => [
                                        'That changes what visibility looks like. Instead of asking only whether you rank, teams now need to ask whether their pages are understandable enough to be selected, summarized, and cited.',
                                        'This matters across research, evaluation, and category education. If AI systems answer the question before the user reaches your site, GEO becomes part of the discovery layer that shapes which brands make the shortlist.',
                                    ],
                                ],
                                [
                                    'title' => 'How Argusly supports GEO',
                                    'intro' => 'Argusly gives GEO a practical operating model.',
                                    'paragraphs' => [
                                        'Teams can build structured pages in content chains, connect GEO work to SEO and LLM visibility, and strengthen internal linking across the topic so every page supports a broader knowledge graph.',
                                        'The result is content that is easier to publish consistently and easier for AI systems to parse because the output is structured, interlinked, and available in LLM-ready formats such as markdown and llms.txt.',
                                    ],
                                    'bullets' => [
                                        'Build topic clusters through content chains instead of isolated one-off pages',
                                        'Publish structured content that is easier for answer engines to segment and reuse',
                                        'Combine SEO and GEO in one workflow instead of forcing teams to choose',
                                        'Support AI discovery with internal linking and LLM-ready outputs',
                                    ],
                                ],
                                [
                                    'title' => 'Key takeaways',
                                    'bullets' => [
                                        'GEO optimizes content for AI-generated answers, not only for search result clicks',
                                        'Structured content and clear definitions make a page easier to retrieve and cite',
                                        'GEO builds on SEO rather than replacing it',
                                        'Argusly helps teams operationalize GEO with structure, linking, and LLM-ready publishing',
                                    ],
                                ],
                            ],
                            'hub_page_key' => 'ai_search',
                            'related_page_keys' => ['ai_search', 'llm_visibility', 'ai_search_optimization'],
                            'platform_links' => [
                                ['label' => 'Platform overview', 'route' => 'public.product.platform'],
                                ['label' => 'Pricing', 'route' => 'pricing'],
                            ],
                            'cta' => [
                                'eyebrow' => 'Bring GEO into the workflow',
                                'title' => 'Structure pages for retrieval, citation, and AI answers',
                                'text' => 'Use Argusly to connect GEO work to page structure, content chains, and LLM visibility tracking.',
                                'primary_label' => 'Book a demo',
                                'primary_route' => 'public.early-access.show',
                                'primary_params' => ['intent' => 'demo'],
                                'secondary_label' => 'Contact team',
                                'secondary_route' => 'public.company.contact',
                            ],
                        ],
                    ],
                    'nl' => [
                        'title' => 'GEO: optimaliseren voor AI en generative search',
                        'slug' => 'geo',
                        'seo_title' => 'Wat is GEO (Generative Engine Optimization)?',
                        'meta_description' => 'Ontdek hoe je content optimaliseert voor AI-systemen zoals ChatGPT en Gemini en zichtbaar wordt in gegenereerde antwoorden.',
                        'canonical_path' => '/nl/geo',
                        'content' => [
                            'eyebrow' => 'Generative Engine Optimization',
                            'subheadline' => 'GEO is de praktijk van content zo vormgeven dat AI-systemen die kunnen vinden, begrijpen en gebruiken in gegenereerde antwoorden.',
                            'intro' => 'Generative Engine Optimization richt zich op zichtbaarheid in systemen die antwoorden samenstellen. In plaats van alleen te optimaliseren voor een klik vanuit zoekresultaten, helpt GEO content presteren wanneer gebruikers AI-tools om uitleg, vergelijking of aanbevelingen vragen.',
                            'sections' => [
                                [
                                    'title' => 'Wat er misgaat in traditionele optimalisatie',
                                    'intro' => 'Veel pagina’s zijn nog steeds alleen voor rankings geschreven.',
                                    'paragraphs' => [
                                        'Klassieke SEO gaat er vaak van uit dat succes betekent dat je in een lijst met links verschijnt en een klik wint. Maar AI-zoekproducten beantwoorden vragen steeds vaker direct, vatten meerdere bronnen samen en citeren alleen pagina’s die duidelijk genoeg zijn om te vertrouwen.',
                                        'Daardoor ontstaat een structureel probleem. Een pagina kan nog best ranken, maar toch zwak zijn voor AI-gebruik omdat de definitie verstopt zit, de headings vaag zijn, de entiteiten onduidelijk zijn of omdat ondersteunende context ontbreekt.',
                                    ],
                                ],
                                [
                                    'title' => 'Wat GEO betekent',
                                    'intro' => 'GEO staat voor Generative Engine Optimization.',
                                    'paragraphs' => [
                                        'Het is de praktijk van content optimaliseren voor generatieve zoeksystemen die antwoorden synthetiseren in plaats van alleen links te tonen. GEO draait om retrieval, extracteerbaarheid, citeerbaarheid en topical clarity.',
                                        'Een eenvoudig voorbeeld is een pagina die GEO meteen definieert, het concept met voorbeelden uitlegt, het vergelijkt met SEO en doorlinkt naar pagina’s over LLM-zichtbaarheid en AI zoekmachine optimalisatie. Zo krijgt een AI-systeem duidelijke bouwstenen om te hergebruiken.',
                                    ],
                                ],
                                [
                                    'title' => 'Hoe GEO werkt',
                                    'intro' => 'GEO begint bij begrijpen hoe antwoordsystemen informatie selecteren en hergebruiken.',
                                    'steps' => [
                                        [
                                            'title' => 'Breng relevante prompts in kaart',
                                            'text' => 'Bepaal welke vragen kopers, marketeers en teams in AI-tools stellen, niet alleen welke keywords ze in Google typen.',
                                        ],
                                        [
                                            'title' => 'Publiceer expliciete antwoorden',
                                            'text' => 'Zet definities, vergelijkingen, voorbeelden en besliscriteria in heldere secties zodat een antwoordsysteem ze zonder giswerk kan gebruiken.',
                                        ],
                                        [
                                            'title' => 'Houd de pagina structureel schoon',
                                            'text' => 'Gebruik precieze headings, lijsten en afgebakende secties zodat de pagina correct kan worden opgesplitst en geciteerd.',
                                        ],
                                        [
                                            'title' => 'Versterk de entiteitscontext',
                                            'text' => 'Noem producten, begrippen en relaties expliciet en versterk die met interne links naar verwante pagina’s.',
                                        ],
                                        [
                                            'title' => 'Volg wat echt wordt gebruikt',
                                            'text' => 'Bekijk waar je merk wordt geciteerd of juist ontbreekt in AI-antwoorden en verbeter zwakke pagina’s op basis van dat bewijs.',
                                        ],
                                    ],
                                ],
                                [
                                    'title' => 'Waarom GEO nu belangrijk is',
                                    'intro' => 'Zoeken verschuift van ranglijsten naar gegenereerde antwoorden.',
                                    'paragraphs' => [
                                        'Daardoor verandert wat zichtbaarheid betekent. Teams moeten niet alleen vragen of ze ranken, maar ook of hun pagina’s duidelijk genoeg zijn om geselecteerd, samengevat en geciteerd te worden.',
                                        'Dat speelt een rol in onderzoek, evaluatie en categorie-educatie. Als AI-systemen het antwoord al geven voordat iemand je site bezoekt, wordt GEO onderdeel van de discovery-laag die bepaalt welke merken op de shortlist komen.',
                                    ],
                                ],
                                [
                                    'title' => 'Hoe Argusly GEO ondersteunt',
                                    'intro' => 'Argusly geeft GEO een praktisch operationeel model.',
                                    'paragraphs' => [
                                        'Teams kunnen gestructureerde pagina’s bouwen in content chains, GEO koppelen aan SEO en LLM-zichtbaarheid, en interne links versterken zodat elke pagina een breder kennisnetwerk ondersteunt.',
                                        'Het resultaat is content die consistenter gepubliceerd kan worden en makkelijker door AI-systemen te lezen is, omdat de output gestructureerd, onderling verbonden en beschikbaar is in LLM-ready formaten zoals markdown en llms.txt.',
                                    ],
                                    'bullets' => [
                                        'Bouw topicclusters via content chains in plaats van losse pagina’s',
                                        'Publiceer gestructureerde content die antwoordsystemen eenvoudiger kunnen hergebruiken',
                                        'Combineer SEO en GEO in één workflow in plaats van een keuze te forceren',
                                        'Ondersteun AI discovery met interne links en LLM-ready output',
                                    ],
                                ],
                                [
                                    'title' => 'Belangrijkste punten',
                                    'bullets' => [
                                        'GEO optimaliseert content voor AI-antwoorden en niet alleen voor klikken vanuit zoekresultaten',
                                        'Gestructureerde content en heldere definities maken een pagina makkelijker te vinden en te citeren',
                                        'GEO bouwt voort op SEO in plaats van SEO te vervangen',
                                        'Argusly helpt teams GEO operationeel te maken met structuur, linking en LLM-ready publicatie',
                                    ],
                                ],
                            ],
                            'hub_page_key' => 'ai_search',
                            'related_page_keys' => ['ai_search', 'llm_visibility', 'ai_search_optimization'],
                            'platform_links' => [
                                ['label' => 'Platformoverzicht', 'route' => 'public.product.platform'],
                                ['label' => 'Prijzen', 'route' => 'pricing'],
                            ],
                            'cta' => [
                                'eyebrow' => 'Breng GEO in de workflow',
                                'title' => 'Structureer pagina’s voor retrieval, citatie en AI-antwoorden',
                                'text' => 'Gebruik Argusly om GEO te koppelen aan paginastructuur, content chains en LLM-zichtbaarheid.',
                                'primary_label' => 'Plan een demo',
                                'primary_route' => 'public.early-access.show',
                                'primary_params' => ['intent' => 'demo'],
                                'secondary_label' => 'Contact opnemen',
                                'secondary_route' => 'public.company.contact',
                            ],
                        ],
                    ],
                ],
            ],
            'llm_visibility' => [
                'sort_order' => 30,
                'translations' => [
                    'en' => [
                        'title' => 'LLM visibility: when AI mentions your brand',
                        'slug' => 'llm-visibility',
                        'seo_title' => 'What is LLM visibility and how to improve it',
                        'meta_description' => 'Understand how and when your brand appears in AI-generated answers and how to influence it.',
                        'canonical_path' => '/en/llm-visibility',
                        'content' => [
                            'eyebrow' => 'AI answer presence',
                            'subheadline' => 'LLM visibility measures whether your brand, pages, and entities are present when AI systems answer relevant questions.',
                            'intro' => 'As more research happens inside ChatGPT, Gemini, Perplexity, Claude, and similar tools, brand visibility no longer depends on rankings alone. LLM visibility looks at whether a model mentions you, cites you, and places your brand in the right context.',
                            'sections' => [
                                [
                                    'title' => 'What is missing from traditional reporting',
                                    'intro' => 'Traffic data does not tell the whole story anymore.',
                                    'paragraphs' => [
                                        'A buyer can ask an AI tool for software recommendations, category explanations, or vendor comparisons and get a useful answer without ever clicking through to a website. That means strong influence can happen before a session shows up in analytics.',
                                        'Traditional SEO reporting mostly shows rankings, clicks, and sessions. It does not explain whether your brand is being named in AI answers, whether the mention is positive or neutral, or which sources and competitor references shape the answer.',
                                    ],
                                ],
                                [
                                    'title' => 'What LLM visibility means',
                                    'intro' => 'LLM visibility is the degree to which a brand shows up in relevant AI-generated answers.',
                                    'paragraphs' => [
                                        'It includes whether your brand is mentioned at all, whether a page is cited as a source, how clearly your company is described, and whether the answer positions you in the right category or use case.',
                                        'For example, when someone asks for the best platforms for AI search optimization, LLM visibility is not only whether Argusly appears in the answer. It is also whether the answer explains why, cites the right pages, and mentions the product in the correct strategic context.',
                                    ],
                                ],
                                [
                                    'title' => 'How LLM visibility works',
                                    'intro' => 'The work starts with prompts and ends with content improvement.',
                                    'steps' => [
                                        [
                                            'title' => 'Choose a tracked prompt set',
                                            'text' => 'Cover informational, evaluative, and competitor prompts that reflect real discovery behavior.',
                                        ],
                                        [
                                            'title' => 'Record presence, citations, and framing',
                                            'text' => 'Capture whether your brand is mentioned, where it appears in the answer, and which sources or concepts are connected to it.',
                                        ],
                                        [
                                            'title' => 'Compare with competitors',
                                            'text' => 'If rival brands are named more often or in stronger contexts, the gap often points to missing pages, weak entities, or poor internal linking.',
                                        ],
                                        [
                                            'title' => 'Improve the source pages',
                                            'text' => 'Strengthen definitions, topic coverage, evidence, and supporting links so AI systems can interpret the content more confidently.',
                                        ],
                                        [
                                            'title' => 'Repeat and monitor changes',
                                            'text' => 'Visibility should be tracked over time because answer patterns and models change quickly.',
                                        ],
                                    ],
                                ],
                                [
                                    'title' => 'Why LLM visibility matters now',
                                    'intro' => 'Discovery is shifting earlier in the buying journey.',
                                    'paragraphs' => [
                                        'When a shortlist is influenced inside an AI answer, brands need to understand not only whether they can rank, but whether they can be recommended, cited, and correctly framed before the click.',
                                        'This is especially important for B2B teams, where category education, vendor comparisons, and solution framing often happen long before someone fills in a form.',
                                    ],
                                ],
                                [
                                    'title' => 'How Argusly supports LLM visibility',
                                    'intro' => 'Argusly connects monitoring to action.',
                                    'paragraphs' => [
                                        'Instead of treating AI answer visibility as a reporting layer only, Argusly links it back to the pages that need work. Teams can improve structure, strengthen internal links, expand content chains, and publish clearer topic coverage based on observed gaps.',
                                        'Because the content is structured and available in LLM-ready formats such as markdown and llms.txt, the platform helps teams reduce ambiguity between what is published and what an answer system can realistically use.',
                                    ],
                                    'bullets' => [
                                        'Track visibility at prompt, page, and topic level',
                                        'Turn missing mentions into structured content improvements',
                                        'Use internal linking and content chains to strengthen context',
                                        'Publish LLM-ready output alongside SEO and GEO work',
                                    ],
                                ],
                                [
                                    'title' => 'Key takeaways',
                                    'bullets' => [
                                        'LLM visibility measures answer presence, citation, and context, not only traffic',
                                        'A brand can influence decisions in AI tools before any click happens',
                                        'Weak visibility often points to unclear entities, thin topic coverage, or poor linking',
                                        'Argusly helps teams monitor LLM visibility and improve the underlying pages',
                                    ],
                                ],
                            ],
                            'hub_page_key' => 'ai_search',
                            'related_page_keys' => ['ai_search', 'ai_visibility_score', 'geo'],
                            'platform_links' => [
                                ['label' => 'Platform overview', 'route' => 'public.product.platform'],
                                ['label' => 'Pricing', 'route' => 'pricing'],
                            ],
                            'cta' => [
                                'eyebrow' => 'Track and improve',
                                'title' => 'See where your brand appears in AI answers',
                                'text' => 'Use Argusly to measure LLM visibility and turn weak answer presence into specific content actions.',
                                'primary_label' => 'Book a demo',
                                'primary_route' => 'public.early-access.show',
                                'primary_params' => ['intent' => 'demo'],
                                'secondary_label' => 'Contact team',
                                'secondary_route' => 'public.company.contact',
                            ],
                        ],
                    ],
                    'nl' => [
                        'title' => 'LLM zichtbaarheid: wanneer noemt AI jouw merk?',
                        'slug' => 'llm-zichtbaarheid',
                        'seo_title' => 'Wat is LLM visibility en hoe vergroot je het?',
                        'meta_description' => 'Begrijp hoe en wanneer jouw merk verschijnt in AI-antwoorden en hoe je dit actief kunt beïnvloeden.',
                        'canonical_path' => '/nl/llm-zichtbaarheid',
                        'content' => [
                            'eyebrow' => 'Aanwezigheid in AI-antwoorden',
                            'subheadline' => 'LLM-zichtbaarheid meet of je merk, pagina’s en entiteiten zichtbaar zijn wanneer AI-systemen relevante vragen beantwoorden.',
                            'intro' => 'Nu steeds meer onderzoek plaatsvindt in ChatGPT, Gemini, Perplexity, Claude en vergelijkbare tools, hangt merkzichtbaarheid niet meer alleen af van rankings. LLM-zichtbaarheid kijkt of een model je noemt, citeert en in de juiste context plaatst.',
                            'sections' => [
                                [
                                    'title' => 'Wat ontbreekt in traditionele rapportage',
                                    'intro' => 'Verkeersdata vertelt niet meer het hele verhaal.',
                                    'paragraphs' => [
                                        'Een koper kan een AI-tool vragen om software-aanbevelingen, categorie-uitleg of leveranciersvergelijkingen en een bruikbaar antwoord krijgen zonder ooit door te klikken. Daardoor kan invloed ontstaan voordat er een sessie in analytics zichtbaar is.',
                                        'Traditionele SEO-rapportage laat vooral rankings, klikken en sessies zien. Het verklaart niet of je merk wordt genoemd in AI-antwoorden, of die vermelding positief of neutraal is, of welke bronnen en concurrenten het antwoord sturen.',
                                    ],
                                ],
                                [
                                    'title' => 'Wat LLM-zichtbaarheid betekent',
                                    'intro' => 'LLM-zichtbaarheid is de mate waarin een merk terugkomt in relevante AI-antwoorden.',
                                    'paragraphs' => [
                                        'Het gaat om meer dan een losse vermelding. Het omvat of je merk überhaupt wordt genoemd, of een pagina als bron wordt geciteerd, hoe duidelijk je bedrijf wordt beschreven en of het antwoord je in de juiste categorie of use case plaatst.',
                                        'Wanneer iemand bijvoorbeeld vraagt naar de beste platforms voor AI zoekmachine optimalisatie, gaat LLM-zichtbaarheid niet alleen over het noemen van Argusly. Het gaat ook over de uitleg waarom, de juiste bronverwijzingen en de correcte strategische context.',
                                    ],
                                ],
                                [
                                    'title' => 'Hoe LLM-zichtbaarheid werkt',
                                    'intro' => 'Het werk begint bij prompts en eindigt bij contentverbetering.',
                                    'steps' => [
                                        [
                                            'title' => 'Kies een set te volgen prompts',
                                            'text' => 'Neem informatieve, evaluatieve en concurrentgerichte prompts op die echt discovery-gedrag weerspiegelen.',
                                        ],
                                        [
                                            'title' => 'Leg aanwezigheid, citaties en framing vast',
                                            'text' => 'Registreer of je merk genoemd wordt, waar het in het antwoord staat en met welke bronnen of begrippen het verbonden is.',
                                        ],
                                        [
                                            'title' => 'Vergelijk met concurrenten',
                                            'text' => 'Als concurrenten vaker of sterker genoemd worden, wijst dat vaak op ontbrekende pagina’s, zwakke entiteiten of te weinig interne links.',
                                        ],
                                        [
                                            'title' => 'Verbeter de bronpagina’s',
                                            'text' => 'Versterk definities, topicdekking, bewijs en ondersteunende links zodat AI-systemen de content zekerder kunnen interpreteren.',
                                        ],
                                        [
                                            'title' => 'Herhaal en volg veranderingen',
                                            'text' => 'Zichtbaarheid moet over tijd worden gevolgd omdat modellen en antwoordpatronen snel veranderen.',
                                        ],
                                    ],
                                ],
                                [
                                    'title' => 'Waarom LLM-zichtbaarheid nu telt',
                                    'intro' => 'Discovery verschuift naar een eerder moment in de koopreis.',
                                    'paragraphs' => [
                                        'Wanneer een shortlist al in een AI-antwoord wordt beïnvloed, moeten merken niet alleen begrijpen of ze kunnen ranken, maar ook of ze genoemd, geciteerd en juist gepositioneerd worden voordat er een klik volgt.',
                                        'Dat is vooral belangrijk voor B2B-teams, waar categorie-educatie, leveranciersvergelijkingen en oplossingsframing vaak lang voor een contactaanvraag plaatsvinden.',
                                    ],
                                ],
                                [
                                    'title' => 'Hoe Argusly LLM-zichtbaarheid ondersteunt',
                                    'intro' => 'Argusly koppelt monitoring direct aan actie.',
                                    'paragraphs' => [
                                        'In plaats van AI-zichtbaarheid alleen als rapportagelaag te zien, verbindt Argusly die signalen met de pagina’s die verbetering nodig hebben. Teams kunnen structuur aanscherpen, interne links versterken, content chains uitbreiden en topicdekking verbeteren op basis van concrete gaten.',
                                        'Doordat de content gestructureerd is en beschikbaar is in LLM-ready formaten zoals markdown en llms.txt, verkleint het platform de kloof tussen wat je publiceert en wat een antwoordsysteem daadwerkelijk kan gebruiken.',
                                    ],
                                    'bullets' => [
                                        'Volg zichtbaarheid op prompt-, pagina- en topicniveau',
                                        'Vertaal gemiste vermeldingen naar concrete contentverbeteringen',
                                        'Gebruik interne links en content chains om context te versterken',
                                        'Publiceer LLM-ready output naast SEO- en GEO-werk',
                                    ],
                                ],
                                [
                                    'title' => 'Belangrijkste punten',
                                    'bullets' => [
                                        'LLM-zichtbaarheid meet aanwezigheid, citatie en context in antwoorden, niet alleen verkeer',
                                        'Een merk kan beslissingen beïnvloeden in AI-tools voordat er een klik plaatsvindt',
                                        'Zwakke zichtbaarheid wijst vaak op onduidelijke entiteiten, dunne topicdekking of slechte linking',
                                        'Argusly helpt teams LLM-zichtbaarheid te meten en de onderliggende pagina’s te verbeteren',
                                    ],
                                ],
                            ],
                            'hub_page_key' => 'ai_search',
                            'related_page_keys' => ['ai_search', 'ai_visibility_score', 'geo'],
                            'platform_links' => [
                                ['label' => 'Platformoverzicht', 'route' => 'public.product.platform'],
                                ['label' => 'Prijzen', 'route' => 'pricing'],
                            ],
                            'cta' => [
                                'eyebrow' => 'Volg en verbeter',
                                'title' => 'Zie waar je merk terugkomt in AI-antwoorden',
                                'text' => 'Gebruik Argusly om LLM-zichtbaarheid te meten en zwakke answer presence om te zetten in concrete contentacties.',
                                'primary_label' => 'Plan een demo',
                                'primary_route' => 'public.early-access.show',
                                'primary_params' => ['intent' => 'demo'],
                                'secondary_label' => 'Contact opnemen',
                                'secondary_route' => 'public.company.contact',
                            ],
                        ],
                    ],
                ],
            ],
            'ai_search_optimization' => [
                'sort_order' => 40,
                'translations' => [
                    'en' => [
                        'title' => 'AI search optimization: the future of discovery',
                        'slug' => 'ai-search-optimization',
                        'seo_title' => 'AI search optimization explained',
                        'meta_description' => 'Learn how to optimize your content for both search engines and AI-driven discovery systems.',
                        'canonical_path' => '/en/ai-search-optimization',
                        'content' => [
                            'eyebrow' => 'The umbrella discipline',
                            'subheadline' => 'AI search optimization combines SEO, GEO, and LLM visibility into one approach to modern discovery.',
                            'intro' => 'AI search optimization is the broader strategy for being discoverable in a world where search includes rankings, answer engines, chat interfaces, and AI-assisted recommendations. It treats traditional SEO, generative optimization, and AI answer visibility as connected parts of one system.',
                            'sections' => [
                                [
                                    'title' => 'Why separate tactics are no longer enough',
                                    'intro' => 'Many teams still treat SEO, AI visibility, and publishing as unrelated workstreams.',
                                    'paragraphs' => [
                                        'That creates fragmentation. One team optimizes metadata, another experiments with AI prompts, and a third publishes content without a clear structure or internal linking model. The result is uneven visibility and weak topical authority.',
                                        'As discovery shifts from search results to answers, that fragmentation becomes more expensive. A page needs to rank, explain, and be reusable. If those jobs are handled separately, content quality and consistency usually suffer.',
                                    ],
                                ],
                                [
                                    'title' => 'What AI search optimization means',
                                    'intro' => 'AI search optimization is the umbrella category for discovery in search and answer systems.',
                                    'paragraphs' => [
                                        'It includes traditional SEO for crawling and ranking, GEO for retrieval and citation in generated answers, and LLM visibility work for tracking how brands appear across AI interfaces.',
                                        'A practical example is a topic cluster on AI search optimization that includes a foundational SEO page, a GEO explainer, a page on LLM visibility, a comparison page on SEO vs GEO, and a measurement page on AI Visibility Score. Together, those pages cover the topic from multiple discovery angles.',
                                    ],
                                ],
                                [
                                    'title' => 'How AI search optimization works',
                                    'intro' => 'The discipline works best as a structured content program.',
                                    'steps' => [
                                        [
                                            'title' => 'Map topics and decision-stage questions',
                                            'text' => 'Start with the questions people ask in search engines and AI tools across awareness, evaluation, and selection.',
                                        ],
                                        [
                                            'title' => 'Build a connected page set',
                                            'text' => 'Create a cluster of pages that define concepts, compare approaches, answer objections, and support each other with internal links.',
                                        ],
                                        [
                                            'title' => 'Publish in clear, machine-readable structures',
                                            'text' => 'Use explicit headings, definitions, examples, and metadata so both search crawlers and answer systems can interpret the content.',
                                        ],
                                        [
                                            'title' => 'Track classic and AI-era visibility',
                                            'text' => 'Measure rankings, clicks, prompt presence, citations, and share of voice together instead of treating them as separate stories.',
                                        ],
                                        [
                                            'title' => 'Refresh what underperforms',
                                            'text' => 'Use visibility gaps to update weak pages, expand the cluster, and strengthen internal linking where context is missing.',
                                        ],
                                    ],
                                ],
                                [
                                    'title' => 'Why it matters now',
                                    'intro' => 'The future of discovery is blended, not channel-specific.',
                                    'paragraphs' => [
                                        'People will keep using search engines, but more of the evaluation layer is moving into AI-generated answers. That means brands need a discovery strategy that works across rankings, summaries, and recommendations.',
                                        'AI search optimization is useful because it reflects how users actually discover information now. They do not separate SEO, GEO, and LLM behavior. They ask questions and expect one good answer.',
                                    ],
                                ],
                                [
                                    'title' => 'How Argusly supports AI search optimization',
                                    'intro' => 'Argusly provides the operating layer behind the strategy.',
                                    'paragraphs' => [
                                        'Teams can plan and publish content chains, structure pages consistently, combine SEO and GEO signals, and improve LLM visibility without switching between disconnected systems.',
                                        'Because the output is structured and can be delivered in formats such as markdown and llms.txt, Argusly helps teams create a content environment that is easier for search engines, AI systems, and people to use.',
                                    ],
                                    'bullets' => [
                                        'Connect SEO, GEO, and LLM visibility in one workflow',
                                        'Build structured content chains around strategic topics',
                                        'Strengthen discovery with internal linking and reusable page structures',
                                        'Publish LLM-ready output while keeping classic SEO foundations intact',
                                    ],
                                ],
                                [
                                    'title' => 'Key takeaways',
                                    'bullets' => [
                                        'AI search optimization is the umbrella discipline for discovery in search and AI answer systems',
                                        'It combines SEO, GEO, and LLM visibility rather than replacing one with another',
                                        'The strongest approach is a connected content cluster with clear structure and internal links',
                                        'Argusly helps teams operationalize AI search optimization from planning through publishing',
                                    ],
                                ],
                            ],
                            'hub_page_key' => 'ai_search',
                            'related_page_keys' => ['ai_search', 'seo', 'geo', 'llm_visibility'],
                            'platform_links' => [
                                ['label' => 'Platform overview', 'route' => 'public.product.platform'],
                                ['label' => 'Pricing', 'route' => 'pricing'],
                            ],
                            'cta' => [
                                'eyebrow' => 'Use one operating layer',
                                'title' => 'Run AI search optimization as a structured program',
                                'text' => 'Use Argusly to connect SEO, GEO, LLM visibility, and publishing in one repeatable workflow.',
                                'primary_label' => 'Book a demo',
                                'primary_route' => 'public.early-access.show',
                                'primary_params' => ['intent' => 'demo'],
                                'secondary_label' => 'Request early access',
                                'secondary_route' => 'public.early-access.show',
                                'secondary_params' => ['intent' => 'early_access'],
                            ],
                        ],
                    ],
                    'nl' => [
                        'title' => 'AI search optimization: de toekomst van vindbaarheid',
                        'slug' => 'ai-zoekmachine-optimalisatie',
                        'seo_title' => 'AI search optimization: SEO en GEO gecombineerd',
                        'meta_description' => 'Begrijp hoe AI search werkt en hoe je jouw content optimaliseert voor zowel zoekmachines als AI-systemen.',
                        'canonical_path' => '/nl/ai-zoekmachine-optimalisatie',
                        'content' => [
                            'eyebrow' => 'De overkoepelende discipline',
                            'subheadline' => 'AI zoekmachine optimalisatie brengt SEO, GEO en LLM-zichtbaarheid samen in één aanpak voor moderne discovery.',
                            'intro' => 'AI zoekmachine optimalisatie is de bredere strategie om vindbaar te zijn in een wereld waar zoeken bestaat uit rankings, antwoordsystemen, chatinterfaces en AI-gestuurde aanbevelingen. Het behandelt traditionele SEO, generatieve optimalisatie en zichtbaarheid in AI-antwoorden als verbonden onderdelen van één systeem.',
                            'sections' => [
                                [
                                    'title' => 'Waarom losse tactieken niet meer genoeg zijn',
                                    'intro' => 'Veel teams behandelen SEO, AI-zichtbaarheid en publishing nog steeds als losse werkstromen.',
                                    'paragraphs' => [
                                        'Dat leidt tot versnippering. Het ene team optimaliseert metadata, een ander experimenteert met AI-prompts, en een derde publiceert content zonder duidelijke structuur of model voor interne links. Het resultaat is ongelijke zichtbaarheid en zwakke topical authority.',
                                        'Naarmate discovery verschuift van zoekresultaten naar antwoorden, wordt die versnippering duurder. Een pagina moet ranken, uitleggen en herbruikbaar zijn. Als die taken los van elkaar worden uitgevoerd, lijdt kwaliteit en consistentie eronder.',
                                    ],
                                ],
                                [
                                    'title' => 'Wat AI zoekmachine optimalisatie betekent',
                                    'intro' => 'AI zoekmachine optimalisatie is de overkoepelende categorie voor vindbaarheid in zoek- en antwoordsystemen.',
                                    'paragraphs' => [
                                        'Daaronder vallen traditionele SEO voor crawling en ranking, GEO voor retrieval en citatie in gegenereerde antwoorden, en LLM-zichtbaarheid om te volgen hoe merken in AI-interfaces verschijnen.',
                                        'Een praktisch voorbeeld is een topiccluster over AI zoekmachine optimalisatie met een SEO-pagina, een GEO-uitleg, een pagina over LLM-zichtbaarheid, een vergelijking tussen SEO en GEO en een meetpagina over AI Visibility Score. Samen dekken die het onderwerp vanuit meerdere discovery-hoeken.',
                                    ],
                                ],
                                [
                                    'title' => 'Hoe AI zoekmachine optimalisatie werkt',
                                    'intro' => 'Deze discipline werkt het best als gestructureerd contentprogramma.',
                                    'steps' => [
                                        [
                                            'title' => 'Breng topics en vragen per beslisfase in kaart',
                                            'text' => 'Begin met de vragen die mensen stellen in zoekmachines en AI-tools tijdens oriëntatie, evaluatie en selectie.',
                                        ],
                                        [
                                            'title' => 'Bouw een verbonden set pagina’s',
                                            'text' => 'Maak een cluster van pagina’s die begrippen definiëren, benaderingen vergelijken, bezwaren beantwoorden en elkaar versterken via interne links.',
                                        ],
                                        [
                                            'title' => 'Publiceer in heldere, machineleesbare structuren',
                                            'text' => 'Gebruik expliciete headings, definities, voorbeelden en metadata zodat zoekmachines en antwoordsystemen de content goed kunnen interpreteren.',
                                        ],
                                        [
                                            'title' => 'Volg klassieke en AI-gedreven zichtbaarheid',
                                            'text' => 'Meet rankings, klikken, aanwezigheid in prompts, citaties en share of voice samen in plaats van als losse verhalen.',
                                        ],
                                        [
                                            'title' => 'Vernieuw wat achterblijft',
                                            'text' => 'Gebruik zichtbaarheidsgaten om zwakke pagina’s te verbeteren, het cluster uit te breiden en interne linking te versterken waar context ontbreekt.',
                                        ],
                                    ],
                                ],
                                [
                                    'title' => 'Waarom dit nu belangrijk is',
                                    'intro' => 'De toekomst van discovery is gemengd en niet kanaalgebonden.',
                                    'paragraphs' => [
                                        'Mensen blijven zoekmachines gebruiken, maar een groter deel van de evaluatiefase verschuift naar AI-antwoorden. Daardoor hebben merken een discovery-strategie nodig die werkt in rankings, samenvattingen en aanbevelingen.',
                                        'AI zoekmachine optimalisatie is nuttig omdat het aansluit op hoe mensen nu echt informatie vinden. Zij maken geen onderscheid tussen SEO, GEO en LLM-gedrag. Ze stellen een vraag en verwachten één goed antwoord.',
                                    ],
                                ],
                                [
                                    'title' => 'Hoe Argusly AI zoekmachine optimalisatie ondersteunt',
                                    'intro' => 'Argusly levert de operationele laag achter die strategie.',
                                    'paragraphs' => [
                                        'Teams kunnen content chains plannen en publiceren, paginastructuren consistent houden, SEO- en GEO-signalen combineren en LLM-zichtbaarheid verbeteren zonder tussen losse systemen te schakelen.',
                                        'Omdat de output gestructureerd is en kan worden geleverd in formaten zoals markdown en llms.txt, helpt Argusly teams een contentomgeving bouwen die voor zoekmachines, AI-systemen en mensen eenvoudiger te gebruiken is.',
                                    ],
                                    'bullets' => [
                                        'Verbind SEO, GEO en LLM-zichtbaarheid in één workflow',
                                        'Bouw gestructureerde content chains rond strategische onderwerpen',
                                        'Versterk discovery met interne links en herbruikbare paginastructuren',
                                        'Publiceer LLM-ready output terwijl de klassieke SEO-basis intact blijft',
                                    ],
                                ],
                                [
                                    'title' => 'Belangrijkste punten',
                                    'bullets' => [
                                        'AI zoekmachine optimalisatie is de overkoepelende discipline voor discovery in zoek- en AI-antwoordsystemen',
                                        'Het combineert SEO, GEO en LLM-zichtbaarheid in plaats van één discipline te vervangen',
                                        'De sterkste aanpak is een verbonden contentcluster met heldere structuur en interne links',
                                        'Argusly helpt teams AI zoekmachine optimalisatie operationeel maken van planning tot publicatie',
                                    ],
                                ],
                            ],
                            'hub_page_key' => 'ai_search',
                            'related_page_keys' => ['ai_search', 'seo', 'geo', 'llm_visibility'],
                            'platform_links' => [
                                ['label' => 'Platformoverzicht', 'route' => 'public.product.platform'],
                                ['label' => 'Prijzen', 'route' => 'pricing'],
                            ],
                            'cta' => [
                                'eyebrow' => 'Gebruik één operating layer',
                                'title' => 'Run AI zoekmachine optimalisatie als gestructureerd programma',
                                'text' => 'Gebruik Argusly om SEO, GEO, LLM-zichtbaarheid en publishing in één herhaalbare workflow te verbinden.',
                                'primary_label' => 'Plan een demo',
                                'primary_route' => 'public.early-access.show',
                                'primary_params' => ['intent' => 'demo'],
                                'secondary_label' => 'Vraag early access aan',
                                'secondary_route' => 'public.early-access.show',
                                'secondary_params' => ['intent' => 'early_access'],
                            ],
                        ],
                    ],
                ],
            ],
            'seo_vs_geo' => [
                'sort_order' => 50,
                'translations' => [
                    'en' => [
                        'title' => 'SEO vs GEO: key differences explained',
                        'slug' => 'seo-vs-geo',
                        'seo_title' => 'SEO vs GEO: what is the difference?',
                        'meta_description' => 'Discover the differences between SEO and GEO and how to combine them for maximum visibility.',
                        'canonical_path' => '/en/seo-vs-geo',
                        'content' => [
                            'eyebrow' => 'A practical comparison',
                            'subheadline' => 'SEO and GEO solve different discovery moments, and the strongest strategy usually uses both.',
                            'intro' => 'The question is not whether SEO or GEO wins. The useful question is what each discipline optimizes for, where they overlap, and how a team should combine them when search behavior includes both result pages and AI-generated answers.',
                            'sections' => [
                                [
                                    'title' => 'Why the comparison matters',
                                    'intro' => 'Many teams frame GEO as a replacement for SEO.',
                                    'paragraphs' => [
                                        'That is too simplistic. Traditional search traffic still matters, and many AI systems still depend on the same web signals that make a page understandable and credible in classic search.',
                                        'The real change is that SEO alone does not fully describe how modern discovery works. Teams now need to think about ranking, retrieval, citation, and answer inclusion together.',
                                    ],
                                ],
                                [
                                    'title' => 'What the difference looks like',
                                    'intro' => 'SEO and GEO overlap in structure and content quality, but they optimize for different surfaces.',
                                    'table' => [
                                        'headers' => ['Topic', 'SEO', 'GEO'],
                                        'rows' => [
                                            ['Primary surface', 'Traditional search engines', 'AI answer engines and chat interfaces'],
                                            ['Main goal', 'Rank and earn qualified clicks', 'Be selected, summarized, and cited in answers'],
                                            ['Core content pattern', 'Pages that match search intent', 'Pages that are easy to extract and reuse'],
                                            ['Success signals', 'Rankings, clicks, sessions, conversions', 'Mentions, citations, answer position, share of voice'],
                                            ['Structural priority', 'Crawlability, metadata, internal linking', 'Explicit answers, clean sections, entity clarity'],
                                            ['Best use case', 'Capture demand in classic search journeys', 'Influence discovery when users ask AI for answers'],
                                        ],
                                    ],
                                ],
                                [
                                    'title' => 'How to use both',
                                    'intro' => 'Most teams should layer GEO on top of a strong SEO base.',
                                    'steps' => [
                                        [
                                            'title' => 'Start with SEO foundations',
                                            'text' => 'Make pages crawlable, indexable, internally linked, and aligned to clear search intent.',
                                        ],
                                        [
                                            'title' => 'Add answer-ready structure',
                                            'text' => 'Write explicit definitions, comparisons, and examples that can be extracted by generative systems.',
                                        ],
                                        [
                                            'title' => 'Connect related pages',
                                            'text' => 'Build supporting pages around the topic so engines and models can see context across the cluster.',
                                        ],
                                        [
                                            'title' => 'Measure both channels',
                                            'text' => 'Review rankings and clicks alongside AI mentions, citations, and competitor presence.',
                                        ],
                                    ],
                                ],
                                [
                                    'title' => 'Why the comparison matters now',
                                    'intro' => 'Users increasingly move from search results to answers inside the same journey.',
                                    'paragraphs' => [
                                        'Someone may first search in Google, then ask an AI system to compare vendors, then return to the web to validate the recommendation. That means discovery is no longer tied to one interface.',
                                        'Brands that treat SEO and GEO as separate debates often miss the operational point. The same page structure, topic depth, and linking model can support both outcomes if the content is built deliberately.',
                                    ],
                                ],
                                [
                                    'title' => 'How Argusly helps teams use both',
                                    'intro' => 'Argusly is built for mixed discovery environments.',
                                    'paragraphs' => [
                                        'Teams can create structured page sets, combine SEO and GEO workflows, and connect topic pages through content chains and internal linking instead of managing traditional search and AI visibility in separate content systems.',
                                        'That makes it easier to publish content that ranks, supports AI retrieval, and stays available in LLM-ready formats such as markdown and llms.txt.',
                                    ],
                                    'bullets' => [
                                        'Run SEO and GEO inside one structured content workflow',
                                        'Use content chains to connect supporting pages around the same topic',
                                        'Improve internal linking so both search engines and AI systems can follow context',
                                        'Publish pages that support ranking, answer extraction, and LLM-ready delivery',
                                    ],
                                ],
                                [
                                    'title' => 'Key takeaways',
                                    'bullets' => [
                                        'SEO and GEO are different, but they depend on many of the same content fundamentals',
                                        'SEO focuses on rankings and clicks, while GEO focuses on answer inclusion and citation',
                                        'Most teams should keep SEO and add GEO, not replace one with the other',
                                        'Argusly helps teams manage both in one operating layer',
                                    ],
                                ],
                            ],
                            'hub_page_key' => 'ai_search',
                            'related_page_keys' => ['ai_search', 'seo', 'geo'],
                            'platform_links' => [
                                ['label' => 'Platform overview', 'route' => 'public.product.platform'],
                                ['label' => 'Pricing', 'route' => 'pricing'],
                            ],
                            'cta' => [
                                'eyebrow' => 'Use both deliberately',
                                'title' => 'Manage SEO and GEO from one content system',
                                'text' => 'Use Argusly to structure, link, and publish pages that perform in search results and AI answers.',
                                'primary_label' => 'Book a demo',
                                'primary_route' => 'public.early-access.show',
                                'primary_params' => ['intent' => 'demo'],
                                'secondary_label' => 'Contact team',
                                'secondary_route' => 'public.company.contact',
                            ],
                        ],
                    ],
                    'nl' => [
                        'title' => 'SEO vs GEO: wat is het verschil en wat heb je nodig?',
                        'slug' => 'seo-en-geo-verschil',
                        'seo_title' => 'SEO vs GEO uitgelegd: de verschillen en overlap',
                        'meta_description' => 'Ontdek het verschil tussen SEO en GEO en hoe je beide combineert voor maximale online zichtbaarheid.',
                        'canonical_path' => '/nl/seo-en-geo-verschil',
                        'content' => [
                            'eyebrow' => 'Een praktische vergelijking',
                            'subheadline' => 'SEO en GEO lossen verschillende discovery-momenten op, en de sterkste strategie gebruikt meestal allebei.',
                            'intro' => 'De vraag is niet of SEO of GEO wint. De nuttige vraag is waarvoor elke discipline optimaliseert, waar de overlap zit en hoe een team ze combineert wanneer zoekgedrag zowel resultaatpagina’s als AI-antwoorden omvat.',
                            'sections' => [
                                [
                                    'title' => 'Waarom deze vergelijking belangrijk is',
                                    'intro' => 'Veel teams presenteren GEO als vervanging van SEO.',
                                    'paragraphs' => [
                                        'Dat is te simplistisch. Traditioneel zoekverkeer blijft belangrijk, en veel AI-systemen leunen nog steeds op dezelfde websignalen die een pagina begrijpelijk en geloofwaardig maken in klassieke zoekmachines.',
                                        'De echte verandering is dat SEO alleen niet meer volledig beschrijft hoe moderne discovery werkt. Teams moeten ranking, retrieval, citatie en opname in antwoorden samen gaan bekijken.',
                                    ],
                                ],
                                [
                                    'title' => 'Hoe het verschil eruitziet',
                                    'intro' => 'SEO en GEO overlappen in structuur en contentkwaliteit, maar optimaliseren voor andere omgevingen.',
                                    'table' => [
                                        'headers' => ['Onderwerp', 'SEO', 'GEO'],
                                        'rows' => [
                                            ['Primaire omgeving', 'Traditionele zoekmachines', 'AI-antwoordsystemen en chatinterfaces'],
                                            ['Hoofddoel', 'Ranken en gekwalificeerde klikken verdienen', 'Geselecteerd, samengevat en geciteerd worden in antwoorden'],
                                            ['Kernpatroon van content', 'Pagina’s die zoekintentie matchen', 'Pagina’s die makkelijk te extraheren en te hergebruiken zijn'],
                                            ['Succesindicatoren', 'Rankings, klikken, sessies, conversies', 'Vermeldingen, citaties, positie in antwoorden, share of voice'],
                                            ['Structurele prioriteit', 'Crawlbaarheid, metadata, interne linking', 'Expliciete antwoorden, schone secties, heldere entiteiten'],
                                            ['Beste use case', 'Vraag opvangen in klassieke zoekreizen', 'Discovery beïnvloeden wanneer gebruikers AI om antwoorden vragen'],
                                        ],
                                    ],
                                ],
                                [
                                    'title' => 'Hoe je beide inzet',
                                    'intro' => 'De meeste teams doen er goed aan GEO bovenop een sterke SEO-basis te leggen.',
                                    'steps' => [
                                        [
                                            'title' => 'Begin met SEO-fundamenten',
                                            'text' => 'Maak pagina’s crawlbaar, indexeerbaar, intern gelinkt en afgestemd op duidelijke zoekintentie.',
                                        ],
                                        [
                                            'title' => 'Voeg answer-ready structuur toe',
                                            'text' => 'Schrijf expliciete definities, vergelijkingen en voorbeelden die generatieve systemen kunnen gebruiken.',
                                        ],
                                        [
                                            'title' => 'Verbind verwante pagina’s',
                                            'text' => 'Bouw ondersteunende pagina’s rond hetzelfde onderwerp zodat engines en modellen context over het cluster zien.',
                                        ],
                                        [
                                            'title' => 'Meet beide kanalen',
                                            'text' => 'Bekijk rankings en klikken naast AI-vermeldingen, citaties en aanwezigheid van concurrenten.',
                                        ],
                                    ],
                                ],
                                [
                                    'title' => 'Waarom deze vergelijking nu telt',
                                    'intro' => 'Gebruikers bewegen steeds vaker van zoekresultaten naar antwoorden binnen dezelfde reis.',
                                    'paragraphs' => [
                                        'Iemand kan eerst in Google zoeken, daarna een AI-systeem om leveranciersvergelijking vragen en vervolgens teruggaan naar het web om de aanbeveling te controleren. Discovery zit dus niet meer in één interface opgesloten.',
                                        'Merken die SEO en GEO als losse discussies behandelen, missen vaak het operationele punt. Dezelfde paginastructuur, topicdiepte en linkinglogica kunnen beide uitkomsten ondersteunen als de content bewust is opgebouwd.',
                                    ],
                                ],
                                [
                                    'title' => 'Hoe Argusly helpt om beide te gebruiken',
                                    'intro' => 'Argusly is gebouwd voor gemengde discovery-omgevingen.',
                                    'paragraphs' => [
                                        'Teams kunnen gestructureerde paginasets maken, SEO- en GEO-workflows combineren en topicpagina’s verbinden via content chains en interne links in plaats van traditionele search en AI-zichtbaarheid in aparte systemen te beheren.',
                                        'Daardoor wordt het eenvoudiger om content te publiceren die kan ranken, AI retrieval ondersteunt en beschikbaar blijft in LLM-ready formaten zoals markdown en llms.txt.',
                                    ],
                                    'bullets' => [
                                        'Run SEO en GEO in één gestructureerde contentworkflow',
                                        'Gebruik content chains om ondersteunende pagina’s rond hetzelfde onderwerp te verbinden',
                                        'Verbeter interne links zodat zoekmachines en AI-systemen context beter kunnen volgen',
                                        'Publiceer pagina’s die ranking, answer extraction en LLM-ready levering ondersteunen',
                                    ],
                                ],
                                [
                                    'title' => 'Belangrijkste punten',
                                    'bullets' => [
                                        'SEO en GEO verschillen, maar leunen op veel van dezelfde contentfundamenten',
                                        'SEO focust op rankings en klikken, GEO op opname in antwoorden en citatie',
                                        'De meeste teams houden SEO en voegen GEO toe in plaats van het ene door het andere te vervangen',
                                        'Argusly helpt teams beide disciplines in één operating layer te beheren',
                                    ],
                                ],
                            ],
                            'hub_page_key' => 'ai_search',
                            'related_page_keys' => ['ai_search', 'seo', 'geo'],
                            'platform_links' => [
                                ['label' => 'Platformoverzicht', 'route' => 'public.product.platform'],
                                ['label' => 'Prijzen', 'route' => 'pricing'],
                            ],
                            'cta' => [
                                'eyebrow' => 'Gebruik beide bewust',
                                'title' => 'Beheer SEO en GEO vanuit één contentsysteem',
                                'text' => 'Gebruik Argusly om pagina’s te structureren, intern te linken en te publiceren voor zoekresultaten én AI-antwoorden.',
                                'primary_label' => 'Plan een demo',
                                'primary_route' => 'public.early-access.show',
                                'primary_params' => ['intent' => 'demo'],
                                'secondary_label' => 'Contact opnemen',
                                'secondary_route' => 'public.company.contact',
                            ],
                        ],
                    ],
                ],
            ],
            'ai_visibility_score' => [
                'sort_order' => 60,
                'translations' => [
                    'en' => [
                        'title' => 'AI Visibility Score: measure your AI presence',
                        'slug' => 'ai-visibility-score',
                        'seo_title' => 'AI Visibility Score explained',
                        'meta_description' => 'Learn how to measure your visibility in AI systems based on presence, ranking, and context.',
                        'canonical_path' => '/en/ai-visibility-score',
                        'content' => [
                            'eyebrow' => 'A practical metric',
                            'subheadline' => 'An AI Visibility Score is a way to summarize how often and how well your brand appears across relevant AI answers.',
                            'intro' => 'A score will never replace detailed prompt analysis, but it is useful when teams need a simple way to track whether AI answer visibility is improving or getting weaker. The key is to treat the score as an operating signal, not a vanity number.',
                            'sections' => [
                                [
                                    'title' => 'Why teams need a score',
                                    'intro' => 'Raw screenshots and prompt logs do not scale.',
                                    'paragraphs' => [
                                        'As more prompts, models, and competitors are tracked, the amount of evidence grows quickly. Teams need a summary metric that helps them spot change, prioritize work, and compare topics or competitors without reading every answer one by one.',
                                        'That is the role of an AI Visibility Score. It gives a percentage or weighted score for how visible your brand is across a selected prompt set, while still allowing drill-down into the reasons behind the number.',
                                    ],
                                ],
                                [
                                    'title' => 'What an AI Visibility Score means',
                                    'intro' => 'The score is a composite measure of answer presence and quality.',
                                    'paragraphs' => [
                                        'A useful score does not only ask whether your brand appears. It also looks at where you appear, how positively or neutrally you are framed, whether your pages are cited, and how often competitors outrank or outframe you inside answers.',
                                        'For example, if your brand appears in 42 out of 100 tracked prompts, is cited in 28 of them, and is mentioned in a strong top position in 19, the score can combine those signals into one trend line that is easier to monitor.',
                                    ],
                                ],
                                [
                                    'title' => 'How an AI Visibility Score works',
                                    'intro' => 'The value depends on the quality of the underlying prompt and weighting model.',
                                    'steps' => [
                                        [
                                            'title' => 'Define the prompt set',
                                            'text' => 'Choose prompts that reflect your category, use cases, competitors, and decision stages.',
                                        ],
                                        [
                                            'title' => 'Track brand presence',
                                            'text' => 'Measure whether your brand appears at all and whether the answer includes one of your pages as a source.',
                                        ],
                                        [
                                            'title' => 'Add weighting factors',
                                            'text' => 'Include answer position, sentiment, context quality, and competitor share so the score reflects more than simple mention frequency.',
                                        ],
                                        [
                                            'title' => 'Aggregate by page, topic, or market',
                                            'text' => 'Roll the signals up into a percentage or weighted index that can be compared over time.',
                                        ],
                                        [
                                            'title' => 'Use the score to prioritize action',
                                            'text' => 'Treat a falling or weak score as a signal to improve source pages, coverage, and internal linking rather than as an isolated reporting metric.',
                                        ],
                                    ],
                                ],
                                [
                                    'title' => 'Why it matters now',
                                    'intro' => 'Reporting is shifting from rankings alone to answer coverage.',
                                    'paragraphs' => [
                                        'When discovery happens inside AI systems, teams need a measurement model that reflects presence inside answers, not only visibility on a search result page. A score helps translate scattered answer behavior into a directional metric leadership can understand.',
                                        'It also helps prioritize investment. If one topic has weak AI visibility and a competitor dominates the answer context, that is often a sign to improve content depth, structure, and supporting links around that topic.',
                                    ],
                                ],
                                [
                                    'title' => 'How Argusly supports AI Visibility Score workflows',
                                    'intro' => 'Argusly connects the score to content operations.',
                                    'paragraphs' => [
                                        'Teams can use AI visibility data alongside structured content, content chains, and internal linking decisions instead of viewing the score in a reporting vacuum. The platform makes it easier to see which pages should be refreshed and what related pages are missing.',
                                        'Because Argusly combines SEO and GEO thinking with LLM-ready outputs such as markdown and llms.txt, the score becomes a practical trigger for publishing improvements rather than a disconnected dashboard number.',
                                    ],
                                    'bullets' => [
                                        'Use score changes to prioritize refreshes, new pages, and linking improvements',
                                        'Connect visibility tracking to structured content and content chains',
                                        'Compare answer presence with competitor context instead of isolated mentions',
                                        'Tie reporting to LLM-ready publishing outputs and operational next steps',
                                    ],
                                ],
                                [
                                    'title' => 'Key takeaways',
                                    'bullets' => [
                                        'An AI Visibility Score summarizes brand presence across monitored AI answers',
                                        'A useful score includes presence, position, sentiment, citations, and competitor context',
                                        'The score is most valuable when it leads to page-level improvements',
                                        'Argusly turns AI visibility scoring into a structured content workflow',
                                    ],
                                ],
                            ],
                            'hub_page_key' => 'ai_search',
                            'related_page_keys' => ['ai_search', 'llm_visibility', 'ai_search_optimization'],
                            'platform_links' => [
                                ['label' => 'Platform overview', 'route' => 'public.product.platform'],
                                ['label' => 'Pricing', 'route' => 'pricing'],
                            ],
                            'cta' => [
                                'eyebrow' => 'Measure what matters',
                                'title' => 'Turn AI visibility scores into content decisions',
                                'text' => 'Use Argusly to connect answer visibility metrics to source-page improvements, content chains, and internal linking.',
                                'primary_label' => 'Book a demo',
                                'primary_route' => 'public.early-access.show',
                                'primary_params' => ['intent' => 'demo'],
                                'secondary_label' => 'Contact team',
                                'secondary_route' => 'public.company.contact',
                            ],
                        ],
                    ],
                    'nl' => [
                        'title' => 'AI Visibility Score: meet je zichtbaarheid in AI',
                        'slug' => 'ai-visibility-score',
                        'seo_title' => 'AI Visibility Score: meten van AI zichtbaarheid',
                        'meta_description' => 'Leer hoe je zichtbaarheid in AI-systemen meet op basis van aanwezigheid, positie en context.',
                        'canonical_path' => '/nl/ai-visibility-score',
                        'content' => [
                            'eyebrow' => 'Een praktische metric',
                            'subheadline' => 'Een AI Visibility Score vat samen hoe vaak en hoe sterk je merk zichtbaar is in relevante AI-antwoorden.',
                            'intro' => 'Een score vervangt nooit gedetailleerde promptanalyse, maar is wel nuttig wanneer teams eenvoudig willen volgen of AI-zichtbaarheid verbetert of juist verslechtert. De kern is dat je de score als stuursignaal gebruikt en niet als vanity metric.',
                            'sections' => [
                                [
                                    'title' => 'Waarom teams een score nodig hebben',
                                    'intro' => 'Losse screenshots en promptlogs schalen niet.',
                                    'paragraphs' => [
                                        'Naarmate je meer prompts, modellen en concurrenten volgt, groeit de hoeveelheid bewijs snel. Teams hebben dan een samenvattende metric nodig om verandering te herkennen, prioriteiten te stellen en topics of concurrenten te vergelijken zonder elk antwoord apart te lezen.',
                                        'Dat is de rol van een AI Visibility Score. De score geeft een percentage of gewogen index voor hoe zichtbaar je merk is in een geselecteerde promptset, terwijl je nog steeds kunt inzoomen op de oorzaken achter het getal.',
                                    ],
                                ],
                                [
                                    'title' => 'Wat een AI Visibility Score betekent',
                                    'intro' => 'De score is een samengestelde maat voor aanwezigheid en kwaliteit in antwoorden.',
                                    'paragraphs' => [
                                        'Een bruikbare score kijkt niet alleen of je merk voorkomt. Hij kijkt ook waar je voorkomt, hoe positief of neutraal je wordt geframed, of je pagina’s als bron worden geciteerd en hoe vaak concurrenten in antwoorden sterker naar voren komen.',
                                        'Als je merk bijvoorbeeld in 42 van de 100 gevolgde prompts verschijnt, in 28 daarvan wordt geciteerd en in 19 antwoorden hoog staat, kan de score die signalen combineren tot één trendlijn die makkelijker te volgen is.',
                                    ],
                                ],
                                [
                                    'title' => 'Hoe een AI Visibility Score werkt',
                                    'intro' => 'De waarde hangt af van de kwaliteit van de promptset en de weging erachter.',
                                    'steps' => [
                                        [
                                            'title' => 'Definieer de promptset',
                                            'text' => 'Kies prompts die je categorie, use cases, concurrenten en beslisfasen goed afdekken.',
                                        ],
                                        [
                                            'title' => 'Meet aanwezigheid van het merk',
                                            'text' => 'Registreer of je merk voorkomt en of een van je pagina’s als bron in het antwoord terugkomt.',
                                        ],
                                        [
                                            'title' => 'Voeg wegingsfactoren toe',
                                            'text' => 'Neem antwoordpositie, sentiment, kwaliteit van context en concurrentaandeel mee zodat de score meer zegt dan alleen vermeldingsfrequentie.',
                                        ],
                                        [
                                            'title' => 'Agregeer per pagina, topic of markt',
                                            'text' => 'Rol de signalen op naar een percentage of gewogen index die je over tijd kunt vergelijken.',
                                        ],
                                        [
                                            'title' => 'Gebruik de score om actie te prioriteren',
                                            'text' => 'Zie een zwakke of dalende score als signaal om bronpagina’s, topicdekking en interne linking te verbeteren in plaats van als los rapportagegetal.',
                                        ],
                                    ],
                                ],
                                [
                                    'title' => 'Waarom dit nu belangrijk is',
                                    'intro' => 'Rapportage verschuift van rankings alleen naar dekking in antwoorden.',
                                    'paragraphs' => [
                                        'Wanneer discovery plaatsvindt in AI-systemen, hebben teams een meetmodel nodig dat aanwezigheid in antwoorden weerspiegelt en niet alleen zichtbaarheid op een resultatenpagina. Een score helpt verspreid antwoordgedrag te vertalen naar een richtinggevend getal dat ook voor leiderschap bruikbaar is.',
                                        'De score helpt ook bij prioritering. Als een onderwerp zwakke AI-zichtbaarheid heeft en een concurrent de antwoordcontext domineert, is dat vaak een teken dat contentdiepte, structuur en ondersteunende links rond dat onderwerp beter moeten.',
                                    ],
                                ],
                                [
                                    'title' => 'Hoe Argusly AI Visibility Score-workflows ondersteunt',
                                    'intro' => 'Argusly verbindt de score direct met contentoperaties.',
                                    'paragraphs' => [
                                        'Teams kunnen AI-zichtbaarheid naast gestructureerde content, content chains en beslissingen over interne links gebruiken in plaats van de score los te bekijken. Het platform maakt het eenvoudiger om te zien welke pagina’s refreshes nodig hebben en welke verwante pagina’s nog ontbreken.',
                                        'Omdat Argusly SEO en GEO combineert met LLM-ready output zoals markdown en llms.txt, wordt de score een praktisch startsignaal voor publicatieverbetering in plaats van een los dashboardgetal.',
                                    ],
                                    'bullets' => [
                                        'Gebruik scoreveranderingen om refreshes, nieuwe pagina’s en linkingverbeteringen te prioriteren',
                                        'Verbind zichtbaarheidsscores aan gestructureerde content en content chains',
                                        'Vergelijk answer presence met concurrentcontext in plaats van losse vermeldingen',
                                        'Koppel rapportage aan LLM-ready publicatie en operationele vervolgstappen',
                                    ],
                                ],
                                [
                                    'title' => 'Belangrijkste punten',
                                    'bullets' => [
                                        'Een AI Visibility Score vat merkpresence samen over gemonitorde AI-antwoorden',
                                        'Een bruikbare score bevat presence, positie, sentiment, citaties en concurrentcontext',
                                        'De score is het meest waardevol wanneer die leidt tot verbeteringen op paginaniveau',
                                        'Argusly maakt van AI visibility scoring een gestructureerde contentworkflow',
                                    ],
                                ],
                            ],
                            'hub_page_key' => 'ai_search',
                            'related_page_keys' => ['ai_search', 'llm_visibility', 'ai_search_optimization'],
                            'platform_links' => [
                                ['label' => 'Platformoverzicht', 'route' => 'public.product.platform'],
                                ['label' => 'Prijzen', 'route' => 'pricing'],
                            ],
                            'cta' => [
                                'eyebrow' => 'Meet wat ertoe doet',
                                'title' => 'Maak van AI visibility scores concrete contentbeslissingen',
                                'text' => 'Gebruik Argusly om answer visibility te koppelen aan verbeteringen in bronpagina’s, content chains en interne linking.',
                                'primary_label' => 'Plan een demo',
                                'primary_route' => 'public.early-access.show',
                                'primary_params' => ['intent' => 'demo'],
                                'secondary_label' => 'Contact opnemen',
                                'secondary_route' => 'public.company.contact',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
