<?php

namespace App\Notifications\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

final class InAppNotification extends NotificationModel
{
    protected $table = 'notifications';

    protected $fillable = [
        'recipient_user_id', 'organization_id', 'template_id', 'event_type',
        'subject_type', 'subject_id', 'title', 'body', 'safe_payload', 'severity',
        'status', 'deduplication_key', 'read_at', 'acknowledged_at', 'expires_at',
    ];

    protected $attributes = ['severity' => 'information', 'status' => 'unread'];

    protected $casts = [
        'safe_payload' => 'array',
        'read_at' => 'immutable_datetime',
        'acknowledged_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
    ];

    /** @return HasMany<NotificationDeliveryAttempt, $this> */
    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(NotificationDeliveryAttempt::class, 'notification_id');
    }
}
