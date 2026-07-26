<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('login_identifier', 190)->unique();
            $table->string('employee_identifier', 120)->nullable()->unique();
            $table->text('email')->nullable();
            $table->char('email_lookup_hash', 64)->nullable()->unique();
            $table->text('phone')->nullable();
            $table->json('name');
            $table->string('preferred_locale', 5)->default('en');
            $table->string('password');
            $table->dateTime('password_changed_at', 6)->nullable();
            $table->dateTime('password_expires_at', 6)->nullable();
            $table->boolean('must_change_password')->default(true);
            $table->unsignedSmallInteger('failed_login_count')->default(0);
            $table->dateTime('locked_until', 6)->nullable();
            $table->dateTime('last_login_at', 6)->nullable();
            $table->string('status', 30)->default('invited');
            $table->text('status_reason')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->rememberToken();
            $table->timestamps(6);
            $table->index(['status', 'locked_until']);
            $table->index(['last_login_at', 'status']);
        });

        Schema::create('user_password_history', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26);
            $table->string('password_hash');
            $table->dateTime('created_at', 6);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('user_credential_tokens', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26);
            $table->string('purpose', 30);
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at', 6);
            $table->dateTime('used_at', 6)->nullable();
            $table->char('requested_ip_hash', 64)->nullable();
            $table->timestamps(6);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'purpose', 'expires_at']);
        });

        Schema::create('user_mfa_methods', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26);
            $table->string('method_type', 30);
            $table->string('label', 120);
            $table->text('secret');
            $table->text('recovery_codes')->nullable();
            $table->dateTime('verified_at', 6)->nullable();
            $table->dateTime('last_used_at', 6)->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'method_type', 'label'], 'user_mfa_method_label_unique');
            $table->index(['user_id', 'status']);
        });

        Schema::create('user_sessions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26);
            $table->char('access_token_hash', 64)->unique();
            $table->char('refresh_token_hash', 64)->unique();
            $table->char('refresh_family_id', 26);
            $table->unsignedInteger('refresh_sequence')->default(1);
            $table->string('auth_strength', 30)->default('password');
            $table->dateTime('mfa_verified_at', 6)->nullable();
            $table->dateTime('trusted_until', 6)->nullable();
            $table->dateTime('access_expires_at', 6);
            $table->dateTime('refresh_expires_at', 6);
            $table->dateTime('last_seen_at', 6);
            $table->dateTime('revoked_at', 6)->nullable();
            $table->string('revocation_reason', 500)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'revoked_at', 'refresh_expires_at'], 'user_session_effective_idx');
            $table->index(['refresh_family_id', 'refresh_sequence']);
        });

        Schema::create('authentication_attempts', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26)->nullable();
            $table->char('identifier_hash', 64);
            $table->string('outcome', 50);
            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->char('correlation_id', 36)->nullable();
            $table->dateTime('occurred_at', 6);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['identifier_hash', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authentication_attempts');
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('user_mfa_methods');
        Schema::dropIfExists('user_credential_tokens');
        Schema::dropIfExists('user_password_history');
        Schema::dropIfExists('users');
    }
};
