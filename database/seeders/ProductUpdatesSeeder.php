<?php

namespace Database\Seeders;

use App\Models\ProductUpdate;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ProductUpdatesSeeder extends Seeder
{
    public function run(): void
    {
        $updates = $this->getUpdates();

        foreach ($updates as $update) {
            ProductUpdate::query()->updateOrCreate(
                ['title' => $update['title']],
                [
                    'summary' => $update['summary'],
                    'body_markdown' => $update['body_markdown'],
                    'version' => $update['version'] ?? null,
                    'tags' => ProductUpdate::normalizeTags($update['tags']),
                    'is_public' => (bool) $update['is_public'],
                    'published_at' => Carbon::parse($update['published_at']),
                ]
            );
        }
    }

    /**
     * Public-safe product updates for the /product-updates page.
     *
     * Guidelines:
     * - Focus on customer value and outcomes
     * - Avoid internal terminology, technical details, and implementation specifics
     * - Use plain product language that customers understand
     * - Categories: new, improved, fixed
     *
     * @return array<int, array{title: string, summary: string, body_markdown: string, version: ?string, tags: array<int, string>, is_public: bool, published_at: string}>
     */
    private function getUpdates(): array
    {
        return [
            // February 2026
            [
                'title' => 'AI-powered content creation is here',
                'summary' => 'Create high-quality content with AI assistance, powered by flexible credit-based pricing.',
                'body_markdown' => <<<'MD'
## What's new

Argusly now helps you create better content faster with built-in AI assistance.

- **Credit-based pricing** – Pay only for what you use
- **Usage visibility** – See exactly how your credits are spent
- **Quality scoring** – Get instant feedback on content quality
- **Multiple variations** – Generate different versions to find the best fit

This is the foundation for all AI-powered features on the platform.
MD,
                'version' => null,
                'tags' => ['new'],
                'is_public' => true,
                'published_at' => '2026-02-20 10:00:00',
            ],
            [
                'title' => 'Generate featured images with AI',
                'summary' => 'Create eye-catching featured images directly from your content without leaving the platform.',
                'body_markdown' => <<<'MD'
## New feature

You can now generate featured images for your content using AI.

- **One-click generation** – Create images that match your content
- **Social-ready formats** – Images optimized for sharing
- **Easy regeneration** – Not happy? Generate a new version instantly

Create complete, publish-ready content without switching between tools.
MD,
                'version' => null,
                'tags' => ['new'],
                'is_public' => true,
                'published_at' => '2026-02-21 14:00:00',
            ],
            [
                'title' => 'Choose your preferred AI provider',
                'summary' => 'Select from multiple AI providers to match your content needs and preferences.',
                'body_markdown' => <<<'MD'
## More flexibility

Argusly now supports multiple AI providers, giving you choice in how your content is created.

- **Multiple options** – Choose the AI that works best for your content
- **Workspace defaults** – Set your preferred provider for your team
- **Brand voice matching** – Use different providers for different content types
- **Unified tracking** – See all usage in one place regardless of provider
MD,
                'version' => null,
                'tags' => ['new'],
                'is_public' => true,
                'published_at' => '2026-02-23 09:00:00',
            ],
            [
                'title' => 'New blog and legal pages',
                'summary' => 'A refreshed blog experience and centralized legal documentation.',
                'body_markdown' => <<<'MD'
## Public site updates

We've improved the public areas of Argusly.

### Blog

- Cleaner, more readable layouts
- Better content organization
- Improved mobile experience

### Legal hub

- All legal documents in one place
- Easy navigation between policies
- Clear, accessible language

### Emails

- Refreshed notification designs
- Clearer calls to action
MD,
                'version' => null,
                'tags' => ['new', 'improved'],
                'is_public' => true,
                'published_at' => '2026-02-23 16:00:00',
            ],
            [
                'title' => 'Easier subscription management',
                'summary' => 'Start your subscription faster and manage invoices more easily.',
                'body_markdown' => <<<'MD'
## Billing made simpler

We've improved the subscription experience.

- **Faster start** – Get started without completing every setup step first
- **Better invoices** – Clearer invoice details and easier downloads
- **Smoother onboarding** – New subscribers get up and running faster

Managing your Argusly subscription is now more straightforward.
MD,
                'version' => null,
                'tags' => ['improved'],
                'is_public' => true,
                'published_at' => '2026-02-24 11:00:00',
            ],
            [
                'title' => 'Plan content in series',
                'summary' => 'Organize related content into series and plan your editorial calendar more effectively.',
                'body_markdown' => <<<'MD'
## New: Content Series

Group related content into themed series for better planning and execution.

- **Series organization** – Keep related content together
- **Credit planning** – Reserve credits for upcoming content
- **Publication scheduling** – Plan when each piece goes live
- **Progress tracking** – See how your series is coming along

Perfect for content teams managing editorial calendars.
MD,
                'version' => null,
                'tags' => ['new'],
                'is_public' => true,
                'published_at' => '2026-02-27 10:00:00',
            ],
            [
                'title' => 'Built-in content analytics',
                'summary' => 'Track how your published content performs without third-party tools.',
                'body_markdown' => <<<'MD'
## See how your content performs

Argusly now includes built-in analytics for your published content.

- **Traffic insights** – See visits across your connected sites
- **Performance trends** – Track how content performs over time
- **Site verification** – Confirm ownership of your sites
- **Learnings page** – Understand what's working and what isn't

Get actionable insights without extra tools or setup.
MD,
                'version' => null,
                'tags' => ['new'],
                'is_public' => true,
                'published_at' => '2026-02-28 14:00:00',
            ],

            // March 2026
            [
                'title' => 'More reliable site connections',
                'summary' => 'Connected sites are now monitored automatically so issues are caught early.',
                'body_markdown' => <<<'MD'
## Better connection reliability

We've improved how Argusly monitors your connected sites.

- **Automatic checks** – Your connections are monitored continuously
- **Early warnings** – Get notified before problems affect publishing
- **Clearer status** – See connection health at a glance

### Brand settings

We've also centralized brand configuration:

- **One place for brand settings** – Manage your brand voice centrally
- **Consistent output** – All content follows your brand guidelines
MD,
                'version' => null,
                'tags' => ['improved'],
                'is_public' => true,
                'published_at' => '2026-03-01 10:00:00',
            ],
            [
                'title' => 'Better content organization',
                'summary' => 'Organize content with categories and tags, plus improved notifications.',
                'body_markdown' => <<<'MD'
## Organize your content

New ways to keep your content organized and stay informed.

- **Categories and tags** – Organize content your way
- **Notification center** – All updates in one place
- **Improved navigation** – Find what you need faster

These improvements make managing larger content libraries much easier.
MD,
                'version' => null,
                'tags' => ['improved'],
                'is_public' => true,
                'published_at' => '2026-03-03 09:00:00',
            ],
            [
                'title' => 'Product updates page',
                'summary' => 'A dedicated page to see all improvements and new features.',
                'body_markdown' => <<<'MD'
## Stay informed

You're looking at it! This page keeps you updated on everything new in Argusly.

- **Search** – Find specific updates quickly
- **Filter by topic** – See only what's relevant to you
- **Chronological view** – Follow our progress over time

Bookmark this page to stay up to date.
MD,
                'version' => null,
                'tags' => ['new'],
                'is_public' => true,
                'published_at' => '2026-03-03 15:00:00',
            ],
            [
                'title' => 'Platform stability improvements',
                'summary' => 'Various fixes for a more reliable experience across the platform.',
                'body_markdown' => <<<'MD'
## More reliable experience

We've made improvements across the platform for better stability.

- **Smoother payments** – Fewer interruptions during checkout
- **Better analytics** – More accurate data collection
- **Improved sign-in** – More reliable verification process

These behind-the-scenes improvements make Argusly more dependable.
MD,
                'version' => null,
                'tags' => ['fixed'],
                'is_public' => true,
                'published_at' => '2026-03-04 11:00:00',
            ],
            [
                'title' => 'SEO scoring for your content',
                'summary' => 'Get automatic SEO quality scores and suggestions for every piece of content.',
                'body_markdown' => <<<'MD'
## Write content that ranks

New SEO tools help you create content that performs better in search.

- **Quality scores** – Instant SEO assessment for every draft
- **Performance tracking** – See how content performs after publishing
- **Actionable suggestions** – Clear guidance on what to improve

Make data-driven decisions about your content strategy.
MD,
                'version' => null,
                'tags' => ['new'],
                'is_public' => true,
                'published_at' => '2026-03-06 10:00:00',
            ],
            [
                'title' => 'Compare content variations',
                'summary' => 'Generate multiple versions of your content and pick the best one.',
                'body_markdown' => <<<'MD'
## Introducing Draft Compare

Not sure which version works best? Generate multiple variations and compare them side by side.

- **Multiple versions** – Create different takes on the same content
- **Side-by-side view** – Compare variations easily
- **Mix and match** – Combine the best parts from different versions
- **Clear credit usage** – See exactly what each variation costs

Make confident content decisions with more options to choose from.
MD,
                'version' => null,
                'tags' => ['new'],
                'is_public' => true,
                'published_at' => '2026-03-06 16:00:00',
            ],
            [
                'title' => 'Multilingual content support',
                'summary' => 'Create and manage content in multiple languages from one workspace.',
                'body_markdown' => <<<'MD'
## Reach global audiences

Argusly now supports creating content in multiple languages.

- **Language settings** – Configure which languages you work in
- **Translation support** – Create versions in different languages
- **Language variants** – Manage all versions of your content together
- **Localized publishing** – Publish to language-specific destinations

Expand your reach without duplicating your workflow.
MD,
                'version' => null,
                'tags' => ['new'],
                'is_public' => true,
                'published_at' => '2026-03-08 10:00:00',
            ],
            [
                'title' => 'Developer API and documentation',
                'summary' => 'Build custom integrations with the Argusly API.',
                'body_markdown' => <<<'MD'
## For developers

We're opening Argusly to developers who want to build custom integrations.

- **API documentation** – Complete reference for all available endpoints
- **Testing tools** – Try the API before you build
- **Headless capabilities** – Use Argusly as a headless content source

Build custom workflows and integrations tailored to your needs.
MD,
                'version' => null,
                'tags' => ['new'],
                'is_public' => true,
                'published_at' => '2026-03-09 09:00:00',
            ],
            [
                'title' => 'Smarter content briefs',
                'summary' => 'AI helps you research topics and create better content briefs.',
                'body_markdown' => <<<'MD'
## Better starting points

New AI-powered research features help you create stronger content briefs.

- **Topic research** – Get background information automatically
- **Competitive insights** – See what others are writing about
- **Brief suggestions** – AI helps shape your content direction
- **Related content** – Find connections between your content pieces

Start every piece of content with better context and direction.
MD,
                'version' => null,
                'tags' => ['new'],
                'is_public' => true,
                'published_at' => '2026-03-09 16:00:00',
            ],
            [
                'title' => 'AI feedback on your drafts',
                'summary' => 'Get specific suggestions to improve your content before publishing.',
                'body_markdown' => <<<'MD'
## Improve before you publish

AI now reviews your drafts and suggests specific improvements.

- **Comprehensive review** – Analysis of tone, structure, and clarity
- **Specific suggestions** – Actionable feedback you can apply
- **Quality guidance** – Know what to fix before publishing

Get a second opinion on every draft, instantly.
MD,
                'version' => null,
                'tags' => ['new'],
                'is_public' => true,
                'published_at' => '2026-03-12 10:00:00',
            ],
            [
                'title' => 'Publish directly to WordPress',
                'summary' => 'Send content straight to your WordPress site with one click.',
                'body_markdown' => <<<'MD'
## WordPress publishing

Publish your content directly to WordPress without leaving Argusly.

- **One-click publishing** – Send content to WordPress instantly
- **Draft or publish** – Choose when content goes live
- **Categories and tags** – Your WordPress taxonomy is synced automatically

Seamlessly move content from creation to publication.
MD,
                'version' => null,
                'tags' => ['new'],
                'is_public' => true,
                'published_at' => '2026-03-15 14:00:00',
            ],
            [
                'title' => 'Clearer publishing workflow',
                'summary' => 'Better visibility into where your content is in the publication process.',
                'body_markdown' => <<<'MD'
## Know where content stands

We've improved how you track content through the publishing process.

- **Clear status** – See exactly where each piece is
- **Progress tracking** – Follow content from draft to published
- **Better reliability** – Fewer issues during publishing

More visibility and control over your content workflow.
MD,
                'version' => null,
                'tags' => ['improved'],
                'is_public' => true,
                'published_at' => '2026-03-16 10:00:00',
            ],
            [
                'title' => 'Content discovery suggestions',
                'summary' => 'AI suggests related content and topics based on what you\'re creating.',
                'body_markdown' => <<<'MD'
## Find related content

New discovery features help you connect your content.

- **Related suggestions** – See content that connects to what you're working on
- **Topic clusters** – Understand how your content relates
- **Better internal linking** – Create stronger connections between pieces

Build a more cohesive content library.
MD,
                'version' => null,
                'tags' => ['new'],
                'is_public' => true,
                'published_at' => '2026-03-17 11:00:00',
            ],
            [
                'title' => 'Faster workspace setup with AI',
                'summary' => 'AI analyzes your website to configure your workspace automatically.',
                'body_markdown' => <<<'MD'
## Get started in minutes

New AI-powered onboarding helps you set up faster.

- **Website analysis** – AI learns your brand voice from your existing site
- **Style detection** – Your content style is captured automatically
- **Topic suggestions** – Get content ideas based on your site
- **Guided setup** – Clear steps to get you publishing quickly

Go from signup to first content in minutes, not hours.
MD,
                'version' => null,
                'tags' => ['new'],
                'is_public' => true,
                'published_at' => '2026-03-18 09:00:00',
            ],
            [
                'title' => 'Smoother subscription changes',
                'summary' => 'Upgrading, downgrading, and managing your plan is now easier.',
                'body_markdown' => <<<'MD'
## Better subscription management

We've improved how plan changes work.

- **Reliable payments** – Fewer interruptions with recurring billing
- **Easy plan changes** – Upgrade or downgrade smoothly
- **Fair billing** – Credits are calculated accurately when you change plans

Subscription management is now more reliable and transparent.
MD,
                'version' => null,
                'tags' => ['improved'],
                'is_public' => true,
                'published_at' => '2026-03-18 14:00:00',
            ],
            [
                'title' => 'Image presets and more WordPress options',
                'summary' => 'Save image settings as presets and publish to more WordPress content types.',
                'body_markdown' => <<<'MD'
## Better images and publishing

New features for content presentation and WordPress publishing.

### Image presets

- **Save your settings** – Create presets for common image sizes
- **Format options** – Blog, social, and featured image formats
- **Automatic optimization** – Images are resized and optimized for you

### WordPress improvements

- **More content types** – Publish beyond just blog posts
- **Better organization** – Categories work more reliably

Create content that looks great everywhere.
MD,
                'version' => null,
                'tags' => ['new', 'improved'],
                'is_public' => true,
                'published_at' => '2026-03-18 17:00:00',
            ],
            [
                'title' => 'SEO insights and content opportunities',
                'summary' => 'AI identifies SEO improvements and content gaps you can fill.',
                'body_markdown' => <<<'MD'
## Smarter content decisions

New intelligence features help you find opportunities to improve.

### SEO insights

- **Improvement suggestions** – See exactly what to fix for better rankings
- **Keyword opportunities** – Discover terms you should be targeting
- **Competitive context** – Understand what's working for others

### Content opportunities

- **Gap analysis** – Find topics you haven't covered yet
- **Priority guidance** – Know which improvements matter most
- **Quick actions** – Apply suggestions with less effort

Make smarter content decisions backed by AI insights.
MD,
                'version' => null,
                'tags' => ['new'],
                'is_public' => true,
                'published_at' => '2026-03-18 20:00:00',
            ],
        ];
    }
}
