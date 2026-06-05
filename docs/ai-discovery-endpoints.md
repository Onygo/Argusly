# AI Discovery Endpoints

Argusly now exposes public AI discovery entry points on the marketing site:

- `/llms.txt`
- `/llms-full.txt`

The discovery output is plain text and references public Markdown URLs, currently based on public blog posts:

- `/blog/{slug}.md`

Notes:
- `llms.txt` returns a compact curated list.
- `llms-full.txt` returns a larger index.
- Both endpoints are locale-aware through the existing public locale middleware.
- Blog Markdown prefers source Markdown when available and falls back to deterministic HTML-to-Markdown conversion for legacy HTML-only posts.
