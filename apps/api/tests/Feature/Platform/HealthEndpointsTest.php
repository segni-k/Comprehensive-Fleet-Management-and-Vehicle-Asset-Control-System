<?php

namespace Tests\Feature\Platform;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class HealthEndpointsTest extends TestCase
{
    public function test_liveness_returns_platform_metadata_and_correlation_id(): void
    {
        $correlationId = (string) Str::uuid();

        $response = $this->withHeader('X-Correlation-ID', $correlationId)
            ->getJson('/api/v1/health/live');

        $response->assertOk()
            ->assertHeader('X-Correlation-ID', $correlationId)
            ->assertJsonPath('status', 'live')
            ->assertJsonPath('correlation_id', $correlationId)
            ->assertJsonStructure(['time', 'version', 'checks' => ['application' => ['status']]]);
    }

    public function test_readiness_reports_disabled_optional_dependencies_as_ready(): void
    {
        config([
            'platform.health.database' => false,
            'platform.health.redis' => false,
            'platform.health.queue' => false,
        ]);

        $this->getJson('/api/v1/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.database.status', 'disabled')
            ->assertJsonPath('checks.redis.status', 'disabled')
            ->assertJsonPath('checks.queue.status', 'disabled');
    }

    public function test_readiness_degrades_when_queue_is_synchronous(): void
    {
        config([
            'platform.health.database' => false,
            'platform.health.redis' => false,
            'platform.health.queue' => true,
            'queue.default' => 'sync',
        ]);

        $this->getJson('/api/v1/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.queue.detail', 'synchronous_driver_not_ready');
    }

    public function test_readiness_degrades_without_exposing_redis_connection_details(): void
    {
        config([
            'platform.health.database' => false,
            'platform.health.redis' => true,
            'platform.health.queue' => false,
        ]);
        Redis::shouldReceive('connection')->once()->andThrow(new RuntimeException('redis-secret'));

        $this->getJson('/api/v1/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.redis.detail', 'connectivity_failed')
            ->assertJsonMissing(['redis-secret']);
    }
}
