<?php

return [
    'agentic_planner' => [
        'default_selection' => [
            'scoped_runtime_enabled' => (bool) env('MOS_AGENTIC_PLANNER_DEFAULT_SELECTION_SCOPED_RUNTIME_ENABLED', false),
            'scoped_runtime_switch_enabled' => (bool) env('MOS_AGENTIC_PLANNER_DEFAULT_SELECTION_SCOPED_RUNTIME_SWITCH_ENABLED', false),
            'scoped_runtime_activation_enabled' => (bool) env('MOS_AGENTIC_PLANNER_DEFAULT_SELECTION_SCOPED_RUNTIME_ACTIVATION_ENABLED', false),
            'allowed_scopes' => [
                // [
                //     'workspace_id' => 'workspace-id',
                //     'objective_ids' => ['objective-id-a', 'objective-id-b'],
                //     'metadata_only_ok_review_acknowledged' => false,
                // ],
            ],
            'switch_allowed_scopes' => [
                // [
                //     'workspace_id' => 'workspace-id',
                //     'objective_ids' => ['objective-id-a', 'objective-id-b'],
                //     'runtime_switch_contract_acknowledged' => false,
                // ],
            ],
        ],
    ],
];
