<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('code', 190)->unique();
            $table->string('domain', 80);
            $table->text('description');
            $table->json('allowed_scope_modes');
            $table->json('resource_types')->nullable();
            $table->boolean('delegable')->default(false);
            $table->boolean('requires_mfa')->default(false);
            $table->boolean('requires_step_up')->default(false);
            $table->boolean('maker_checker_required')->default(false);
            $table->string('status', 30)->default('inactive');
            $table->timestamps(6);
            $table->index(['domain', 'status']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('code', 120);
            $table->json('name');
            $table->text('description');
            $table->boolean('is_privileged')->default(false);
            $table->string('status', 30)->default('draft');
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->unique(['code', 'effective_from'], 'role_code_version_unique');
            $table->index(['status', 'effective_from', 'effective_to']);
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->char('role_id', 26);
            $table->char('permission_id', 26);
            $table->json('constraints')->nullable();
            $table->timestamps(6);
            $table->primary(['role_id', 'permission_id']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->restrictOnDelete();
        });

        Schema::create('user_role_assignments', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26);
            $table->char('role_id', 26);
            $table->char('organization_id', 26);
            $table->string('scope_mode', 40);
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->char('requested_by', 26);
            $table->char('approved_by', 26)->nullable();
            $table->char('assigned_by', 26);
            $table->json('assignment_authority_snapshot');
            $table->string('status', 30)->default('pending');
            $table->text('reason');
            $table->dateTime('approved_at', 6)->nullable();
            $table->dateTime('revoked_at', 6)->nullable();
            $table->char('revoked_by', 26)->nullable();
            $table->text('revocation_reason')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('revoked_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(
                ['user_id', 'role_id', 'organization_id', 'scope_mode', 'effective_from'],
                'user_role_assignment_version_unique',
            );
            $table->index(
                ['user_id', 'status', 'effective_from', 'effective_to'],
                'user_role_assignment_effective_idx',
            );
            $table->index(['organization_id', 'scope_mode', 'status']);
        });

        Schema::create('role_assignment_scope_grants', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('assignment_id', 26);
            $table->string('grant_type', 40);
            $table->char('organization_id', 26)->nullable();
            $table->string('resource_type', 120)->nullable();
            $table->char('resource_id', 26)->nullable();
            $table->timestamps(6);
            $table->foreign('assignment_id')->references('id')->on('user_role_assignments')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->unique(
                ['assignment_id', 'grant_type', 'organization_id', 'resource_type', 'resource_id'],
                'role_assignment_scope_grant_unique',
            );
            $table->index(['organization_id', 'grant_type']);
            $table->index(['resource_type', 'resource_id']);
        });

        Schema::create('delegations', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('delegator_user_id', 26);
            $table->char('delegatee_user_id', 26);
            $table->char('source_assignment_id', 26);
            $table->char('organization_id', 26);
            $table->string('scope_mode', 40);
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6);
            $table->char('requested_by', 26);
            $table->char('approved_by', 26)->nullable();
            $table->char('revoked_by', 26)->nullable();
            $table->dateTime('revoked_at', 6)->nullable();
            $table->text('revocation_reason')->nullable();
            $table->json('authority_snapshot');
            $table->string('status', 30)->default('pending');
            $table->text('reason');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('delegator_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('delegatee_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('source_assignment_id')->references('id')->on('user_role_assignments')->restrictOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('revoked_by')->references('id')->on('users')->nullOnDelete();
            $table->index(
                ['delegatee_user_id', 'status', 'effective_from', 'effective_to'],
                'delegation_effective_idx',
            );
            $table->index(['delegator_user_id', 'status']);
        });

        Schema::create('delegation_permissions', function (Blueprint $table): void {
            $table->char('delegation_id', 26);
            $table->char('permission_id', 26);
            $table->primary(['delegation_id', 'permission_id']);
            $table->foreign('delegation_id')->references('id')->on('delegations')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->restrictOnDelete();
        });

        Schema::create('delegation_scope_grants', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('delegation_id', 26);
            $table->string('grant_type', 40);
            $table->char('organization_id', 26)->nullable();
            $table->string('resource_type', 120)->nullable();
            $table->char('resource_id', 26)->nullable();
            $table->timestamps(6);
            $table->foreign('delegation_id')->references('id')->on('delegations')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->index(['organization_id', 'grant_type']);
            $table->index(['resource_type', 'resource_id']);
        });

        Schema::create('role_approval_authorities', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('role_id', 26);
            $table->string('authority_code', 190);
            $table->string('resource_type', 120);
            $table->string('action', 120);
            $table->decimal('amount_limit', 19, 4)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('risk_ceiling', 30)->nullable();
            $table->json('conditions')->nullable();
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->string('status', 30)->default('inactive');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->unique(['role_id', 'authority_code', 'effective_from'], 'role_authority_version_unique');
            $table->index(['resource_type', 'action', 'status', 'effective_from', 'effective_to'], 'role_authority_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_approval_authorities');
        Schema::dropIfExists('delegation_scope_grants');
        Schema::dropIfExists('delegation_permissions');
        Schema::dropIfExists('delegations');
        Schema::dropIfExists('role_assignment_scope_grants');
        Schema::dropIfExists('user_role_assignments');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
