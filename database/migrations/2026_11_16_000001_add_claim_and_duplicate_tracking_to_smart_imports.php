<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_imports', function (Blueprint $table): void {
            $table->string('claimed_storage_path', 512)->nullable()->after('storage_path');
            $table->foreignUuid('possible_duplicate_of')
                ->nullable()
                ->after('file_hash')
                ->constrained('smart_imports')
                ->nullOnDelete();
            $table->unique(['storage_disk', 'claimed_storage_path'], 'smart_imports_claimed_path_unique');
        });
    }

    public function down(): void
    {
        Schema::table('smart_imports', function (Blueprint $table): void {
            $table->dropUnique('smart_imports_claimed_path_unique');
            $table->dropConstrainedForeignId('possible_duplicate_of');
            $table->dropColumn('claimed_storage_path');
        });
    }
};
