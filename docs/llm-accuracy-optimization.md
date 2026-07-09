# LLM Accuracy Optimization

Argusly follows the OpenAI accuracy optimization loop: start with prompt engineering, keep an evaluation baseline, inspect failures, and only add heavier tools such as retrieval or fine-tuning when the failure mode justifies it.

## Current Guardrails

- `llm_requests.metadata.prompt_hash` and `message_metrics` record prompt provenance without storing raw prompt text.
- `prompt_version`, `eval_rubric_version`, `schema_name`, and `context_strategy` identify which request variant produced an output.
- Source briefing analysis uses strict structured output with confidence and diagnostics fields.
- Translation uses strict structured output for content, SEO fields, slug, keywords, and translation notes.
- Draft generation includes quality gates for grounding, cautious claims, thesis movement, and complete JSON output.

## Evaluation Loop

1. Select 20 or more representative examples for the workflow being improved.
2. Record expected outcomes: required fields, unacceptable hallucinations, source reuse risk, link preservation, SEO constraints, and human-review triggers.
3. Run the current `prompt_version` and store the request IDs.
4. Label failures by likely cause:
   - `instruction_following`: output ignored prompt or schema requirements.
   - `missing_context`: model could not know the answer from supplied context.
   - `context_noise`: model was distracted by irrelevant context.
   - `behavior_consistency`: same task shape fails inconsistently across examples.
   - `business_risk`: wrong output has material customer, compliance, or brand impact.
5. Change one thing at a time and compare pass rate, latency, token cost, and review flags.

## Escalation Rules

- Improve prompts first when failures are instruction-following, formatting, tone, or rubric clarity issues.
- Add or tune retrieval only when failures are caused by missing or stale context.
- Reduce supplied context when failures look like context noise or source overfitting.
- Consider fine-tuning only after prompt baselines are stable and failures are behavior-consistency problems with representative examples.
- Keep high-risk workflows assistant-led or review-gated when business impact is larger than the expected automation gain.
