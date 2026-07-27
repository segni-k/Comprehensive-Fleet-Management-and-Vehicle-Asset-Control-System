<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definitions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('code', 120);
            $table->unsignedInteger('version_number');
            $table->json('name');
            $table->string('process_type', 120);
            $table->char('organization_id', 26)->nullable();
            $table->json('applicability_rules');
            $table->json('assignment_rules');
            $table->json('escalation_rules')->nullable();
            $table->boolean('maker_checker_required')->default(true);
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->string('status', 30)->default('draft');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->unique(['code', 'version_number']);
            $table->index(['process_type', 'organization_id', 'status', 'effective_from'], 'workflow_definition_resolution_idx');
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });

        Schema::create('workflow_states', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workflow_definition_id', 26);
            $table->string('code', 80);
            $table->json('name');
            $table->string('state_type', 30);
            $table->unsignedSmallInteger('sort_order');
            $table->boolean('is_initial')->default(false);
            $table->boolean('is_terminal')->default(false);
            $table->json('service_level')->nullable();
            $table->timestamps(6);
            $table->foreign('workflow_definition_id')->references('id')->on('workflow_definitions')->cascadeOnDelete();
            $table->unique(['workflow_definition_id', 'code']);
            $table->unique(['workflow_definition_id', 'sort_order']);
        });

        Schema::create('workflow_transitions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workflow_definition_id', 26);
            $table->string('code', 80);
            $table->char('from_state_id', 26);
            $table->char('to_state_id', 26);
            $table->string('required_permission', 190);
            $table->json('guard_rules');
            $table->boolean('reason_required')->default(true);
            $table->boolean('maker_checker_required')->default(true);
            $table->boolean('delegation_allowed')->default(true);
            $table->timestamps(6);
            $table->foreign('workflow_definition_id')->references('id')->on('workflow_definitions')->cascadeOnDelete();
            $table->foreign('from_state_id')->references('id')->on('workflow_states')->restrictOnDelete();
            $table->foreign('to_state_id')->references('id')->on('workflow_states')->restrictOnDelete();
            $table->unique(['workflow_definition_id', 'code']);
            $table->index(['from_state_id', 'required_permission']);
        });

        Schema::create('workflow_instances', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workflow_definition_id', 26);
            $table->char('current_state_id', 26);
            $table->char('organization_id', 26);
            $table->string('subject_type', 100);
            $table->char('subject_id', 26);
            $table->char('created_by', 26);
            $table->json('context_snapshot');
            $table->dateTime('due_at', 6)->nullable();
            $table->string('status', 30)->default('active');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('workflow_definition_id')->references('id')->on('workflow_definitions')->restrictOnDelete();
            $table->foreign('current_state_id')->references('id')->on('workflow_states')->restrictOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['subject_type', 'subject_id', 'workflow_definition_id'], 'workflow_instance_subject_unique');
            $table->index(['organization_id', 'status', 'due_at']);
            $table->index(['current_state_id', 'status']);
        });

        Schema::create('workflow_assignments', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workflow_instance_id', 26);
            $table->char('assigned_user_id', 26)->nullable();
            $table->string('required_permission', 190);
            $table->char('organization_id', 26);
            $table->dateTime('assigned_at', 6);
            $table->dateTime('due_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->string('status', 30)->default('open');
            $table->foreign('workflow_instance_id')->references('id')->on('workflow_instances')->cascadeOnDelete();
            $table->foreign('assigned_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->index(['assigned_user_id', 'status', 'due_at']);
            $table->index(['organization_id', 'status', 'due_at']);
        });

        Schema::create('workflow_actions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workflow_instance_id', 26);
            $table->char('transition_id', 26);
            $table->char('from_state_id', 26);
            $table->char('to_state_id', 26);
            $table->char('actor_user_id', 26);
            $table->char('actor_session_id', 26)->nullable();
            $table->char('role_assignment_id', 26)->nullable();
            $table->char('delegation_id', 26)->nullable();
            $table->json('authority_snapshot');
            $table->json('context_snapshot');
            $table->text('reason');
            $table->string('idempotency_key', 190);
            $table->unsignedBigInteger('expected_record_version');
            $table->dateTime('acted_at', 6);
            $table->foreign('workflow_instance_id')->references('id')->on('workflow_instances')->restrictOnDelete();
            $table->foreign('transition_id')->references('id')->on('workflow_transitions')->restrictOnDelete();
            $table->foreign('from_state_id')->references('id')->on('workflow_states')->restrictOnDelete();
            $table->foreign('to_state_id')->references('id')->on('workflow_states')->restrictOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('actor_session_id')->references('id')->on('user_sessions')->nullOnDelete();
            $table->foreign('role_assignment_id')->references('id')->on('user_role_assignments')->nullOnDelete();
            $table->foreign('delegation_id')->references('id')->on('delegations')->nullOnDelete();
            $table->unique(['workflow_instance_id', 'idempotency_key']);
            $table->index(['workflow_instance_id', 'acted_at']);
            $table->index(['actor_user_id', 'acted_at']);
        });

        Schema::create('workflow_comments', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workflow_instance_id', 26);
            $table->char('author_user_id', 26);
            $table->text('body');
            $table->char('document_id', 26)->nullable();
            $table->dateTime('created_at', 6);
            $table->foreign('workflow_instance_id')->references('id')->on('workflow_instances')->restrictOnDelete();
            $table->foreign('author_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('document_id')->references('id')->on('documents')->restrictOnDelete();
            $table->index(['workflow_instance_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_comments');
        Schema::dropIfExists('workflow_actions');
        Schema::dropIfExists('workflow_assignments');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_transitions');
        Schema::dropIfExists('workflow_states');
        Schema::dropIfExists('workflow_definitions');
    }
};
