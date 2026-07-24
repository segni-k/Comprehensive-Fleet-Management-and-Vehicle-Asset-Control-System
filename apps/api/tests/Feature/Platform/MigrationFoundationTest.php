<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MigrationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_approved_foundation_tables_are_created(): void
    {
        foreach (['application_versions', 'idempotency_keys', 'outbox_messages', 'cache', 'jobs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected foundation table {$table}");
        }

        foreach (['users', 'organizations', 'vehicles', 'drivers', 'trips'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Business table {$table} must not exist");
        }
    }
}
