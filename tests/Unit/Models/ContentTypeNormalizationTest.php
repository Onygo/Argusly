<?php

use App\Models\Content;
use App\Enums\ContentType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Content model type normalization', function () {
    it('normalizes blog to article on set', function () {
        $content = new Content();
        $content->type = 'blog';

        expect($content->type)->toBe('article');
    });

    it('normalizes blog_post to article on set', function () {
        $content = new Content();
        $content->type = 'blog_post';

        expect($content->type)->toBe('article');
    });

    it('preserves valid enum values', function () {
        $content = new Content();

        $content->type = 'article';
        expect($content->type)->toBe('article');

        $content->type = 'knowledge_base';
        expect($content->type)->toBe('knowledge_base');

        $content->type = 'seo_page';
        expect($content->type)->toBe('seo_page');

        $content->type = 'press_release';
        expect($content->type)->toBe('press_release');
    });

    it('normalizes landing to seo_page', function () {
        $content = new Content();
        $content->type = 'landing';

        expect($content->type)->toBe('seo_page');
    });

    it('normalizes kb to knowledge_base', function () {
        $content = new Content();
        $content->type = 'kb';

        expect($content->type)->toBe('knowledge_base');
    });

    it('accepts ContentType enum directly', function () {
        $content = new Content();
        $content->type = ContentType::ARTICLE;

        expect($content->type)->toBe('article');
    });

    it('defaults unknown values to article', function () {
        $content = new Content();
        $content->type = 'unknown_type';

        expect($content->type)->toBe('article');
    });

    it('handles empty string gracefully', function () {
        $content = new Content();
        $content->type = '';

        expect($content->type)->toBe('article');
    });

    it('handles whitespace gracefully', function () {
        $content = new Content();
        $content->type = '  blog  ';

        expect($content->type)->toBe('article');
    });
});
