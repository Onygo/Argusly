<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BrandVoice extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'organization_id',
        'generated_from_context_id',
        'name',
        'tone_of_voice',
        'writing_style',
        'example_paragraph',
        'do_rules',
        'dont_rules',
        'vocabulary_guidelines',
        'default_language',
        'default_tone',
        'style_guide',
        'preferred_terminology',
        'disallowed_terminology',
        'formatting_rules',
        'is_default',
        'ai_provider_override',
        'ai_model_override',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function generatedFromContext()
    {
        return $this->belongsTo(BrandContext::class, 'generated_from_context_id');
    }

    /**
     * @return array<int, string>
     */
    public function preferredTerminologyArray(): array
    {
        return $this->splitLines((string) ($this->preferred_terminology ?? ''));
    }

    /**
     * @return array<int, string>
     */
    public function disallowedTerminologyArray(): array
    {
        return $this->splitLines((string) ($this->disallowed_terminology ?? ''));
    }

    /**
     * @return array<int, string>
     */
    private function splitLines(string $value): array
    {
        return collect(preg_split('/\R+/', $value) ?: [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();
    }
}
