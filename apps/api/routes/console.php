<?php

use App\Organization\Services\OrganizationStatusTransitionService;
use App\Outbox\Jobs\PublishOutboxMessages;
use App\Workflow\Jobs\EscalateOverdueWorkflows;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(
    fn (): int => app(OrganizationStatusTransitionService::class)->applyDue(),
)->name('organization-status-transitions')->everyMinute()->withoutOverlapping();

Schedule::job(new PublishOutboxMessages)
    ->name('publish-outbox-messages')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new EscalateOverdueWorkflows)
    ->name('escalate-overdue-workflows')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
