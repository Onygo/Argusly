<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AgenticPlannerDefaultSelectionRuntimeSwitchAudit extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id',
        'objective_ids',
        'phase_3t_status',
        'phase_3u_eligibility',
        'phase_3v_guard_allowed',
        'phase_3w_selected_planner_remains',
        'phase_3x_contract_status',
        'switch_flag_enabled',
        'runtime_guard_flag_enabled',
        'switch_decision',
        'blocked_reasons',
        'operator_acknowledgements',
        'rollback_mode',
        'selected_planner',
        'selected_action_ownership_mode',
        'payload_namespace',
        'payload_version',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'objective_ids' => 'array',
        'phase_3v_guard_allowed' => 'boolean',
        'switch_flag_enabled' => 'boolean',
        'runtime_guard_flag_enabled' => 'boolean',
        'blocked_reasons' => 'array',
        'operator_acknowledgements' => 'array',
        'payload' => 'array',
        'created_at' => 'datetime',
    ];
}
