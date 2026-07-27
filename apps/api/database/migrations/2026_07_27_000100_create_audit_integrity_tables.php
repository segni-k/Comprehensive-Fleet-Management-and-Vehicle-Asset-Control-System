<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->unsignedBigInteger('sequence');
            $table->string('partition_key', 120);
            $table->string('event_type', 190);
            $table->string('category', 80);
            $table->string('action', 120);
            $table->string('outcome', 40);
            $table->string('severity', 30);
            $table->string('priority', 30)->default('normal');
            $table->char('actor_user_id', 26)->nullable();
            $table->char('actor_session_id', 26)->nullable();
            $table->char('impersonator_user_id', 26)->nullable();
            $table->char('delegation_id', 26)->nullable();
            $table->char('organization_id', 26)->nullable();
            $table->string('subject_type', 100);
            $table->char('subject_id', 26)->nullable();
            $table->char('request_id', 36)->nullable();
            $table->char('correlation_id', 36)->nullable();
            $table->char('causation_id', 36)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->text('reason')->nullable();
            $table->char('approval_reference', 26)->nullable();
            $table->char('workflow_reference', 26)->nullable();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->json('changed_fields')->nullable();
            $table->json('metadata')->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->char('event_hash', 64)->unique();
            $table->dateTime('occurred_at', 6);
            $table->dateTime('created_at', 6);

            $table->unique(['partition_key', 'sequence'], 'audit_partition_sequence_unique');
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index(['actor_user_id', 'occurred_at']);
            $table->index(['correlation_id', 'occurred_at']);
            $table->index(['category', 'severity', 'occurred_at']);
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('actor_session_id')->references('id')->on('user_sessions')->nullOnDelete();
            $table->foreign('impersonator_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('delegation_id')->references('id')->on('delegations')->nullOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });

        Schema::create('audit_chain_checkpoints', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('partition_key', 120);
            $table->unsignedBigInteger('last_sequence');
            $table->char('last_event_hash', 64);
            $table->string('verification_status', 30)->default('verified');
            $table->dateTime('verified_at', 6);
            $table->char('verified_by', 26)->nullable();
            $table->json('verification_details')->nullable();
            $table->timestamps(6);
            $table->unique('partition_key');
            $table->foreign('verified_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_checkpoints');
        Schema::dropIfExists('audit_events');
    }
};
