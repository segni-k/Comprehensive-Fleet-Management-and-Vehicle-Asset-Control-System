<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('code', 120)->unique();
            $table->json('name');
            $table->json('allowed_mime_types');
            $table->unsignedBigInteger('maximum_bytes');
            $table->boolean('malware_scan_required')->default(true);
            $table->string('retention_class', 80);
            $table->string('status', 30)->default('inactive');
            $table->timestamps(6);
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('document_type_id', 26);
            $table->char('organization_id', 26);
            $table->string('owner_type', 100);
            $table->char('owner_id', 26);
            $table->string('category', 80);
            $table->string('classification', 40)->default('internal');
            $table->char('created_by', 26);
            $table->char('current_version_id', 26)->nullable();
            $table->dateTime('retention_until', 6)->nullable();
            $table->dateTime('expires_at', 6)->nullable();
            $table->dateTime('archived_at', 6)->nullable();
            $table->char('archived_by', 26)->nullable();
            $table->text('archive_reason')->nullable();
            $table->string('status', 30)->default('quarantined');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);

            $table->foreign('document_type_id')->references('id')->on('document_types')->restrictOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('archived_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['owner_type', 'owner_id', 'status']);
            $table->index(['expires_at', 'status']);
        });

        Schema::create('document_versions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('document_id', 26);
            $table->unsignedInteger('version_number');
            $table->char('supersedes_version_id', 26)->nullable();
            $table->string('storage_disk', 80);
            $table->string('storage_key', 500)->unique();
            $table->string('original_filename', 255);
            $table->string('media_type', 190);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum_algorithm', 20)->default('sha256');
            $table->char('checksum', 64);
            $table->char('uploaded_by', 26);
            $table->string('scan_status', 30)->default('pending');
            $table->string('trust_status', 30)->default('quarantined');
            $table->dateTime('trusted_at', 6)->nullable();
            $table->dateTime('created_at', 6);

            $table->foreign('document_id')->references('id')->on('documents')->restrictOnDelete();
            $table->foreign('supersedes_version_id')->references('id')->on('document_versions')->restrictOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['document_id', 'version_number']);
            $table->index(['checksum', 'size_bytes']);
            $table->index(['scan_status', 'created_at']);
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->foreign('current_version_id')->references('id')->on('document_versions')->restrictOnDelete();
        });

        Schema::create('document_links', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('document_id', 26);
            $table->string('linked_entity_type', 100);
            $table->char('linked_entity_id', 26);
            $table->string('purpose', 120);
            $table->char('linked_by', 26);
            $table->dateTime('created_at', 6);
            $table->foreign('document_id')->references('id')->on('documents')->restrictOnDelete();
            $table->foreign('linked_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['document_id', 'linked_entity_type', 'linked_entity_id', 'purpose'], 'document_link_unique');
            $table->index(['linked_entity_type', 'linked_entity_id']);
        });

        Schema::create('document_scan_attempts', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('document_version_id', 26);
            $table->string('scanner_adapter', 120);
            $table->string('scanner_reference', 190)->nullable();
            $table->string('outcome', 40);
            $table->string('failure_class', 80)->nullable();
            $table->json('safe_metadata')->nullable();
            $table->dateTime('started_at', 6);
            $table->dateTime('completed_at', 6)->nullable();
            $table->foreign('document_version_id')->references('id')->on('document_versions')->restrictOnDelete();
            $table->index(['document_version_id', 'started_at']);
            $table->index(['outcome', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_scan_attempts');
        Schema::dropIfExists('document_links');
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_types');
    }
};
