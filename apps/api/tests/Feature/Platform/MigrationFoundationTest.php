<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MigrationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_approved_platform_through_milestone_six_tables_are_created(): void
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
            'audit_events', 'audit_chain_checkpoints', 'documents', 'document_versions',
            'workflow_definitions', 'workflow_instances', 'workflow_actions',
            'notification_templates', 'notifications', 'notification_delivery_attempts',
            'outbox_dead_letters', 'outbox_consumer_receipts',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected Milestone 3 or 4 table {$table}");
        }

        foreach ([
            'vehicle_categories', 'vehicle_classes', 'vehicle_manufacturers',
            'vehicle_models', 'vehicle_trims', 'fleet_units', 'vehicles',
            'vehicle_plate_history', 'vehicle_status_history',
            'vehicle_ownership_history', 'vehicle_fleet_assignments',
            'vehicle_odometer_readings', 'drivers', 'driver_status_history',
            'driver_licence_classes', 'vehicle_class_licence_classes',
            'driver_licences', 'driver_licence_class_assignments',
            'driver_qualifications', 'driver_restrictions',
            'vehicle_driver_assignments', 'fleet_compliance_records',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected Milestone 5 table {$table}");
        }

        foreach ([
            'place_categories', 'places', 'place_addresses',
            'place_hierarchy_edges', 'place_organization_mappings',
            'place_history', 'location_policy_versions', 'operational_zones',
            'operational_zone_places', 'road_classifications',
            'road_conditions', 'route_masters', 'route_versions',
            'route_segments', 'route_restrictions',
            'distance_reference_versions', 'distance_reference_legs',
            'route_distance_imports',
            'route_distance_import_rows',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected Milestone 6 table {$table}");
        }

        foreach (['trips', 'fuel_vouchers', 'work_orders', 'stock_movements'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Later-milestone table {$table} must not exist");
        }
    }
}
