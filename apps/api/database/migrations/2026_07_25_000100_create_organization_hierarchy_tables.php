<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_types', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('code', 50)->unique();
            $table->string('name_key', 190);
            $table->json('translations');
            $table->text('description');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('may_be_root')->default(false);
            $table->string('status', 30)->default('inactive');
            $table->string('configuration_status', 30)->default('template');
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->index(['status', 'sort_order']);
            $table->index(['effective_from', 'effective_to']);
        });

        Schema::create('organization_type_rules', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('parent_type_id', 26);
            $table->char('child_type_id', 26);
            $table->string('status', 30)->default('inactive');
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('parent_type_id')->references('id')->on('organization_types')->restrictOnDelete();
            $table->foreign('child_type_id')->references('id')->on('organization_types')->restrictOnDelete();
            $table->unique(['parent_type_id', 'child_type_id', 'effective_from'], 'organization_type_rule_version_unique');
            $table->index(['parent_type_id', 'child_type_id', 'status', 'effective_from', 'effective_to'], 'organization_type_rule_effective_idx');
        });

        Schema::create('organizations', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('type_id', 26);
            $table->string('code', 80)->unique();
            $table->json('name');
            $table->text('description')->nullable();
            $table->string('status', 30)->default('draft');
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('type_id')->references('id')->on('organization_types')->restrictOnDelete();
            $table->index(['type_id', 'status']);
            $table->index(['effective_from', 'effective_to']);
        });

        Schema::create('organization_hierarchy_edges', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('parent_id', 26);
            $table->char('child_id', 26);
            $table->string('status', 30)->default('scheduled');
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->foreign('parent_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('child_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->unique(['child_id', 'effective_from'], 'organization_child_edge_start_unique');
            $table->index(['child_id', 'status', 'effective_from', 'effective_to'], 'organization_child_edge_effective_idx');
            $table->index(['parent_id', 'status', 'effective_from', 'effective_to'], 'organization_parent_edge_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_hierarchy_edges');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('organization_type_rules');
        Schema::dropIfExists('organization_types');
    }
};
