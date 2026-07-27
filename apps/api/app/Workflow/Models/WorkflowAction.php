<?php

namespace App\Workflow\Models;

final class WorkflowAction extends WorkflowModel
{
    public $timestamps = false;

    protected $fillable = [
        'workflow_instance_id', 'transition_id', 'from_state_id', 'to_state_id',
        'actor_user_id', 'actor_session_id', 'role_assignment_id', 'delegation_id',
        'authority_snapshot', 'context_snapshot', 'reason', 'idempotency_key',
        'expected_record_version', 'acted_at',
    ];

    protected $casts = [
        'authority_snapshot' => 'array',
        'context_snapshot' => 'array',
        'acted_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new \LogicException('Workflow actions are append-only.'));
        self::deleting(fn (): never => throw new \LogicException('Workflow actions are append-only.'));
    }
}
