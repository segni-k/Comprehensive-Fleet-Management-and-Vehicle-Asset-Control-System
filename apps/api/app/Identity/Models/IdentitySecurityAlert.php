<?php

namespace App\Identity\Models;

final class IdentitySecurityAlert extends IdentityModel
{
    public const UPDATED_AT = null;

    protected $table = 'identity_security_alerts';

    protected $fillable = [
        'alert_type', 'severity', 'user_id', 'subject_type', 'subject_id', 'payload',
        'status', 'acknowledged_at', 'acknowledged_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'acknowledged_at' => 'immutable_datetime',
    ];
}
