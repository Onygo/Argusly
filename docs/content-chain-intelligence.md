# Content Chain Intelligence

This extension adds two coordinated layers to chained content management:

- Growth suggestions for follow-up articles based on existing content quality, performance signals, cluster gaps and editorial guidance.
- Contextual inline linking that proposes or applies chained links inside article body content, with footer links kept as a secondary fallback.

## Added domain model

- `content_chain_guidances`
  - Stores editorial steering per content item.
  - Supports source enablement, preferred angle, goal type, priority, explicit topic, target audience, target intent and inline-linking mode.
- `content_chain_suggestions`
  - Stores generated growth, inline-link and footer-link suggestions.
  - Keeps score, rationale, source snapshot, placement metadata and review status.

Review states:

- `suggested`
- `approved`
- `rejected`
- `auto_applied`
- `converted`

## Services and flow

- `ChainedContentScoringService`
  - Produces a transparent weighted source score.
- `ChainedSuggestionGenerator`
  - Builds growth opportunities such as deep dives, comparisons, how-to pieces and support content.
- `InlineLinkCandidateMatcher`
  - Finds safe inline-link candidates from existing article HTML without touching existing anchors.
- `ContextualLinkInsertionService`
  - Applies approved inline links and appends a generated footer section for remaining targets.
- `ChainedContentOpportunityService`
  - Orchestrates scoring, signal collection, target selection and persistence.
- `ChainedContentCreationService`
  - Converts an approved growth suggestion into a new `Content` plus `Brief`.

## Admin workflow

The content overview screen now includes a chained intelligence panel:

- Editorial guidance form for manual steering
- Growth suggestions with rationale and source score
- Inline-link suggestions with anchor and placement context
- Footer fallback suggestions
- Review actions: approve, reject, apply links, create chained article

## Inline linking rules

- Existing manual anchors are preserved.
- Headings are skipped unless explicitly enabled.
- Generic anchor terms are filtered out.
- Inline links are limited per article.
- Footer links only include remaining chained targets that were not already used inline.
- Generated footer markup is idempotent and can be recalculated safely.

## Scoring inputs

Configured in `config/content_chain.php`:

- quality score
- page views
- engagement rate
- recency
- chain gap score
- manual priority boost
- topical gap score

Thresholds and weights are config-driven so ranking can be tuned without changing business logic.

## Analytics fallback behavior

Performance signals are read conservatively:

- prefer `analytics_rollups_daily.article_id`
- fallback to `url_key` when available
- fallback to `path_hash`

This keeps the feature compatible with both normalized and older rollup shapes.

## Tests

Coverage added for:

- scoring transparency
- inline link matching
- suggestion refresh flow
- duplicate prevention on refresh
- manual rejection persistence
- approved inline/footer link application
- creating a chained article from a growth suggestion
