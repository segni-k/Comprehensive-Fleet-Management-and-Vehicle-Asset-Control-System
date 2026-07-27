<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_categories', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('code', 50)->unique();
            $table->json('name');
            $table->string('classification', 30);
            $table->boolean('allows_children')->default(true);
            $table->boolean('requires_coordinates')->default(false);
            $table->boolean('system_defined')->default(false);
            $table->string('status', 30)->default('active');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
        });

        Schema::create('places', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('code', 80)->unique();
            $table->json('name');
            $table->char('place_category_id', 26);
            $table->char('owning_organization_id', 26);
            $table->char('administrative_organization_id', 26)->nullable();
            $table->string('external_reference', 120)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('elevation_m')->nullable();
            $table->string('timezone', 64)->default('Africa/Addis_Ababa');
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->string('status', 30)->default('draft');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('place_category_id')->references('id')->on('place_categories')->restrictOnDelete();
            $table->foreign('owning_organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('administrative_organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->index(['owning_organization_id', 'status', 'place_category_id'], 'place_owner_status_category_idx');
            $table->index(['administrative_organization_id', 'status'], 'place_admin_org_status_idx');
            $table->index(['latitude', 'longitude']);
        });

        Schema::create('place_addresses', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('place_id', 26);
            $table->string('address_type', 30)->default('physical');
            $table->string('country_code', 2)->default('ET');
            $table->string('region', 120)->nullable();
            $table->string('zone', 120)->nullable();
            $table->string('woreda', 120)->nullable();
            $table->string('city_town', 120)->nullable();
            $table->string('kebele', 120)->nullable();
            $table->string('street', 190)->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->text('directions')->nullable();
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->char('recorded_by', 26);
            $table->timestamps(6);
            $table->foreign('place_id')->references('id')->on('places')->restrictOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['place_id', 'effective_from', 'effective_to'], 'place_address_period_idx');
        });

        Schema::create('place_hierarchy_edges', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('parent_place_id', 26);
            $table->char('child_place_id', 26);
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->text('reason');
            $table->char('approved_by', 26);
            $table->timestamps(6);
            $table->foreign('parent_place_id')->references('id')->on('places')->restrictOnDelete();
            $table->foreign('child_place_id')->references('id')->on('places')->restrictOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['child_place_id', 'effective_from'], 'place_child_effective_unique');
            $table->index(['parent_place_id', 'effective_from', 'effective_to'], 'place_parent_period_idx');
        });

        Schema::create('place_organization_mappings', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('place_id', 26);
            $table->char('organization_id', 26);
            $table->string('mapping_role', 30);
            $table->boolean('is_primary')->default(false);
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->char('recorded_by', 26);
            $table->timestamps(6);
            $table->foreign('place_id')->references('id')->on('places')->restrictOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['place_id', 'organization_id', 'mapping_role', 'effective_from'], 'place_org_mapping_unique');
            $table->index(['organization_id', 'mapping_role', 'effective_to'], 'place_org_mapping_current_idx');
        });

        Schema::create('place_history', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('place_id', 26);
            $table->string('event_type', 50);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot');
            $table->text('reason')->nullable();
            $table->char('changed_by', 26);
            $table->dateTime('effective_at', 6);
            $table->foreign('place_id')->references('id')->on('places')->restrictOnDelete();
            $table->foreign('changed_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['place_id', 'effective_at']);
        });

        Schema::create('location_policy_versions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('place_id', 26);
            $table->unsignedInteger('version');
            $table->unsignedInteger('allowed_radius_m');
            $table->unsignedInteger('maximum_accuracy_m');
            $table->unsignedInteger('maximum_reading_age_seconds');
            $table->boolean('verifier_required')->default(false);
            $table->boolean('qr_required')->default(false);
            $table->boolean('photo_required')->default(false);
            $table->boolean('offline_allowed')->default(true);
            $table->unsignedInteger('maximum_offline_delay_minutes')->default(1440);
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->string('status', 30)->default('draft');
            $table->char('approved_by', 26)->nullable();
            $table->dateTime('approved_at', 6)->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('place_id')->references('id')->on('places')->restrictOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['place_id', 'version']);
            $table->index(['place_id', 'status', 'effective_from', 'effective_to'], 'location_policy_effective_idx');
        });

        Schema::create('operational_zones', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('organization_id', 26);
            $table->string('code', 80);
            $table->json('name');
            $table->text('description')->nullable();
            $table->string('zone_type', 30);
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->string('status', 30)->default('active');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status', 'zone_type']);
        });

        Schema::create('operational_zone_places', function (Blueprint $table): void {
            $table->char('operational_zone_id', 26);
            $table->char('place_id', 26);
            $table->string('membership_type', 30)->default('included');
            $table->boolean('is_primary')->default(false);
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->primary(['operational_zone_id', 'place_id', 'effective_from'], 'operational_zone_place_pk');
            $table->foreign('operational_zone_id')->references('id')->on('operational_zones')->restrictOnDelete();
            $table->foreign('place_id')->references('id')->on('places')->restrictOnDelete();
            $table->index(['place_id', 'effective_to']);
        });

        Schema::create('road_classifications', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('code', 50)->unique();
            $table->json('name');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->string('status', 30)->default('active');
            $table->timestamps(6);
        });

        Schema::create('road_conditions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('code', 50)->unique();
            $table->json('name');
            $table->unsignedSmallInteger('severity')->default(0);
            $table->string('status', 30)->default('active');
            $table->timestamps(6);
        });

        Schema::create('route_masters', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('organization_id', 26);
            $table->string('code', 80);
            $table->json('name');
            $table->char('origin_place_id', 26);
            $table->char('destination_place_id', 26);
            $table->boolean('directional')->default(true);
            $table->string('status', 30)->default('draft');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('origin_place_id')->references('id')->on('places')->restrictOnDelete();
            $table->foreign('destination_place_id')->references('id')->on('places')->restrictOnDelete();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status', 'origin_place_id', 'destination_place_id'], 'route_master_search_idx');
        });

        Schema::create('route_versions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('route_master_id', 26);
            $table->unsignedInteger('version');
            $table->string('alternative_label', 190);
            $table->boolean('preferred')->default(false);
            $table->decimal('estimated_distance_km', 10, 2);
            $table->unsignedInteger('estimated_duration_minutes');
            $table->string('source_type', 30);
            $table->string('source_reference', 500);
            $table->char('source_document_id', 26)->nullable();
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->string('status', 30)->default('draft');
            $table->char('approved_by', 26)->nullable();
            $table->dateTime('approved_at', 6)->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('route_master_id')->references('id')->on('route_masters')->restrictOnDelete();
            $table->foreign('source_document_id')->references('id')->on('documents')->restrictOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['route_master_id', 'version']);
            $table->unique(['route_master_id', 'alternative_label', 'effective_from'], 'route_alternative_period_unique');
            $table->index(['route_master_id', 'status', 'effective_from', 'effective_to'], 'route_version_effective_idx');
        });

        Schema::create('route_segments', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('route_version_id', 26);
            $table->unsignedSmallInteger('sequence');
            $table->char('origin_place_id', 26);
            $table->char('destination_place_id', 26);
            $table->char('road_classification_id', 26)->nullable();
            $table->char('road_condition_id', 26)->nullable();
            $table->decimal('distance_km', 10, 2);
            $table->unsignedInteger('duration_minutes');
            $table->boolean('mandatory_stop')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->foreign('route_version_id')->references('id')->on('route_versions')->restrictOnDelete();
            $table->foreign('origin_place_id')->references('id')->on('places')->restrictOnDelete();
            $table->foreign('destination_place_id')->references('id')->on('places')->restrictOnDelete();
            $table->foreign('road_classification_id')->references('id')->on('road_classifications')->restrictOnDelete();
            $table->foreign('road_condition_id')->references('id')->on('road_conditions')->restrictOnDelete();
            $table->unique(['route_version_id', 'sequence']);
            $table->index(['origin_place_id', 'destination_place_id']);
        });

        Schema::create('route_restrictions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('route_version_id', 26);
            $table->char('route_segment_id', 26)->nullable();
            $table->string('restriction_type', 50);
            $table->text('description');
            $table->decimal('maximum_weight_kg', 12, 2)->nullable();
            $table->decimal('maximum_height_m', 6, 2)->nullable();
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamps(6);
            $table->foreign('route_version_id')->references('id')->on('route_versions')->restrictOnDelete();
            $table->foreign('route_segment_id')->references('id')->on('route_segments')->restrictOnDelete();
            $table->index(['route_version_id', 'status', 'effective_from', 'effective_to'], 'route_restriction_effective_idx');
        });

        Schema::create('distance_reference_versions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('organization_id', 26);
            $table->string('code', 80);
            $table->string('name', 200);
            $table->string('source_type', 30);
            $table->string('source_reference', 500);
            $table->char('source_document_id', 26)->nullable();
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->string('status', 30)->default('draft');
            $table->char('approved_by', 26)->nullable();
            $table->dateTime('approved_at', 6)->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('source_document_id')->references('id')->on('documents')->restrictOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status', 'effective_from', 'effective_to'], 'distance_version_effective_idx');
        });

        Schema::create('distance_reference_legs', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('version_id', 26);
            $table->char('origin_place_id', 26);
            $table->char('destination_place_id', 26);
            $table->char('route_version_id', 26)->nullable();
            $table->string('route_label', 200)->nullable();
            $table->decimal('distance_km', 10, 2);
            $table->unsignedInteger('estimated_duration_minutes')->nullable();
            $table->boolean('directional')->default(false);
            $table->decimal('tolerance_percent', 6, 3)->nullable();
            $table->timestamps(6);
            $table->foreign('version_id')->references('id')->on('distance_reference_versions')->restrictOnDelete();
            $table->foreign('origin_place_id')->references('id')->on('places')->restrictOnDelete();
            $table->foreign('destination_place_id')->references('id')->on('places')->restrictOnDelete();
            $table->foreign('route_version_id')->references('id')->on('route_versions')->restrictOnDelete();
            $table->unique(['version_id', 'origin_place_id', 'destination_place_id', 'route_label'], 'distance_leg_route_unique');
            $table->index(['origin_place_id', 'destination_place_id', 'version_id'], 'distance_leg_lookup_idx');
        });

        Schema::create('route_distance_imports', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('organization_id', 26);
            $table->string('import_type', 30);
            $table->string('source_name', 190);
            $table->char('source_checksum', 64);
            $table->char('document_id', 26)->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('valid_row_count')->default(0);
            $table->unsignedInteger('invalid_row_count')->default(0);
            $table->json('validation_summary')->nullable();
            $table->string('status', 30)->default('validated');
            $table->char('imported_by', 26);
            $table->char('approved_by', 26)->nullable();
            $table->dateTime('approved_at', 6)->nullable();
            $table->char('rolled_back_by', 26)->nullable();
            $table->dateTime('rolled_back_at', 6)->nullable();
            $table->text('rollback_reason')->nullable();
            $table->timestamps(6);
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('document_id')->references('id')->on('documents')->restrictOnDelete();
            $table->foreign('imported_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('rolled_back_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['organization_id', 'source_checksum']);
            $table->index(['organization_id', 'status', 'created_at']);
        });

        Schema::create('route_distance_import_rows', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('import_id', 26);
            $table->unsignedInteger('row_number');
            $table->json('normalized_payload');
            $table->json('validation_errors')->nullable();
            $table->string('status', 30);
            $table->string('applied_entity_type', 50)->nullable();
            $table->char('applied_entity_id', 26)->nullable();
            $table->timestamps(6);
            $table->foreign('import_id')->references('id')->on('route_distance_imports')->restrictOnDelete();
            $table->unique(['import_id', 'row_number']);
            $table->index(['import_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_distance_import_rows');
        Schema::dropIfExists('route_distance_imports');
        Schema::dropIfExists('distance_reference_legs');
        Schema::dropIfExists('distance_reference_versions');
        Schema::dropIfExists('route_restrictions');
        Schema::dropIfExists('route_segments');
        Schema::dropIfExists('route_versions');
        Schema::dropIfExists('route_masters');
        Schema::dropIfExists('road_conditions');
        Schema::dropIfExists('road_classifications');
        Schema::dropIfExists('operational_zone_places');
        Schema::dropIfExists('operational_zones');
        Schema::dropIfExists('location_policy_versions');
        Schema::dropIfExists('place_history');
        Schema::dropIfExists('place_organization_mappings');
        Schema::dropIfExists('place_hierarchy_edges');
        Schema::dropIfExists('place_addresses');
        Schema::dropIfExists('places');
        Schema::dropIfExists('place_categories');
    }
};
