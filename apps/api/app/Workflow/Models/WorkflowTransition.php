<?php

namespace App\Workflow\Models;

final class WorkflowTransition extends WorkflowModel
{
    protected $fillable = [
        'workflow_definition_id', 'code', 'from_state_id', 'to_state_id',
        'required_permission', 'guard_rules', 'reason_required',
        'maker_checker_required', 'delegation_allowed',
    ];

    protected $casts = [
        'guard_rules' => 'array',
        'reason_required' => 'boolean',
        'maker_checker_required' => 'boolean',
        'delegation_allowed' => 'boolean',
    ];
}
