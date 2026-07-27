<?php

namespace App\Outbox\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OutboxDeadLetter extends OutboxModel
{
    public $timestamps = false;

    protected $fillable = [
        'outbox_message_id', 'failure_class', 'safe_diagnostic', 'attempts',
        'failed_at', 'replayed_at', 'replayed_by', 'replay_reason',
    ];

    protected $casts = ['failed_at' => 'immutable_datetime', 'replayed_at' => 'immutable_datetime'];

    /** @return BelongsTo<OutboxMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(OutboxMessage::class, 'outbox_message_id');
    }
}
