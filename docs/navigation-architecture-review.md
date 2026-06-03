# Argusly Navigation Architecture Review

Date: 2026-05-31
Reviewer: SaaS Product Architecture / UX Architecture

## Executive Summary

Argusly already has the right underlying product domains for a Brand Intelligence Operating System, but the current app navigation exposes too many feature surfaces as equal top-level destinations. This creates early-stage clarity because every feature is visible, but it does not scale to hundreds of features, add-ons, connectors, agents, CRMs, monitoring tools and operator screens.

The recommended direction is to keep existing functionality intact and reorganize the product shell around nine durable pillars:

1. Intelligence
2. Visibility
3. Content
4. Marketing
5. Agents
6. Relationships
7. Assets
8. Reporting
9. Administration

The current route and module structure can support this without a feature rewrite. The primary change is information architecture: promote workspaces, demote feature pages into secondary navigation, and separate operator/admin tooling from daily user workflows.

## Current Implementation Audit

### Primary Navigation Today

Current navigation is configured in `config/navigation.php` under `navigation.app` and rendered by `resources/views/components/app/sidebar.blade.php`.

The sidebar currently renders a flat list:

- Dashboard
- Intelligence
- Visibility
- Search performance
- Competitors
- Topics
- Mentions
- Content
- Distribution
- Marketing
- Audiences
- Briefings
- Newsletters
- Campaigns
- Social posts
- Calendar
- Analytics
- Agents
- Automations
- Reports
- Notifications
- Relationships
- Sources
- Domain Events
- Settings

This is the core scalability problem. The product already contains several domains, but the navigation treats most domain screens as peers.

### Existing Access Model

The current implementation already has useful primitives:

- Account context via `current_account()`.
- Brand context via `current_brand()`.
- User context via `auth()->user()`.
- Module entitlement checks through `ModuleAccessService`.
- Permission checks through middleware and route definitions.
- Settings-level module filtering in `resources/views/components/settings/nav.blade.php`.

These primitives should become the foundation for context-aware navigation. The issue is not entitlement logic; it is the lack of a grouped navigation model.

### Route and Domain Inventory

The existing application surfaces map naturally into workspaces:

| Existing surface | Current route area | Recommended pillar |
| --- | --- | --- |
| Dashboard | `/dashboard` | Dashboard |
| Intelligence signals | `/intelligence` | Intelligence |
| Recommendations | `/recommendations/*` | Intelligence |
| Topics | `/topics` | Intelligence |
| Competitors | `/competitors` | Intelligence |
| Mentions | `/mentions` | Intelligence or Visibility |
| AI visibility checks/prompts | `/visibility` | Visibility |
| Search performance | `/search-performance` | Visibility |
| Content assets | `/content` | Content |
| Answer Blocks | `/content/answer-blocks` | Content |
| Distribution | `/distribution` | Content or Marketing |
| Social posts | `/social-posts` | Marketing |
| Marketing OS | `/marketing` | Marketing |
| Audiences | `/marketing/audiences` | Marketing |
| Briefings | `/marketing/briefings` | Marketing |
| Newsletters | `/marketing/newsletters` | Marketing |
| Campaigns | `/campaigns` | Marketing |
| Calendar | `/calendar` | Marketing |
| Agents | `/agents` | Agents |
| Automations | `/automations` | Agents |
| Relationships | `/relationships` | Relationships |
| Sources | `/sources` | Administration or Intelligence setup |
| Analytics | `/analytics` | Reporting |
| Reports | `/reports` | Reporting |
| Notifications | `/notifications` | User utility, not primary workspace |
| Settings | `/settings/*` | Administration |
| Domain events | `/admin/domain-events` | Developer Tools |
| Connectors | `/settings/connectors` | Administration / Developer Tools |
| Integrations | `/settings/integrations` | Administration |
| Social profiles | `/settings/social-profiles` | Administration |
| Email providers | `/settings/email-providers` | Administration |
| Properties | `/settings/properties` | Administration |
| Publishing channels | `/settings/channels` | Administration |
| Knowledge Graph | `/settings/knowledge-graph` | Intelligence or Administration |

## Duplicate Concepts

### Sources vs Integrations vs Connectors

Current users can encounter:

- Sources
- Integrations
- Connectors
- Publishing channels
- Social profiles
- Email providers

These are different concepts architecturally, but they read as adjacent setup surfaces. In primary navigation, "Sources" looks like a daily workspace even though it is closer to ingestion setup and sync operations. Recommendation: group these under Administration with clear subgroups:

- Connections: Integrations, Social profiles, Email providers.
- Data Sources: Sources, Source syncs.
- Publishing Infrastructure: Publishing channels, Connectors.
- Developer Tools: Connector tokens, Connector logs, API access.

### Intelligence vs Visibility vs Mentions

Mentions currently sit under Visibility entitlement, but product language positions them as Mention Intelligence. They can belong to either pillar depending on user intent:

- Visibility: "Where and how is the brand appearing?"
- Intelligence: "What does this signal mean and what should we do?"

Recommendation: expose Mentions as a secondary screen under Intelligence for analysis, and allow Visibility dashboards to deep-link into mention evidence when relevant.

### Topics vs Knowledge Graph

Topics and Knowledge Graph are semantically related but separated across primary navigation and settings. Topics are a working intelligence surface; Knowledge Graph is brand semantic configuration.

Recommendation:

- Topics remain in Intelligence as a working screen.
- Knowledge Graph moves to Administration > Brand Setup, with contextual entry points from Intelligence where needed.

### Marketing vs Campaigns vs Calendar vs Newsletters

Marketing OS currently competes with individual marketing execution screens. Users should not have to know whether something is a "Marketing OS" feature or a "Campaign" feature.

Recommendation: make Marketing the workspace, with Campaigns, Calendar, Audiences, Briefings, Newsletters and Social posts as secondary screens.

### Analytics vs Reports vs Search Performance

The current split can confuse users:

- Search Performance is an input/monitoring surface.
- Analytics is a performance insight surface.
- Reports are packaged executive outputs.

Recommendation:

- Search Performance belongs to Visibility.
- Analytics belongs to Reporting as "Performance".
- Reports belongs to Reporting as "Reports".

### Agents vs Automations

Agents and Automations are adjacent. Agents are the actors; Automations are recurring or rule-based execution. They should not be top-level peers.

Recommendation: make Agents the workspace and place Automations under it.

## Confusing Naming

### "Distribution"

"Distribution" is technically correct but can feel abstract. In the product it covers website publishing, social scheduling, audits and translations. Better names:

- Preferred: Publishing
- Alternative: Distribution Hub

Recommendation: use "Publishing" in navigation and keep "Distribution Hub" as an internal or page subtitle if useful.

### "Analytics"

"Analytics" is broad and can collide with GA4, reports and performance dashboards. Better names:

- Preferred: Performance
- Alternative: Content Performance

Recommendation: place it under Reporting as "Performance".

### "Domain Events"

This is operator/developer language and should not appear in primary product navigation.

Recommendation: move to Administration > Developer Tools > Domain Events.

### "Settings"

"Settings" is too broad for a system that will include account, billing, team, modules, integrations, connectors, source syncs and developer tools.

Recommendation: rename the primary destination to "Administration". Keep "Settings" as a subsection for account/brand/team preferences.

### "Search performance"

Use title case consistently: "Search Performance". In the navigation architecture, group it under Visibility as a secondary screen.

## Menu Items That Should Move

| Current item | Move to |
| --- | --- |
| Search performance | Visibility > Search Performance |
| Competitors | Intelligence > Competitors |
| Topics | Intelligence > Topics |
| Mentions | Intelligence > Mentions |
| Distribution | Content > Publishing |
| Audiences | Marketing > Audiences |
| Briefings | Marketing > Briefings |
| Newsletters | Marketing > Newsletters |
| Campaigns | Marketing > Campaigns |
| Social posts | Marketing > Social Posts |
| Calendar | Marketing > Calendar |
| Analytics | Reporting > Performance |
| Automations | Agents > Automations |
| Notifications | Topbar user utility, not primary nav |
| Sources | Administration > Data Sources |
| Domain Events | Administration > Developer Tools |
| Settings | Administration |

## Menu Items That Should Merge

No existing functionality should be removed, but several navigation concepts should be merged at the IA level:

- Marketing, Campaigns, Audiences, Briefings, Newsletters, Social posts and Calendar merge into one Marketing workspace.
- Agents and Automations merge into one Agents workspace.
- Analytics and Reports merge into one Reporting workspace.
- Sources, Integrations, Connectors, Properties, Channels, Social profiles and Email providers merge into Administration setup groups.
- Topics and Knowledge Graph should be connected through Intelligence, with Knowledge Graph treated as setup/configuration.

## Missing Groupings

The current app lacks these IA groupings:

- Workspace shell: a stable top-level unit that can contain many feature screens.
- Secondary navigation: workspace-specific sections below each pillar.
- Contextual navigation: record-level tabs/actions for details like campaigns, content assets, reports and sources.
- Utility navigation: notifications, locale, profile, account and brand context.
- Operator navigation: developer tools, logs, syncs and system health.
- Add-on visibility: locked/hidden states for modules not enabled on the account.

## UX Risks

### High Risk

- The flat sidebar will become unusable as future modules launch.
- Operator screens such as Domain Events create anxiety and noise for non-technical users.
- Users cannot form a mental model of Argusly as a Brand Intelligence OS because features are listed by implementation area rather than operating-system pillar.
- Marketing execution screens are fragmented, making campaign workflows feel scattered.
- Settings and source/integration setup are split across unrelated navigation areas.

### Medium Risk

- Active states only match exact routes, so nested detail pages can lose clear sidebar context.
- Account and brand context appear in the topbar but are not treated as first-class switchers.
- Notifications are rendered as a primary nav item and a topbar item, creating duplicate utility access.
- Module-gated pages disappear individually rather than preserving a predictable workspace shape.
- "Soon" badges can make the nav feel roadmap-driven rather than task-driven.

### Low Risk

- Naming inconsistency between page headings and nav labels creates small comprehension costs.
- Settings navigation is horizontal and will not scale as setup surfaces grow.
- There is no obvious mobile navigation model beyond hidden desktop sidebar.
- There are no visible hierarchy cues for users with many brands or modules.

## Priority Recommendations

### High Priority

1. Replace the flat primary sidebar with grouped workspace navigation.
2. Move Domain Events and future logs/monitoring into Administration > Developer Tools.
3. Move Sources into Administration > Data Sources while preserving deep links from Intelligence and Visibility.
4. Consolidate Marketing OS, Campaigns, Audiences, Briefings, Newsletters, Social posts and Calendar under Marketing.
5. Consolidate Analytics and Reports under Reporting.
6. Add nested active-state rules so detail routes keep the correct workspace and section highlighted.

### Medium Priority

1. Rename Settings to Administration in primary navigation.
2. Introduce secondary navigation inside each workspace.
3. Turn account and brand labels into explicit switchers.
4. Introduce clear workspace landing pages with only relevant actions.
5. Add entitlement-aware workspace visibility rules.
6. Move Notifications to the topbar/user utility area only.

### Low Priority

1. Standardize capitalization and page subtitles.
2. Rename Distribution to Publishing in user-facing navigation.
3. Add optional pinned/favorite workspace shortcuts later.
4. Add keyboard command palette later, after the IA is stable.
5. Consider breadcrumbs for deep record pages.

## Recommended Target IA

The durable primary navigation should be:

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

This supports the current product and future modules without redesigning the shell. New features should almost always enter as secondary or tertiary navigation under one of these pillars.

## Future Module Placement

| Future module | Recommended location |
| --- | --- |
| Influencer Intelligence | Relationships or Intelligence, depending on whether the primary workflow is CRM or monitoring |
| Creator CRM | Relationships > Creators |
| Journalist CRM | Relationships > Journalists |
| Stakeholder CRM | Relationships > Stakeholders |
| Relationship Graph | Relationships > Graph |
| AI Visibility Monitoring | Visibility > AI Visibility |
| Prompt Monitoring | Visibility > Prompts |
| Citation Tracking | Visibility > Citations |
| Marketing Calendar | Marketing > Calendar |
| Newsletters | Marketing > Newsletters |
| Outreach | Marketing > Outreach or Relationships > Outreach depending on scope |
| Lead Intelligence | Intelligence > Leads |
| Marketplace | Administration > Marketplace or topbar utility if commercial |
| Connectors | Administration > Connectors |
| Future mobile app | Same workspace model, bottom tabs for top-level destinations |

## Architectural Principle

Argusly should not navigate by implementation module. It should navigate by user operating mode:

- Understand the market: Intelligence.
- Measure brand presence: Visibility.
- Produce reusable knowledge and content: Content.
- Execute campaigns and publishing: Marketing.
- Delegate work: Agents.
- Manage people and organizations: Relationships.
- Govern reusable media and files: Assets.
- Communicate outcomes: Reporting.
- Configure and operate the system: Administration.

