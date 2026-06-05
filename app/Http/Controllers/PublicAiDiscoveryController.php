<?php

namespace App\Http\Controllers;

use App\Services\AiDiscovery\PublicBlogMarkdownService;
use App\Services\AiDiscovery\PublicLlmsService;
use App\Services\PublicBlog\PublicBlogService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PublicAiDiscoveryController extends Controller
{
    public function llms(Request $request, PublicLlmsService $llms): Response
    {
        return response(
            $llms->render(false, (string) app()->getLocale()),
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }

    public function llmsFull(Request $request, PublicLlmsService $llms): Response
    {
        return response(
            $llms->render(true, (string) app()->getLocale()),
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }

    public function blogMarkdown(Request $request, string $slug, PublicBlogService $blog, PublicBlogMarkdownService $markdown): Response
    {
        $locale = (string) app()->getLocale();
        $post = $blog->getPostBySlug($slug, $locale);

        if (! $post) {
            abort(404);
        }

        return response(
            $markdown->render($post),
            200,
            ['Content-Type' => 'text/markdown; charset=UTF-8']
        );
    }
}
