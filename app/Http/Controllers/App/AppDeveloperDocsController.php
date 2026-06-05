<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Services\ApiDocs\DocsRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AppDeveloperDocsController extends Controller
{
    public function __construct(
        protected DocsRenderer $docsRenderer
    ) {}

    /**
     * Display the API documentation reference.
     */
    public function index(Request $request): View
    {
        Gate::authorize('manage-organization');

        $activeTag = $request->query('tag');
        $tags = $this->docsRenderer->getTags();

        // Default to first tag if none specified
        if ($activeTag === null && ! empty($tags)) {
            $activeTag = $tags[0]['name'] ?? null;
        }

        $endpoints = $activeTag
            ? ($this->docsRenderer->getEndpointsByTag($activeTag)[$activeTag] ?? [])
            : [];

        return view('app.developer.docs.index', [
            'specExists' => $this->docsRenderer->specExists(),
            'info' => $this->docsRenderer->getInfo(),
            'tags' => $tags,
            'activeTag' => $activeTag,
            'endpoints' => $endpoints,
            'authInfo' => $this->docsRenderer->getAuthInfo(),
            'servers' => $this->docsRenderer->getServers(),
            'statistics' => $this->docsRenderer->getStatistics(),
        ]);
    }

    /**
     * Display a single endpoint.
     */
    public function endpoint(Request $request, string $method, string $path): View
    {
        Gate::authorize('manage-organization');

        // Decode path (it's URL encoded)
        $path = '/'.ltrim(urldecode($path), '/');

        $endpoint = $this->docsRenderer->getEndpoint($path, strtolower($method));

        if ($endpoint === null) {
            abort(404, 'Endpoint not found');
        }

        return view('app.developer.docs.endpoint', [
            'endpoint' => $endpoint,
            'authInfo' => $this->docsRenderer->getAuthInfo(),
            'servers' => $this->docsRenderer->getServers(),
        ]);
    }

    /**
     * Display the downloads page.
     */
    public function downloads(Request $request): View
    {
        Gate::authorize('manage-organization');

        $openApiPath = config('publishlayer-docs.openapi.output', 'docs/openapi/publishlayer.yaml');
        $postmanCollectionPath = config('publishlayer-docs.postman.output_dir', 'docs/postman/').'publishlayer-collection.json';
        $postmanEnvironmentPath = config('publishlayer-docs.postman.output_dir', 'docs/postman/').'publishlayer-environment.json';

        return view('app.developer.docs.downloads', [
            'files' => [
                [
                    'name' => 'OpenAPI Specification',
                    'description' => 'OpenAPI 3.1.0 specification for the Argusly API',
                    'filename' => 'publishlayer-openapi.yaml',
                    'exists' => File::exists(base_path($openApiPath)),
                    'route' => 'app.developer.docs.download.openapi',
                    'format' => 'YAML',
                ],
                [
                    'name' => 'Postman Collection',
                    'description' => 'Import directly into Postman with all endpoints pre-configured',
                    'filename' => 'publishlayer-collection.json',
                    'exists' => File::exists(base_path($postmanCollectionPath)),
                    'route' => 'app.developer.docs.download.postman-collection',
                    'format' => 'JSON',
                ],
                [
                    'name' => 'Postman Environment',
                    'description' => 'Environment variables for the Postman collection',
                    'filename' => 'publishlayer-environment.json',
                    'exists' => File::exists(base_path($postmanEnvironmentPath)),
                    'route' => 'app.developer.docs.download.postman-environment',
                    'format' => 'JSON',
                ],
            ],
            'statistics' => $this->docsRenderer->getStatistics(),
        ]);
    }

    /**
     * Download OpenAPI specification.
     */
    public function downloadOpenApi(): Response|BinaryFileResponse
    {
        Gate::authorize('manage-organization');

        $path = base_path(config('publishlayer-docs.openapi.output', 'docs/openapi/publishlayer.yaml'));

        if (! File::exists($path)) {
            abort(404, 'OpenAPI specification not found. Run php artisan publishlayer:generate-openapi first.');
        }

        return response()->download($path, 'publishlayer-openapi.yaml', [
            'Content-Type' => 'application/x-yaml',
        ]);
    }

    /**
     * Download Postman collection.
     */
    public function downloadPostmanCollection(): Response|BinaryFileResponse
    {
        Gate::authorize('manage-organization');

        $path = base_path(config('publishlayer-docs.postman.output_dir', 'docs/postman/').'publishlayer-collection.json');

        if (! File::exists($path)) {
            abort(404, 'Postman collection not found. Run php artisan publishlayer:generate-postman first.');
        }

        return response()->download($path, 'publishlayer-collection.json', [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Download Postman environment.
     */
    public function downloadPostmanEnvironment(): Response|BinaryFileResponse
    {
        Gate::authorize('manage-organization');

        $path = base_path(config('publishlayer-docs.postman.output_dir', 'docs/postman/').'publishlayer-environment.json');

        if (! File::exists($path)) {
            abort(404, 'Postman environment not found. Run php artisan publishlayer:generate-postman first.');
        }

        return response()->download($path, 'publishlayer-environment.json', [
            'Content-Type' => 'application/json',
        ]);
    }
}
