<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_categories', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('code', 50)->unique();
            $table->json('name');
            $table->string('status', 30)->default('active');
            $table->timestamps(6);
        });

        Schema::create('vehicle_classes', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('vehicle_category_id', 26);
            $table->string('code', 50)->unique();
            $table->json('name');
            $table->decimal('default_capacity_kg', 12, 2)->nullable();
            $table->unsignedSmallInteger('default_seating_capacity')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamps(6);
            $table->foreign('vehicle_category_id')->references('id')->on('vehicle_categories')->restrictOnDelete();
        });

        Schema::create('vehicle_manufacturers', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('code', 50)->unique();
            $table->string('name', 120)->unique();
            $table->string('status', 30)->default('active');
            $table->timestamps(6);
        });

        Schema::create('vehicle_models', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('manufacturer_id', 26);
            $table->string('code', 80);
            $table->string('name', 120);
            $table->string('status', 30)->default('active');
            $table->timestamps(6);
            $table->foreign('manufacturer_id')->references('id')->on('vehicle_manufacturers')->restrictOnDelete();
            $table->unique(['manufacturer_id', 'code']);
        });

        Schema::create('vehicle_trims', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('vehicle_model_id', 26);
            $table->string('code', 80);
            $table->string('name', 120);
            $table->string('status', 30)->default('active');
            $table->timestamps(6);
            $table->foreign('vehicle_model_id')->references('id')->on('vehicle_models')->restrictOnDelete();
            $table->unique(['vehicle_model_id', 'code']);
        });

        Schema::create('fleet_units', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('organization_id', 26);
            $table->string('code', 80);
            $table->json('name');
            $table->string('physical_base', 255)->nullable();
            $table->string('cost_reference', 120)->nullable();
            $table->string('status', 30)->default('active');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('vehicles', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('asset_number', 80)->unique();
            $table->string('vin', 17)->nullable()->unique();
            $table->string('chassis_number', 100)->unique();
            $table->string('engine_number', 100)->nullable()->unique();
            $table->string('current_plate_number', 40)->nullable()->unique();
            $table->string('registration_number', 100)->nullable();
            $table->char('vehicle_category_id', 26);
            $table->char('vehicle_class_id', 26);
            $table->char('manufacturer_id', 26);
            $table->char('vehicle_model_id', 26);
            $table->char('vehicle_trim_id', 26)->nullable();
            $table->char('owning_organization_id', 26);
            $table->char('custodian_organization_id', 26);
            $table->char('fleet_unit_id', 26)->nullable();
            $table->string('ownership_type', 30);
            $table->unsignedSmallInteger('model_year')->nullable();
            $table->string('color', 60)->nullable();
            $table->string('fuel_type', 30);
            $table->string('transmission', 30);
            $table->decimal('capacity_kg', 12, 2)->nullable();
            $table->unsignedSmallInteger('seating_capacity')->nullable();
            $table->string('acquisition_method', 40)->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_value', 18, 2)->nullable();
            $table->string('supplier_reference', 190)->nullable();
            $table->string('funding_source', 120)->nullable();
            $table->date('commissioned_on')->nullable();
            $table->decimal('baseline_odometer_km', 14, 1)->default(0);
            $table->decimal('current_odometer_km', 14, 1)->default(0);
            $table->string('status', 30)->default('draft');
            $table->dateTime('retired_at', 6)->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('vehicle_category_id')->references('id')->on('vehicle_categories')->restrictOnDelete();
            $table->foreign('vehicle_class_id')->references('id')->on('vehicle_classes')->restrictOnDelete();
            $table->foreign('manufacturer_id')->references('id')->on('vehicle_manufacturers')->restrictOnDelete();
            $table->foreign('vehicle_model_id')->references('id')->on('vehicle_models')->restrictOnDelete();
            $table->foreign('vehicle_trim_id')->references('id')->on('vehicle_trims')->restrictOnDelete();
            $table->foreign('owning_organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('custodian_organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('fleet_unit_id')->references('id')->on('fleet_units')->restrictOnDelete();
            $table->index(['custodian_organization_id', 'status']);
            $table->index(['custodian_organization_id', 'current_plate_number'], 'vehicle_org_plate_idx');
            $table->index(['vehicle_class_id', 'status']);
            $table->index(['fleet_unit_id', 'status']);
        });

        Schema::create('vehicle_plate_history', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('vehicle_id', 26);
            $table->string('plate_number', 40);
            $table->string('issuing_region', 100)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->string('status', 30)->default('active');
            $table->string('change_reason', 500)->nullable();
            $table->char('changed_by', 26);
            $table->timestamps(6);
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->restrictOnDelete();
            $table->foreign('changed_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['vehicle_id', 'effective_from']);
            $table->index(['plate_number', 'status']);
        });

        Schema::create('vehicle_status_history', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('vehicle_id', 26);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('reason')->nullable();
            $table->char('changed_by', 26);
            $table->dateTime('effective_at', 6);
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->restrictOnDelete();
            $table->foreign('changed_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['vehicle_id', 'effective_at']);
        });

        Schema::create('vehicle_ownership_history', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('vehicle_id', 26);
            $table->char('owning_organization_id', 26);
            $table->char('custodian_organization_id', 26);
            $table->string('ownership_type', 30);
            $table->string('transfer_reference', 190)->nullable();
            $table->text('reason')->nullable();
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->char('recorded_by', 26);
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->restrictOnDelete();
            $table->foreign('owning_organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('custodian_organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['vehicle_id', 'effective_from']);
            $table->index(['custodian_organization_id', 'effective_to'], 'vehicle_ownership_current_idx');
        });

        Schema::create('vehicle_fleet_assignments', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('vehicle_id', 26);
            $table->char('fleet_unit_id', 26);
            $table->dateTime('starts_at', 6);
            $table->dateTime('ends_at', 6)->nullable();
            $table->text('reason')->nullable();
            $table->char('assigned_by', 26);
            $table->string('status', 30)->default('active');
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->restrictOnDelete();
            $table->foreign('fleet_unit_id')->references('id')->on('fleet_units')->restrictOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['vehicle_id', 'starts_at']);
            $table->index(['fleet_unit_id', 'status']);
        });

        Schema::create('vehicle_odometer_readings', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('vehicle_id', 26);
            $table->decimal('reading_km', 14, 1);
            $table->string('source', 50);
            $table->dateTime('recorded_at', 6);
            $table->char('recorded_by', 26);
            $table->char('document_id', 26)->nullable();
            $table->text('notes')->nullable();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->restrictOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('document_id')->references('id')->on('documents')->restrictOnDelete();
            $table->index(['vehicle_id', 'recorded_at']);
        });

        Schema::create('drivers', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26)->nullable()->unique();
            $table->string('employee_number', 80)->unique();
            $table->char('organization_id', 26);
            $table->string('full_name', 190);
            $table->text('phone_encrypted')->nullable();
            $table->text('email_encrypted')->nullable();
            $table->text('emergency_contact_encrypted')->nullable();
            $table->string('employment_status', 30)->default('active');
            $table->string('status', 30)->default('active');
            $table->string('availability_status', 30)->default('available');
            $table->date('hired_on')->nullable();
            $table->date('terminated_on')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->index(['organization_id', 'status', 'availability_status']);
            $table->index(['organization_id', 'full_name'], 'driver_org_name_idx');
        });

        Schema::create('driver_status_history', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('driver_id', 26);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('availability_status', 30);
            $table->text('reason')->nullable();
            $table->char('changed_by', 26);
            $table->dateTime('effective_at', 6);
            $table->foreign('driver_id')->references('id')->on('drivers')->restrictOnDelete();
            $table->foreign('changed_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['driver_id', 'effective_at']);
        });

        Schema::create('driver_licence_classes', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('code', 50)->unique();
            $table->json('name');
            $table->string('status', 30)->default('active');
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->timestamps(6);
        });

        Schema::create('vehicle_class_licence_classes', function (Blueprint $table): void {
            $table->char('vehicle_class_id', 26);
            $table->char('driver_licence_class_id', 26);
            $table->primary(['vehicle_class_id', 'driver_licence_class_id'], 'vehicle_licence_class_pk');
            $table->foreign('vehicle_class_id')->references('id')->on('vehicle_classes')->restrictOnDelete();
            $table->foreign('driver_licence_class_id')->references('id')->on('driver_licence_classes')->restrictOnDelete();
        });

        Schema::create('driver_licences', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('driver_id', 26);
            $table->text('licence_number_encrypted');
            $table->char('licence_number_hash', 64)->unique();
            $table->string('issuing_authority', 190);
            $table->date('issued_on')->nullable();
            $table->date('expires_on');
            $table->string('status', 30)->default('pending_verification');
            $table->char('supersedes_licence_id', 26)->nullable();
            $table->char('document_id', 26)->nullable();
            $table->dateTime('verified_at', 6)->nullable();
            $table->char('verified_by', 26)->nullable();
            $table->timestamps(6);
            $table->foreign('driver_id')->references('id')->on('drivers')->restrictOnDelete();
            $table->foreign('supersedes_licence_id')->references('id')->on('driver_licences')->restrictOnDelete();
            $table->foreign('document_id')->references('id')->on('documents')->restrictOnDelete();
            $table->foreign('verified_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['driver_id', 'status', 'expires_on']);
            $table->index(['expires_on', 'status']);
        });

        Schema::create('driver_licence_class_assignments', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('driver_licence_id', 26);
            $table->char('driver_licence_class_id', 26);
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->foreign('driver_licence_id')->references('id')->on('driver_licences')->restrictOnDelete();
            $table->foreign('driver_licence_class_id')->references('id')->on('driver_licence_classes')->restrictOnDelete();
            $table->unique(['driver_licence_id', 'driver_licence_class_id', 'effective_from'], 'driver_licence_class_term_unique');
        });

        Schema::create('driver_qualifications', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('driver_id', 26);
            $table->string('code', 80);
            $table->string('title', 190);
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->char('document_id', 26)->nullable();
            $table->string('status', 30)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->foreign('driver_id')->references('id')->on('drivers')->restrictOnDelete();
            $table->foreign('document_id')->references('id')->on('documents')->restrictOnDelete();
            $table->index(['driver_id', 'status', 'expires_on']);
        });

        Schema::create('driver_restrictions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('driver_id', 26);
            $table->string('code', 80);
            $table->text('description');
            $table->dateTime('starts_at', 6);
            $table->dateTime('ends_at', 6)->nullable();
            $table->string('status', 30)->default('active');
            $table->char('imposed_by', 26);
            $table->text('reason');
            $table->timestamps(6);
            $table->foreign('driver_id')->references('id')->on('drivers')->restrictOnDelete();
            $table->foreign('imposed_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['driver_id', 'status', 'starts_at', 'ends_at']);
        });

        Schema::create('vehicle_driver_assignments', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('vehicle_id', 26);
            $table->char('driver_id', 26);
            $table->char('organization_id', 26);
            $table->string('assignment_type', 30);
            $table->boolean('exclusive')->default(true);
            $table->dateTime('starts_at', 6);
            $table->dateTime('ends_at', 6)->nullable();
            $table->text('reason');
            $table->char('approved_by', 26)->nullable();
            $table->char('assigned_by', 26);
            $table->decimal('handover_odometer_km', 14, 1)->nullable();
            $table->string('handover_fuel_level', 30)->nullable();
            $table->boolean('keys_handed_over')->default(false);
            $table->boolean('documents_handed_over')->default(false);
            $table->text('condition_notes')->nullable();
            $table->boolean('acknowledgement_required')->default(false);
            $table->dateTime('acknowledged_at', 6)->nullable();
            $table->string('status', 30)->default('active');
            $table->dateTime('closed_at', 6)->nullable();
            $table->char('closed_by', 26)->nullable();
            $table->text('closure_reason')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->restrictOnDelete();
            $table->foreign('driver_id')->references('id')->on('drivers')->restrictOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('closed_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['vehicle_id', 'status', 'starts_at', 'ends_at'], 'assignment_vehicle_period_idx');
            $table->index(['driver_id', 'status', 'starts_at', 'ends_at'], 'assignment_driver_period_idx');
            $table->index(['organization_id', 'status']);
        });

        Schema::create('fleet_compliance_records', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('entity_type', 30);
            $table->char('entity_id', 26);
            $table->char('organization_id', 26);
            $table->string('document_type', 50);
            $table->text('document_number_encrypted')->nullable();
            $table->char('document_number_hash', 64)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->char('document_id', 26)->nullable();
            $table->string('status', 30)->default('current');
            $table->char('supersedes_record_id', 26)->nullable();
            $table->timestamps(6);
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('document_id')->references('id')->on('documents')->restrictOnDelete();
            $table->foreign('supersedes_record_id')->references('id')->on('fleet_compliance_records')->restrictOnDelete();
            $table->index(['entity_type', 'entity_id', 'status']);
            $table->index(['organization_id', 'expires_on', 'status'], 'fleet_compliance_expiry_idx');
            $table->index('document_number_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_compliance_records');
        Schema::dropIfExists('vehicle_driver_assignments');
        Schema::dropIfExists('driver_restrictions');
        Schema::dropIfExists('driver_qualifications');
        Schema::dropIfExists('driver_licence_class_assignments');
        Schema::dropIfExists('driver_licences');
        Schema::dropIfExists('vehicle_class_licence_classes');
        Schema::dropIfExists('driver_licence_classes');
        Schema::dropIfExists('driver_status_history');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('vehicle_odometer_readings');
        Schema::dropIfExists('vehicle_fleet_assignments');
        Schema::dropIfExists('vehicle_ownership_history');
        Schema::dropIfExists('vehicle_status_history');
        Schema::dropIfExists('vehicle_plate_history');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('fleet_units');
        Schema::dropIfExists('vehicle_trims');
        Schema::dropIfExists('vehicle_models');
        Schema::dropIfExists('vehicle_manufacturers');
        Schema::dropIfExists('vehicle_classes');
        Schema::dropIfExists('vehicle_categories');
    }
};
