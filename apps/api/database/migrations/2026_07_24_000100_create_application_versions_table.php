<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('application', 50);
            $table->string('version', 50);
            $table->string('environment', 30);
            $table->timestamp('deployed_at', precision: 6);
            $table->string('commit_sha', 64)->nullable();
            $table->timestamps(precision: 6);

            $table->unique(['application', 'environment', 'version']);
            $table->index(['application', 'environment', 'deployed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_versions');
    }
};
