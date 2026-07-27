<?php

namespace App\Outbox\Infrastructure;

use App\Outbox\Contracts\OutboxPublisher;
use App\Outbox\Models\OutboxMessage;
use Illuminate\Support\Facades\Log;

final class LogOutboxPublisher implements OutboxPublisher
{
    public function publish(OutboxMessage $message): void
    {
        Log::info('outbox.message.published', [
            'id' => $message->id,
            'topic' => $message->topic,
            'aggregate_type' => $message->aggregate_type,
            'aggregate_id' => $message->aggregate_id,
            'payload_version' => $message->payload_version,
            'correlation_id' => $message->correlation_id,
        ]);
    }
}
