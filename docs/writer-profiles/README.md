# Writer Profiles

Writer Profiles are reusable style cards for content generation. They sit beside brand profiles, company profiles, personas, ICPs, campaign briefs, and Agentic Marketing recommendations.

## What They Do

Writer Profiles analyze sample texts and store abstract style guidance:

- tone
- writing style
- structure
- vocabulary
- formatting preferences
- concrete do and don’t rules
- abstract example patterns
- confidence score

They must not store or reuse unique sentences, claims, examples, anecdotes, or recognizable wording from source material unless source retention is explicitly enabled and the user has permission to store it.

## Priority In Generation

Generation uses this priority order:

1. Campaign brief
2. Brand/company positioning
3. Persona/ICP
4. Content goal/search intent
5. Writer profile
6. Channel constraints

The writer profile controls style only. It cannot override strategy, facts, target audience, compliance, positioning, search intent, or channel limits.

## Privacy

Each profile has `retain_source_text`.

- `false`: source text is analyzed transiently and `writer_profile_sources.source_text` stays null.
- `true`: source text is stored with the profile source row.

The UI warns users that pasted or selected texts are used for style analysis. Keep retention off for confidential, licensed, or third-party source texts unless storage is allowed.

## Prompt Structure

The prompt templates live in `App\Services\WriterProfiles\WriterProfilePromptTemplates`.

- analysis: creates a structured style card from multiple examples
- refinement: updates an existing style card without copying examples
- apply: adds compact writer guidance to generation context

All templates include the same core rule: use the writer profile only as style direction and do not reuse unique source text.

## Where It Is Used

- `DraftGenerationService` appends compact writer profile context for drafts.
- `SocialPostVariantGenerationProvider` applies profiles to LinkedIn variants.
- `AgenticMarketingActionPlanner` can recommend a writer profile for planned actions.
- `WriterProfileFitService` scores generated content for tone, structure, vocabulary, readability, brand/persona consistency, and overfitting risk.
