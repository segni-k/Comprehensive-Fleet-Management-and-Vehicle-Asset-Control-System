<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_hierarchy_move_previews', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('source_organization_id', 26);
            $table->char('current_parent_id', 26)->nullable();
            $table->char('proposed_parent_id', 26);
            $table->dateTime('requested_effective_at', 6);
            $table->text('reason');
            $table->json('snapshot');
            $table->unsignedBigInteger('tree_version');
            $table->unsignedBigInteger('preview_version')->default(1);
            $table->dateTime('expires_at', 6);
            $table->string('status', 30)->default('active');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('source_organization_id', 'org_move_preview_source_fk')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('current_parent_id', 'org_move_preview_current_parent_fk')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('proposed_parent_id', 'org_move_preview_proposed_parent_fk')->references('id')->on('organizations')->restrictOnDelete();
            $table->index(['status', 'expires_at']);
        });

        Schema::create('organization_hierarchy_move_requests', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('preview_id', 26);
            $table->char('source_organization_id', 26);
            $table->char('proposed_parent_id', 26);
            $table->unsignedBigInteger('preview_version');
            $table->dateTime('requested_effective_at', 6);
            $table->dateTime('scheduled_at', 6)->nullable();
            $table->text('reason');
            $table->string('approval_status', 30)->default('pending');
            $table->boolean('maker_checker_required')->default(true);
            $table->string('application_status', 30)->default('not_scheduled');
            $table->text('failure_reason')->nullable();
            $table->string('requested_by', 190);
            $table->string('decided_by', 190)->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('preview_id', 'org_move_request_preview_fk')->references('id')->on('organization_hierarchy_move_previews')->restrictOnDelete();
            $table->foreign('source_organization_id', 'org_move_request_source_fk')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('proposed_parent_id', 'org_move_request_parent_fk')->references('id')->on('organizations')->restrictOnDelete();
            $table->index(['approval_status', 'application_status', 'scheduled_at'], 'organization_move_due_idx');
        });

        Schema::create('organization_hierarchy_move_impacts', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('preview_id', 26);
            $table->string('impact_type', 50);
            $table->string('subject_type', 80);
            $table->char('subject_id', 26)->nullable();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->string('resolution', 50)->nullable();
            $table->timestamps(6);
            $table->foreign('preview_id', 'org_move_impact_preview_fk')->references('id')->on('organization_hierarchy_move_previews')->restrictOnDelete();
            $table->index(['preview_id', 'impact_type']);
        });

        Schema::create('organization_hierarchy_change_history', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('event_type', 120);
            $table->string('subject_type', 80);
            $table->char('subject_id', 26);
            $table->char('organization_id', 26)->nullable();
            $table->string('actor_reference', 190);
            $table->text('reason')->nullable();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->char('correlation_id', 36)->nullable();
            $table->dateTime('occurred_at', 6);
            $table->timestamps(6);
            $table->index(['subject_type', 'subject_id', 'occurred_at'], 'organization_history_subject_idx');
            $table->index(['organization_id', 'occurred_at'], 'organization_history_org_time_idx');
            $table->index(['event_type', 'occurred_at'], 'organization_history_event_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_hierarchy_change_history');
        Schema::dropIfExists('organization_hierarchy_move_impacts');
        Schema::dropIfExists('organization_hierarchy_move_requests');
        Schema::dropIfExists('organization_hierarchy_move_previews');
    }
};
