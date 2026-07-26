<?php

namespace App\Identity\Models;

final class BreakGlassAccess extends IdentityModel
{
    protected $table = 'break_glass_access';

    protected $fillable = [
        'user_id',
        'organization_id',
        'requested_session_id',
        'permission_codes',
        'reason',
        'started_at',
        'expires_at',
        'ended_at',
        'ended_by',
        'status',
        'reviewed_by',
        'review_decision',
        'review_notes',
        'reviewed_at',
    ];

    protected $attributes = [
        'status' => 'active',
        'record_version' => 1,
    ];

    protected $casts = [
        'permission_codes' => 'array',
        'started_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'ended_at' => 'immutable_datetime',
        'reviewed_at' => 'immutable_datetime',
    ];
}
