<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_types', function (Blueprint $table): void {
            $table->dropUnique('organization_types_code_unique');
            $table->unique(['code', 'effective_from'], 'organization_type_code_version_unique');
        });
    }

    public function down(): void
    {
        Schema::table('organization_types', function (Blueprint $table): void {
            $table->dropUnique('organization_type_code_version_unique');
            $table->unique('code');
        });
    }
};
