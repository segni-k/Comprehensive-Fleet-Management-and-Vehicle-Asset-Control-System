<?php

namespace App\Workflow\Jobs;

use App\Workflow\Services\WorkflowCollaborationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class EscalateOverdueWorkflows implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function handle(WorkflowCollaborationService $workflows): void
    {
        $workflows->escalateOverdue();
    }
}
