# Argusly LLM Layer

Argusly centralizes AI generation through the LLM layer. Application services should not call provider SDKs or OpenAI-specific HTTP clients directly. They should resolve a provider/model through `LlmResolver` and execute through `LlmClientInterface` or the convenience `LlmPromptRuntime`.

## Providers

Supported providers are:

- `openai`
- `anthropic`
- `google`
- `mistral`
- `groq`
- `openrouter`

Provider records live in `llm_providers`. Each provider has a stable provider key, display name, status, optional base URL, optional API key environment variable name, and JSON settings. API keys are never stored in the database or displayed in the UI.

## Models

Model records live in `llm_models` and belong to a provider. Models define:

- model identifier and display name
- type: `chat`, `completion`, `embedding`, or `vision`
- context window
- support flags for JSON, tools, vision, and streaming
- optional input/output cost per 1k tokens
- status and metadata

Seeded models cover common chat models for the initial providers. Disabled providers or disabled models are excluded from normal resolution.

## Defaults

Defaults live in `llm_settings`.

Resolution order is:

1. Brand setting
2. Account setting
3. Global setting
4. Environment fallback

Settings may define primary and fallback provider/model pairs, temperature, max tokens, and additional JSON settings. Global defaults are configured in the admin LLM UI. Account and brand defaults are configured in the tenant settings UI when permitted.

## Account And Brand Overrides

Account admins can set account-level defaults. Brand admins can set brand-level defaults. Brand settings win over account settings. Account settings win over global settings.

Application services pass the current `Account`, optional `Brand`, optional `User`, and a purpose into `LlmPromptRuntime`. The runtime resolves the provider/model and adds account, brand, user, source, fallback, and purpose metadata to the request.

## Environment Keys

API keys remain environment based:

- `OPENAI_API_KEY`
- `ANTHROPIC_API_KEY`
- `GOOGLE_AI_API_KEY`
- `MISTRAL_API_KEY`
- `GROQ_API_KEY`
- `OPENROUTER_API_KEY`

Default fallbacks can be controlled with:

- `LLM_DEFAULT_PROVIDER`
- `LLM_DEFAULT_MODEL`

OpenAI may use real HTTP configuration when an API key is present outside tests. Other providers are registered but use fake behavior until real provider integrations are added.

## Usage Tracking

Every centralized call creates an `llm_requests` record. Records include account, brand, user, provider, model, purpose, status, usage tokens, estimated cost, credits charged, latency, error message, metadata, creation time, and completion time.

Supported purposes are:

- `content_generation`
- `translation`
- `answer_block`
- `audit`
- `visibility_check`
- `social_post`
- `newsletter`
- `agent_task`
- `briefing_execution`
- `url_to_draft`
- `chained_content`
- `agentic_marketing`

Successful requests store usage and emit `LlmRequestCompleted`. Failed requests store the error and emit `LlmRequestFailed`. Successful charged requests deduct credits through `CreditService` and emit `LlmCreditsConsumed`.

## Fallback Behavior

When a primary provider call fails and the resolved settings include a fallback provider/model, `LlmClientManager` records the failed primary request, creates a second request with fallback metadata, and executes the fallback model. Credits are charged only for successful completed requests.

## Tests And Fake Provider

Tests use the fake client behavior by default. Fake responses can be controlled with request metadata `fake_content`, which keeps generation flows deterministic while still exercising resolver, request tracking, credit deduction, fallback metadata, and domain events.

New generation flows should add focused tests that assert:

- the flow uses the LLM layer
- the expected purpose is recorded in `llm_requests`
- successful calls deduct credits
- failed primary calls can fall back when configured
