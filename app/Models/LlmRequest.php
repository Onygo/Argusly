<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LlmRequest extends Model
{
    use HasUuids;

    protected $table = 'llm_requests';

    protected $fillable = [
        'workspace_id',
        'site_id',
        'user_id',
        'feature',
        'modality',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'credits_consumed',
        'input_cost_eur',
        'output_cost_eur',
        'total_cost_eur',
        'latency_ms',
        'status',
        'error_type',
        'error_message',
        'error_code',
        'request_id',
        'job_id',
        'retry_count',
        'metadata',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'total_tokens' => 'integer',
        'credits_consumed' => 'float',
        'input_cost_eur' => 'float',
        'output_cost_eur' => 'float',
        'total_cost_eur' => 'float',
        'latency_ms' => 'integer',
        'retry_count' => 'integer',
        'metadata' => 'array',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function site()
    {
        return $this->belongsTo(ClientSite::class, 'site_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
