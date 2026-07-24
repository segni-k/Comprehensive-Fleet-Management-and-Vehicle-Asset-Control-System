<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute((int) config('platform.api_rate_limit_per_minute'))
                ->by($request->ip());
        });

        Queue::before(fn (JobProcessing $event) => Log::info('queue.job.processing', [
            'connection' => $event->connectionName,
            'queue' => $event->job->getQueue(),
            'job' => $event->job->resolveName(),
        ]));
        Queue::after(fn (JobProcessed $event) => Log::info('queue.job.processed', [
            'connection' => $event->connectionName,
            'queue' => $event->job->getQueue(),
            'job' => $event->job->resolveName(),
        ]));
        Queue::exceptionOccurred(fn (JobExceptionOccurred $event) => Log::error('queue.job.failed', [
            'connection' => $event->connectionName,
            'queue' => $event->job->getQueue(),
            'job' => $event->job->resolveName(),
            'exception' => $event->exception::class,
        ]));
    }
}
