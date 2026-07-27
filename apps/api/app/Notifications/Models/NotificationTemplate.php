<?php

namespace App\Notifications\Models;

use Database\Factories\NotificationTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

final class NotificationTemplate extends NotificationModel
{
    /** @use HasFactory<NotificationTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id', 'code', 'version_number', 'channel', 'locale', 'subject', 'body',
        'allowed_variables', 'classification', 'status', 'effective_from', 'effective_to',
    ];

    protected $casts = [
        'allowed_variables' => 'array',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
    ];

    protected static function newFactory(): NotificationTemplateFactory
    {
        return NotificationTemplateFactory::new();
    }
}
