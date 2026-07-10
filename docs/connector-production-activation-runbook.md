# Connector Production Activation Runbook

This runbook turns the data connector code into a controlled production rollout. It covers the external provider setup that cannot be completed by code alone, the first staging proof, production backfill limits, monitoring, privacy lifecycle, and the gate before Phase 31.

## Rollout Order

Do not activate all nine providers at once.

Pilot one provider from each group:

- Google connector: Google Analytics 4 or Google Search Console.
- Ads connector: Google Ads.
- CRM connector: Pipedrive.

After the pilot is stable, continue with the remaining providers:

- Pre-Phase 28 providers: Google Search Console, Google Analytics 4, LinkedIn Company Pages.
- Phase 28 providers: Google Ads, Meta Ads, Microsoft Ads, HubSpot, Pipedrive, Salesforce.

## Provider App Checklist

Complete this checklist per provider before enabling production OAuth:

- Production client ID and client secret are present in the environment.
- Callback URL exactly matches the configured redirect URI.
- Required scopes match `config/data_connectors.php`.
- Test account and production account are both known.
- Provider app verification or review is complete where required.
- Refresh tokens are issued and can refresh without manual reconnect.
- Account discovery returns the expected account, property, organization, ad account, or CRM portal.
- Dataset selection is explicit before sync.
- API quota model and warning thresholds are documented.
- Privacy, disconnect, deletion, and export procedures are documented.

Configured callback paths:

| Provider | Callback path |
| --- | --- |
| Google Search Console | `/connectors/oauth/google-search-console/callback` |
| Google Analytics 4 | `/connectors/oauth/google-analytics-4/callback` |
| LinkedIn | `/connectors/oauth/linkedin/callback` |
| Google Ads | `/connectors/oauth/google-ads/callback` |
| Meta Ads | `/connectors/oauth/meta-ads/callback` |
| Microsoft Ads | `/connectors/oauth/microsoft-ads/callback` |
| HubSpot | `/connectors/oauth/hubspot/callback` |
| Pipedrive | `/connectors/oauth/pipedrive/callback` |
| Salesforce | `/connectors/oauth/salesforce/callback` |

## Staging End-to-End Test

Run one full ketentest for GA4, Google Ads, and Pipedrive before production activation:

1. OAuth.
2. Account discovery.
3. Dataset selection.
4. First sync.
5. Raw records.
6. Normalization.
7. Reporting.
8. Attribution.
9. Intelligence feed.

Checks that must be signed off:

- Account and property names match the source.
- Campaign count matches the source.
- Spend is approximately equal to the source.
- Currency display is correct.
- Records land on the correct local reporting date.
- Re-syncing does not duplicate raw records.
- A 30-day backfill works.
- Token refresh works without manual reconnect.
- Data stays inside the correct workspace.

## Production Sync Ramp

Use staged historical windows:

1. First sync: last 7 days.
2. Next backfill: last 30 days.
3. Next backfill: 90 days.
4. Longer backfills only after mapping, quota, and reporting are verified.

Backfill safety defaults are enforced in `config/data_connectors.php`:

- `DATA_CONNECTOR_BACKFILL_DEFAULT_CHUNK_DAYS=7`
- `DATA_CONNECTOR_BACKFILL_MAX_CHUNK_DAYS=30`
- `DATA_CONNECTOR_BACKFILL_MAX_REQUESTED_DAYS=90`
- `DATA_CONNECTOR_BACKFILL_MAX_RANGES_PER_REQUEST=30`

Raise these limits only for a planned maintenance window with provider quota confirmed.

## Operational Monitoring

Connector diagnostics already expose health, quotas, raw records, normalization, currency coverage, backfills, async report jobs, and webhook readiness. Production monitoring must alert on:

- `needs_reconnect`.
- Repeated failing syncs.
- Quota hard stops.
- Stagnating queues.
- Old or incomplete datasets.
- Normalization failures.
- Daily connector health summary.
- Retention policy for raw records, sync runs, and audit logs.

Classify health as:

- Technically healthy.
- Connected but no data.
- Data received but not normalized.
- Normalized but insufficient reporting coverage.
- Reporting available but insufficient attribution matches.

## Data Quality Fixtures

During pilot syncs, capture sanitized regression fixtures for:

- Missing fields.
- Deleted campaigns.
- Multiple currencies.
- Multiple timezones.
- Custom CRM fields.
- Empty reporting days.
- Changed pipeline stages.
- Merged CRM contacts.
- API pagination.
- Partially failed async reports.

Never commit real personal data, real tokens, or customer-identifying payloads.

## Reporting And Attribution Definitions

Confirm these definitions with the business before Phase 31:

- Lead.
- Opportunity.
- Conversion.
- Revenue.
- Influenced revenue.
- Campaign influenced.
- CPL.
- CPA.
- ROAS.
- First touch.
- Last touch.
- Attribution lookback.

Customer-specific definitions, especially conversion, revenue, and campaign influence, should become workspace-configurable where needed.

## Onboarding Checklist

The connector onboarding flow should make these states clear to an administrator:

- Connected.
- Account selected.
- Datasets selected.
- First sync complete.
- Normalization complete.
- Reporting available.
- Attribution ready.

The UI should also expose what data is read, which scopes are requested, whether Argusly is read-only, available accounts, selected datasets, sync frequency, historical period, reporting timezone, reporting currency, and first sync status.

## Privacy And Lifecycle

Support three separate operator actions:

- Disconnect connector.
- Delete imported data.
- Delete connector and all associated data.

Document and test:

- What happens on disconnect.
- Whether historical data remains.
- How a workspace deletes all connector data.
- Whether webhook registrations are removed.
- Whether provider tokens are revoked remotely or only removed locally.
- Raw record retention.
- Customer export and deletion.
- Audit log retention after data deletion.

Destructive actions require an additional confirmation step.

## Phase 31 Gate

Start Phase 31 only after a successful real-data pilot. The initial scope should be a provable insight engine:

Normalized data -> deterministic signals -> evidence package -> explanation -> suggested action -> human approval.

Candidate insights:

- Period-over-period deviations.
- Campaign anomalies.
- Rising CPL or CPA.
- Falling conversion.
- Spend without result.
- Campaigns influencing pipeline.
- Missing or stalled CRM follow-up.
- Budget-shift opportunities.
- Underperforming channels.
- Data-quality warnings.

Every insight must include the finding, period, metrics, evidence records, explanation, and suggested action.
