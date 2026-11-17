<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_imports', function (Blueprint $table): void {
            $table->char('file_hash', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('smart_imports')->whereNull('file_hash')->exists()) {
            throw new RuntimeException('Cannot restore the required file checksum while quarantined sessions still have no verified hash.');
        }

        Schema::table('smart_imports', function (Blueprint $table): void {
            $table->char('file_hash', 64)->nullable(false)->change();
        });
    }
};
