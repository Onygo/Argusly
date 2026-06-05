<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Throwable;

class PublicLinkAuditCommand extends Command
{
    protected $signature = 'public:link-audit {--ping : Run internal GET requests against matched routes}';

    protected $description = 'Audit internal links in public Blade views and verify public route targets.';

    public function handle(Kernel $kernel): int
    {
        $links = $this->collectPublicLinks();

        if ($links === []) {
            $this->warn('No links found in public views.');

            return self::SUCCESS;
        }

        $results = [];

        foreach ($links as $link) {
            $resolved = $this->resolvePathFromHref($link['href']);
            if ($resolved === null) {
                continue;
            }

            $path = $resolved;
            $status = $this->resolveStatusForPath($path, $kernel);
            $key = $path;

            if (! isset($results[$key])) {
                $results[$key] = [
                    'path' => $path,
                    'status' => $status,
                    'sources' => [],
                ];
            } elseif ($results[$key]['status'] === 'OK' && $status !== 'OK') {
                $results[$key]['status'] = $status;
            }

            $results[$key]['sources'][$link['source']] = true;
        }

        ksort($results);

        $rows = [];
        $hasErrors = false;

        foreach ($results as $result) {
            $status = $result['status'];
            if ($status !== 'OK') {
                $hasErrors = true;
            }

            $rows[] = [
                $result['path'],
                $status,
                implode(', ', array_keys($result['sources'])),
            ];
        }

        $this->table(['URL path', 'status', 'source locations'], $rows);

        if ($hasErrors) {
            $this->error('Link audit failed: unresolved routes/views were found.');

            return self::FAILURE;
        }

        $this->info('Link audit OK: no missing or broken internal links found.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{href:string, source:string}>
     */
    private function collectPublicLinks(): array
    {
        $paths = array_merge(
            File::glob(resource_path('views/public/**/*.blade.php')) ?: [],
            File::glob(resource_path('views/public/*.blade.php')) ?: [],
            File::glob(resource_path('views/components/public/**/*.blade.php')) ?: [],
            File::glob(resource_path('views/components/public/*.blade.php')) ?: []
        );

        $paths = array_values(array_unique(array_filter($paths, static fn ($p) => is_string($p) && is_file($p))));

        sort($paths);

        $links = [];
        $pattern = '/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/i';

        foreach ($paths as $path) {
            $lines = @file($path);
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $lineNumber => $line) {
                if (! str_contains($line, 'href=')) {
                    continue;
                }

                if (! preg_match_all($pattern, $line, $matches, PREG_SET_ORDER)) {
                    continue;
                }

                foreach ($matches as $match) {
                    $href = trim((string) ($match[2] ?? ''));
                    if ($href === '') {
                        continue;
                    }

                    $relativePath = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path);

                    $links[] = [
                        'href' => $href,
                        'source' => $relativePath . ':' . ($lineNumber + 1),
                    ];
                }
            }
        }

        return $links;
    }

    private function resolvePathFromHref(string $href): ?string
    {
        $href = trim($href);

        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
            return null;
        }

        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://') || str_starts_with($href, '//')) {
            return null;
        }

        if (str_contains($href, '{{') || str_contains($href, '}}')) {
            if (str_contains($href, '$')) {
                return null;
            }

            if (preg_match('/\{\{\s*route\(\s*[\"\']([^\"\']+)[\"\']/i', $href, $matches)) {
                $name = (string) ($matches[1] ?? '');
                if ($name === '' || ! Route::has($name)) {
                    return '__missing_route__:' . $name;
                }

                try {
                    $path = (string) route($name, [], false);
                } catch (Throwable) {
                    return '__broken__:' . $name;
                }

                $fragment = '';
                if (preg_match('/\}\}\s*(#[A-Za-z0-9\-_:.]+)/', $href, $fragmentMatch)) {
                    $fragment = (string) $fragmentMatch[1];
                }

                return $this->normalizePath($path . $fragment);
            }

            if (preg_match('/\{\{\s*url\(\s*[\"\']([^\"\']+)[\"\']/i', $href, $matches)) {
                return $this->normalizePath((string) ($matches[1] ?? ''));
            }

            return null;
        }

        return $this->normalizePath($href);
    }

    private function normalizePath(string $href): string
    {
        $href = trim($href);

        if ($href === '') {
            return '/';
        }

        $parsedPath = (string) (parse_url($href, PHP_URL_PATH) ?: '');
        if ($parsedPath === '') {
            $parsedPath = '/';
        }

        if (! str_starts_with($parsedPath, '/')) {
            $parsedPath = '/' . $parsedPath;
        }

        return rtrim($parsedPath, '/') === '' ? '/' : rtrim($parsedPath, '/');
    }

    private function resolveStatusForPath(string $path, Kernel $kernel): string
    {
        if (str_starts_with($path, '__missing_route__:')) {
            return 'MISSING_ROUTE';
        }

        if (str_starts_with($path, '__broken__:')) {
            return 'BROKEN';
        }

        $matchedPath = $this->findMatchingRoutePath($path);
        if ($matchedPath === null) {
            return 'MISSING_ROUTE';
        }

        if (! (bool) $this->option('ping')) {
            return 'OK';
        }

        try {
            $request = Request::create($matchedPath, 'GET');
            $response = $kernel->handle($request);
            $statusCode = $response->getStatusCode();
            $content = method_exists($response, 'getContent') ? (string) $response->getContent() : '';
            $kernel->terminate($request, $response);

            if ($statusCode >= 500) {
                if (str_contains($content, 'View [') && str_contains($content, 'not found')) {
                    return 'MISSING_VIEW';
                }

                return 'BROKEN';
            }

            if ($statusCode === 404) {
                return 'MISSING_ROUTE';
            }

            return 'OK';
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'View [') && str_contains($message, 'not found')) {
                return 'MISSING_VIEW';
            }

            return 'BROKEN';
        }
    }

    private function findMatchingRoutePath(string $path): ?string
    {
        $candidates = [$path];

        if (preg_match('#^/(nl|en)(/.*)?$#i', $path, $matches)) {
            $trimmed = (string) ($matches[2] ?? '/');
            $candidates[] = $trimmed === '' ? '/' : $trimmed;
        } else {
            $candidates[] = '/nl' . ($path === '/' ? '' : $path);
            $candidates[] = '/en' . ($path === '/' ? '' : $path);
        }

        $candidates = array_values(array_unique($candidates));

        foreach ($candidates as $candidate) {
            $candidate = $candidate === '' ? '/' : $candidate;

            try {
                Route::getRoutes()->match(Request::create($candidate, 'GET'));

                return $candidate;
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}
