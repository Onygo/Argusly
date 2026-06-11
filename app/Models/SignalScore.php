<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\SignalScoreType;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SignalScore extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasSignalIntelligenceTenancy;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'scope_type',
        'scope_key',
        'score_type',
        'score',
        'previous_score',
        'delta',
        'period_start',
        'period_end',
        'breakdown',
        'computed_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'score_type' => SignalScoreType::class,
        'score' => 'float',
        'previous_score' => 'float',
        'delta' => 'float',
        'period_start' => 'date',
        'period_end' => 'date',
        'breakdown' => 'array',
        'computed_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
