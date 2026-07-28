<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Mobile device records ──────────────────────────────────────────────
        Schema::create('mobile_devices', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('organization_id', 26);
            $table->char('driver_id', 26)->nullable();

            // Identity
            $table->string('stable_device_id', 255)->unique();        // hardware-backed stable ID
            $table->string('installation_id', 255)->unique();         // app-install UUID
            $table->string('display_name', 120);
            $table->string('platform', 30)->default('android');       // android
            $table->string('manufacturer', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('os_version', 60)->nullable();
            $table->string('app_version', 60)->nullable();
            $table->string('push_token_reference', 255)->nullable();

            // Lifecycle and trust
            $table->string('enrollment_state', 30)->default('not_enrolled');
            $table->string('trust_state', 30)->default('untrusted');
            $table->string('lifecycle_state', 30)->default('pending');

            // Timestamps
            $table->timestamp('first_seen_at', 6)->nullable();
            $table->timestamp('last_seen_at', 6)->nullable();
            $table->timestamp('last_sync_at', 6)->nullable();
            $table->timestamp('last_trust_evaluated_at', 6)->nullable();

            // Device capability metadata (JSON)
            $table->json('capability_metadata')->nullable();

            // Remote action checkpoint
            $table->timestamp('remote_actions_checked_at', 6)->nullable();

            // Concurrency
            $table->unsignedBigInteger('record_version')->default(1);

            $table->timestamps(6);

            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('driver_id')->references('id')->on('drivers')->nullOnDelete();

            $table->index(['organization_id', 'lifecycle_state']);
            $table->index(['organization_id', 'enrollment_state']);
            $table->index(['organization_id', 'driver_id']);
            $table->index(['trust_state', 'lifecycle_state']);
        });

        // ── Enrollment challenges (time-limited, single-use) ──────────────────
        Schema::create('device_enrollment_challenges', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('organization_id', 26);
            $table->char('driver_id', 26);
            $table->char('initiated_by', 26);   // user who generated the code

            // Token stored as SHA-256 hash; plaintext returned once
            $table->string('challenge_hash', 64)->unique();

            $table->string('status', 30)->default('active');   // active|claimed|used|expired|cancelled
            $table->timestamp('expires_at', 6);
            $table->timestamp('claimed_at', 6)->nullable();
            $table->char('claimed_by_device_id', 26)->nullable();

            $table->timestamps(6);

            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('driver_id')->references('id')->on('drivers')->restrictOnDelete();
            $table->foreign('initiated_by')->references('id')->on('users')->restrictOnDelete();

            $table->index(['status', 'expires_at']);
            $table->index(['driver_id', 'status']);
        });

        // ── Enrollment requests (workflow records) ────────────────────────────
        Schema::create('device_enrollment_requests', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('organization_id', 26);
            $table->char('mobile_device_id', 26);
            $table->char('challenge_id', 26);
            $table->char('driver_id', 26);

            $table->string('status', 30)->default('pending');  // pending|approved|rejected|cancelled|expired
            $table->string('rejection_reason', 500)->nullable();
            $table->string('cancellation_reason', 500)->nullable();

            // Maker-checker: initiator cannot approve
            $table->char('reviewed_by', 26)->nullable();
            $table->timestamp('reviewed_at', 6)->nullable();

            // Concurrency
            $table->unsignedBigInteger('record_version')->default(1);

            $table->timestamps(6);

            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('mobile_device_id')->references('id')->on('mobile_devices')->restrictOnDelete();
            $table->foreign('challenge_id')->references('id')->on('device_enrollment_challenges')->restrictOnDelete();
            $table->foreign('driver_id')->references('id')->on('drivers')->restrictOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['organization_id', 'status']);
            $table->index(['driver_id', 'status']);
            $table->index(['mobile_device_id', 'status']);
        });

        // ── Driver-device assignments (effective-dated) ───────────────────────
        Schema::create('driver_device_assignments', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('organization_id', 26);
            $table->char('mobile_device_id', 26);
            $table->char('driver_id', 26);

            $table->string('assignment_type', 30)->default('primary');  // primary|temporary|replacement
            $table->string('reason', 500)->nullable();

            $table->timestamp('effective_from', 6);
            $table->timestamp('effective_to', 6)->nullable();

            $table->char('assigned_by', 26);
            $table->char('ended_by', 26)->nullable();
            $table->string('end_reason', 500)->nullable();

            // Replacement relationship
            $table->char('replaces_assignment_id', 26)->nullable();

            $table->string('status', 30)->default('active');   // active|ended

            // Concurrency
            $table->unsignedBigInteger('record_version')->default(1);

            $table->timestamps(6);

            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('mobile_device_id')->references('id')->on('mobile_devices')->restrictOnDelete();
            $table->foreign('driver_id')->references('id')->on('drivers')->restrictOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('ended_by')->references('id')->on('users')->nullOnDelete();

            // One active primary device per driver per org
            $table->unique(['organization_id', 'driver_id', 'assignment_type', 'status'],
                'unique_active_primary_driver_device');

            $table->index(['driver_id', 'status', 'effective_from']);
            $table->index(['mobile_device_id', 'status']);
        });

        // ── Device status history ─────────────────────────────────────────────
        Schema::create('device_status_history', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('mobile_device_id', 26);
            $table->string('status_type', 30);           // lifecycle|enrollment|trust
            $table->string('from_state', 30)->nullable();
            $table->string('to_state', 30);
            $table->string('reason', 500)->nullable();
            $table->char('changed_by', 26)->nullable();
            $table->char('approval_reference', 26)->nullable();  // enrollment request ID
            $table->timestamp('effective_at', 6);
            $table->timestamps(6);

            $table->foreign('mobile_device_id')->references('id')->on('mobile_devices')->restrictOnDelete();

            $table->index(['mobile_device_id', 'status_type', 'effective_at'], 'dsh_device_type_effective_idx');
        });

        // ── Device trust evaluations ──────────────────────────────────────────
        Schema::create('device_trust_evaluations', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('mobile_device_id', 26);

            $table->string('overall_trust_state', 30);   // trusted|degraded|untrusted|revoked
            $table->boolean('app_version_compliant')->default(false);
            $table->boolean('os_version_compliant')->default(false);
            $table->boolean('encryption_ready')->default(false);
            $table->boolean('secure_storage_ready')->default(false);
            $table->boolean('local_db_ready')->default(false);
            $table->boolean('sync_ready')->default(false);
            $table->boolean('policy_compliant')->default(false);

            $table->json('integrity_warnings')->nullable();   // list of warning codes
            $table->json('blocking_reasons')->nullable();     // list of blocking reason codes

            $table->timestamp('evaluated_at', 6);
            $table->char('evaluated_by', 26)->nullable();   // null = automatic evaluation

            $table->timestamps(6);

            $table->foreign('mobile_device_id')->references('id')->on('mobile_devices')->restrictOnDelete();

            $table->index(['mobile_device_id', 'evaluated_at']);
        });

        // ── Device remote actions ─────────────────────────────────────────────
        Schema::create('device_remote_actions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('organization_id', 26);
            $table->char('mobile_device_id', 26);

            $table->string('action_type', 30);   // sign_out|cache_reset|full_sync|force_update|re_enroll
            $table->string('status', 30)->default('pending');   // pending|acknowledged|executed|expired|cancelled

            $table->string('reason', 500)->nullable();
            $table->char('requested_by', 26);
            $table->char('acknowledged_by_device', 26)->nullable();

            $table->timestamp('requested_at', 6);
            $table->timestamp('expires_at', 6);
            $table->timestamp('acknowledged_at', 6)->nullable();
            $table->timestamp('executed_at', 6)->nullable();

            $table->timestamps(6);

            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('mobile_device_id')->references('id')->on('mobile_devices')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->restrictOnDelete();

            $table->index(['mobile_device_id', 'status', 'expires_at'], 'dra_device_status_expires_idx');
            $table->index(['status', 'expires_at'], 'dra_status_expires_idx');
        });

        // ── Sync sessions ─────────────────────────────────────────────────────
        Schema::create('mobile_sync_sessions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('mobile_device_id', 26);
            $table->char('driver_id', 26)->nullable();
            $table->char('organization_id', 26);

            $table->string('session_type', 30)->default('incremental');  // full|incremental|upload_only
            $table->string('status', 30)->default('started');            // started|downloading|uploading|completed|failed|cancelled

            $table->unsignedInteger('datasets_downloaded')->default(0);
            $table->unsignedInteger('records_downloaded')->default(0);
            $table->unsignedInteger('commands_uploaded')->default(0);
            $table->unsignedInteger('commands_accepted')->default(0);
            $table->unsignedInteger('commands_rejected')->default(0);
            $table->unsignedInteger('conflicts_recorded')->default(0);

            $table->string('failure_category', 60)->nullable();
            $table->string('failure_detail', 500)->nullable();

            $table->timestamp('started_at', 6);
            $table->timestamp('completed_at', 6)->nullable();

            $table->timestamps(6);

            $table->foreign('mobile_device_id')->references('id')->on('mobile_devices')->restrictOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();

            $table->index(['mobile_device_id', 'started_at']);
            $table->index(['organization_id', 'status']);
        });

        // ── Sync cursors (per-dataset incremental tracking) ───────────────────
        Schema::create('mobile_sync_cursors', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('mobile_device_id', 26);

            $table->string('dataset_name', 100);
            $table->unsignedBigInteger('cursor_value')->default(0);
            $table->unsignedBigInteger('dataset_version')->default(0);
            $table->unsignedInteger('record_count')->default(0);
            $table->string('checksum', 64)->nullable();

            $table->timestamp('last_success_at', 6)->nullable();
            $table->timestamp('invalidated_at', 6)->nullable();
            $table->boolean('requires_full_refresh')->default(false);

            $table->timestamps(6);

            $table->foreign('mobile_device_id')->references('id')->on('mobile_devices')->restrictOnDelete();
            $table->unique(['mobile_device_id', 'dataset_name']);
        });

        // ── Dataset versions (server-side published versions) ─────────────────
        Schema::create('mobile_dataset_versions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('organization_id', 26);

            $table->string('dataset_name', 100);
            $table->unsignedBigInteger('version_number')->default(1);
            $table->unsignedInteger('record_count')->default(0);
            $table->string('checksum', 64)->nullable();

            $table->timestamp('published_at', 6);
            $table->char('published_by', 26)->nullable();

            $table->timestamps(6);

            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->unique(['organization_id', 'dataset_name', 'version_number'], 'mdv_org_name_ver_unique');
            $table->index(['organization_id', 'dataset_name', 'published_at'], 'mdv_org_name_pub_idx');
        });

        // ── Offline commands (server-side receipt tracking) ───────────────────
        Schema::create('mobile_offline_commands', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('mobile_device_id', 26);
            $table->char('driver_id', 26)->nullable();
            $table->char('organization_id', 26);

            // Client-generated identity
            $table->string('client_command_id', 80)->unique();
            $table->string('idempotency_key', 80)->unique();

            $table->string('command_type', 60);
            $table->string('status', 30)->default('received');
            // received|processing|accepted|rejected|conflicted|permanently_failed

            $table->json('encrypted_payload')->nullable();  // for audit storage
            $table->string('conflict_type', 60)->nullable();
            $table->string('rejection_reason', 500)->nullable();

            $table->unsignedSmallInteger('attempt_count')->default(1);
            $table->timestamp('next_retry_at', 6)->nullable();
            $table->timestamp('last_attempted_at', 6)->nullable();

            $table->timestamp('received_at', 6);
            $table->timestamp('processed_at', 6)->nullable();

            $table->timestamps(6);

            $table->foreign('mobile_device_id')->references('id')->on('mobile_devices')->restrictOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();

            $table->index(['mobile_device_id', 'status', 'received_at'], 'moc_device_status_recv_idx');
            $table->index(['status', 'next_retry_at'], 'moc_status_retry_idx');
        });

        // ── Device policy versions ────────────────────────────────────────────
        Schema::create('mobile_device_policy_versions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('organization_id', 26);

            $table->string('minimum_app_version', 60)->nullable();
            $table->string('minimum_os_version', 60)->nullable();
            $table->unsignedSmallInteger('offline_access_hours')->default(72);
            $table->unsignedSmallInteger('sync_interval_minutes')->default(30);
            $table->unsignedSmallInteger('max_pending_commands')->default(100);
            $table->unsignedSmallInteger('enrollment_challenge_minutes')->default(15);

            $table->json('additional_policy')->nullable();  // extensible JSON

            $table->boolean('is_active')->default(false);
            $table->timestamp('effective_from', 6);
            $table->timestamp('effective_to', 6)->nullable();

            $table->char('created_by', 26);
            $table->char('approved_by', 26)->nullable();
            $table->timestamp('approved_at', 6)->nullable();

            $table->timestamps(6);

            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['organization_id', 'is_active', 'effective_from'], 'mdp_org_active_eff_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_device_policy_versions');
        Schema::dropIfExists('mobile_offline_commands');
        Schema::dropIfExists('mobile_dataset_versions');
        Schema::dropIfExists('mobile_sync_cursors');
        Schema::dropIfExists('mobile_sync_sessions');
        Schema::dropIfExists('device_remote_actions');
        Schema::dropIfExists('device_trust_evaluations');
        Schema::dropIfExists('device_status_history');
        Schema::dropIfExists('driver_device_assignments');
        Schema::dropIfExists('device_enrollment_requests');
        Schema::dropIfExists('device_enrollment_challenges');
        Schema::dropIfExists('mobile_devices');
    }
};
