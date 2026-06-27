# Human Content Engine

The Human Content Engine is the editorial quality layer for Argusly generated content. It is part of the real draft, automation, translation, publication, and intelligence pipeline. It is not a separate generation path.

## Architecture

The engine is composed of these integrated services:

- `App\Services\Editorial\EditorialPlanningService`
  - Creates and persists an Editorial Plan before generation.
  - Uses `EditorialPatternLibrary` and `CorpusDiversityService` to avoid repeated article movement.
- `App\Services\Editorial\EditorialPatternLibrary`
  - Selects narrative patterns from topic, audience, intent, funnel stage, evidence, and related content.
- `App\Services\HumanContent\AiFingerprintDetector`
  - Detects generic headings, transitions, predictable openings/endings, list overuse, uniform rhythm, cliches, filler, and related AI-like patterns in English and Dutch.
- `App\Services\HumanContent\CorpusDiversityService`
  - Compares a draft against recent related workspace content and reports structural repetition risk.
- `App\Services\HumanContent\HumanContentScoreService`
  - Scores generated or translated drafts for human and editorial quality.
- `App\Services\HumanContent\HumanizationService`
  - Applies one targeted repair pass when scores fail. It preserves facts, links, entities, SEO metadata, schema compatibility, CTA intent, and research evidence.
- `App\Services\HumanContent\HumanContentGate`
  - Blocks automatic publication when generated content fails minimum thresholds.
- `App\Services\HumanContent\HumanContentDashboardService`
  - Aggregates stored score payloads for workspace-level editorial health dashboards.

## Pipeline

### Manual And Automation Draft Generation

`GenerateDraftJob` is the single generation lifecycle for manual and automation drafts:

1. Prepare draft context.
2. Create or load the Editorial Plan.
3. Generate the draft through `DraftGenerationService`.
4. Run `HumanContentScoreService`.
5. Run AI fingerprint detection through the score service.
6. Run `HumanizationService` once if the score fails.
7. Re-score.
8. Persist before/after scores, findings, humanization changes, and publish gate status.
9. Continue existing lifecycle, internal link, webhook, and publication logic.

`ContentAutomationArticleService` uses the same generation job/service path and evaluates `HumanContentGate` before auto-publication. Automation items that fail are marked as needing editorial review instead of failing the whole run.

### Series Generation

`SeriesArticleGenerationService` creates briefs/drafts with Editorial Plan metadata and then dispatches the normal draft generation pipeline. Series articles inherit the same scoring, humanization, and publishing gate behavior once generation runs.

### Translation

`TranslationService` preserves meaning, facts, SEO intent, entities, CTA intent, and link URLs while allowing target-language editorial naturalization. After a translated draft is created or refreshed:

1. `HumanContentScoreService` scores the translated draft in the target locale.
2. `HumanizationService` runs once if thresholds fail.
3. The translated draft is re-scored.
4. Locale-specific scores are stored under `human_content.locales.{locale}` and `translation.human_content`.

### Content Improvement

Draft improvement actions continue through `DraftIntelligenceService`. Improvements preserve the existing draft intelligence path; after analysis, `DraftIntelligenceService` enriches the payload with Human Content scores and updates publish readiness when the Human Content score or gate fails.

### Scheduled Publication

Publication uses `ContentPublicationService` and connector publishing services. Automatic publication checks `HumanContentGate` before dispatch. Manual saves remain allowed, but automatic publication is blocked and marked `needs_editorial_review` when the gate fails.

## Scoring Dimensions

`HumanContentScoreService` returns:

- `human_content_score`
- `editorial_quality_score`
- `originality_score`
- `narrative_flow_score`
- `human_voice_score`
- `expertise_score`
- `insight_density_score`
- `evidence_usage_score`
- `rhythm_score`
- `curiosity_score`
- `ai_fingerprint_score`
- `uniqueness_score`

The payload also includes:

- `dimension_breakdown`
- `findings`
- `recommendations`
- `suggested_humanization_actions`
- `ai_fingerprint`
- `corpus_diversity`
- `signals`

## Gate Thresholds

`HumanContentGate` blocks automatic publication when any of these fail:

- Human Content Score is below 70.
- Editorial Quality Score is below 65.
- Originality Score is below 65.
- AI Fingerprint Score is above 45.
- Severe fingerprint findings exist.
- Generated articles are missing a usable Editorial Plan.

Manual drafts may still be saved when the gate fails. Automatic publication is blocked and content is marked `needs_editorial_review`.

## UI Behavior

Draft Intelligence shows Human Content metrics next to existing SEO and AEO/intelligence sections. It shows:

- current and before/after scores
- top findings
- recommended improvements
- humanization status
- publish gate status and reasons
- actions to run Humanization or re-score Human Content

The workspace-level Human Content dashboard is available at `app.insights.human-content.index`. It uses stored score payloads only and caches aggregate results. It shows:

- average Human Content, Editorial Quality, Originality, AI Fingerprint, Narrative Flow, and Human Voice scores
- trend over time
- most repetitive articles
- most original articles
- most human articles
- articles blocked by the Human Content Gate
- common AI fingerprint findings

Supported dashboard filters:

- workspace
- site
- locale
- content type
- period

## Automation Behavior

Automation runs continue after weak content is generated. The generated draft is saved with scores and gate reasons, the automation item is marked for editorial review, and auto-publication is skipped. This keeps the run auditable without silently publishing weak content.

## Storage

Human Content metadata is stored on draft `meta`, including:

- `editorial_plan`
- `human_content.before`
- `human_content.after`
- `human_content.locales.{locale}` for translations
- `human_content_score_before`
- `human_content_score_after`
- `ai_fingerprint_score_before`
- `ai_fingerprint_score_after`
- `fingerprint_findings`
- `corpus_diversity_findings`
- `humanization_changes`
- `humanization_status`
- `publish_gate_status`
- `human_content_gate`

The dashboard and UI read these stored payloads; they do not run expensive live scans on page load.
