<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MigrationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_approved_platform_through_milestone_three_tables_are_created(): void
    {
        foreach (['application_versions', 'idempotency_keys', 'outbox_messages', 'cache', 'jobs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected foundation table {$table}");
        }

        foreach (['organization_types', 'organization_type_rules', 'organizations', 'organization_hierarchy_edges', 'organization_hierarchy_move_requests'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected Milestone 2 table {$table}");
        }

        foreach ([
            'users', 'user_sessions', 'user_mfa_methods', 'permissions', 'roles',
            'user_role_assignments', 'delegations', 'access_reviews', 'break_glass_access',
            'identity_access_audit_events',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected Milestone 3 table {$table}");
        }

        foreach (['vehicles', 'drivers', 'trips'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Later-milestone table {$table} must not exist");
        }
    }
}
