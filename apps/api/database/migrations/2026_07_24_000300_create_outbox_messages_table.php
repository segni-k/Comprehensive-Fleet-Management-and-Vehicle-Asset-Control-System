<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('topic', 120);
            $table->string('aggregate_type', 100);
            $table->string('aggregate_id', 100);
            $table->json('payload');
            $table->string('correlation_id', 36)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at', precision: 6);
            $table->timestamp('published_at', precision: 6)->nullable();
            $table->timestamp('failed_at', precision: 6)->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->timestamps(precision: 6);

            $table->index(['published_at', 'available_at']);
            $table->index(['aggregate_type', 'aggregate_id', 'created_at']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
