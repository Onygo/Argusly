# Argusly Credit Economy

Argusly uses a central credit cost catalog as the source of truth for all feature credit consumption. Product services should not contain hardcoded credit prices. They should call `CreditService`, which resolves pricing through `CreditCostResolver`.

## Catalog Structure

Credit costs live in `credit_cost_catalog`.

Important fields:

- `code`: stable machine key, such as `blog_generation` or `visibility_check`
- `category`: `content`, `translation`, `visibility`, `social`, `newsletter`, `agent`, `monitoring`, or `system`
- `default_cost`: default number of credits
- `minimum_cost` and `maximum_cost`: optional bounds for future variable pricing
- `cost_type`: `fixed` or `variable`
- `status`: `active` or `inactive`
- `metadata`: variable-pricing rules and operational notes

Legacy cost keys are aliases only. They do not contain prices. For example, `content_generation` resolves to `blog_generation`.

## Overrides

Enterprise and custom pricing lives in `credit_cost_overrides`.

Resolution order:

1. Brand override
2. Account override
3. Catalog default

Brand overrides must belong to the same account. Overrides can be disabled with `status = inactive` without deleting pricing history.

## Pricing Resolution

Use `CreditCostResolver` for direct pricing questions:

- `resolveCost($code)`
- `resolveCostForAccount($account, $code)`
- `resolveCostForBrand($account, $brand, $code)`
- `supportsOverride($code)`
- `calculateVariableCost($catalog, $baseCost, $context)`

Use `CreditService` for actual wallet changes:

- `consume($account, $user, $code, $description, $subject, $metadata)`
- `consumeForAccount($account, $code, $description, $subject, $metadata)`
- `grant(...)`
- `refund(...)`

Consumption stores the resolved catalog code, requested code, catalog ID, and source in `credit_transactions.metadata`.

## Usage Tracking

Every successful credit consumption updates `credit_usage_stats`.

Stats are aggregated by:

- account
- optional brand
- catalog code
- monthly period

The admin Cost Catalog screen shows usage count, credits used, overrides, and last-used time.

## Domain Events

Credit workflows emit:

- `CreditCostResolved`
- `CreditsConsumed`
- `CreditsRefunded`
- `CreditOverrideCreated`
- `LowCreditsDetected`

Existing low-credit signal production remains connected to credit transactions and can create recommendations through the recommendation engine.

## Future Variable Pricing

Variable pricing is prepared but conservative by default. Catalog rows can store rules in metadata, for example:

- `translation`: base cost plus planned per-1000-word pricing
- `visibility_check`: base cost plus planned per-provider pricing
- `agent_task`: base cost plus planned LLM-usage pricing

Rules are only applied when metadata marks them active. Until then, variable catalog rows behave like fixed base costs.
