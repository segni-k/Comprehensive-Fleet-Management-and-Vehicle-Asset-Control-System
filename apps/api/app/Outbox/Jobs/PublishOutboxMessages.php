<?php

namespace App\Outbox\Jobs;

use App\Outbox\Services\OutboxService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class PublishOutboxMessages implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [15, 60, 300];

    public function handle(OutboxService $outbox): void
    {
        $outbox->processDue();
    }

    public function failed(?Throwable $exception): void
    {
        report($exception);
    }
}
