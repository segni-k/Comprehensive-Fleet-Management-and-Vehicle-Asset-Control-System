<?php

namespace App\Http\Controllers\Platform;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class HealthController
{
    public function live(Request $request): JsonResponse
    {
        return $this->response($request, 'live', ['application' => ['status' => 'up']], 200);
    }

    public function ready(Request $request): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
        ];
        $ready = collect($checks)->every(
            fn (array $check): bool => in_array($check['status'], ['up', 'disabled'], true),
        );

        return $this->response($request, $ready ? 'ready' : 'degraded', $checks, $ready ? 200 : 503);
    }

    /**
     * @return array{status: string, detail?: string}
     */
    private function checkDatabase(): array
    {
        if (! config('platform.health.database')) {
            return ['status' => 'disabled'];
        }

        try {
            DB::select('SELECT 1');

            return ['status' => 'up'];
        } catch (Throwable) {
            return ['status' => 'down', 'detail' => 'connectivity_failed'];
        }
    }

    /**
     * @return array{status: string, detail?: string}
     */
    private function checkRedis(): array
    {
        if (! config('platform.health.redis')) {
            return ['status' => 'disabled'];
        }

        try {
            Redis::connection()->ping();

            return ['status' => 'up'];
        } catch (Throwable) {
            return ['status' => 'down', 'detail' => 'connectivity_failed'];
        }
    }

    /**
     * @return array{status: string, detail?: string}
     */
    private function checkQueue(): array
    {
        if (! config('platform.health.queue')) {
            return ['status' => 'disabled'];
        }

        return config('queue.default') === 'sync'
            ? ['status' => 'down', 'detail' => 'synchronous_driver_not_ready']
            : ['status' => 'up'];
    }

    /**
     * @param  array<string, array{status: string, detail?: string}>  $checks
     */
    private function response(Request $request, string $status, array $checks, int $httpStatus): JsonResponse
    {
        return response()->json([
            'status' => $status,
            'time' => now()->toIso8601String(),
            'version' => config('platform.application_version'),
            'checks' => $checks,
            'correlation_id' => $request->attributes->get('correlation_id'),
        ], $httpStatus);
    }
}
