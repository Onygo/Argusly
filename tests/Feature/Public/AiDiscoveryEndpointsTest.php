<?php

use App\Contracts\PublicBlogSource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->bind(PublicBlogSource::class, fn () => new class implements PublicBlogSource
    {
        public function fetchPublishedPosts(): array
        {
            return [
                [
                    'id' => 'post-1',
                    'slug' => 'publishlayer-showcase-post',
                    'title' => 'PublishLayer Showcase Post',
                    'excerpt' => 'A showcase post generated inside PublishLayer.',
                    'content' => "# PublishLayer Showcase Post\n\nThis is canonical markdown.",
                    'content_format' => 'markdown',
                    'featured_image' => '',
                    'author' => 'PublishLayer Team',
                    'published_at' => '2026-02-20T09:00:00+00:00',
                    'tags' => ['showcase', 'ai'],
                    'categories' => ['Product'],
                    'locale' => 'en',
                    'meta_description' => 'Showcase post meta description.',
                    'canonical_url' => '',
                ],
                [
                    'id' => 'post-2',
                    'slug' => 'connector-fallback-post',
                    'title' => 'Connector Fallback Post',
                    'excerpt' => 'HTML source fallback excerpt.',
                    'content' => '<h2>Fallback heading</h2><p>Fallback HTML body.</p>',
                    'content_format' => 'html',
                    'featured_image' => '',
                    'author' => 'PublishLayer Team',
                    'published_at' => '2026-02-19T09:00:00+00:00',
                    'tags' => ['connector'],
                    'categories' => ['Docs'],
                    'locale' => 'en',
                    'meta_description' => 'Fallback post meta description.',
                    'canonical_url' => '',
                ],
            ];
        }
    });
});

it('renders public blog markdown routes', function () {
    $this->get('/blog/publishlayer-showcase-post.md?lang=en')
        ->assertRedirect('/en/blog/publishlayer-showcase-post.md');

    $this->get('/en/blog/publishlayer-showcase-post.md')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
        ->assertSee('# PublishLayer Showcase Post', false)
        ->assertSee('This is canonical markdown.', false);

    $this->get('/blog/connector-fallback-post.md?lang=en')
        ->assertRedirect('/en/blog/connector-fallback-post.md');

    $this->get('/en/blog/connector-fallback-post.md')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
        ->assertSee('# Connector Fallback Post', false)
        ->assertSee('Fallback HTML body.', false);
});

it('renders llms discovery endpoints with markdown links', function () {
    $this->get('/llms.txt?lang=en')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('# PublishLayer', false)
        ->assertSee('## Articles', false)
        ->assertSee('/en/blog/publishlayer-showcase-post.md', false);

    $this->get('/llms-full.txt?lang=en')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('/en/blog/publishlayer-showcase-post.md', false)
        ->assertSee('/en/blog/connector-fallback-post.md', false);
});
