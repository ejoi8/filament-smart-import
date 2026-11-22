<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_imports', function (Blueprint $table): void {
            $table->timestamp('retention_hold_at')->nullable();
            $table->string('retention_hold_reason', 191)->nullable();
        });

        Schema::create('smart_import_audit_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('import_id');
            $table->string('tenant_id', 191)->nullable();
            $table->string('actor_type', 191)->nullable();
            $table->string('actor_id', 191)->nullable();
            $table->string('event', 64);
            $table->string('reason_code', 64)->nullable();
            $table->unsignedInteger('mapping_revision')->nullable();
            $table->unsignedInteger('staging_revision')->nullable();
            $table->unsignedInteger('attempt_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Audit history intentionally outlives an import session and has no foreign key.
            $table->index(['import_id', 'created_at'], 'smart_import_audit_import_created_index');
            $table->index(['actor_type', 'actor_id', 'created_at'], 'smart_import_audit_actor_created_index');
            $table->index(['tenant_id', 'created_at'], 'smart_import_audit_tenant_created_index');
            $table->index(['event', 'created_at'], 'smart_import_audit_event_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_import_audit_entries');

        Schema::table('smart_imports', function (Blueprint $table): void {
            $table->dropColumn(['retention_hold_at', 'retention_hold_reason']);
        });
    }
};
