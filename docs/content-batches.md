# Content Batches (Cluster Generation)

## What it does
- Lets client users generate up to 10 related articles from one main keyword.
- Creates a batch with per-item progress and error tracking.
- Reuses existing Brief -> Draft generation flow and existing WordPress delivery flow.

## Flow
1. Create batch in app UI: `Content -> Generate multiple articles`.
2. Provide:
- main keyword
- subkeywords list (up to 10, one per line)
- optional generation settings (language, tone, length, brand voice, team member)
3. Start batch.
4. For each item:
- create content + brief
- create draft from brief
- run draft generation job
- update item and batch status

## Status model
- Batch: `draft`, `running`, `partially_completed`, `completed`, `failed`, `canceled`
- Item: `pending`, `briefing`, `drafting`, `done`, `failed`

## Quotas and credits
- Batch generation enforces `PlanQuotaService::assertCanGenerateArticle(...)`.
- Batch generation uses existing credit wallet flow (`reserve`, `commit`, release on failure).
- Credits estimate is based on the default content credit action cost times item count.

## Queue workers
- Batch jobs are dispatched on queue `generation`.
- Run workers as usual, for example:

```bash
php artisan queue:work --queue=generation,default,deliveries --timeout=3600
```

## Routes
- `GET /app/content/batches/create`
- `POST /app/content/batches`
- `POST /app/content/batches/{batch}/start`
- `GET /app/content/batches/{batch}`
- `POST /app/content/batches/{batch}/items/{item}/retry`
- `POST /app/content/batches/{batch}/cancel`
