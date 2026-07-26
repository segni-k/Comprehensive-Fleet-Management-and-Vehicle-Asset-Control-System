<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_status_transitions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('subject_type', 40);
            $table->char('subject_id', 26);
            $table->string('target_status', 30);
            $table->dateTime('effective_at', 6);
            $table->string('status', 30)->default('scheduled');
            $table->string('requested_by', 190);
            $table->text('reason');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestamps(6);
            $table->index(
                ['subject_type', 'subject_id', 'status', 'effective_at'],
                'organization_status_transition_due_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_status_transitions');
    }
};
