<?php

namespace App\Outbox\Models;

final class OutboxConsumerReceipt extends OutboxModel
{
    public $timestamps = false;

    protected $fillable = [
        'consumer', 'outbox_message_id', 'idempotency_key', 'processed_at', 'result_metadata',
    ];

    protected $casts = [
        'processed_at' => 'immutable_datetime',
        'result_metadata' => 'array',
    ];
}
