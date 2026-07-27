<?php

namespace App\Workflow\Models;

final class WorkflowAssignment extends WorkflowModel
{
    public $timestamps = false;

    protected $fillable = [
        'workflow_instance_id', 'assigned_user_id', 'required_permission',
        'organization_id', 'assigned_at', 'due_at', 'completed_at', 'status',
    ];

    protected $casts = [
        'assigned_at' => 'immutable_datetime',
        'due_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];
}
