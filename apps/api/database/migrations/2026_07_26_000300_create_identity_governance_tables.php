<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_reviews', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('organization_id', 26)->nullable();
            $table->char('requested_by', 26);
            $table->char('reviewer_id', 26);
            $table->string('review_type', 50);
            $table->json('criteria');
            $table->dateTime('due_at', 6);
            $table->dateTime('completed_at', 6)->nullable();
            $table->string('status', 30)->default('open');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reviewer_id')->references('id')->on('users')->restrictOnDelete();
            $table->index(['reviewer_id', 'status', 'due_at']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('access_review_items', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('access_review_id', 26);
            $table->string('subject_type', 50);
            $table->char('subject_id', 26);
            $table->json('authority_snapshot');
            $table->string('decision', 30)->nullable();
            $table->text('review_notes')->nullable();
            $table->char('decided_by', 26)->nullable();
            $table->dateTime('decided_at', 6)->nullable();
            $table->timestamps(6);
            $table->foreign('access_review_id')->references('id')->on('access_reviews')->cascadeOnDelete();
            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['access_review_id', 'subject_type', 'subject_id'], 'access_review_subject_unique');
        });

        Schema::create('break_glass_access', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26);
            $table->char('organization_id', 26)->nullable();
            $table->char('requested_session_id', 26);
            $table->json('permission_codes');
            $table->text('reason');
            $table->dateTime('started_at', 6);
            $table->dateTime('expires_at', 6);
            $table->dateTime('ended_at', 6)->nullable();
            $table->char('ended_by', 26)->nullable();
            $table->string('status', 30)->default('active');
            $table->char('reviewed_by', 26)->nullable();
            $table->string('review_decision', 30)->nullable();
            $table->text('review_notes')->nullable();
            $table->dateTime('reviewed_at', 6)->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('requested_session_id')->references('id')->on('user_sessions')->restrictOnDelete();
            $table->foreign('ended_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['user_id', 'status', 'expires_at']);
            $table->index(['reviewed_at', 'status']);
        });

        Schema::create('identity_security_alerts', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('alert_type', 80);
            $table->string('severity', 30);
            $table->char('user_id', 26)->nullable();
            $table->string('subject_type', 80);
            $table->char('subject_id', 26);
            $table->json('payload');
            $table->string('status', 30)->default('open');
            $table->dateTime('acknowledged_at', 6)->nullable();
            $table->char('acknowledged_by', 26)->nullable();
            $table->dateTime('created_at', 6);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('acknowledged_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['severity', 'status', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('identity_access_audit_events', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('event_type', 190);
            $table->char('actor_user_id', 26)->nullable();
            $table->char('actor_session_id', 26)->nullable();
            $table->char('organization_id', 26)->nullable();
            $table->string('subject_type', 80);
            $table->char('subject_id', 26)->nullable();
            $table->string('outcome', 40);
            $table->string('priority', 30)->default('normal');
            $table->text('reason')->nullable();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->char('correlation_id', 36)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->dateTime('occurred_at', 6);
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('actor_session_id')->references('id')->on('user_sessions')->nullOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->index(['event_type', 'occurred_at']);
            $table->index(['actor_user_id', 'occurred_at']);
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['priority', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_access_audit_events');
        Schema::dropIfExists('identity_security_alerts');
        Schema::dropIfExists('break_glass_access');
        Schema::dropIfExists('access_review_items');
        Schema::dropIfExists('access_reviews');
    }
};
