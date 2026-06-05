<?php

namespace App\Services\WriterProfiles;

use App\Models\WriterProfile;

class WriterProfilePromptTemplates
{
    public static function analysisSystemPrompt(): string
    {
        return implode("\n", [
            'You analyze writing style for reusable writer profiles.',
            'Abstract style characteristics only. Do not copy source sentences, unique claims, examples, anecdotes, or recognizable phrasing.',
            'Return strict JSON only with tone_summary, writing_style_summary, structure_summary, vocabulary_notes, formatting_preferences, do_rules, dont_rules, example_patterns, and confidence_score.',
            'Rules must be concrete and reusable for future writing.',
            'example_patterns must describe abstract patterns, not quote or paraphrase unique source text.',
        ]);
    }

    public static function refinementSystemPrompt(): string
    {
        return implode("\n", [
            'You refine an existing writer profile based on new guidance or additional texts.',
            'Preserve the profile as an abstract style card.',
            'Do not import unique sentences, claims, examples, anecdotes, or recognizable formulations from source material.',
            'Return the same structured JSON fields as the analysis prompt.',
        ]);
    }

    public static function applySystemInstruction(WriterProfile $profile, ?string $channel = null): string
    {
        return $profile->compactPromptContext($channel);
    }
}
