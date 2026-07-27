<?php

namespace App\Outbox\Contracts;

use App\Outbox\Models\OutboxMessage;

interface OutboxPublisher
{
    public function publish(OutboxMessage $message): void;
}
