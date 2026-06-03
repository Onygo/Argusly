<?php

namespace App\Http\Controllers;

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

    public function page(string $page): View
    {
        abort_unless(in_array($page, ['platform', 'security', 'about', 'contact', 'privacy', 'terms'], true), 404);

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
     * @return array{title: string, eyebrow: string, description: string, sections: array<int, array{title: string, body: string}>}
     */
    private function pageContent(string $page): array
    {
        return match ($page) {
            'platform' => [
                'eyebrow' => 'Platform',
                'title' => 'One operating layer for AI visibility and agentic marketing.',
                'description' => 'Argusly connects monitoring, content, recommendations and controlled automation in one tenant-aware workspace.',
                'sections' => [
                    ['title' => 'Visibility intelligence', 'body' => 'Track AI, search, social and competitor signals from the same account and brand context.'],
                    ['title' => 'Actionable workflows', 'body' => 'Turn signals into content, campaigns, tasks and publishing actions with policy-protected controls.'],
                    ['title' => 'Tenant-safe operations', 'body' => 'Roles, modules, credits and integrations stay scoped to the account and brand a user belongs to.'],
                ],
            ],
            'security' => [
                'eyebrow' => 'Security',
                'title' => 'Built around tenant boundaries and operational auditability.',
                'description' => 'Argusly separates platform administration from account administration and records sensitive actions.',
                'sections' => [
                    ['title' => 'Tenant isolation', 'body' => 'Account and brand memberships determine what customers can see and do.'],
                    ['title' => 'Platform administration', 'body' => 'Global customer management is reserved for unscoped platform admins.'],
                    ['title' => 'Audit trails', 'body' => 'Admin changes, credit movements and domain events are recorded for troubleshooting.'],
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
                'description' => 'The product combines visibility monitoring, brand intelligence and execution workflows for modern marketing teams.',
                'sections' => [
                    ['title' => 'Why now', 'body' => 'AI answer engines are becoming a primary surface for discovery and reputation.'],
                    ['title' => 'How we work', 'body' => 'We prioritize measurable signals, clear operations and controlled automation over noisy dashboards.'],
                    ['title' => 'Where we are going', 'body' => 'A production-usable foundation first, then deeper intelligence and creator capabilities.'],
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
