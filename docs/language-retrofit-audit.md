# Argusly Language and Locale Retrofit Audit

Date: 2026-05-29  
Scope: Laravel 13 codebase after Prompt 50: connector platform/API, publishing queue, AI Visibility provider adapters, prompt library, and AI Visibility scheduler.

This is an audit only. No implementation changes are included here.

## Executive Summary

Argusly has partial language support in content and prompt-library foundations, but it is not yet consistent enough for multilingual or multi-market operation.

The strongest existing coverage is:

- `content_assets`: has `language` and `locale`, unique slugs by locale, connector payloads expose both.
- `visibility_prompt_templates`: has `locale`, `market`, and `persona`.
- `properties`: has `primary_language`.
- `brands`: has `market` and `language`.

The main retrofit gaps are:

- No `content_translations` table/model exists.
- Several domains store user-facing or ingestion text without `language` or `locale`: mentions, sources, intelligence signals, recommendations, campaigns, visibility citations, visibility answer entities.
- `generated_assets` and `answer_blocks` only store `language`, not `locale`.
- `visibility_provider_runs` do not persist `language`, `locale`, `market`, or `persona` as first-class run dimensions; run metadata also drops `persona`.
- Dashboards and index pages largely filter by status/type/provider/date, not language/locale/market.
- Forms mostly use free-text `language`/`locale` fields or omit them entirely; there is no canonical language/locale selector.
- Connector event intake accepts raw payloads but does not validate or normalize `language`/`locale`.
- Tests mostly use `en`/`en_US` defaults and do not exercise multilingual isolation, filtering, or connector payload compatibility.

## Canonical Locale Model Needed

Before adding columns, define a canonical strategy:

- `language`: short BCP 47 primary/subtag value such as `en`, `nl`, `de`, `fr`, or `en-GB`.
- `locale`: market-specific locale such as `en_US`, `en_GB`, `nl_NL`, or `fr_FR`.
- `market`: commercial/geographic market such as `US`, `UK`, `NL`, `DE`, or `EU`.
- `persona`: AI Visibility audience/context dimension, not a language substitute.

Recommendation for retrofit: keep both `language` and `locale` where records contain user-facing text or generated text. Keep `market` on visibility, campaign, source, and signal domains where market segmentation affects interpretation.

## Domain Audit

| Domain | Current support | Missing / risk | Retrofit priority |
| --- | --- | --- | --- |
| `content_assets` | Has `language`, `locale`; slug uniqueness includes locale; form exposes both as text inputs; connector payload includes both. | No language/locale filters; hardcoded defaults `en`/`en_US`; no canonical validation; no translation relationship. | High |
| `generated_assets` | Has nullable `language`; generation payload includes source asset locale. | No `locale`; generated asset language can diverge from source without locale context; output has no target locale/market. | High |
| `answer_blocks` | Has `language`; inherits content asset language on create; connector payload includes block language. | No `locale`; no language filter; standalone answer blocks cannot distinguish `en_US` vs `en_GB`; form is free text. | High |
| `content_translations` | No table/model/controller references found. | Entire translation layer is absent; no source/target locale, translation status, fallback, or linkage. | Critical |
| `publishing_actions` | Request payload embeds `content.language` and `content.locale`. | No first-class `language`/`locale` columns for queue filtering/reporting; published/failed callbacks do not validate locale; action payload top level lacks locale. | Medium |
| Connector API payloads | Pending content response includes content `language`/`locale`; answer blocks include `language`. | Connector events do not require or normalize `language`/`locale`; answer blocks lack `locale`; manifest/capabilities do not advertise locale support; pending endpoint has no language/locale filters. | High |
| `visibility_prompt_templates` | Has `locale`, `market`, `persona`; create/update validation supports all three. | No `language`; UI lacks a `persona` field despite controller/model support; locale is free text; no list filters. | High |
| `visibility_provider_runs` | Linked to prompt templates; run context passes `locale` and `market` in some paths. | No `language`, `locale`, `market`, `persona` columns; run metadata does not persist context; `persona` is not passed to providers in controller/scheduler. Historical runs lose dimensions if template changes. | Critical |
| `visibility_citations` | Stores citation URL/domain/title/snippet. | No citation `language`/`locale`; snippets from localized SERPs/AI answers cannot be filtered or evaluated by locale. | Medium |
| `visibility_answer_entities` | Stores entity/sentiment/position. | No `language`/`locale`; extracted entity sentiment is not scoped to answer language or market. | Medium |
| `visibility_run_schedules` | Linked to prompt template; schedule settings JSON available. | No first-class `locale`, `market`, `persona`; settings do not snapshot these; schedule execution depends on current template values. | High |
| `recommendations` | Tenant/brand/signal scoped. | No `language`/`locale`; recommendation text and action are assumed universal; no localized recommendation variants. | Medium |
| `intelligence_signals` | Stores payload JSON and tenant/brand dimensions. | No `language`/`locale`/`market`; signal feed filters omit locale; connector-event signals copy raw payload but do not normalize language context. | High |
| `sources` | Stores type/provider/status/scope/metadata. | No `language`, `locale`, or `market`; source registry cannot represent a Dutch RSS feed, German Google SERP, or US Perplexity corpus as first-class dimensions. | High |
| `mentions` | Stores title/content/url/author/sentiment/source/date. | No `language`/`locale`; mention feed filters omit language; sentiment can be compared across languages without context. | Critical |
| `campaigns` | Stores campaign content, topics, signals, metadata. | No `language`/`locale`/`market`; campaign forms and filters omit language; linked assets/signals can mix locales silently. | High |

## Schema Findings

### Tables with useful language/locale foundations

- `content_assets.language`, `content_assets.locale`
- `answer_blocks.language`
- `generated_assets.language`
- `visibility_prompt_templates.locale`
- `visibility_prompt_templates.market`
- `visibility_prompt_templates.persona`
- `brands.language`
- `brands.market`
- `properties.primary_language`

### Tables missing first-class language/locale columns

Add explicit columns where the record itself contains text, generated output, ingestion output, or market-sensitive interpretation:

- `generated_assets`: add `locale`; consider `market`.
- `answer_blocks`: add `locale`.
- `publishing_actions`: add denormalized `language`, `locale` for queue filters and reporting.
- `visibility_provider_runs`: add `language`, `locale`, `market`, `persona`.
- `visibility_citations`: add `language`, `locale`.
- `visibility_answer_entities`: add `language`, `locale`.
- `visibility_run_schedules`: add snapshot fields or generated columns for `locale`, `market`, `persona`.
- `recommendations`: add `language`, `locale`; consider `market`.
- `intelligence_signals`: add `language`, `locale`; consider `market`.
- `sources`: add `language`, `locale`, `market`.
- `mentions`: add `language`, `locale`.
- `campaigns`: add `language`, `locale`, `market`.

### Missing table

`content_translations` is not present in migrations, models, services, routes, views, or tests.

Suggested future shape:

- `id`, `uuid`
- `account_id`, `brand_id`
- `source_content_asset_id`
- `translated_content_asset_id`
- `source_language`, `source_locale`
- `target_language`, `target_locale`
- `status`
- `provider`, `model`
- `requested_by`, `approved_by`
- `input_payload`, `output_payload`, `metadata`
- timestamps

## Hardcoded Language Assumptions

Current hardcoded English/default-locale assumptions:

- `ContentAsset` model defaults to `language = en`, `locale = en_US`.
- `ContentAssetController::create()` defaults new assets to `en` / `en_US`.
- `ContentAssetService::uniqueSlug()` falls back to `en_US`.
- `AnswerBlock` model defaults to `en`.
- `AnswerBlockController::create()` defaults standalone blocks to `en`.
- `GeneratedAssetFactory`, `ContentAssetFactory`, and `AnswerBlockFactory` default to `en` / `en_US`.
- `ContentAssetSeeder` creates demo content only in `en` / `en_US`.
- `PublishingFoundationSeeder` derives `primary_language` from brand language or `en`.
- Visibility prompt UI defaults locale to `en_US`.
- Visibility example prompts are English-only.
- Fake AI visibility provider returns English answers, citations, and snippets.
- Placeholder visibility checks/results/citations are English-only.
- Content generation fake output is English-only and ignores requested language except storing `GeneratedAsset.language`.
- Marketing/public page and in-app copy are all English-only. This may be acceptable for product UI, but it should not leak into generated content, prompt runs, or ingestion outputs.

## Form and UI Gaps

### Content assets

Current form exposes `language` and `locale`, but as free-text inputs. Index filters support only `status` and `type`.

Missing:

- Language select.
- Locale select.
- Language/locale filters.
- Display both language and locale consistently; index currently displays locale only.
- Validation against supported languages/locales.

### Answer blocks

Current form exposes `language` as free text. Index filters support only `status` and `type`.

Missing:

- Locale field.
- Language/locale filters.
- Inheritance/display of parent content asset locale.
- Protection against linking a block to an asset with mismatched locale unless explicitly allowed.

### Generated assets

Generation request accepts optional `language`, but the UI does not provide a robust target language/locale selector and the table cannot store locale.

Missing:

- Target locale field.
- Translation-specific source/target selection.
- Tests for requested generation language differing from source content language.

### Visibility

Prompt library stores `locale`, `market`, and `persona`, but the UI only exposes `intent`, `locale`, `market`, and `status`.

Missing:

- Persona field in create and update forms.
- Language field or derived language display from locale.
- Filters for prompt templates and provider runs by locale, market, persona, provider.
- Provider run cards should display locale/market/persona.
- Schedule creation/management UI is not present, so schedule locale behavior is opaque.

### Mentions

Mention feed filters by source, sentiment, date, and brand scope.

Missing:

- Language filter.
- Locale filter.
- Display language/locale on mention cards and details.
- Source language/locale inheritance or normalization.

### Sources

Source registry form and filters cover type, provider, status, and scope.

Missing:

- Source language.
- Source locale.
- Source market.
- Filters for language/locale/market.

### Intelligence signals and recommendations

Signal feed filters by status, type, category, and priority. Recommendations are displayed in dashboards/cards without language context.

Missing:

- Signal language/locale/market filters.
- Recommendation language/locale fields and display.
- Rules for whether recommendations are localized copies or canonical account-level actions.

### Campaigns

Campaign forms and filters cover type/status/content/topics/signals.

Missing:

- Campaign language.
- Campaign locale.
- Campaign market.
- Filters for language/locale/market.
- Guardrails for linking content assets, signals, and topics from incompatible locales.

## Connector API Findings

Good:

- `/api/v1/content/pending` returns `content.language` and `content.locale`.
- Publishing request payload generated by `PublishingService` embeds `content.language` and `content.locale`.

Gaps:

- `answer_blocks` in connector payloads include `language` but not `locale`.
- `publishing_action` payload does not expose language/locale at the action level.
- `/api/v1/connector/events` accepts raw `payload` and validates event-specific identifiers, but does not require `language` or `locale` for content/taxonomy events.
- Connector event domain events store raw payload language only if the connector voluntarily sends it.
- Connector-generated intelligence signals do not normalize language/locale from event payload.
- `/api/v1/content/pending` cannot be filtered by `language` or `locale`.
- `/api/v1/content/{content}/published` and `/failed` callbacks do not accept or validate final published locale, localized URL, or remote locale mapping.
- Manifest/capabilities payloads do not advertise supported languages/locales or whether the connector can publish localized content.

Suggested future connector contract additions:

- `content.language`
- `content.locale`
- `content.market`
- `answer_blocks[].locale`
- `publishing_action.language`
- `publishing_action.locale`
- `connector.supported_locales`
- Event payload fields for `language`, `locale`, `market`, and external locale identifiers.

## AI Visibility Run Findings

Prompt templates now support locale, market, and persona, but run execution does not preserve these dimensions strongly enough.

Specific gaps:

- `VisibilityController::runPrompt()` passes `locale` and `market` in context, but omits `persona`.
- `RunScheduleService::runSchedule()` passes `locale` and `market`, but omits `persona`.
- `ProviderRunService::runPrompt()` creates provider run metadata with `adapter_key` and `fake`, but does not persist the full run context.
- `visibility_provider_runs` has no first-class `language`, `locale`, `market`, or `persona`.
- `visibility_run_schedules` has no first-class locale/market/persona snapshot.
- `visibility_citations` and `visibility_answer_entities` inherit tenant scope only, not language/locale.
- Placeholder scoring hashes provider/query/brand but not locale/market/persona, so different locales can collapse into the same deterministic result if query text is reused.

Minimum future behavior:

- Snapshot prompt template locale/market/persona onto every run at execution time.
- Pass persona to providers.
- Include language/locale/market/persona in score seeds, dedupe keys, and signal generation.
- Preserve run context even if the prompt template later changes.

## Dashboard and Filter Gaps

Dashboards currently aggregate without language dimensions:

- Main dashboard recommendations, visibility stats, recent mentions, and sentiment overview.
- Content index.
- Answer block index.
- Visibility timeline, prompt library, provider runs.
- Mention feed and sentiment overview.
- Source registry.
- Intelligence signal feed.
- Campaign index.

Risk: multilingual data will be aggregated into a single feed/score, causing misleading sentiment, visibility, recommendation, and campaign decisions.

Recommended future filters:

- Global context selector: brand + market + locale.
- Per-page filters: language, locale, market where relevant.
- Dashboard metrics: segment by locale before aggregating.
- Saved preferences: default to brand/property language or user-selected locale.

## Test Coverage Gaps

Existing tests mainly validate tenant scoping, permissions, connector flows, publishing queue behavior, provider artifacts, and scheduler execution. They use `en` / `en_US` defaults where language appears.

Missing test cases:

- Content index filters by language and locale.
- Slug uniqueness allows same slug across locales and rejects duplicates within same locale.
- Content creation rejects unsupported language/locale once canonical validation exists.
- Generated assets preserve both target language and target locale.
- Answer blocks inherit and expose parent content asset locale.
- Standalone answer blocks can be filtered by language/locale.
- Connector pending payload includes content and answer block locale.
- Connector events normalize incoming `language`/`locale` into domain events and signals.
- Published callback handles localized external URLs.
- Prompt template create/update stores `persona` from UI.
- Manual prompt runs pass and persist locale, market, and persona.
- Scheduled prompt runs snapshot locale, market, and persona.
- Provider run dedupe/scoring differs by locale/market/persona.
- Citations and answer entities inherit run locale.
- Mentions can be created, listed, and filtered by language/locale.
- Source registry can create and filter localized sources.
- Intelligence signals and recommendations can be segmented by language/locale/market.
- Campaigns prevent or warn on mixed-locale asset/signal/topic links.
- Dashboard aggregates are locale-aware.

## Suggested Retrofit Order

1. Define canonical supported languages/locales/markets in config and validation rules.
2. Add schema columns for language/locale/market/persona to the high-risk data tables.
3. Add `content_translations` table/model and relationships.
4. Snapshot locale/market/persona onto visibility runs and schedules.
5. Normalize connector API contracts and event payload validation.
6. Add filters and selectors to content, visibility, mentions, sources, intelligence, campaigns, and dashboards.
7. Add multilingual feature tests before changing business logic.
8. Update seeders/factories to include at least `en_US`, `en_GB`, `nl_NL`, and one non-English example.

## Open Decisions

- Should `locale` use underscore (`en_US`) or hyphen (`en-US`) internally? Current code uses `en_US`.
- Should `language` allow full BCP 47 tags (`pt-BR`) or only primary language codes?
- Should `market` be independent from locale, or derived from locale by default?
- Should campaigns be single-locale by design, or support multiple locale variants under one campaign?
- Should recommendations be localized records, or canonical records with translated presentation text?
- Should connector installations declare supported locales at installation level, channel level, or both?
