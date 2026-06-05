<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Support\KeywordSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createKeywordSanitizationTestContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Keyword Sanitization Test Org',
        'slug' => 'keyword-sanitization-test-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Test BV',
        'billing_address_line1' => 'Test Street 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Keyword Sanitization Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Keyword Sanitization Site',
        'site_url' => 'https://keyword-test.example.com',
        'base_url' => 'https://keyword-test.example.com',
        'allowed_domains' => ['keyword-test.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => 'Test User',
        'email' => 'keyword-test-' . Str::random(8) . '@example.com',
        'password' => bcrypt('password'),
    ]);

    return [$organization, $workspace, $site, $user];
}

describe('Content model primary_keyword sanitization', function () {
    it('sanitizes overly long primary_keyword on create', function () {
        [$organization, $workspace, $site, $user] = createKeywordSanitizationTestContext();

        // Create a keyword that exceeds 255 characters but stays under word count limit
        $longWord = str_repeat('x', 60);
        $longKeyword = implode(' ', array_fill(0, 5, $longWord)); // 5 words, ~304 chars

        $content = Content::query()->create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Article',
            'primary_keyword' => $longKeyword,
            'type' => 'article',
            'status' => 'draft',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        expect(mb_strlen($content->primary_keyword))->toBeLessThanOrEqual(KeywordSanitizer::MAX_LENGTH);
    });

    it('sanitizes paragraph-like primary_keyword to derived keyword', function () {
        [$organization, $workspace, $site, $user] = createKeywordSanitizationTestContext();

        $paragraph = 'This is a paragraph that someone accidentally pasted as a keyword. It should be rejected and a fallback used instead.';

        $content = Content::query()->create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Article Title',
            'primary_keyword' => $paragraph,
            'type' => 'article',
            'status' => 'draft',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        // Should derive a keyword or set to null, not store the full paragraph
        expect($content->primary_keyword)->not->toBe($paragraph);
        // The derived keyword should be shorter than the paragraph
        if ($content->primary_keyword !== null) {
            expect(mb_strlen($content->primary_keyword))->toBeLessThan(mb_strlen($paragraph));
        }
    });

    it('sanitizes JSON fragment primary_keyword', function () {
        [$organization, $workspace, $site, $user] = createKeywordSanitizationTestContext();

        $jsonFragment = '{"title": "Test", "primary_keyword": "seo keyword"}';

        $content = Content::query()->create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Article',
            'primary_keyword' => $jsonFragment,
            'type' => 'article',
            'status' => 'draft',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        // Should not contain the full JSON
        expect($content->primary_keyword)->not->toBe($jsonFragment);
    });

    it('keeps valid short keywords unchanged', function () {
        [$organization, $workspace, $site, $user] = createKeywordSanitizationTestContext();

        $validKeyword = 'content marketing strategy';

        $content = Content::query()->create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Article',
            'primary_keyword' => $validKeyword,
            'type' => 'article',
            'status' => 'draft',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        expect($content->primary_keyword)->toBe($validKeyword);
    });

    it('allows null primary_keyword', function () {
        [$organization, $workspace, $site, $user] = createKeywordSanitizationTestContext();

        $content = Content::query()->create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Article',
            'primary_keyword' => null,
            'type' => 'article',
            'status' => 'draft',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        expect($content->primary_keyword)->toBeNull();
    });

    it('sanitizes primary_keyword on update', function () {
        [$organization, $workspace, $site, $user] = createKeywordSanitizationTestContext();

        $content = Content::query()->create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Article',
            'primary_keyword' => 'valid keyword',
            'type' => 'article',
            'status' => 'draft',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        // Create a keyword that exceeds 255 characters but stays under word count limit
        $longWord = str_repeat('x', 60);
        $longKeyword = implode(' ', array_fill(0, 5, $longWord));

        $content->primary_keyword = $longKeyword;
        $content->save();

        expect(mb_strlen($content->primary_keyword))->toBeLessThanOrEqual(KeywordSanitizer::MAX_LENGTH);
    });
});

describe('Brief model primary_keyword sanitization', function () {
    it('sanitizes overly long primary_keyword', function () {
        [$organization, $workspace, $site, $user] = createKeywordSanitizationTestContext();

        $longWord = str_repeat('x', 60);
        $longKeyword = implode(' ', array_fill(0, 5, $longWord));

        $brief = Brief::query()->create([
            'client_site_id' => $site->id,
            'created_by_user_id' => $user->id,
            'title' => 'Test Brief',
            'primary_keyword' => $longKeyword,
            'status' => 'pending',
            'progress' => 0,
        ]);

        expect(mb_strlen($brief->primary_keyword))->toBeLessThanOrEqual(KeywordSanitizer::MAX_LENGTH);
    });

    it('rejects prompt-like text as keyword', function () {
        [$organization, $workspace, $site, $user] = createKeywordSanitizationTestContext();

        $promptText = 'You are an SEO expert. Please write a keyword.';

        $brief = Brief::query()->create([
            'client_site_id' => $site->id,
            'created_by_user_id' => $user->id,
            'title' => 'Marketing Article',
            'primary_keyword' => $promptText,
            'status' => 'pending',
            'progress' => 0,
        ]);

        expect($brief->primary_keyword)->not->toBe($promptText);
    });
});

describe('prevents SQL truncation errors', function () {
    it('never stores values exceeding database column limit', function () {
        [$organization, $workspace, $site, $user] = createKeywordSanitizationTestContext();

        // This would cause "Data too long" error without sanitization
        $massiveKeyword = str_repeat('a', 1000);

        // Should not throw an exception
        $content = Content::query()->create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Article',
            'primary_keyword' => $massiveKeyword,
            'type' => 'article',
            'status' => 'draft',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        expect(mb_strlen($content->primary_keyword ?? ''))->toBeLessThanOrEqual(255);
    });

    it('handles malformed automation input gracefully', function () {
        [$organization, $workspace, $site, $user] = createKeywordSanitizationTestContext();

        // Simulate what might happen with malformed LLM output
        $malformedInput = "Here is the article outline:\n\n1. Introduction to content marketing\n2. Best practices\n3. Case studies\n\nPrimary keyword should be 'content marketing' but this entire paragraph was accidentally mapped.";

        $content = Content::query()->create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Content Marketing Guide',
            'primary_keyword' => $malformedInput,
            'type' => 'article',
            'status' => 'draft',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        // Should not contain the full malformed input
        expect($content->primary_keyword)->not->toBe($malformedInput);
        // Should fit within database column
        expect(mb_strlen($content->primary_keyword ?? ''))->toBeLessThanOrEqual(255);
    });
});
