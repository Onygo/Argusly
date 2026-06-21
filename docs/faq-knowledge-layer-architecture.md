# Argusly FAQ Knowledge Layer Architecture

Datum: 2026-06-19  
Doel: FAQ's centraal beheren, automatisch toewijzen aan marketingpagina's, FAQPage JSON-LD genereren, interne links voorstellen/renderen, NL/EN ondersteunen en AI Visibility optimaliseren.

## Design Goals

De FAQ Knowledge Layer moet geen losse pagina-copy zijn. Het wordt een centrale knowledge base voor buyer questions die door homepage, platform, solutions, markets, pricing, contact, security, resources en blogtemplates kan worden hergebruikt.

Belangrijkste principes:
- Een FAQ bestaat centraal, met locale-specifieke vraag en antwoord.
- Pagina's krijgen FAQ's automatisch via taxonomie, intent, funnel, route en page metadata.
- Handmatige pinning blijft mogelijk voor high-impact pagina's.
- FAQPage JSON-LD wordt gegenereerd vanuit dezelfde FAQ's die zichtbaar renderen.
- Interne links worden uit route metadata en FAQ link suggestions opgebouwd.
- NL en EN zijn first-class, niet achteraf vertaald.
- AI Visibility is meetbaar via score, entities, intent, semantic coverage en page fit.

## Conceptual Model

```
FaqItem
  hasMany FaqTranslation
  belongsToMany FaqCategory
  belongsToMany FaqPageTarget
  hasMany FaqInternalLink
  hasMany FaqPerformanceSnapshot

FaqPage
  represents a canonical public page target
  hasMany FaqPageAssignment

FaqPageAssignment
  joins FaqItem to FaqPage
  stores assignment source, position, confidence and override state
```

## Taxonomie

### Categories

Seed deze categorieen als `faq_categories`:
- `ai_visibility`
- `opportunity_intelligence`
- `agentic_marketing`
- `product_platform`
- `pricing`
- `security`
- `governance`
- `integrations`
- `markets`
- `industries`
- `content_operations`
- `competitive_intelligence`

### Search Intent

Gebruik vaste enum-waarden:
- `informational`
- `commercial_investigation`
- `comparison`
- `implementation`
- `integration`
- `security_review`
- `pricing_evaluation`
- `roi`
- `supporting_trust`

### Funnel Stage

Gebruik vaste enum-waarden:
- `awareness`
- `consideration`
- `decision`
- `post_purchase`

### Page Types

Gebruik vaste enum-waarden:
- `homepage`
- `platform`
- `solution`
- `market`
- `pricing`
- `contact`
- `security`
- `resource`
- `blog`
- `legal`

## Database Structuur

### `faq_items`

Centrale, taal-onafhankelijke FAQ entity.

Velden:
- `id` uuid
- `key` string unique, bijvoorbeeld `ai_visibility_vs_seo`
- `status` string: `draft`, `review`, `published`, `archived`
- `default_category_id` nullable uuid
- `search_intent` string
- `funnel_stage` string
- `priority` unsignedSmallInteger, 0-100
- `schema_enabled` boolean
- `ai_visibility_score` decimal 5,2
- `entities` json nullable
- `buyer_objections` json nullable
- `evaluation_topics` json nullable
- `implementation_topics` json nullable
- `roi_topics` json nullable
- `created_by` nullable foreignId users
- `updated_by` nullable foreignId users
- timestamps

### `faq_translations`

Locale-specifieke question/answer.

Velden:
- `id` uuid
- `faq_item_id` uuid
- `locale` string length 5
- `question` string
- `answer` text
- `summary` nullable string
- `answer_html` nullable longText
- `meta_title` nullable string
- `is_published` boolean
- `translation_quality_score` decimal 5,2 nullable
- timestamps
- unique: `faq_item_id`, `locale`

### `faq_categories`

Taxonomie.

Velden:
- `id` uuid
- `key` string unique
- `label` string
- `description` text nullable
- `sort_order` integer
- `is_active` boolean
- timestamps

### `faq_category_faq_item`

Many-to-many tussen FAQ en categorie.

Velden:
- `faq_item_id` uuid
- `faq_category_id` uuid
- unique pair

### `faq_pages`

Canonical public targets. Dit voorkomt dat FAQ assignments direct vastzitten aan Blade files of route strings.

Velden:
- `id` uuid
- `key` string unique, bijvoorbeeld `public.solutions.ai-visibility`
- `route_name` string nullable
- `page_type` string
- `locale_mode` string default `localized`
- `canonical_path_en` nullable string
- `canonical_path_nl` nullable string
- `title_en` nullable string
- `title_nl` nullable string
- `topic_key` nullable string, bijvoorbeeld `ai-visibility` of market key
- `primary_category_id` nullable uuid
- `secondary_categories` json nullable
- `target_search_intents` json nullable
- `target_funnel_stages` json nullable
- `schema_enabled` boolean
- `max_faq_items` unsignedTinyInteger default 8
- `is_active` boolean
- timestamps

### `faq_page_assignments`

Stores automatic and manual assignments.

Velden:
- `id` uuid
- `faq_page_id` uuid
- `faq_item_id` uuid
- `locale` nullable string length 5
- `position` unsignedSmallInteger default 0
- `assignment_type` string: `manual`, `auto`, `pinned`, `excluded`
- `confidence_score` decimal 5,2 nullable
- `reason` text nullable
- `is_pinned` boolean
- `is_excluded` boolean
- `starts_at` timestamp nullable
- `ends_at` timestamp nullable
- timestamps
- unique: `faq_page_id`, `faq_item_id`, `locale`

### `faq_internal_links`

Recommended links per FAQ, optionally localized.

Velden:
- `id` uuid
- `faq_item_id` uuid
- `target_page_id` nullable uuid
- `locale` nullable string length 5
- `route_name` nullable string
- `url` nullable string
- `anchor_text` string
- `context` nullable string
- `priority` unsignedSmallInteger default 50
- `is_active` boolean
- timestamps

### `faq_performance_snapshots`

AI Visibility and SEO learning loop.

Velden:
- `id` uuid
- `faq_item_id` uuid
- `faq_page_id` nullable uuid
- `locale` string length 5
- `ai_visibility_score` decimal 5,2 nullable
- `impressions` unsignedInteger default 0
- `clicks` unsignedInteger default 0
- `faq_engagements` unsignedInteger default 0
- `cta_clicks` unsignedInteger default 0
- `schema_valid` boolean nullable
- `measured_at` timestamp
- timestamps

## Migrations

### Migration 1: FAQ core tables

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('faq_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('status')->default('draft')->index();
            $table->uuid('default_category_id')->nullable();
            $table->string('search_intent', 80)->index();
            $table->string('funnel_stage', 40)->index();
            $table->unsignedSmallInteger('priority')->default(50)->index();
            $table->boolean('schema_enabled')->default(true);
            $table->decimal('ai_visibility_score', 5, 2)->default(0);
            $table->json('entities')->nullable();
            $table->json('buyer_objections')->nullable();
            $table->json('evaluation_topics')->nullable();
            $table->json('implementation_topics')->nullable();
            $table->json('roi_topics')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('default_category_id')->references('id')->on('faq_categories')->nullOnDelete();
        });

        Schema::create('faq_translations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('faq_item_id');
            $table->string('locale', 5);
            $table->string('question');
            $table->text('answer');
            $table->string('summary')->nullable();
            $table->longText('answer_html')->nullable();
            $table->string('meta_title')->nullable();
            $table->boolean('is_published')->default(false);
            $table->decimal('translation_quality_score', 5, 2)->nullable();
            $table->timestamps();

            $table->foreign('faq_item_id')->references('id')->on('faq_items')->cascadeOnDelete();
            $table->unique(['faq_item_id', 'locale']);
            $table->index(['locale', 'is_published']);
        });

        Schema::create('faq_category_faq_item', function (Blueprint $table): void {
            $table->uuid('faq_item_id');
            $table->uuid('faq_category_id');

            $table->foreign('faq_item_id')->references('id')->on('faq_items')->cascadeOnDelete();
            $table->foreign('faq_category_id')->references('id')->on('faq_categories')->cascadeOnDelete();
            $table->primary(['faq_item_id', 'faq_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_category_faq_item');
        Schema::dropIfExists('faq_translations');
        Schema::dropIfExists('faq_items');
        Schema::dropIfExists('faq_categories');
    }
};
```

### Migration 2: Page targeting and linking

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_pages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('route_name')->nullable()->index();
            $table->string('page_type', 40)->index();
            $table->string('locale_mode', 30)->default('localized');
            $table->string('canonical_path_en')->nullable();
            $table->string('canonical_path_nl')->nullable();
            $table->string('title_en')->nullable();
            $table->string('title_nl')->nullable();
            $table->string('topic_key')->nullable()->index();
            $table->uuid('primary_category_id')->nullable();
            $table->json('secondary_categories')->nullable();
            $table->json('target_search_intents')->nullable();
            $table->json('target_funnel_stages')->nullable();
            $table->boolean('schema_enabled')->default(true);
            $table->unsignedTinyInteger('max_faq_items')->default(8);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->foreign('primary_category_id')->references('id')->on('faq_categories')->nullOnDelete();
        });

        Schema::create('faq_page_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('faq_page_id');
            $table->uuid('faq_item_id');
            $table->string('locale', 5)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('assignment_type', 30)->default('auto')->index();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->text('reason')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_excluded')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->foreign('faq_page_id')->references('id')->on('faq_pages')->cascadeOnDelete();
            $table->foreign('faq_item_id')->references('id')->on('faq_items')->cascadeOnDelete();
            $table->unique(['faq_page_id', 'faq_item_id', 'locale']);
            $table->index(['faq_page_id', 'locale', 'position']);
        });

        Schema::create('faq_internal_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('faq_item_id');
            $table->uuid('target_page_id')->nullable();
            $table->string('locale', 5)->nullable();
            $table->string('route_name')->nullable();
            $table->string('url')->nullable();
            $table->string('anchor_text');
            $table->string('context')->nullable();
            $table->unsignedSmallInteger('priority')->default(50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('faq_item_id')->references('id')->on('faq_items')->cascadeOnDelete();
            $table->foreign('target_page_id')->references('id')->on('faq_pages')->nullOnDelete();
            $table->index(['faq_item_id', 'locale', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_internal_links');
        Schema::dropIfExists('faq_page_assignments');
        Schema::dropIfExists('faq_pages');
    }
};
```

### Migration 3: Performance snapshots

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_performance_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('faq_item_id');
            $table->uuid('faq_page_id')->nullable();
            $table->string('locale', 5);
            $table->decimal('ai_visibility_score', 5, 2)->nullable();
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('faq_engagements')->default(0);
            $table->unsignedInteger('cta_clicks')->default(0);
            $table->boolean('schema_valid')->nullable();
            $table->timestamp('measured_at')->index();
            $table->timestamps();

            $table->foreign('faq_item_id')->references('id')->on('faq_items')->cascadeOnDelete();
            $table->foreign('faq_page_id')->references('id')->on('faq_pages')->nullOnDelete();
            $table->index(['faq_item_id', 'locale', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_performance_snapshots');
    }
};
```

## Models

### `App\Models\FaqItem`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FaqItem extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_REVIEW = 'review';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'key',
        'status',
        'default_category_id',
        'search_intent',
        'funnel_stage',
        'priority',
        'schema_enabled',
        'ai_visibility_score',
        'entities',
        'buyer_objections',
        'evaluation_topics',
        'implementation_topics',
        'roi_topics',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'priority' => 'integer',
        'schema_enabled' => 'boolean',
        'ai_visibility_score' => 'decimal:2',
        'entities' => 'array',
        'buyer_objections' => 'array',
        'evaluation_topics' => 'array',
        'implementation_topics' => 'array',
        'roi_topics' => 'array',
    ];

    public function defaultCategory(): BelongsTo
    {
        return $this->belongsTo(FaqCategory::class, 'default_category_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(FaqCategory::class, 'faq_category_faq_item');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(FaqTranslation::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(FaqPageAssignment::class);
    }

    public function internalLinks(): HasMany
    {
        return $this->hasMany(FaqInternalLink::class);
    }

    public function translation(string $locale): ?FaqTranslation
    {
        if ($this->relationLoaded('translations')) {
            return $this->translations->first(fn (FaqTranslation $translation): bool => $translation->locale === strtolower($locale));
        }

        return $this->translations()->where('locale', strtolower($locale))->first();
    }
}
```

### `App\Models\FaqTranslation`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaqTranslation extends Model
{
    use HasUuids;

    protected $fillable = [
        'faq_item_id',
        'locale',
        'question',
        'answer',
        'summary',
        'answer_html',
        'meta_title',
        'is_published',
        'translation_quality_score',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'translation_quality_score' => 'decimal:2',
    ];

    public function faqItem(): BelongsTo
    {
        return $this->belongsTo(FaqItem::class);
    }
}
```

### Supporting models

Maak dezelfde eenvoudige Eloquent models:
- `FaqCategory`
- `FaqPage`
- `FaqPageAssignment`
- `FaqInternalLink`
- `FaqPerformanceSnapshot`

Belangrijke relaties:
- `FaqPage::assignments(): HasMany`
- `FaqPage::primaryCategory(): BelongsTo`
- `FaqPageAssignment::faqPage(): BelongsTo`
- `FaqPageAssignment::faqItem(): BelongsTo`
- `FaqInternalLink::faqItem(): BelongsTo`
- `FaqInternalLink::targetPage(): BelongsTo`

## Repositories

### `App\Repositories\FaqKnowledgeRepository`

Verantwoordelijk voor query's, niet voor business rules.

```php
<?php

namespace App\Repositories;

use App\Models\FaqItem;
use App\Models\FaqPage;
use Illuminate\Support\Collection;

class FaqKnowledgeRepository
{
    public function pageByKey(string $key): ?FaqPage
    {
        return FaqPage::query()
            ->where('key', $key)
            ->where('is_active', true)
            ->first();
    }

    public function assignedForPage(FaqPage $page, string $locale): Collection
    {
        return $page->assignments()
            ->with(['faqItem.translations', 'faqItem.categories', 'faqItem.internalLinks.targetPage'])
            ->where(fn ($query) => $query->whereNull('locale')->orWhere('locale', strtolower($locale)))
            ->where('is_excluded', false)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderByDesc('is_pinned')
            ->orderBy('position')
            ->get()
            ->pluck('faqItem')
            ->filter(fn (?FaqItem $item): bool => $item instanceof FaqItem && $item->status === FaqItem::STATUS_PUBLISHED)
            ->values();
    }

    public function candidatesForPage(FaqPage $page, string $locale, int $limit = 12): Collection
    {
        return FaqItem::query()
            ->with(['translations', 'categories', 'internalLinks.targetPage'])
            ->where('status', FaqItem::STATUS_PUBLISHED)
            ->whereHas('translations', fn ($query) => $query
                ->where('locale', strtolower($locale))
                ->where('is_published', true)
            )
            ->when($page->primary_category_id, fn ($query) => $query
                ->whereHas('categories', fn ($categoryQuery) => $categoryQuery->where('faq_categories.id', $page->primary_category_id))
            )
            ->orderByDesc('priority')
            ->orderByDesc('ai_visibility_score')
            ->limit($limit)
            ->get();
    }
}
```

## Services

### `FaqAssignmentService`

Doel: bepalen welke FAQ's op welke pagina verschijnen.

Scoring:
- Category match: 35 punten
- Search intent match: 20 punten
- Funnel stage match: 15 punten
- Entity/topic match: 15 punten
- Priority: 0-10 punten
- AI visibility score: 0-5 punten
- Penalize duplicate question on sibling page: -20 punten

```php
<?php

namespace App\Services\Faq;

use App\Models\FaqItem;
use App\Models\FaqPage;
use App\Repositories\FaqKnowledgeRepository;
use Illuminate\Support\Collection;

class FaqAssignmentService
{
    public function __construct(
        private readonly FaqKnowledgeRepository $repository,
    ) {}

    public function resolveForPageKey(string $pageKey, string $locale): Collection
    {
        $page = $this->repository->pageByKey($pageKey);

        if (! $page) {
            return collect();
        }

        $manual = $this->repository->assignedForPage($page, $locale);
        $remaining = max(0, (int) $page->max_faq_items - $manual->count());

        if ($remaining === 0) {
            return $manual->take((int) $page->max_faq_items)->values();
        }

        $manualIds = $manual->pluck('id')->all();

        $auto = $this->repository
            ->candidatesForPage($page, $locale, 30)
            ->reject(fn (FaqItem $item): bool => in_array($item->id, $manualIds, true))
            ->sortByDesc(fn (FaqItem $item): float => $this->score($item, $page))
            ->take($remaining);

        return $manual->merge($auto)->values();
    }

    public function score(FaqItem $item, FaqPage $page): float
    {
        $score = 0.0;

        if ($page->primary_category_id && $item->categories->contains('id', $page->primary_category_id)) {
            $score += 35;
        }

        if (in_array($item->search_intent, (array) $page->target_search_intents, true)) {
            $score += 20;
        }

        if (in_array($item->funnel_stage, (array) $page->target_funnel_stages, true)) {
            $score += 15;
        }

        if ($page->topic_key && in_array($page->topic_key, (array) $item->entities, true)) {
            $score += 15;
        }

        $score += min(10, (int) $item->priority / 10);
        $score += min(5, (float) $item->ai_visibility_score / 20);

        return $score;
    }
}
```

### `FaqSchemaService`

Doel: FAQPage JSON-LD genereren op basis van zichtbaar gerenderde FAQ's.

```php
<?php

namespace App\Services\Faq;

use App\Models\FaqItem;
use Illuminate\Support\Collection;

class FaqSchemaService
{
    public function forItems(Collection $items, string $locale): ?array
    {
        $mainEntity = $items
            ->filter(fn (FaqItem $item): bool => (bool) $item->schema_enabled)
            ->map(function (FaqItem $item) use ($locale): ?array {
                $translation = $item->translation($locale);

                if (! $translation || ! $translation->is_published) {
                    return null;
                }

                $question = $this->cleanText($translation->question);
                $answer = $this->cleanText($translation->answer);

                if ($question === '' || $answer === '') {
                    return null;
                }

                return [
                    '@type' => 'Question',
                    'name' => $question,
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $answer,
                    ],
                ];
            })
            ->filter()
            ->values()
            ->all();

        if ($mainEntity === []) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $mainEntity,
        ];
    }

    private function cleanText(?string $value): string
    {
        $plain = strip_tags((string) $value);
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? '';

        return trim($plain);
    }
}
```

### `FaqInternalLinkService`

Doel: links resolveren naar localized public URLs.

Taken:
- Kies actieve `FaqInternalLink` records voor locale.
- Resolve `target_page_id` naar `FaqPage`.
- Gebruik `LocalizedMarketingUrl::route()` wanneer `route_name` aanwezig is.
- Fallback naar expliciete `url`.
- Deduplicate links per FAQ.
- Beperk tot 2-3 links per antwoord.

### `FaqAiVisibilityScoringService`

Doel: score berekenen en bijwerken.

Scorefactoren:
- Direct antwoord in eerste zin.
- Bevat Argusly of kernentity.
- Bevat category/entity terms.
- Bevat buyer objection of evaluation criterion.
- Antwoordlengte 50-120 woorden.
- Heeft interne links.
- Schema enabled.
- Heeft NL en EN translation.

Output: `ai_visibility_score` 0-100.

### `FaqRenderer`

Facade/service voor controllers en Blade.

```php
<?php

namespace App\Services\Faq;

class FaqRenderer
{
    public function __construct(
        private readonly FaqAssignmentService $assignments,
        private readonly FaqSchemaService $schema,
    ) {}

    public function viewData(string $pageKey, string $locale): array
    {
        $items = $this->assignments->resolveForPageKey($pageKey, $locale);

        return [
            'faqItems' => $items,
            'faqSchema' => $this->schema->forItems($items, $locale),
        ];
    }
}
```

## API Ontwerp

### Admin API

Prefix: `/admin/faq`

Endpoints:
- `GET /admin/faq/items`
- `POST /admin/faq/items`
- `GET /admin/faq/items/{faqItem}`
- `PUT /admin/faq/items/{faqItem}`
- `DELETE /admin/faq/items/{faqItem}`
- `POST /admin/faq/items/{faqItem}/translations`
- `PUT /admin/faq/items/{faqItem}/translations/{locale}`
- `POST /admin/faq/items/{faqItem}/links`
- `POST /admin/faq/items/{faqItem}/score`
- `GET /admin/faq/pages`
- `GET /admin/faq/pages/{faqPage}/assignments`
- `POST /admin/faq/pages/{faqPage}/assignments`
- `POST /admin/faq/pages/{faqPage}/auto-assign`
- `POST /admin/faq/pages/{faqPage}/preview`

### Public/Internal API

Prefix: `/api/public/faq`

Endpoints:
- `GET /api/public/faq/page/{pageKey}?locale=en`
- `GET /api/public/faq/schema/{pageKey}?locale=en`

Response:

```json
{
  "page_key": "public.solutions.ai-visibility",
  "locale": "en",
  "items": [
    {
      "id": "uuid",
      "key": "ai_visibility_vs_seo",
      "question": "Is AI visibility the same as SEO?",
      "answer": "No. AI visibility extends SEO...",
      "category": "AI Visibility",
      "search_intent": "comparison",
      "funnel_stage": "consideration",
      "priority": 90,
      "internal_links": [
        {
          "anchor_text": "AI Visibility Scan",
          "url": "https://argusly.com/en/contact#contact-form"
        }
      ],
      "schema_enabled": true,
      "ai_visibility_score": 88.5
    }
  ],
  "schema": {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    "mainEntity": []
  }
}
```

## Admin Interface

### Navigation

Admin menu:
- FAQ Knowledge
  - Items
  - Categories
  - Page Assignments
  - AI Visibility Scores
  - Performance

### FAQ Item editor

Fields:
- Key
- Status
- Categories
- Search intent
- Funnel stage
- Priority
- Schema enabled
- AI visibility score
- Entities
- Buyer objections
- Evaluation topics
- Implementation topics
- ROI topics
- EN question/answer
- NL question/answer
- Internal links
- Related pages

UX:
- Side-by-side EN/NL editor.
- Score checklist for AI Visibility readiness.
- "Preview on page" selector.
- "Generate schema preview" panel.
- Duplicate question warning.
- Missing translation warning.
- Buyer question quality checklist.

### Page assignment screen

For each `FaqPage`:
- Page metadata: route, type, category, intent, funnel.
- Currently assigned FAQ's.
- Pinned FAQ's.
- Excluded FAQ's.
- Auto candidates with confidence score.
- Drag-and-drop order.
- Locale preview.
- JSON-LD validation preview.

## Public Rendering

### Blade component

`resources/views/components/public/faq-section.blade.php`

```blade
@props([
    'items' => collect(),
    'schema' => null,
    'title' => __('public.faq.title'),
    'eyebrow' => 'FAQ',
])

@if ($items->isNotEmpty())
    @if (! empty($schema))
        <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif

    <section id="faq" class="bg-white">
        <div class="mx-auto max-w-4xl px-4 py-16 sm:px-6 md:py-20">
            <div class="text-center">
                <p class="pl-public-eyebrow">{{ $eyebrow }}</p>
                <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ $title }}</h2>
            </div>

            <div class="mt-10 space-y-4">
                @foreach ($items as $item)
                    @php($translation = $item->translation(app()->getLocale()))
                    @continue(! $translation)
                    <article class="pl-public-card-compact pl-public-canvas p-5">
                        <h3 class="pl-public-heading pl-public-heading-card">{{ $translation->question }}</h3>
                        <div class="mt-2 text-sm leading-7 text-textSecondary">
                            {!! $translation->answer_html ?: e($translation->answer) !!}
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </section>
@endif
```

### Controller integration

For static controllers:

```php
$faq = app(\App\Services\Faq\FaqRenderer::class)->viewData($routeName, $locale);

return view('public.solution', [
    // existing data...
    'faqItems' => $faq['faqItems'],
    'faqSchema' => $faq['faqSchema'],
]);
```

For Blade:

```blade
<x-public.faq-section
    :items="$faqItems ?? collect()"
    :schema="$faqSchema ?? null"
    :title="__('public.faq.common_title')"
/>
```

## Schema Generation

Rules:
- Only include FAQ items visible on the page.
- Only include translations published for the current locale.
- Strip HTML from schema answer text.
- Keep schema item order identical to visible order.
- Do not include disabled or archived FAQ items.
- Do not include duplicate questions on the same page.

Validation tests:
- Page renders visible FAQ.
- JSON-LD includes same question count.
- JSON-LD `mainEntity[*].name` matches visible headings.
- Empty FAQ does not render FAQPage schema.

## Auto Assignment Logic

### Page metadata examples

Homepage:
```php
[
    'key' => 'landing',
    'page_type' => 'homepage',
    'primary_category' => 'agentic_marketing',
    'secondary_categories' => ['ai_visibility', 'opportunity_intelligence', 'content_operations'],
    'target_search_intents' => ['informational', 'commercial_investigation'],
    'target_funnel_stages' => ['awareness', 'consideration'],
]
```

AI Visibility solution:
```php
[
    'key' => 'public.solutions.ai-visibility',
    'page_type' => 'solution',
    'primary_category' => 'ai_visibility',
    'secondary_categories' => ['competitive_intelligence', 'content_operations'],
    'target_search_intents' => ['informational', 'comparison', 'implementation'],
    'target_funnel_stages' => ['awareness', 'consideration', 'decision'],
]
```

Pricing:
```php
[
    'key' => 'pricing',
    'page_type' => 'pricing',
    'primary_category' => 'pricing',
    'secondary_categories' => ['product_platform', 'governance', 'integrations'],
    'target_search_intents' => ['pricing_evaluation', 'roi', 'commercial_investigation'],
    'target_funnel_stages' => ['consideration', 'decision'],
]
```

## Seeders

### `FaqCategorySeeder`

Seed the categories listed in this document.

### `FaqPageSeeder`

Seed canonical targets:
- `landing`
- `public.product.overview`
- `public.product.platform`
- `public.solutions.ai-visibility`
- `public.solutions.opportunity-intelligence`
- `public.solutions.competitive-intelligence`
- `public.solutions.marketing-without-large-team`
- `public.agentic-marketing`
- `pricing`
- `public.company.contact`
- `public.legal.security`
- all `public.markets.*`
- `public.marketing-pages.show`
- `public.blog.show`

### `FaqStarterSeeder`

Seed 80-120 initial FAQ items:
- 10 AI Visibility
- 10 Opportunity Intelligence
- 10 Agentic Marketing
- 10 Product Platform
- 8 Pricing
- 8 Security
- 8 Governance
- 8 Integrations
- 16 Markets/Industries
- 8 Content Operations
- 8 Competitive Intelligence

## Routes

```php
Route::prefix('admin/faq')->middleware(['auth', 'admin'])->name('admin.faq.')->group(function () {
    Route::resource('items', AdminFaqItemController::class);
    Route::resource('categories', AdminFaqCategoryController::class)->except(['show']);
    Route::get('pages', [AdminFaqPageController::class, 'index'])->name('pages.index');
    Route::get('pages/{faqPage}', [AdminFaqPageController::class, 'show'])->name('pages.show');
    Route::post('pages/{faqPage}/assignments', [AdminFaqPageAssignmentController::class, 'store'])->name('pages.assignments.store');
    Route::post('pages/{faqPage}/auto-assign', [AdminFaqPageAssignmentController::class, 'autoAssign'])->name('pages.auto-assign');
});
```

## Authorization

Policies:
- `FaqItemPolicy`
- `FaqCategoryPolicy`
- `FaqPagePolicy`

Permissions:
- Admins can create/edit/publish.
- Editors can draft/update translations.
- Reviewers can approve.
- Support can view.

## Testing Strategy

Feature tests:
- Admin can create FAQ with EN/NL translations.
- Published FAQ can be assigned to page.
- Auto assignment returns relevant FAQ's.
- Public page renders FAQ and JSON-LD.
- FAQPage JSON-LD excludes unpublished translation.
- Internal links resolve localized URLs.

Unit tests:
- `FaqAssignmentServiceTest`
- `FaqSchemaServiceTest`
- `FaqInternalLinkServiceTest`
- `FaqAiVisibilityScoringServiceTest`

Regression tests:
- Pricing renders FAQPage JSON-LD when visible FAQ's exist.
- Solution pages render FAQ when assigned.
- Market pages use market-specific FAQ's.

## Rollout Plan

### Phase 1: Core

- Add migrations and models.
- Seed categories and page targets.
- Build repository and schema service.
- Build Blade component.
- Integrate Pricing first because visible FAQ's already exist.

### Phase 2: High-impact pages

- Add assignment support to:
  - AI Visibility
  - Opportunity Intelligence
  - Product Platform
  - Pricing
  - Contact
  - Security

### Phase 3: Market automation

- Seed market page targets from `config('argusly_markets.pages')`.
- Auto-assign vertical FAQ's by market key and category.
- Add market-specific internal links.

### Phase 4: Admin UX

- Build FAQ item editor.
- Build page assignment dashboard.
- Add preview and schema validation.

### Phase 5: AI Visibility loop

- Add scoring service.
- Add performance snapshots.
- Feed Search Console, internal analytics and LLM tracking data into FAQ performance.
- Recommend FAQ refreshes when score or engagement drops.

## Recommended File Map

Migrations:
- `database/migrations/xxxx_xx_xx_000001_create_faq_core_tables.php`
- `database/migrations/xxxx_xx_xx_000002_create_faq_page_targeting_tables.php`
- `database/migrations/xxxx_xx_xx_000003_create_faq_performance_snapshots_table.php`

Models:
- `app/Models/FaqItem.php`
- `app/Models/FaqTranslation.php`
- `app/Models/FaqCategory.php`
- `app/Models/FaqPage.php`
- `app/Models/FaqPageAssignment.php`
- `app/Models/FaqInternalLink.php`
- `app/Models/FaqPerformanceSnapshot.php`

Repositories:
- `app/Repositories/FaqKnowledgeRepository.php`

Services:
- `app/Services/Faq/FaqAssignmentService.php`
- `app/Services/Faq/FaqSchemaService.php`
- `app/Services/Faq/FaqInternalLinkService.php`
- `app/Services/Faq/FaqAiVisibilityScoringService.php`
- `app/Services/Faq/FaqRenderer.php`

Admin controllers:
- `app/Http/Controllers/Admin/AdminFaqItemController.php`
- `app/Http/Controllers/Admin/AdminFaqCategoryController.php`
- `app/Http/Controllers/Admin/AdminFaqPageController.php`
- `app/Http/Controllers/Admin/AdminFaqPageAssignmentController.php`

Resources:
- `app/Http/Resources/Admin/FaqItemResource.php`
- `app/Http/Resources/Public/FaqItemResource.php`

Blade:
- `resources/views/admin/faq/items/index.blade.php`
- `resources/views/admin/faq/items/edit.blade.php`
- `resources/views/admin/faq/pages/index.blade.php`
- `resources/views/admin/faq/pages/show.blade.php`
- `resources/views/components/public/faq-section.blade.php`

Seeders:
- `database/seeders/FaqCategorySeeder.php`
- `database/seeders/FaqPageSeeder.php`
- `database/seeders/FaqStarterSeeder.php`

## Final Recommendation

Start with a central FAQ Knowledge Layer instead of patching FAQ arrays into each page. Keep existing `StructuredAnswerBlock` behavior for article/content answer blocks, but introduce `FaqItem` as the reusable marketing FAQ entity. That gives Argusly one controlled system for page FAQ's, internal links, schema, localization and AI Visibility scoring.
