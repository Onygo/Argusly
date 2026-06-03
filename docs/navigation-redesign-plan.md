# Argusly Navigation Redesign Plan

Date: 2026-05-31
Status: Product architecture proposal

## Design Goal

Argusly should feel like a clean, modern operating system for brand intelligence: calm at the top level, deep where needed, and context-aware enough that users only see the surfaces that matter for their account, brand, role and enabled modules.

This plan does not add features and does not remove functionality. It reorganizes existing surfaces into a scalable navigation architecture.

## 1. New Navigation Architecture

### Primary Navigation

The primary sidebar should contain a small number of durable workspaces:

| Primary item | Purpose | Default route |
| --- | --- | --- |
| Dashboard | Cross-workspace command center | `dashboard` |
| Intelligence | Signals, competitors, topics, mentions and recommendations | `app.intelligence` |
| Visibility | AI/search visibility monitoring and evidence | `app.visibility` |
| Content | Content assets, answer blocks and publishing readiness | `app.content.index` |
| Marketing | Campaigns, audiences, briefings, newsletters, social publishing and calendar | `app.marketing` |
| Agents | Agents, agent runs, tasks and automations | `app.agents` |
| Relationships | Contacts, organizations and relationship graph | `app.relationships` |
| Assets | Generated assets, social media assets and reusable media library | Future workspace route or existing content/assets route when available |
| Reporting | Reports and performance dashboards | `app.reports` |
| Administration | Account, brand, team, modules, integrations, sources and developer tools | `settings.account` |

The primary navigation should not include feature-level items such as Topics, Newsletters, Domain Events or Search Performance.

### Primary Navigation Groups

Recommended sidebar grouping:

- Overview
  - Dashboard
- Workspaces
  - Intelligence
  - Visibility
  - Content
  - Marketing
  - Agents
  - Relationships
  - Assets
  - Reporting
- System
  - Administration

This structure scales because new modules usually become secondary items within an existing workspace.

### Secondary Navigation

Each workspace should have its own secondary navigation. This can appear as:

- Expanded child items inside the sidebar when the workspace is active.
- A horizontal subnav near the page header for narrower screens.
- A drawer or section list on mobile.

Recommended secondary navigation:

#### Intelligence

- Overview
- Signals
- Recommendations
- Topics
- Competitors
- Mentions
- Knowledge Graph
- Future: Lead Intelligence
- Future: Influencer Intelligence

#### Visibility

- Overview
- AI Visibility
- Prompt Library
- Visibility Runs
- Citations
- Search Performance
- Mentions Evidence
- Schedules
- Future: Prompt Monitoring
- Future: AI Overview Tracking

#### Content

- Overview
- Content Assets
- Answer Blocks
- Audits
- Translations
- Lifecycle Scores
- Publishing Readiness
- Future: Content Briefs
- Future: Templates

#### Marketing

- Overview
- Marketing OS
- Campaigns
- Calendar
- Audiences
- Briefings
- Newsletters
- Social Posts
- Publishing
- Future: Outreach
- Future: Lead Campaigns

#### Agents

- Overview
- Agents
- Agent Tasks
- Agent Runs
- Automations
- Approvals
- Future: Playbooks
- Future: Agent Marketplace

#### Relationships

- Overview
- Relationship Graph
- Contacts
- Organizations
- Audiences
- Future: Creators
- Future: Journalists
- Future: Stakeholders
- Future: Interactions

#### Assets

- Overview
- Generated Assets
- Social Media Assets
- Content Attachments
- Brand Assets
- Future: Media Library
- Future: Files
- Future: Exports

#### Reporting

- Overview
- Reports
- Performance
- Executive Snapshots
- Exports
- Future: Scheduled Reports
- Future: Dashboards

#### Administration

- Overview
- Account
- Brands
- Team
- Modules and Billing
- Integrations
- Data Sources
- Connectors
- Publishing Channels
- Social Profiles
- Email Providers
- Properties
- Knowledge Graph Setup
- Activity Logs
- Developer Tools

### Contextual Navigation

Contextual navigation should appear inside specific records and detail screens. It should not inflate the global sidebar.

Examples:

- Campaign detail:
  - Overview
  - Board
  - Content
  - Tasks
  - Calendar
  - Performance
  - Approvals

- Content asset detail:
  - Overview
  - Draft
  - Answer Blocks
  - Translations
  - Audit
  - Lifecycle
  - Publishing
  - Social Posts

- Visibility check or prompt detail:
  - Overview
  - Runs
  - Answers
  - Citations
  - Entities
  - History

- Source detail:
  - Overview
  - Connections
  - Sync History
  - Logs
  - Records

- Report detail:
  - Overview
  - Sections
  - Snapshots
  - Export
  - Schedule

### Mobile Navigation

Mobile should not mirror the entire desktop sidebar.

Recommended mobile pattern:

- Bottom navigation with five items:
  - Dashboard
  - Intelligence
  - Content
  - Marketing
  - More
- More opens a workspace drawer:
  - Visibility
  - Agents
  - Relationships
  - Assets
  - Reporting
  - Administration
- Workspace screens use a compact secondary nav as a horizontal scroll or sheet.
- Account, brand and user controls live in a top sheet accessed from the header.

For mobile, keep Administration and Developer Tools behind More and permission checks.

## 2. Sidebar Redesign

### Structure

The desktop sidebar should be collapsible and grouped:

- Top area:
  - Argusly logo
  - Account switcher
  - Brand switcher
- Navigation area:
  - Overview group
  - Workspaces group
  - System group
- Bottom area:
  - Notifications shortcut
  - Help/docs shortcut, if later available
  - User menu

### Expanded State

In expanded state, the sidebar shows:

- Workspace icon
- Workspace label
- Optional count/badge
- Expand chevron for workspaces with child items
- Active child item under the active workspace

Example:

- Intelligence
  - Overview
  - Signals
  - Topics
  - Competitors
  - Mentions

Only the active workspace should auto-expand by default. Users can manually expand other groups, but the default should stay calm.

### Collapsed State

In collapsed state, the sidebar shows:

- Icon-only primary destinations.
- Tooltips on hover.
- Active indicator as a left rail or filled background.
- Account/brand condensed into initials or brand avatar.
- User menu as avatar.

Collapsed state should preserve context without forcing users into a hamburger-only experience.

### Active State Handling

Active state should be based on route patterns, not only exact route names.

Examples:

- `app.content.*`, `app.distribution`, `app.social-posts.*` can resolve to Content or Marketing based on final IA choice.
- `app.campaigns*`, `app.calendar*`, `app.newsletters*`, `app.briefings*`, `app.audiences*`, `app.marketing*` resolve to Marketing.
- `app.visibility*`, `app.search-performance` resolve to Visibility.
- `app.reports*`, `app.analytics` resolve to Reporting.
- `settings.*`, `app.sources.*`, `app.domain-events` resolve to Administration.

Record detail pages should keep both the primary workspace and secondary section active.

### Account Context

The account switcher should be the topmost context control. It should show:

- Current account name.
- Plan/module status only when relevant.
- Switch account action.
- Account settings shortcut for users with permission.

Switching account should reset brand context, matching the existing tenant behavior.

### Brand Context

The brand switcher should sit directly below account context or as a combined account/brand selector. It should show:

- Current brand name.
- Brand avatar/initial.
- All accessible brands for the selected account.
- "All brands" only where the route supports account-level scope.

If a page requires a brand and none is selected, show a scoped empty state with a brand selection action.

### User Context

The user menu should move logout, locale and profile-like actions out of the topbar clutter. It should show:

- User name/email.
- Locale switcher.
- Notification preferences.
- Logout.

Notifications should remain globally available, preferably in the topbar or bottom sidebar utility area, but not as a primary workspace.

### UX Recommendations

- Use stable icons for primary workspaces.
- Keep primary labels short: Intelligence, Visibility, Content, Marketing, Agents, Relationships, Assets, Reporting, Administration.
- Use secondary labels for feature specificity.
- Do not show disabled add-ons as equal navigation destinations by default.
- Use "Upgrade" or "Request access" states only in Administration > Modules or contextual empty states.
- Avoid "Soon" badges in primary nav. Roadmap items should not compete with enabled workspaces.

## 3. Workspace Architecture

### Dashboard

Purpose: The executive command center for the current account and brand.

Primary screens:

- Overview
- Intelligence feed
- Recommendations
- Key metrics
- Recent activity

Secondary screens:

- Notifications
- Assigned tasks
- Recent reports

Navigation hierarchy:

- Dashboard
  - Overview
  - Signals
  - Actions
  - Activity

### Intelligence Workspace

Purpose: Convert market, topic, competitor, mention and system signals into decisions.

Primary screens:

- Intelligence Overview
- Signals
- Recommendations
- Topics
- Competitors
- Mentions

Secondary screens:

- Topic clusters
- Competitor snapshots
- Mention detail
- Knowledge Graph entry points
- Future Lead Intelligence
- Future Influencer Intelligence

Navigation hierarchy:

- Intelligence
  - Overview
  - Signals
  - Recommendations
  - Topics
  - Competitors
  - Mentions
  - Knowledge Graph

### Visibility Workspace

Purpose: Monitor where and how the brand appears across AI answers, search surfaces and cited sources.

Primary screens:

- Visibility Overview
- AI Visibility
- Prompt Library
- Visibility Runs
- Citations
- Search Performance

Secondary screens:

- Provider runs
- Prompt schedules
- Answer entities
- Mention evidence
- Future Prompt Monitoring
- Future Citation Tracking

Navigation hierarchy:

- Visibility
  - Overview
  - AI Visibility
  - Prompts
  - Runs
  - Citations
  - Search Performance
  - Schedules

### Content Workspace

Purpose: Manage content assets, answer blocks, audits, translations and content lifecycle readiness.

Primary screens:

- Content Overview
- Content Assets
- Answer Blocks
- Audits
- Translations
- Lifecycle

Secondary screens:

- Content asset detail
- Generated content runs
- Publishing actions
- Social repurposing entry points

Navigation hierarchy:

- Content
  - Overview
  - Assets
  - Answer Blocks
  - Audits
  - Translations
  - Lifecycle
  - Publishing Readiness

### Marketing Workspace

Purpose: Plan and execute campaigns, social publishing, newsletters, audiences, briefings and calendar-based work.

Primary screens:

- Marketing Overview
- Campaigns
- Calendar
- Audiences
- Briefings
- Newsletters
- Social Posts
- Publishing

Secondary screens:

- Campaign boards
- Newsletter detail
- Audience detail
- Social post variants
- Approvals
- Future Outreach

Navigation hierarchy:

- Marketing
  - Overview
  - Campaigns
  - Calendar
  - Audiences
  - Briefings
  - Newsletters
  - Social Posts
  - Publishing

### Agents Workspace

Purpose: Let users supervise autonomous and semi-autonomous work without mixing agent operations into every feature screen.

Primary screens:

- Agents Overview
- Agents
- Tasks
- Runs
- Automations
- Approvals

Secondary screens:

- Agent detail
- Agent run detail
- Automation detail
- Future Playbooks

Navigation hierarchy:

- Agents
  - Overview
  - Agents
  - Tasks
  - Runs
  - Automations
  - Approvals

### Relationships Workspace

Purpose: Manage people, organizations and relationship intelligence across contacts, creators, journalists, stakeholders and audiences.

Primary screens:

- Relationships Overview
- Relationship Graph
- Contacts
- Organizations
- Audiences

Secondary screens:

- Contact detail
- Organization detail
- Relationship edges
- Future Creator CRM
- Future Journalist CRM
- Future Stakeholder CRM

Navigation hierarchy:

- Relationships
  - Overview
  - Graph
  - Contacts
  - Organizations
  - Audiences
  - Future: Creators
  - Future: Journalists
  - Future: Stakeholders

### Assets Workspace

Purpose: Centralize reusable generated assets, media, files and brand materials without overloading Content.

Primary screens:

- Assets Overview
- Generated Assets
- Social Media Assets
- Brand Assets
- Files

Secondary screens:

- Asset detail
- Usage
- Rights/metadata
- Future Media Library

Navigation hierarchy:

- Assets
  - Overview
  - Generated
  - Social Media
  - Brand
  - Files

Current implementation note: generated assets and social media assets exist as models, but there is not yet a full standalone Assets workspace route. Until that exists, keep Assets hidden or route to the nearest existing asset index. Do not create a new feature solely for navigation.

### Reporting Workspace

Purpose: Communicate outcomes through reports, performance views, executive snapshots and exports.

Primary screens:

- Reporting Overview
- Reports
- Performance
- Executive Snapshots

Secondary screens:

- Report detail
- Report sections
- Snapshots
- Future scheduled reports
- Future exports

Navigation hierarchy:

- Reporting
  - Overview
  - Reports
  - Performance
  - Snapshots
  - Exports

### Administration Workspace

Purpose: Configure accounts, brands, people, modules, integrations, sources, connectors and technical operations.

Primary screens:

- Administration Overview
- Account
- Brands
- Team
- Modules and Billing
- Integrations
- Data Sources
- Publishing Infrastructure
- Developer Tools

Secondary screens:

- Social profiles
- Email providers
- Properties
- Publishing channels
- Connectors
- Source syncs
- Knowledge Graph setup
- Domain events
- Activity logs
- System monitoring

Navigation hierarchy:

- Administration
  - Account
  - Brands
  - Team
  - Modules and Billing
  - Connections
    - Integrations
    - Social Profiles
    - Email Providers
  - Data Sources
    - Sources
    - Source Syncs
  - Publishing Infrastructure
    - Properties
    - Channels
    - Connectors
  - Brand Setup
    - Knowledge Graph
  - Developer Tools
    - Domain Events
    - Connector Logs
    - Source Sync Logs
    - Activity Logs
    - System Monitoring

## 4. Context-Aware Navigation Rules

### Account Switcher Rules

- Always show the current account in the app shell.
- Show only accounts where the user has active membership.
- When switching accounts, clear current brand selection.
- If the destination route is unavailable in the new account, redirect to Dashboard.
- If the current module is unavailable in the new account, redirect to the closest available workspace or Dashboard.

### Brand Switcher Rules

- Show brands available through active brand membership.
- Allow account-level pages to show "All brands" only when the data model supports account scope.
- Disable or hide brand-only actions when no brand is selected.
- Preserve brand context across routes when valid.
- If a route requires brand context, present a brand-required empty state rather than a generic error.

### Module Visibility Rules

- Hide entire workspaces when none of their child routes are available.
- Show a workspace if at least one child section is available.
- Hide child sections when their module entitlement is unavailable.
- Keep Administration visible for users with account/admin permissions even if commercial modules are limited.
- Do not show disabled add-ons in primary navigation. Show them in Administration > Modules and in contextual upgrade prompts.

Examples:

- If `competitive_intelligence` is disabled, hide Intelligence > Competitors.
- If `visibility` is disabled, hide Visibility or show only available Intelligence sections.
- If `agentic_content` and `agentic_social` are disabled, hide Agents.
- If `connectors` is disabled, hide Administration > Connectors.

### Permission Visibility Rules

- Navigation should check both module entitlement and permission.
- Non-admin users should not see Administration sections requiring `manage_account`, `manage_users` or `manage_billing`.
- View-only users should see read-only workspace sections but not create/manage actions.
- Users without `manage_account` should not see Developer Tools.
- Users without `view_agents` should not see Agents.

Examples:

- Hide Team for users without `manage_users`.
- Hide Modules and Billing for users without `manage_billing`.
- Hide Sources, Connectors, Domain Events and Developer Tools for users without `manage_account`.
- Show Campaigns to users with `view_campaigns`, but hide create campaign actions without `manage_campaigns`.

### Contextual Action Rules

Workspace headers should show actions relevant to the current workspace and permissions:

- Intelligence: mark reviewed, dismiss signal, create task from recommendation.
- Visibility: run prompt, create check, manage schedule.
- Content: create content, generate, audit, translate, publish when permitted.
- Marketing: create campaign, create briefing, create newsletter, add calendar task.
- Agents: run agent, create automation when permitted.
- Relationships: add contact, add organization, create relationship.
- Reporting: create report, export when permitted.
- Administration: connect integration, add source, rotate token, manage module when permitted.

## 5. Administration and Developer Tools Separation

Technical and operator functionality should leave primary navigation and live under Administration.

### Administration

Use for business/admin configuration:

- Account
- Brands
- Team
- Modules and Billing
- Integrations
- Social Profiles
- Email Providers
- Properties
- Publishing Channels
- Sources
- Source Syncs
- Connectors
- Knowledge Graph Setup

### Developer Tools

Use for technical/operator diagnostics:

- Domain Events
- Connector Logs
- Source Sync Logs
- Activity Logs
- System Monitoring
- API Tokens
- Webhook/Connector payload inspection

Developer Tools should be hidden unless the user has account management or developer-level permission. If later a dedicated permission is added, use something like `manage_developer_tools` instead of broad `manage_account`.

## 6. Future-Proofing Rules

New modules should be assigned to an existing workspace unless they create a new durable operating mode.

### Placement Rules

- Monitoring, signals and market interpretation go to Intelligence or Visibility.
- Publishing, campaigns and outbound execution go to Marketing.
- Autonomous execution goes to Agents.
- People, organizations and CRM concepts go to Relationships.
- Files, reusable media and generated artifacts go to Assets.
- Dashboards, reports and exports go to Reporting.
- Setup, logs, integrations and infrastructure go to Administration.

### Future Module Map

| Future capability | Workspace | Secondary section |
| --- | --- | --- |
| Influencer Intelligence | Intelligence | Influencers |
| Creator CRM | Relationships | Creators |
| Journalist CRM | Relationships | Journalists |
| Stakeholder CRM | Relationships | Stakeholders |
| Relationship Graph | Relationships | Graph |
| AI Visibility Monitoring | Visibility | AI Visibility |
| Prompt Monitoring | Visibility | Prompts |
| Citation Tracking | Visibility | Citations |
| Marketing Calendar | Marketing | Calendar |
| Newsletters | Marketing | Newsletters |
| Outreach | Marketing or Relationships | Outreach |
| Lead Intelligence | Intelligence | Leads |
| Marketplace | Administration | Marketplace |
| Connectors | Administration | Connectors |
| Mobile app | Mobile shell | Same workspaces |

## 7. Proposed Navigation Configuration Shape

Future implementation should move from a flat list to a tree-like config:

```php
[
    'groups' => [
        [
            'label' => 'Overview',
            'items' => [
                [
                    'key' => 'dashboard',
                    'label' => 'Dashboard',
                    'route' => 'dashboard',
                    'active' => ['dashboard'],
                    'modules' => ['core'],
                    'permission' => 'view_dashboard',
                ],
            ],
        ],
        [
            'label' => 'Workspaces',
            'items' => [
                [
                    'key' => 'marketing',
                    'label' => 'Marketing',
                    'route' => 'app.marketing',
                    'active' => ['app.marketing*', 'app.campaigns*', 'app.calendar*', 'app.newsletters*', 'app.briefings*', 'app.audiences*', 'app.social-posts*'],
                    'children' => [
                        ['label' => 'Overview', 'route' => 'app.marketing'],
                        ['label' => 'Campaigns', 'route' => 'app.campaigns'],
                        ['label' => 'Calendar', 'route' => 'app.calendar'],
                        ['label' => 'Audiences', 'route' => 'app.audiences'],
                        ['label' => 'Briefings', 'route' => 'app.briefings'],
                        ['label' => 'Newsletters', 'route' => 'app.newsletters'],
                        ['label' => 'Social Posts', 'route' => 'app.social-posts.index'],
                    ],
                ],
            ],
        ],
    ],
]
```

The exact implementation can vary, but the config should support:

- Groups.
- Workspace items.
- Children.
- Route pattern active states.
- Module requirements.
- Permission requirements.
- Optional visibility callbacks.
- Optional badge/count metadata.

## 8. Migration Plan

### Phase 1: IA Foundation and Low-Risk Shell Changes

Goal: Introduce hierarchy without changing routes or features.

Work:

- Replace flat `config/navigation.php` structure with grouped navigation metadata.
- Render grouped primary navigation in the existing sidebar.
- Keep all existing routes intact.
- Add route-pattern active matching.
- Move Notifications out of primary sidebar and keep it in the topbar/user utility area.
- Rename Settings to Administration in the primary label while keeping current `settings.*` routes.
- Move Domain Events under Administration > Developer Tools in navigation only.
- Move Sources under Administration > Data Sources in navigation only.
- Add secondary child links under active workspaces.

Success criteria:

- No existing route is removed.
- Existing permissions and module gates still apply.
- Top-level navigation has no more than ten destinations.
- Detail pages highlight their parent workspace.

### Phase 2: Workspace Refinement

Goal: Make each pillar feel like a coherent workspace.

Work:

- Add workspace-specific secondary navigation components.
- Normalize page headings and eyebrow labels around workspace names.
- Update Marketing workspace navigation to include Campaigns, Calendar, Audiences, Briefings, Newsletters and Social Posts.
- Update Reporting workspace navigation to include Reports and Performance.
- Update Visibility workspace navigation to include AI Visibility and Search Performance.
- Update Intelligence workspace navigation to include Signals, Recommendations, Topics, Competitors and Mentions.
- Update Administration settings navigation from a horizontal pill list to grouped vertical sections.
- Add clear account and brand switcher UI patterns.
- Define mobile navigation with bottom tabs plus More drawer.

Success criteria:

- Users can infer where future modules belong.
- Settings/admin pages no longer feel like peer product workspaces.
- Mobile navigation can reach every existing surface without exposing a 25-item list.

### Phase 3: Context-Aware and Future-Ready Navigation

Goal: Make navigation adaptive to account, brand, module and permission context.

Work:

- Add workspace availability evaluation: show a workspace only when at least one child is visible.
- Add child-level module and permission filtering.
- Add contextual action slots for workspace headers.
- Add record-level contextual tabs for complex detail pages.
- Add locked/upgrade states only where product wants commercial upsell.
- Add developer-tool permission separation when the permission model is ready.
- Prepare Assets workspace visibility once a true asset index exists.
- Add automated tests for navigation visibility across roles/modules.

Success criteria:

- Owner, admin, manager, editor, viewer, billing and external users see appropriate navigation.
- Disabled modules do not create dead links.
- Administration and Developer Tools remain hidden from non-admin users.
- New future modules can be added by editing navigation config, not redesigning the shell.

## 9. Naming Recommendations

Use these navigation labels:

- Dashboard
- Intelligence
- Visibility
- Content
- Marketing
- Agents
- Relationships
- Assets
- Reporting
- Administration

Use these secondary labels:

- Signals, not Intelligence Feed.
- Recommendations, not Actions unless they are execution tasks.
- AI Visibility, not Visibility Checks.
- Prompts, not Prompt Templates.
- Search Performance, not Search performance.
- Publishing, not Distribution.
- Performance, not Analytics.
- Modules and Billing, not Modules alone.
- Developer Tools, not Admin technical tools.
- Data Sources, not Sources when grouped under Administration.

## 10. Final Recommendation

Move Argusly from feature-list navigation to workspace navigation now, before the next wave of features lands. The existing Laravel route, module and permission foundations are strong enough to support the change incrementally. The main product decision is to make the nine pillars the permanent mental model and force every current and future feature to live inside one of those pillars unless it truly represents a new operating mode.

