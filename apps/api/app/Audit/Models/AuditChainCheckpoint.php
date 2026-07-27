<?php

namespace App\Audit\Models;

final class AuditChainCheckpoint extends AuditModel
{
    protected $fillable = [
        'partition_key', 'last_sequence', 'last_event_hash', 'verification_status',
        'verified_at', 'verified_by', 'verification_details',
    ];

    protected $casts = [
        'verified_at' => 'immutable_datetime',
        'verification_details' => 'array',
    ];
}
