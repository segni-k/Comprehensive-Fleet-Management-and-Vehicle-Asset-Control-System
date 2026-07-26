<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_contacts', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('organization_id', 26);
            $table->string('contact_type', 50);
            $table->string('value', 500);
            $table->boolean('is_primary')->default(false);
            $table->string('status', 30)->default('active');
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->index(['organization_id', 'contact_type', 'status', 'effective_from', 'effective_to'], 'organization_contact_effective_idx');
        });

        Schema::create('organization_manager_responsibilities', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('code', 80)->unique();
            $table->string('name_key', 190);
            $table->string('status', 30)->default('inactive');
            $table->timestamps(6);
        });

        Schema::create('organization_manager_assignments', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('organization_id', 26);
            $table->char('user_id', 26)->comment('Foreign key added by Milestone 3 IAM migration');
            $table->char('responsibility_id', 26);
            $table->string('status', 30)->default('pending');
            $table->string('appointing_authority', 500);
            $table->string('approval_reference', 190)->nullable();
            $table->boolean('delegation_restricted')->default(true);
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('responsibility_id')->references('id')->on('organization_manager_responsibilities')->restrictOnDelete();
            $table->index(['organization_id', 'status', 'effective_from', 'effective_to'], 'organization_manager_effective_idx');
            $table->index(['user_id', 'status', 'effective_from', 'effective_to'], 'organization_manager_user_effective_idx');
        });

        Schema::create('organization_setting_definitions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('key', 190)->unique();
            $table->string('name_key', 190);
            $table->string('value_type', 30);
            $table->json('validation_rules')->nullable();
            $table->boolean('inheritable')->default(true);
            $table->boolean('sensitive')->default(false);
            $table->string('status', 30)->default('inactive');
            $table->timestamps(6);
        });

        Schema::create('organization_setting_values', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('organization_id', 26);
            $table->char('setting_definition_id', 26);
            $table->json('value');
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->string('approved_by_reference', 190)->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('setting_definition_id')->references('id')->on('organization_setting_definitions')->restrictOnDelete();
            $table->unique(['organization_id', 'setting_definition_id', 'effective_from'], 'organization_setting_version_unique');
            $table->index(['organization_id', 'setting_definition_id', 'effective_from', 'effective_to'], 'organization_setting_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_setting_values');
        Schema::dropIfExists('organization_setting_definitions');
        Schema::dropIfExists('organization_manager_assignments');
        Schema::dropIfExists('organization_manager_responsibilities');
        Schema::dropIfExists('organization_contacts');
    }
};
