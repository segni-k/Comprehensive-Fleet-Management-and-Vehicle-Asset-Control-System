<?php

namespace App\Notifications\Models;

final class NotificationDeliveryAttempt extends NotificationModel
{
    public $timestamps = false;

    protected $fillable = [
        'notification_id', 'channel', 'adapter', 'attempt_number', 'status',
        'failure_class', 'provider_reference', 'safe_diagnostic', 'next_attempt_at', 'attempted_at',
    ];

    protected $casts = [
        'next_attempt_at' => 'immutable_datetime',
        'attempted_at' => 'immutable_datetime',
    ];
}
