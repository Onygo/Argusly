<?php

namespace Database\Seeders;

use App\Models\Workspace;
use App\Models\WriterProfile;
use Illuminate\Database\Seeder;

class WriterProfileSeeder extends Seeder
{
    public function run(): void
    {
        $workspace = Workspace::query()->orderBy('created_at')->first();

        if (! $workspace) {
            return;
        }

        WriterProfile::query()->firstOrCreate(
            [
                'workspace_id' => (string) $workspace->id,
                'name' => 'Praktisch strategisch',
            ],
            [
                'source_type' => WriterProfile::SOURCE_MANUAL,
                'profile_scope' => WriterProfile::SCOPE_COMPANY,
                'description' => 'Direct, concreet en strategisch zonder zwaar jargon.',
                'tone_summary' => 'Direct, nuchter en praktisch strategisch.',
                'writing_style_summary' => 'Korte alinea’s, duidelijke observaties en steeds een brug naar actie.',
                'structure_summary' => 'Probleem -> inzicht -> actie.',
                'vocabulary_notes' => 'Weinig jargon; gebruik concrete businesswoorden en vermijd abstracte beloftes.',
                'formatting_preferences' => 'Korte alinea’s, duidelijke tussenkoppen en selectieve bullets.',
                'do_rules' => [
                    'Begin met het concrete probleem.',
                    'Maak het inzicht praktisch toepasbaar.',
                    'Sluit af met een duidelijke volgende stap.',
                ],
                'dont_rules' => [
                    'Gebruik geen hype of lege superlatieven.',
                    'Gebruik het writer profile alleen als stijlrichting en kopieer geen bronzinnen.',
                ],
                'example_patterns' => [
                    'Probleem -> inzicht -> actie.',
                    'Korte constatering gevolgd door praktische consequentie.',
                ],
                'confidence_score' => 0.72,
                'status' => WriterProfile::STATUS_ACTIVE,
                'retain_source_text' => false,
                'channel_defaults' => [
                    'blog' => true,
                    'linkedin' => true,
                    'newsletter' => true,
                    'landing_page' => false,
                ],
                'last_analyzed_at' => now(),
                'metadata' => [
                    'demo' => true,
                    'privacy_note' => 'No source text is stored for this demo profile.',
                ],
            ],
        );
    }
}
