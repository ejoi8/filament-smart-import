<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_import_rows', function (Blueprint $table): void {
            $table->unsignedInteger('staging_revision')->default(0)->after('source_row_number');
        });
    }

    public function down(): void
    {
        Schema::table('smart_import_rows', function (Blueprint $table): void {
            $table->dropColumn('staging_revision');
        });
    }
};
