<?php

namespace App\Notifications\Models;

final class NotificationPreference extends NotificationModel
{
    protected $fillable = [
        'user_id', 'event_type', 'channel', 'enabled', 'quiet_hours',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'quiet_hours' => 'array',
    ];
}
