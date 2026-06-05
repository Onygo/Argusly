<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyProfile extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'company_name',
        'industry',
        'short_description',
        'long_description',
        'mission',
        'vision',
        'value_proposition',
        'key_services',
        'value_propositions',
        'proof_points',
        'compliance_rules',
        'banned_claims',
        'target_audience',
        'generated_from_context_id',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function generatedFromContext()
    {
        return $this->belongsTo(BrandContext::class, 'generated_from_context_id');
    }

    /**
     * @return array<int, string>
     */
    public function keyServicesArray(): array
    {
        return $this->splitLines((string) ($this->key_services ?? ''));
    }

    /**
     * @return array<int, string>
     */
    public function valuePropositionsArray(): array
    {
        return $this->splitLines((string) ($this->value_propositions ?? ''));
    }

    /**
     * @return array<int, string>
     */
    public function proofPointsArray(): array
    {
        return $this->splitLines((string) ($this->proof_points ?? ''));
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
