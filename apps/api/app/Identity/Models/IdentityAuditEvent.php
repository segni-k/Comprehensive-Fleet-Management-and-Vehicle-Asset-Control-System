<?php

namespace App\Identity\Models;

final class IdentityAuditEvent extends IdentityModel
{
    public $timestamps = false;

    protected $table = 'identity_access_audit_events';

    protected $fillable = [
        'event_type', 'actor_user_id', 'actor_session_id', 'organization_id', 'subject_type',
        'subject_id', 'outcome', 'priority', 'reason', 'before_snapshot', 'after_snapshot',
        'correlation_id', 'ip_hash', 'occurred_at',
    ];

    protected $casts = [
        'before_snapshot' => 'array',
        'after_snapshot' => 'array',
        'occurred_at' => 'immutable_datetime',
    ];
}
