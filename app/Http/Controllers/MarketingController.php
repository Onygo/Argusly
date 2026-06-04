<?php

namespace App\Http\Controllers;

use App\Mail\ContactRequestSubmitted;
use App\Mail\PilotSignupRequested;
use App\Models\ContentAsset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class MarketingController extends Controller
{
    public function home(): View
    {
        return view('marketing.home');
    }

    public function signup(): View
    {
        return view('marketing.signup');
    }

    public function contact(): View
    {
        return view('marketing.contact');
    }

    public function storeSignup(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'company' => ['required', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'role' => ['nullable', 'string', 'max:255'],
            'goal' => ['nullable', 'string', 'max:2000'],
            'consent' => ['accepted'],
        ]);

        $signup = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'company' => $validated['company'],
            'website' => $validated['website'] ?? null,
            'role' => $validated['role'] ?? null,
            'goal' => $validated['goal'] ?? null,
            'status' => 'pending',
            'metadata' => json_encode([
                'source' => 'marketing_signup',
                'consent' => true,
                'consent_copy' => 'Agreed to be contacted about the Argusly pilot subscription and accepted Privacy Policy and Terms.',
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('pilot_signups')->insert($signup);

        Mail::to(config('argusly.pilot_signup_recipient'))
            ->queue(new PilotSignupRequested([
                'name' => $signup['name'],
                'email' => $signup['email'],
                'company' => $signup['company'],
                'website' => $signup['website'],
                'role' => $signup['role'],
                'goal' => $signup['goal'],
                'created_at' => $signup['created_at']->toDateTimeString(),
            ]));

        return redirect()
            ->route('marketing.signup')
            ->with('status', 'Thanks. We received your pilot subscription request and will follow up shortly.');
    }

    public function storeContact(Request $request): RedirectResponse
    {
        if ($request->filled('homepage')) {
            return redirect()
                ->route('marketing.contact')
                ->with('status', 'Thanks. Your message has been received and we will get back to you shortly.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'topic' => ['required', 'string', 'in:pilot,sales,support,partnership,press,other'],
            'message' => ['required', 'string', 'max:3000'],
            'consent' => ['accepted'],
        ]);

        $triage = $this->triageContactRequest($validated);

        $contactRequest = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'company' => $validated['company'] ?? null,
            'website' => $validated['website'] ?? null,
            'topic' => $validated['topic'],
            'message' => $validated['message'],
            'status' => $triage['status'],
            'metadata' => json_encode([
                'source' => 'marketing_contact',
                'consent' => true,
                'consent_copy' => 'Agreed that Argusly may respond to this contact request and accepted Privacy Policy and Terms.',
                'lead_score' => $triage['score'],
                'lead_quality' => $triage['quality'],
                'lead_signals' => $triage['signals'],
                'suggested_reply' => $triage['suggested_reply'],
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('contact_requests')->insert($contactRequest);

        Mail::to(config('argusly.contact_recipient'))
            ->queue(new ContactRequestSubmitted([
                'name' => $contactRequest['name'],
                'email' => $contactRequest['email'],
                'company' => $contactRequest['company'],
                'website' => $contactRequest['website'],
                'topic' => $contactRequest['topic'],
                'message' => $contactRequest['message'],
                'status' => $contactRequest['status'],
                'lead_score' => $triage['score'],
                'lead_quality' => $triage['quality'],
                'lead_signals' => $triage['signals'],
                'suggested_reply' => $triage['suggested_reply'],
                'created_at' => $contactRequest['created_at']->toDateTimeString(),
            ]));

        return redirect()
            ->route('marketing.contact')
            ->with('status', 'Thanks. Your message has been received and we will get back to you shortly.');
    }

    /**
     * @param  array{name: string, email: string, company?: string|null, website?: string|null, topic: string, message: string}  $contactRequest
     * @return array{score: int, quality: string, status: string, signals: array<int, string>, suggested_reply: string}
     */
    private function triageContactRequest(array $contactRequest): array
    {
        $score = 100;
        $signals = [];

        $email = strtolower($contactRequest['email']);
        [$localPart, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $name = trim($contactRequest['name']);
        $company = strtolower(trim((string) ($contactRequest['company'] ?? '')));
        $message = trim($contactRequest['message']);

        if ($this->usesPersonalEmailDomain($domain)) {
            $score -= 20;
            $signals[] = 'Personal email domain';
        }

        if ($this->looksRandom($localPart)) {
            $score -= 25;
            $signals[] = 'Random-looking email handle';
        }

        if ($this->looksLikeJoinedName($name)) {
            $score -= 15;
            $signals[] = 'Name looks autogenerated';
        }

        if ($company === '' || in_array($company, ['google', 'meta', 'amazon', 'microsoft', 'apple'], true)) {
            $score -= 15;
            $signals[] = $company === '' ? 'Company not provided' : 'Generic large-company claim';
        }

        if (empty($contactRequest['website'])) {
            $score -= 15;
            $signals[] = 'Website not provided';
        }

        if ($contactRequest['topic'] === 'other') {
            $score -= 10;
            $signals[] = 'Generic topic';
        }

        if (str_word_count($message) < 8) {
            $score -= 15;
            $signals[] = 'Very short message';
        }

        $score = max(0, min(100, $score));
        $quality = match (true) {
            $score < 45 => 'Low',
            $score < 70 => 'Needs review',
            default => 'Promising',
        };

        return [
            'score' => $score,
            'quality' => $quality,
            'status' => $score < 45 ? 'unqualified' : 'new',
            'signals' => $signals,
            'suggested_reply' => $this->contactQualificationReply(),
        ];
    }

    private function usesPersonalEmailDomain(string $domain): bool
    {
        return in_array($domain, [
            'gmail.com',
            'googlemail.com',
            'hotmail.com',
            'icloud.com',
            'live.com',
            'outlook.com',
            'proton.me',
            'protonmail.com',
            'yahoo.com',
        ], true);
    }

    private function looksRandom(string $value): bool
    {
        return preg_match('/[a-z]{6,}\d{2,}$/', $value) === 1
            || preg_match('/[bcdfghjklmnpqrstvwxyz]{5,}/', $value) === 1;
    }

    private function looksLikeJoinedName(string $name): bool
    {
        return preg_match('/^[A-Z][a-z]+[A-Z][a-z]+$/', $name) === 1;
    }

    private function contactQualificationReply(): string
    {
        return "Hi,\n\nThanks for reaching out. To point you to the right Argusly pricing, could you share:\n\n- Which company you represent\n- Which product or service you are interested in\n- Your company website\n- How many users or brands you want to monitor\n\nBest,\nArgusly";
    }

    public function page(string $page): View
    {
        abort_unless(in_array($page, ['platform', 'security', 'about', 'privacy', 'terms'], true), 404);

        return view('marketing.page', [
            'page' => $page,
            'content' => $this->pageContent($page),
        ]);
    }

    public function blog(): View
    {
        return view('marketing.blog.index', [
            'posts' => ContentAsset::query()
                ->where('type', 'article')
                ->whereIn('status', ['published', 'approved'])
                ->latest('published_at')
                ->latest()
                ->paginate(12),
        ]);
    }

    public function blogShow(string $slug): View
    {
        $post = ContentAsset::query()
            ->where('type', 'article')
            ->whereIn('status', ['published', 'approved'])
            ->where('slug', $slug)
            ->latest('published_at')
            ->firstOrFail();

        return view('marketing.blog.show', ['post' => $post]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pageContent(string $page): array
    {
        return match ($page) {
            'platform' => [
                'eyebrow' => 'Platform',
                'title' => 'One operating layer for AI visibility and agentic marketing.',
                'description' => 'Argusly helps teams see where their brand appears in AI answers, search, social and competitor conversations, then turns those signals into coordinated content, campaign and publishing work.',
                'hero_points' => ['AI answer monitoring', 'Competitor intelligence', 'Content workflows', 'Agent guardrails'],
                'sections' => [
                    ['title' => 'Visibility intelligence', 'body' => 'Track where your brand, products, people and competitors appear across AI answers, organic search, social conversations and source material.'],
                    ['title' => 'Actionable workflows', 'body' => 'Convert visibility gaps into briefs, content updates, campaigns, approvals, publishing actions and follow-up tasks for your team.'],
                    ['title' => 'Agent-assisted operations', 'body' => 'Use controlled agents to research topics, draft content, monitor mentions, flag competitor movement and keep recurring marketing work moving.'],
                ],
                'details_eyebrow' => 'What customers can do',
                'details_title' => 'From signal collection to execution.',
                'details_description' => 'Argusly is built for teams that need more than a dashboard. The workspace connects measurement, context, recommendations and execution so marketing operators can act on what AI and search surfaces are saying.',
                'details' => [
                    ['icon' => 'eye', 'title' => 'Monitor AI visibility', 'body' => 'Track brand presence, citations, answer coverage and sentiment across AI assistants and emerging search experiences. See which topics you own, where competitors are winning and which source pages shape the answer.'],
                    ['icon' => 'radar', 'title' => 'Map brand and competitor entities', 'body' => 'Build a living view of brands, products, competitors, topics and narratives. Argusly connects mentions and evidence back to the account and brand context your team actually manages.'],
                    ['icon' => 'file-text', 'title' => 'Create answer-ready content', 'body' => 'Turn discovered gaps into briefs, answer blocks, article updates and campaign material. Content work can move from recommendation to draft to approval without losing the original evidence.'],
                    ['icon' => 'bot', 'title' => 'Coordinate marketing agents', 'body' => 'Let agents monitor, research, draft, score and recommend while human teams keep approval and publishing controls. The goal is not more alerts, but more finished work.'],
                    ['icon' => 'megaphone', 'title' => 'Plan campaigns from intelligence', 'body' => 'Use visibility shifts, competitor launches, topic demand and social patterns to create campaign tasks, social posts and distribution plans around real market movement.'],
                    ['icon' => 'bar-chart', 'title' => 'Report on operational progress', 'body' => 'Connect visibility, content lifecycle, recommendations, tasks and publishing activity into reports that show what changed and what work drove it.'],
                ],
                'workflow_eyebrow' => 'Operating rhythm',
                'workflow_title' => 'A practical loop for modern brand visibility.',
                'workflow_description' => 'Potential customers use Argusly to replace scattered monitoring and ad hoc content decisions with a repeatable weekly operating rhythm.',
                'workflow' => [
                    ['title' => 'Watch the market', 'body' => 'Collect AI, search, social, competitor and integration signals in one workspace instead of bouncing between point tools.'],
                    ['title' => 'Prioritize what matters', 'body' => 'Score visibility gaps, content decay, competitor movement and brand risks so teams can focus on the highest-impact actions.'],
                    ['title' => 'Create and approve work', 'body' => 'Generate briefs, drafts, tasks and publishing actions with review steps, evidence and ownership attached.'],
                    ['title' => 'Measure the next cycle', 'body' => 'Track whether published work improves answer coverage, mentions, share of voice, engagement and narrative strength.'],
                ],
            ],
            'security' => [
                'eyebrow' => 'Security',
                'title' => 'Built around tenant boundaries and operational auditability.',
                'description' => 'Argusly is designed for customer workspaces where brand data, integrations, publishing controls and AI-assisted workflows need clear ownership, separation and traceability.',
                'hero_points' => ['Account and brand scoping', 'Role-aware access', 'Connector controls', 'Operational audit trails'],
                'sections' => [
                    ['title' => 'Tenant isolation', 'body' => 'Account and brand memberships determine what users can see, manage and connect. Customer workspaces stay scoped by default.'],
                    ['title' => 'Platform administration', 'body' => 'Global customer management is separated from account work so platform operators and customer teams have different control surfaces.'],
                    ['title' => 'Audit trails', 'body' => 'Admin changes, credit movements, domain events, connector activity and sensitive workflow events can be recorded for troubleshooting and review.'],
                ],
                'details_eyebrow' => 'Trust model',
                'details_title' => 'Controls for teams that publish, connect and automate.',
                'details_description' => 'AI visibility work touches brand knowledge, connected channels, customer content and publishing actions. Argusly keeps those workflows understandable with scoped permissions and traceable operations.',
                'details' => [
                    ['icon' => 'shield', 'title' => 'Scoped workspaces', 'body' => 'Users operate inside account and brand boundaries. That keeps dashboards, recommendations, content, reports and relationship data aligned with the workspace they belong to.'],
                    ['icon' => 'settings', 'title' => 'Role and module controls', 'body' => 'Access can follow user roles, account memberships, brand memberships and module availability, so teams can open the right capabilities without exposing everything.'],
                    ['icon' => 'network', 'title' => 'Connector accountability', 'body' => 'External services such as analytics, publishing channels and social integrations can be managed through connector records, health checks, permissions and operational logs.'],
                    ['icon' => 'file-text', 'title' => 'Evidence-aware decisions', 'body' => 'Recommendations, generated content and reports can preserve source context, making it easier to review why an action was suggested before it is approved.'],
                    ['icon' => 'bot', 'title' => 'Human review around agents', 'body' => 'Agents can assist with research, drafts, monitoring and tasks while publishing and sensitive customer actions remain reviewable workflows.'],
                    ['icon' => 'activity', 'title' => 'Operational history', 'body' => 'Activity logs, domain events and admin records help teams investigate what changed, which workflow created it and where follow-up is needed.'],
                ],
                'workflow_eyebrow' => 'Governance workflow',
                'workflow_title' => 'Security that follows the work.',
                'workflow_description' => 'The goal is not a static security page. Argusly applies boundaries and records to the daily work of monitoring, generating, approving and publishing.',
                'workflow' => [
                    ['title' => 'Assign workspace access', 'body' => 'Invite users into the right account and brand context, with permissions that match their operational responsibility.'],
                    ['title' => 'Connect data sources deliberately', 'body' => 'Manage integrations and publishing channels as workspace assets with ownership, token handling and health visibility.'],
                    ['title' => 'Review AI-assisted output', 'body' => 'Keep generated drafts, recommendations and publishing actions tied to evidence and approval steps before external use.'],
                    ['title' => 'Investigate with traceability', 'body' => 'Use audit records, events and logs to understand configuration changes, workflow outcomes and connector activity.'],
                ],
            ],
            'privacy' => [
                'eyebrow' => 'Privacy Policy',
                'title' => 'How Argusly handles personal, workspace and integration data.',
                'description' => 'This privacy policy explains what Argusly processes when someone visits the site, requests pilot access, signs in, uses a workspace, or connects third-party services.',
                'sections' => [
                    ['title' => '1. Data we process', 'body' => 'Argusly may process account and contact data such as names, work email addresses, company details, pilot signup requests, login records, invitation records and support messages. We may also process organization, account and workspace data such as brand names, domains, team roles, workspace configuration, properties, publishing channels and integration settings.'],
                    ['title' => '2. Customer content and brand knowledge', 'body' => 'When a customer uses Argusly, the platform may store content assets, prompts, answer blocks, translations, audit results, recommendations, brand knowledge, source material, publication status, connector events and related metadata. This information is used to provide the workspace and to keep workflows traceable.'],
                    ['title' => '3. Integration and connector data', 'body' => 'If a customer connects services such as LinkedIn, Google, analytics tools, publishing systems, CMS connectors or other third-party platforms, Argusly may process connection metadata, tokens, account identifiers, permissions, health checks, publishing responses and operational logs required to perform the requested workflow.'],
                    ['title' => '4. Website and technical data', 'body' => 'When someone visits Argusly, we may process basic technical data such as IP address, user agent, requested URLs, timestamps, error logs and security events. This data helps us keep the website and application reliable, secure and understandable during pilot operation.'],
                    ['title' => '5. Why we use data', 'body' => 'We use data to respond to pilot requests, prepare and operate workspaces, provide authentication and access control, support integrations, generate and review intelligence, run content and publishing workflows, secure accounts, troubleshoot issues, maintain audit trails and improve the product.'],
                    ['title' => '6. Legal bases', 'body' => 'Depending on the context, Argusly processes personal data to take steps before entering into a pilot or customer relationship, perform an agreement, protect legitimate operational and security interests, comply with legal obligations, or based on consent where consent is requested.'],
                    ['title' => '7. AI and automation', 'body' => 'Argusly may use AI providers and automated workflows to analyze brand visibility, draft content, summarize signals, generate recommendations or support marketing operations. Customers remain responsible for reviewing outputs before publication or external use. We aim to limit AI context to data that is relevant for the requested workflow.'],
                    ['title' => '8. Processors and sharing', 'body' => 'Argusly does not sell personal data. We may use trusted infrastructure, hosting, database, email, monitoring, analytics, AI, support and integration providers to operate the service. These providers may process limited data only for service delivery, security, support or requested workflows.'],
                    ['title' => '9. International transfers', 'body' => 'Some service providers may process data outside the European Economic Area. Where this happens, Argusly aims to rely on appropriate safeguards such as contractual protections, regional processing options or other legally recognized transfer mechanisms.'],
                    ['title' => '10. Retention', 'body' => 'Pilot requests are retained while we evaluate and follow up on access. Workspace data is retained while the customer relationship or pilot is active, unless deletion is requested or required earlier. Operational logs, audit records and security records may be retained for a reasonable period to protect reliability, traceability and legal interests.'],
                    ['title' => '11. Security', 'body' => 'Argusly is built around account and brand separation, role-based access, connector tokens, module controls and auditability. No system is risk-free, but we use technical and organizational measures intended to protect data against unauthorized access, loss, misuse and unintended disclosure.'],
                    ['title' => '12. Your rights and contact', 'body' => 'Depending on applicable law, individuals may request access, correction, deletion, restriction, portability or objection. For privacy questions or requests, contact Argusly through the contact page or reply to any pilot follow-up email. We may need to verify a request before acting on it.'],
                ],
            ],
            'terms' => [
                'eyebrow' => 'Terms & Conditions',
                'title' => 'Terms for Argusly pilot access and platform use.',
                'description' => 'These terms apply to the Argusly website, pilot signup flow, early access workspaces, APIs, connectors and related services unless a separate written agreement says otherwise.',
                'sections' => [
                    ['title' => '1. Definitions', 'body' => 'Argusly means the SaaS platform for AI visibility, brand intelligence, content workflows, recommendations, connectors and publishing operations. Customer means the organization or person requesting or using access for professional purposes. Workspace means an account, brand or environment configured inside Argusly.'],
                    ['title' => '2. Pilot and early access', 'body' => 'Submitting a pilot request does not guarantee access. Argusly may approve, decline, limit, pause or end pilot access while the product is being prepared for broader availability. Features may be incomplete, experimental, unavailable or changed without prior notice during pilot operation.'],
                    ['title' => '3. Accounts and users', 'body' => 'Customers are responsible for the users they invite, the roles they assign and the accuracy of account information. Login credentials, connector tokens and API keys must be kept confidential. Customers must notify Argusly if they suspect unauthorized access.'],
                    ['title' => '4. Customer content and rights', 'body' => 'Customers retain ownership of content, brand materials, source material, prompts, metadata and other information they provide to Argusly. Customers grant Argusly the right to process that material as needed to provide the requested service, operate workflows, support integrations and maintain the platform.'],
                    ['title' => '5. AI output and recommendations', 'body' => 'Argusly may generate drafts, summaries, recommendations, translations, audits and other AI-assisted output. Output can be incomplete, inaccurate or unsuitable for a particular context. Customers are responsible for reviewing and approving output before publication, distribution or business use.'],
                    ['title' => '6. Integrations and third-party services', 'body' => 'Customers are responsible for having the required rights and permissions to connect third-party services such as LinkedIn, Google, analytics tools, CMS environments, publishing systems or connectors. Third-party services may have their own terms, limits and availability. Argusly is not responsible for external platforms outside its control.'],
                    ['title' => '7. Acceptable use', 'body' => 'Customers may not use Argusly to violate law, infringe intellectual property or privacy rights, send unlawful or harmful content, scrape or access systems without permission, bypass security controls, disrupt the service, overload APIs, misuse connected services or attempt to reverse engineer protected parts of the platform.'],
                    ['title' => '8. Security and access controls', 'body' => 'Argusly uses account, brand, role and module controls to separate workspaces and permissions. Customers must configure access carefully and remove users or integrations that should no longer have access. Argusly may suspend access where needed to protect the platform, customers or third parties.'],
                    ['title' => '9. Availability and changes', 'body' => 'Argusly aims to provide a reliable service, but pilot and early access environments are provided as available. Maintenance, incidents, provider outages, connector changes or external API changes may affect availability or functionality. Argusly may update, replace or remove features as the product evolves.'],
                    ['title' => '10. Commercial terms', 'body' => 'These website terms do not create any paid access plan, service level or onboarding commitment. Any commercial arrangement, service level, onboarding scope or custom condition must be agreed separately in writing between Argusly and the customer.'],
                    ['title' => '11. Intellectual property', 'body' => 'All rights to the Argusly platform, software, interface, workflows, documentation, trademarks and underlying technology remain with Argusly or its licensors. Customers receive only the limited right to use the service during the agreed pilot or access period.'],
                    ['title' => '12. Liability', 'body' => 'To the maximum extent permitted by law, Argusly is not liable for indirect damages, loss of profit, loss of data, reputational harm, third-party platform issues, AI output errors or business decisions based on platform output. Nothing in these terms excludes liability that cannot legally be excluded.'],
                    ['title' => '13. Termination', 'body' => 'Argusly or the customer may end pilot access. Argusly may suspend or terminate access immediately if these terms are violated, security is at risk or continued access could harm the platform, customers or third parties. After termination, access to the workspace may be disabled and data may be retained or deleted according to the privacy policy and operational needs.'],
                    ['title' => '14. Governing law and contact', 'body' => 'Unless agreed otherwise in writing, these terms are governed by Dutch law. Questions about these terms can be sent through the Argusly contact page or raised during the pilot follow-up process.'],
                ],
            ],
            'about' => [
                'eyebrow' => 'Company',
                'title' => 'Argusly helps teams understand how AI talks about their brand.',
                'description' => 'Argusly is built for marketing, growth and intelligence teams that need to understand their reputation across AI answers, search surfaces and competitor narratives, then turn that insight into better work.',
                'hero_points' => ['AI visibility operations', 'Brand intelligence', 'Evidence-led workflows', 'Controlled automation'],
                'sections' => [
                    ['title' => 'Why now', 'body' => 'AI answer engines are becoming a primary surface for discovery, comparison and reputation. Brands need to know what is being said before it shapes demand.'],
                    ['title' => 'How we work', 'body' => 'We prioritize measurable signals, clear operations and controlled automation over noisy dashboards, disconnected alerts and one-off content guesses.'],
                    ['title' => 'Where we are going', 'body' => 'A practical operating layer first: monitor the market, understand the evidence, create better content and coordinate agents with human review.'],
                ],
                'details_eyebrow' => 'Company focus',
                'details_title' => 'Built for the teams responsible for modern brand visibility.',
                'details_description' => 'Argusly is not just another analytics view. The product is shaped around the daily questions customer teams ask when AI systems, search results and social conversations influence how buyers understand a company.',
                'details' => [
                    ['icon' => 'eye', 'title' => 'Visibility teams', 'body' => 'Track whether AI systems mention the brand, cite the right sources, understand product categories and surface competitors in important buying conversations.'],
                    ['icon' => 'radar', 'title' => 'Marketing leadership', 'body' => 'See where narratives are strengthening or weakening across AI, search, social and content channels so strategy is based on evidence instead of anecdotes.'],
                    ['icon' => 'file-text', 'title' => 'Content operators', 'body' => 'Use discovered gaps to refresh pages, create answer-ready blocks, brief new articles and connect every content decision back to measurable visibility opportunities.'],
                    ['icon' => 'activity', 'title' => 'Growth teams', 'body' => 'Find moments where competitor movement, topic demand or brand sentiment should trigger campaigns, landing page updates, social activity or sales enablement.'],
                    ['icon' => 'network', 'title' => 'Agency and multi-brand teams', 'body' => 'Keep account and brand work separated while running repeatable monitoring, reporting and execution workflows for different customers or portfolios.'],
                    ['icon' => 'bot', 'title' => 'AI-forward operators', 'body' => 'Bring agents into research, monitoring, drafting and task creation while keeping guardrails, approvals and traceability around customer-facing work.'],
                ],
                'workflow_eyebrow' => 'Our view',
                'workflow_title' => 'Visibility is becoming an operating discipline.',
                'workflow_description' => 'The companies that win in AI-mediated discovery will not only publish more. They will learn faster, connect evidence to action and build a rhythm around how the market describes them.',
                'workflow' => [
                    ['title' => 'Measure how the market describes you', 'body' => 'Start with the actual answers, sources, topics, citations and competitor comparisons shaping buyer perception.'],
                    ['title' => 'Understand what can be improved', 'body' => 'Separate noise from actionable gaps: missing entities, weak source material, decaying content, unanswered questions or competitor-owned narratives.'],
                    ['title' => 'Turn insight into coordinated work', 'body' => 'Create briefs, content updates, campaigns, tasks and publishing actions from the same place where the signal was discovered.'],
                    ['title' => 'Keep humans in control', 'body' => 'Use automation to accelerate research and execution while keeping review, ownership and auditability around important brand decisions.'],
                ],
            ],
            default => [
                'eyebrow' => 'Contact',
                'title' => 'Talk to Argusly.',
                'description' => 'Use this placeholder page for demo requests, pilot intake and customer conversations while the full form flow is prepared.',
                'sections' => [
                    ['title' => 'Pilot review', 'body' => 'Pilot signup review is staged in the Admin Control Center.'],
                    ['title' => 'Support', 'body' => 'Customer troubleshooting lives under the platform admin developer tools and account detail pages.'],
                    ['title' => 'Sales', 'body' => 'Pilot requests and follow-up conversations are handled while the broader commercial workflow is prepared.'],
                ],
            ],
        };
    }
}
