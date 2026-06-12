<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Growth\ProgrammaticGrowthDemoSeeder as DemoSeederService;
use Illuminate\Database\Seeder;

class ProgrammaticGrowthDemoSeeder extends Seeder
{
    public function run(): void
    {
        $workspace = Workspace::query()->orderBy('created_at')->first();

        if (! $workspace) {
            return;
        }

        $owner = User::query()
            ->where('organization_id', $workspace->organization_id)
            ->whereIn('role', ['owner', 'admin'])
            ->orderBy('created_at')
            ->first();

        app(DemoSeederService::class)->seed($workspace, $owner);
    }
}
