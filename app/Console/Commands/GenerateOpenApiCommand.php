<?php

namespace App\Console\Commands;

use App\Services\ApiDocs\OpenApiGenerator;
use Illuminate\Console\Command;

class GenerateOpenApiCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'argusly:generate-openapi
        {--format=yaml : Output format (yaml or json)}
        {--output= : Output path (default: docs/openapi/argusly.yaml)}
        {--validate : Validate generated spec}
        {--stats : Show route statistics}';

    /**
     * The console command description.
     */
    protected $description = 'Generate OpenAPI specification from Laravel routes';

    /**
     * Execute the console command.
     */
    public function handle(OpenApiGenerator $generator): int
    {
        $this->info('Generating OpenAPI specification...');
        $this->newLine();

        // Show statistics if requested
        if ($this->option('stats')) {
            $this->showStatistics($generator);
        }

        // Generate spec
        $this->components->task('Scanning API routes', function () {
            return true;
        });

        $spec = $generator->generate();
        $routeCount = count($spec['paths'] ?? []);

        $this->components->task("Found {$routeCount} endpoint paths", function () {
            return true;
        });

        // Validate if requested
        if ($this->option('validate')) {
            $errors = $generator->validate($spec);

            if (! empty($errors)) {
                $this->newLine();
                $this->components->error('Validation failed:');
                foreach ($errors as $error) {
                    $this->components->bulletList([$error]);
                }

                return self::FAILURE;
            }

            $this->components->task('Validation passed', function () {
                return true;
            });
        }

        // Write to file
        $format = $this->option('format');
        $output = $this->option('output');

        $outputPath = $generator->write($spec, $output ?? config('argusly-docs.openapi.output'), $format);

        $this->newLine();
        $this->components->info("OpenAPI spec generated: {$outputPath}");

        // Summary
        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['OpenAPI Version', $spec['openapi'] ?? '3.1.0'],
                ['API Title', $spec['info']['title'] ?? 'N/A'],
                ['API Version', $spec['info']['version'] ?? 'N/A'],
                ['Total Paths', count($spec['paths'] ?? [])],
                ['Total Schemas', count($spec['components']['schemas'] ?? [])],
                ['Output Format', strtoupper($format)],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Show route statistics.
     */
    protected function showStatistics(OpenApiGenerator $generator): void
    {
        $stats = $generator->getStatistics();

        $this->components->info('Route Statistics');
        $this->newLine();

        $this->line("Total routes: <comment>{$stats['total_routes']}</comment>");
        $this->newLine();

        $this->line('<info>By Tag:</info>');
        $tagRows = [];
        foreach ($stats['by_tag'] as $tag => $count) {
            $tagRows[] = [$tag, $count];
        }
        $this->table(['Tag', 'Count'], $tagRows);

        $this->line('<info>By Method:</info>');
        $methodRows = [];
        foreach ($stats['by_method'] as $method => $count) {
            $methodRows[] = [strtoupper($method), $count];
        }
        $this->table(['Method', 'Count'], $methodRows);

        $this->newLine();
    }
}
