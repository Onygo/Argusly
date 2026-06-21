# Public Frontend Localization Audit

Use this checklist when Dutch public pages show English copy inside cards, feature blocks, metrics, CTAs, or seeded marketing content.

## Scope

Public frontend copy can come from several places:

- `lang/nl/public.php` for shared navigation, labels, pricing, footer, and common sections
- `app/Http/Controllers/Public*Controller.php` for controller-owned marketing pages
- `database/seeders/MarketingPageSeeder.php` for database-backed marketing pages
- `resources/views/public/**` for hardcoded Blade fallback copy

The existing `App\Agents\Localization\LocalizationAgent` is for content and drafts. It checks localized content records, draft lineage, and translation freshness. It does not cover controller arrays, Blade marketing blocks, or seeded public-page copy.

## Review Rule

For every `/nl/**` public page:

1. Search the route/controller/view source for English marketing fragments.
2. Check cards, metric labels, bullet lists, accordions, CTA panels, and related-path blocks first.
3. Keep accepted product terms only when they are intentional brand/category language, such as `AI Visibility`, `Agentic Marketing`, `SERP`, `LLM`, `pipeline`, or `workflow`.
4. Translate explanatory labels and claims into Dutch, especially phrases such as `Brand mentions`, `Citation share`, `Content readiness`, `Generated summaries`, `Output`, `Compare`, and `Accuracy`.
5. Avoid mixing English grammar into Dutch sentences unless the term is a deliberate product concept.

## Useful Commands

```bash
rg -n "Brand|Citation|Accuracy|Generated|Compare|Output|Competitor|Prompt|Answer|Structured|Content readiness|answer share|content gaps|topic ownership|buyer research" app/Http/Controllers resources/views/public lang/nl database/seeders
```

```bash
php -l app/Http/Controllers/PublicSolutionController.php
php artisan test tests/Feature/Public
```
