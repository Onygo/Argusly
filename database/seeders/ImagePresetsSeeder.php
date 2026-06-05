<?php

namespace Database\Seeders;

use App\Models\ImagePreset;
use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ImagePresetsSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()
            ->where('slug', 'demo-org')
            ->first();

        if (! $organization) {
            return;
        }

        $presets = [
            [
                'name' => 'Modern Minimalist',
                'instructions' => implode("\n", [
                    'Clean and minimal aesthetic',
                    'Soft, neutral color palette with subtle accent colors',
                    'Natural lighting with soft shadows',
                    'Plenty of negative space',
                    'Simple geometric shapes',
                    'Professional and contemporary feel',
                    'No text overlays or logos',
                ]),
                'is_default' => true,
            ],
            [
                'name' => 'Vibrant Tech',
                'instructions' => implode("\n", [
                    'Bold, vibrant color gradients',
                    'Neon accents on dark backgrounds',
                    'Dynamic lighting with glowing effects',
                    'Abstract technological elements',
                    'Futuristic and innovative mood',
                    'High contrast and visual energy',
                    'Suitable for tech blog headers',
                ]),
                'is_default' => false,
            ],
            [
                'name' => 'Warm Editorial',
                'instructions' => implode("\n", [
                    'Warm, inviting color tones',
                    'Natural, organic textures',
                    'Soft focus with depth of field',
                    'Human-centric compositions',
                    'Lifestyle photography aesthetic',
                    'Cozy and approachable feel',
                    'Golden hour lighting preferred',
                ]),
                'is_default' => false,
            ],
            [
                'name' => 'Corporate Professional',
                'instructions' => implode("\n", [
                    'Clean, professional aesthetic',
                    'Blue and grey color palette',
                    'Crisp, sharp imagery',
                    'Business-appropriate compositions',
                    'Modern office environments',
                    'Confident and trustworthy mood',
                    'Suitable for B2B content',
                ]),
                'is_default' => false,
            ],
        ];

        // Clear existing defaults to ensure only one default
        ImagePreset::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->update(['is_default' => false]);

        foreach ($presets as $presetData) {
            ImagePreset::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'name' => $presetData['name'],
                ],
                [
                    'id' => (string) Str::uuid(),
                    'instructions' => $presetData['instructions'],
                    'is_default' => $presetData['is_default'],
                ]
            );
        }
    }
}
