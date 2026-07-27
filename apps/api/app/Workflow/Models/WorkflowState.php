<?php

namespace App\Workflow\Models;

final class WorkflowState extends WorkflowModel
{
    protected $fillable = [
        'workflow_definition_id', 'code', 'name', 'state_type', 'sort_order',
        'is_initial', 'is_terminal', 'service_level',
    ];

    protected $casts = [
        'name' => 'array',
        'is_initial' => 'boolean',
        'is_terminal' => 'boolean',
        'service_level' => 'array',
    ];
}
