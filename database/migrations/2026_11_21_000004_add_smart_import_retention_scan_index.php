<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_imports', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'smart_imports_retention_scan_index');
        });
    }

    public function down(): void
    {
        Schema::table('smart_imports', function (Blueprint $table): void {
            $table->dropIndex('smart_imports_retention_scan_index');
        });
    }
};
