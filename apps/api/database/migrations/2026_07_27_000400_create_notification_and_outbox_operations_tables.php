<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('organization_id', 26)->nullable();
            $table->string('code', 120);
            $table->unsignedInteger('version_number');
            $table->string('channel', 30);
            $table->string('locale', 5);
            $table->string('subject', 255)->nullable();
            $table->text('body');
            $table->json('allowed_variables');
            $table->string('classification', 40)->default('internal');
            $table->string('status', 30)->default('draft');
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['organization_id', 'code', 'version_number', 'channel', 'locale'], 'notification_template_version_unique');
            $table->index(['organization_id', 'code', 'channel', 'locale', 'status'], 'notification_template_resolution_idx');
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26);
            $table->string('event_type', 190);
            $table->string('channel', 30);
            $table->boolean('enabled')->default(true);
            $table->json('quiet_hours')->nullable();
            $table->timestamps(6);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'event_type', 'channel']);
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('recipient_user_id', 26);
            $table->char('organization_id', 26)->nullable();
            $table->char('template_id', 26)->nullable();
            $table->string('event_type', 190);
            $table->string('subject_type', 100)->nullable();
            $table->char('subject_id', 26)->nullable();
            $table->string('title', 255);
            $table->text('body');
            $table->json('safe_payload');
            $table->string('severity', 30)->default('information');
            $table->string('status', 30)->default('unread');
            $table->string('deduplication_key', 190);
            $table->dateTime('read_at', 6)->nullable();
            $table->dateTime('acknowledged_at', 6)->nullable();
            $table->dateTime('expires_at', 6)->nullable();
            $table->timestamps(6);
            $table->foreign('recipient_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('template_id')->references('id')->on('notification_templates')->nullOnDelete();
            $table->unique(['recipient_user_id', 'deduplication_key']);
            $table->index(['recipient_user_id', 'status', 'created_at']);
            $table->index(['organization_id', 'event_type', 'created_at']);
        });

        Schema::create('notification_delivery_attempts', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('notification_id', 26);
            $table->string('channel', 30);
            $table->string('adapter', 120);
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 30);
            $table->string('failure_class', 80)->nullable();
            $table->string('provider_reference', 190)->nullable();
            $table->string('safe_diagnostic', 500)->nullable();
            $table->dateTime('next_attempt_at', 6)->nullable();
            $table->dateTime('attempted_at', 6);
            $table->foreign('notification_id')->references('id')->on('notifications')->restrictOnDelete();
            $table->unique(['notification_id', 'channel', 'attempt_number'], 'notification_delivery_attempt_unique');
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table->string('status', 30)->default('pending')->after('correlation_id');
            $table->unsignedSmallInteger('payload_version')->default(1)->after('payload');
            $table->char('causation_id', 36)->nullable()->after('correlation_id');
            $table->char('organization_id', 26)->nullable()->after('aggregate_id');
            $table->string('deduplication_key', 190)->nullable()->after('topic');
            $table->string('idempotency_key', 190)->nullable()->after('deduplication_key');
            $table->dateTime('next_attempt_at', 6)->nullable()->after('available_at');
            $table->string('lock_owner', 190)->nullable()->after('attempts');
            $table->dateTime('locked_until', 6)->nullable()->after('lock_owner');
            $table->unsignedSmallInteger('maximum_attempts')->default(8)->after('attempts');
            $table->string('last_error_message', 500)->nullable()->after('last_error_code');
            $table->unique('deduplication_key');
            $table->index(['status', 'next_attempt_at', 'available_at'], 'outbox_dispatch_idx');
            $table->index(['lock_owner', 'locked_until']);
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });

        Schema::create('outbox_dead_letters', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('outbox_message_id', 26);
            $table->string('failure_class', 80);
            $table->string('safe_diagnostic', 500);
            $table->unsignedSmallInteger('attempts');
            $table->dateTime('failed_at', 6);
            $table->dateTime('replayed_at', 6)->nullable();
            $table->char('replayed_by', 26)->nullable();
            $table->text('replay_reason')->nullable();
            $table->foreign('outbox_message_id')->references('id')->on('outbox_messages')->restrictOnDelete();
            $table->foreign('replayed_by')->references('id')->on('users')->nullOnDelete();
            $table->unique('outbox_message_id');
            $table->index(['replayed_at', 'failed_at']);
        });

        Schema::create('outbox_consumer_receipts', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('consumer', 120);
            $table->char('outbox_message_id', 26);
            $table->string('idempotency_key', 190);
            $table->dateTime('processed_at', 6);
            $table->json('result_metadata')->nullable();
            $table->foreign('outbox_message_id')->references('id')->on('outbox_messages')->restrictOnDelete();
            $table->unique(['consumer', 'idempotency_key']);
            $table->index(['outbox_message_id', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_consumer_receipts');
        Schema::dropIfExists('outbox_dead_letters');
        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table->dropForeign(['organization_id']);
            $table->dropUnique(['deduplication_key']);
            $table->dropIndex('outbox_dispatch_idx');
            $table->dropIndex(['lock_owner', 'locked_until']);
            $table->dropColumn([
                'status', 'payload_version', 'causation_id', 'organization_id',
                'deduplication_key', 'idempotency_key', 'next_attempt_at',
                'lock_owner', 'locked_until', 'maximum_attempts', 'last_error_message',
            ]);
        });
        Schema::dropIfExists('notification_delivery_attempts');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_templates');
    }
};
