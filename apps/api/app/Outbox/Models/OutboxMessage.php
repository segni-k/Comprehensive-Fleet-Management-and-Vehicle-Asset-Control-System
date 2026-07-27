<?php

namespace App\Outbox\Models;

final class OutboxMessage extends OutboxModel
{
    protected $fillable = [
        'topic', 'deduplication_key', 'idempotency_key', 'aggregate_type',
        'aggregate_id', 'organization_id', 'payload', 'payload_version',
        'correlation_id', 'causation_id', 'status', 'attempts', 'maximum_attempts',
        'lock_owner', 'locked_until', 'available_at', 'next_attempt_at',
        'published_at', 'failed_at', 'last_error_code', 'last_error_message',
    ];

    protected $attributes = ['payload_version' => 1, 'status' => 'pending', 'attempts' => 0, 'maximum_attempts' => 8];

    protected $casts = [
        'payload' => 'array',
        'locked_until' => 'immutable_datetime',
        'available_at' => 'immutable_datetime',
        'next_attempt_at' => 'immutable_datetime',
        'published_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
    ];
}
