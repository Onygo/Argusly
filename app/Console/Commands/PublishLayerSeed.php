<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Models\Organization;
use App\Models\Workspace;
use App\Models\ClientSite;
use App\Models\SiteToken;

class PublishLayerSeed extends Command
{
    protected $signature = 'publishlayer:seed
        {workspace_name : Workspace name}
        {site_name : Client site name}
        {site_url : Canonical site url, for example https://example.com}
        {allowed_domains* : Allowed domains, for example example.com staging.example.com}
        {--organization-id= : Organization id. If omitted and exactly one organization exists, that one is used}
        {--type=wordpress : Client type}
        {--scopes=briefs:write,drafts:read,events:write : Comma separated scopes}';

    protected $description = 'Seed an Argusly workspace, client site, and site token';

    public function handle(): int
    {
        $workspaceName = (string) $this->argument('workspace_name');
        $siteName = (string) $this->argument('site_name');
        $siteUrl = (string) $this->argument('site_url');
        $allowedDomains = (array) $this->argument('allowed_domains');
        $type = (string) $this->option('type');
        $organizationIdInput = $this->option('organization-id');

        $organizationId = $this->resolveOrganizationId($organizationIdInput);
        if (! $organizationId) {
            $this->error('Cannot resolve organization. Provide --organization-id or ensure exactly one organization exists.');
            return self::FAILURE;
        }

        $scopesRaw = (string) $this->option('scopes');
        $scopes = array_values(array_filter(array_map('trim', explode(',', $scopesRaw))));
        if (count($scopes) === 0) {
            $scopes = ['briefs:write', 'drafts:read', 'events:write'];
        }

        $normalizedDomains = $this->normalizeDomains($allowedDomains);
        if (count($normalizedDomains) === 0) {
            $this->error('No valid allowed domains provided');
            return self::FAILURE;
        }

        $workspace = Workspace::create([
            'name' => $workspaceName,
            'organization_id' => $organizationId,
        ]);

        $clientSite = ClientSite::create([
            'workspace_id' => $workspace->id,
            'type' => $type,
            'name' => $siteName,
            'site_url' => $siteUrl,
            'allowed_domains' => $normalizedDomains,
            'is_active' => true,
        ]);

        $plainToken = 'pl_site_' . Str::random(48);

        $siteToken = SiteToken::create([
            'client_site_id' => $clientSite->id,
            'token_hash' => hash('sha256', $plainToken),
            'scopes' => $scopes,
            'revoked' => false,
        ]);

        $this->info('Seed completed');
        $this->line('Workspace id: ' . $workspace->id);
        $this->line('Client site id: ' . $clientSite->id);
        $this->line('Site token id: ' . $siteToken->id);
        $this->line('Allowed domains: ' . implode(', ', $normalizedDomains));
        $this->line('Scopes: ' . implode(', ', $scopes));
        $this->newLine();

        $this->warn('Token is shown once. Store it now.');
        $this->line('Site token: ' . $plainToken);

        return self::SUCCESS;
    }

    private function resolveOrganizationId(mixed $organizationIdInput): ?int
    {
        if (is_string($organizationIdInput) && $organizationIdInput !== '') {
            $organization = Organization::query()->find($organizationIdInput);
            return $organization?->id;
        }

        if (Organization::query()->count() === 1) {
            return (int) Organization::query()->value('id');
        }

        return null;
    }

    private function normalizeDomains(array $domains): array
    {
        $out = [];

        foreach ($domains as $d) {
            $d = trim((string) $d);
            if ($d === '') {
                continue;
            }

            $host = $this->extractHost($d);
            $host = strtolower(trim($host));

            if ($host === '') {
                continue;
            }

            $out[] = $host;
        }

        $out = array_values(array_unique($out));

        return $out;
    }

    private function extractHost(string $value): string
    {
        if (str_contains($value, '://')) {
            $parsed = parse_url($value);
            return (string) ($parsed['host'] ?? '');
        }

        $value = preg_split('/[\/\s]/', $value)[0] ?? '';
        return (string) $value;
    }
}
