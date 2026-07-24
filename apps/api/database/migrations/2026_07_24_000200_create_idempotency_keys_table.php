<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key', 100);
            $table->string('actor_type', 40);
            $table->string('actor_id', 100);
            $table->string('route', 180);
            $table->char('payload_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->string('state', 20)->default('processing');
            $table->timestamp('expires_at', precision: 6);
            $table->timestamps(precision: 6);

            $table->unique(['actor_type', 'actor_id', 'key']);
            $table->index(['state', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
