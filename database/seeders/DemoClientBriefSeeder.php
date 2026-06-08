<?php

namespace Database\Seeders;

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\SiteToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class DemoClientBriefSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->where('slug', 'demo-org')->first();
        if (! $organization) {
            return;
        }

        $workspace = Workspace::query()
            ->where('organization_id', $organization->id)
            ->orderBy('created_at')
            ->first();

        if (! $workspace) {
            return;
        }

        $site = ClientSite::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'name' => 'Argusly',
            ],
            [
                'type' => 'wordpress',
                'site_url' => 'https://argusly-demo.local',
                'base_url' => 'https://argusly-demo.local',
                'allowed_domains' => ['argusly-demo.local'],
                'is_active' => true,
                'status' => 'connected',
            ]
        );

        $hasActiveSiteToken = SiteToken::query()
            ->where('client_site_id', $site->id)
            ->where('revoked', false)
            ->exists();

        if (! $hasActiveSiteToken) {
            $plain = 'pl_site_demo_' . Str::lower(Str::random(48));

            SiteToken::query()->create([
                'id' => (string) Str::uuid(),
                'client_site_id' => $site->id,
                'workspace_id' => $workspace->id,
                'name' => 'Demo WordPress integration key',
                'token_hash' => hash('sha256', $plain),
                'token_encrypted' => Crypt::encryptString($plain),
                'key_prefix' => substr($plain, 0, 14),
                'scopes' => ['briefs:read', 'briefs:write', 'drafts:read', 'drafts:write', 'content:push', 'heartbeat:write'],
                'abilities' => ['briefs:read', 'briefs:write', 'drafts:read', 'drafts:write', 'content:push', 'heartbeat:write'],
                'revoked' => false,
                'revoked_at' => null,
            ]);
        }

        $authorId = User::query()
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->value('id');

        Brief::query()->firstOrCreate(
            [
                'client_site_id' => $site->id,
                'title' => 'Wat is AI Content Governance en waarom elke B2B SaaS het nodig heeft',
            ],
            [
                'created_by_user_id' => $authorId,
                'status' => 'draft',
                'source' => 'client_ui',
                'language' => 'nl',
                'content_type' => 'blog',
                'output_type' => 'kb_article',
                'primary_keyword' => 'AI content governance',
                'secondary_keywords' => [
                    'content governance framework',
                    'AI content compliance',
                    'AI workflows B2B',
                    'brand voice consistency',
                ],
                'target_audience' => 'Marketing managers, SaaS founders, scale up teams',
                'funnel_stage' => 'consideration',
                'search_intent' => 'informational',
                'tone_of_voice' => 'zakelijk, helder, strategisch, geen hype',
                'unique_angle' => 'focus op structuur, controle, compliance en schaalbaarheid',
                'key_points' => [
                    'verschil prompts versus workflow',
                    'brand voice',
                    'multi workspace governance',
                    'credits als cost control',
                    'WordPress integratie als distributielaag',
                ],
                'call_to_action' => 'Bekijk hoe Argusly governance en generatie combineert in één workflow',
                'desired_length_min' => 1200,
                'desired_length_max' => 1500,
                'client_refs' => [
                    'client_type' => 'client_ui',
                    'seed' => 'demo_client_brief',
                ],
                'wp_site_id' => (string) $site->id,
            ]
        );
    }
}
