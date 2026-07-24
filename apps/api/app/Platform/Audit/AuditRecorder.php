<?php

namespace App\Platform\Audit;

interface AuditRecorder
{
    /** @param array<string, bool|float|int|string|null> $metadata */
    public function record(string $eventType, string $actorId, array $metadata = []): void;
}
