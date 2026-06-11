<?php

use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Enums\SignalCategory;
use App\Enums\SignalSeverity;
use App\Enums\SignalStatus;
use App\Enums\SignalType;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Draft;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\SignalDetection;
use App\Models\SignalEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('features.agentic_marketing', true);
    Config::set('features.signal_intelligence', true);

    $this->withoutVite();
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
});

it('renders the core app user flow in English and Dutch without visible translation leaks', function (): void {
    $context = localizedFlowContext();

    $screens = [
        'Dashboard' => route('app.dashboard'),
        'Activation' => route('app.activation.index'),
        'AI Visibility' => route('app.sites.llm-tracking.index', $context['site']),
        'Signal Intelligence' => route('app.signal-intelligence.index'),
        'Opportunity Intelligence' => route('app.agentic-marketing.intelligence.index'),
        'Execution Planning' => route('app.agentic-marketing.campaign-planner.index'),
        'Briefings' => route('app.content.workspace.brief', $context['brief']),
        'Drafting' => route('app.drafts.show', $context['draft']),
        'Governance' => route('app.content.lifecycle.index'),
        'Settings' => route('app.settings'),
        'Workspace' => route('app.workspace-intelligence.index'),
        'Brand' => route('app.brand.company-profile'),
        'User Management' => route('app.settings').'#team',
    ];

    $issues = [];

    foreach (['en', 'nl'] as $locale) {
        foreach ($screens as $screen => $url) {
            $response = $this->actingAs($context['user'])->get($url.(str_contains($url, '?') ? '&' : '?').'lang='.$locale);

            $response->assertOk();

            $html = (string) $response->getContent();
            $visible = localizedFlowVisibleText($html);

            foreach (localizedFlowTranslationLeaks($html, $visible) as $leak) {
                $issues[] = "{$locale} {$screen}: {$leak}";
            }

            if ($locale === 'nl') {
                foreach (array_keys(localizedFlowUnexpectedDutchEnglish($visible)) as $phrase) {
                    $issues[] = "{$locale} {$screen}: untranslated English phrase [{$phrase}]";
                }
            }
        }
    }

    expect($issues)->toBe([]);
});

function localizedFlowContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Lokalisatie Organisatie',
        'slug' => 'lokalisatie-'.Str::random(6),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Lokalisatie Werkruimte',
        'display_name' => 'Lokalisatie Werkruimte',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Lokalisatie Site',
        'site_url' => 'https://lokalisatie.example.com',
        'base_url' => 'https://lokalisatie.example.com',
        'allowed_domains' => ['lokalisatie.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::factory()->create([
        'name' => 'Lokalisatie Gebruiker',
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'status' => 'ready',
        'source' => 'client_ui',
        'title' => 'Lokalisatie briefing',
        'language' => 'nl',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'progress' => 100,
        'primary_keyword' => 'ai zichtbaarheid',
    ]);

    $draft = Draft::query()->create([
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => Draft::STATUS_READY_FOR_REVIEW,
        'title' => 'Lokalisatie concept',
        'output_type' => 'kb_article',
        'language' => 'nl',
        'content_html' => '<h2>Lokalisatie inhoud</h2><p>Een korte tekst voor controle.</p>',
        'meta' => [],
        'links' => [],
    ]);

    $query = LlmTrackingQuery::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Beste AI zichtbaarheid tools',
        'query_text' => 'beste AI zichtbaarheid tools',
        'locale' => 'nl',
        'frequency' => 'daily',
        'is_active' => true,
    ]);

    LlmTrackingQueryRun::query()->create([
        'llm_tracking_query_id' => $query->id,
        'run_at' => now(),
        'provider' => 'openai',
        'model' => 'gpt-test',
        'status' => 'succeeded',
        'raw_response' => 'Argusly wordt genoemd.',
        'brand_mentioned' => true,
    ]);

    $event = SignalEvent::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'category' => SignalCategory::BRAND_VISIBILITY->value,
        'type' => SignalType::BRAND_MENTIONED->value,
        'severity' => SignalSeverity::INFO->value,
        'status' => SignalStatus::DETECTED->value,
        'topic' => 'AI zichtbaarheid',
        'entity_name' => 'Argusly',
        'entity_key' => 'argusly',
        'signal_strength' => 72,
        'confidence_score' => 81,
        'impact_score' => 62,
        'urgency_score' => 45,
        'observed_at' => now(),
        'evidence' => [],
        'metrics' => [],
        'metadata' => [],
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
    ]);

    SignalDetection::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'category' => SignalDetection::CATEGORY_OPPORTUNITY_DETECTION,
        'type' => 'opportunity_candidate',
        'status' => SignalStatus::DETECTED->value,
        'title' => 'AI-zichtbaarheidssignaal',
        'summary' => 'Een opgeslagen signaal wijst op een kans.',
        'primary_topic' => 'AI zichtbaarheid',
        'primary_entity' => 'Argusly',
        'severity' => SignalSeverity::MEDIUM->value,
        'priority_score' => 74,
        'confidence_score' => 83,
        'impact_score' => 70,
        'urgency_score' => 55,
        'risk_score' => 8,
        'opportunity_score' => 84,
        'score_breakdown' => [],
        'evidence_summary' => ['event_id' => $event->id],
        'recommended_actions' => [],
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'metadata' => [],
    ]);

    Opportunity::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'category' => OpportunityCategory::CONTENT_GAP->value,
        'status' => OpportunityStatus::OPEN->value,
        'title' => 'Maak een AI-zichtbaarheidsvergelijking',
        'topic' => 'AI zichtbaarheid',
        'summary' => 'Een beoordeeld signaal werd een kans.',
        'priority_score' => 80,
        'confidence_score' => 78,
        'impact_score' => 75,
        'urgency_score' => 60,
        'effort_score' => 45,
        'score_breakdown' => [],
        'recommended_actions' => [],
        'evidence' => [],
        'source_signal_summary' => [],
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    return compact('organization', 'workspace', 'site', 'user', 'brief', 'draft');
}

function localizedFlowVisibleText(string $html): string
{
    $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

function localizedFlowTranslationLeaks(string $html, string $visible): array
{
    $leaks = [];

    foreach ([
        '/(?<![a-z0-9.])(?:app|admin|public)\.(?:nav|dashboard|content|sites|brand|settings|billing|credits|common|status|time|validation|runtime)\.[a-z0-9_.-]+\b/i',
        '/(?:__|@lang|trans)\([^)]+\)/i',
    ] as $pattern) {
        if (preg_match_all($pattern, $html.' '.$visible, $matches)) {
            foreach (array_unique($matches[0]) as $match) {
                $leaks[] = 'visible translation identifier ['.$match.']';
            }
        }
    }

    return $leaks;
}

function localizedFlowUnexpectedDutchEnglish(string $visible): array
{
    $phrases = [
        'Activation',
        'Available Credits',
        'Back to dashboard',
        'Brief settings',
        'Campaign Planner',
        'Collapse',
        'Company Profile',
        'Content Created',
        'Content Intelligence',
        'Create Content',
        'Dashboard',
        'Drafting',
        'Execution Planning',
        'Expand sidebar',
        'Generate draft',
        'Governance',
        'Intelligence',
        'Opportunity Intelligence',
        'Quick Actions',
        'Recent Content',
        'Signal Intelligence',
        'Start comparison',
        'User Management',
        'Workspace Intelligence',
    ];

    $found = [];

    foreach ($phrases as $phrase) {
        if (preg_match('/\b'.preg_quote($phrase, '/').'\b/u', $visible)) {
            $position = mb_strpos($visible, $phrase);
            $start = max(0, (int) $position - 50);
            $found[$phrase] = mb_substr($visible, $start, 120);
        }
    }

    return $found;
}
