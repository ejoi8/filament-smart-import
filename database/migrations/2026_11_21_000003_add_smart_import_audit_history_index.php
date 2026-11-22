<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_import_audit_entries', function (Blueprint $table): void {
            $table->index(['import_id', 'id'], 'smart_import_audit_import_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('smart_import_audit_entries', function (Blueprint $table): void {
            $table->dropIndex('smart_import_audit_import_id_index');
        });
    }
};
