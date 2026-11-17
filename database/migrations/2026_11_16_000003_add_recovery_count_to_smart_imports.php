<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_imports', function (Blueprint $table): void {
            $table->unsignedSmallInteger('recovery_count')->default(0)->after('current_attempt');
            $table->index(['status', 'heartbeat_at'], 'smart_imports_recovery_index');
        });
    }

    public function down(): void
    {
        Schema::table('smart_imports', function (Blueprint $table): void {
            $table->dropIndex('smart_imports_recovery_index');
            $table->dropColumn('recovery_count');
        });
    }
};
