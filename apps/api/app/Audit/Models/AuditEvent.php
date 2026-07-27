<?php

namespace App\Audit\Models;

final class AuditEvent extends AuditModel
{
    public $timestamps = false;

    protected $fillable = [
        'sequence', 'partition_key', 'event_type', 'category', 'action', 'outcome',
        'severity', 'priority', 'actor_user_id', 'actor_session_id', 'impersonator_user_id',
        'delegation_id', 'organization_id', 'subject_type', 'subject_id', 'request_id',
        'correlation_id', 'causation_id', 'ip_hash', 'user_agent_hash', 'reason',
        'approval_reference', 'workflow_reference', 'before_snapshot', 'after_snapshot',
        'changed_fields', 'metadata', 'previous_hash', 'event_hash', 'occurred_at', 'created_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'before_snapshot' => 'array',
        'after_snapshot' => 'array',
        'changed_fields' => 'array',
        'metadata' => 'array',
        'occurred_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new \LogicException('Audit events are append-only.'));
        self::deleting(fn (): never => throw new \LogicException('Audit events are append-only.'));
    }
}
