<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('importer_key', 100)->nullable();
            $table->string('importer_version', 100)->nullable();
            $table->string('target_model')->nullable();
            $table->string('created_by_type', 191)->nullable();
            $table->string('created_by_id', 191)->nullable();
            $table->string('tenant_id', 191)->nullable();
            $table->string('source', 16);
            $table->string('original_filename');
            $table->string('storage_disk', 64);
            $table->string('storage_path', 512);
            $table->char('file_hash', 64);
            $table->unsignedBigInteger('file_size');
            $table->string('file_type', 100);
            $table->string('sheet_name', 31)->nullable();
            $table->unsignedSmallInteger('sheet_index')->nullable();
            $table->unsignedInteger('sheet_header_row')->nullable();
            $table->json('mapping')->nullable();
            $table->json('options')->nullable();
            $table->unsignedInteger('mapping_revision')->default(0);
            $table->unsignedInteger('staging_revision')->default(0);
            $table->unsignedInteger('confirmed_revision')->nullable();
            $table->string('job_batch_id', 191)->nullable();
            $table->string('intake_status', 24)->default('discovered');
            $table->string('status', 24)->nullable();
            $table->unsignedInteger('current_attempt')->default(0);
            $table->json('attempt_history')->nullable();
            $table->unsignedBigInteger('stage_total')->default(0);
            $table->unsignedBigInteger('stage_processed')->default(0);
            $table->unsignedBigInteger('stage_valid')->default(0);
            $table->unsignedBigInteger('stage_invalid')->default(0);
            $table->unsignedBigInteger('stage_warning')->default(0);
            $table->unsignedBigInteger('execution_total')->default(0);
            $table->unsignedBigInteger('processed')->default(0);
            $table->unsignedBigInteger('created_count')->default(0);
            $table->unsignedBigInteger('updated_count')->default(0);
            $table->unsignedBigInteger('skipped_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamps();

            $table->unique(['storage_disk', 'storage_path'], 'smart_imports_storage_path_unique');
            $table->index('file_hash');
            $table->index(['importer_key', 'status'], 'smart_imports_importer_status_index');
            $table->index(['intake_status', 'created_at'], 'smart_imports_intake_created_index');
            $table->index(['created_by_type', 'created_by_id'], 'smart_imports_actor_index');
            $table->index(['tenant_id', 'created_at'], 'smart_imports_tenant_created_index');
            $table->index('job_batch_id');
        });

        Schema::create('smart_import_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('import_id')->constrained('smart_imports')->cascadeOnDelete();
            $table->string('source_sheet', 31)->default('');
            $table->unsignedBigInteger('source_row_number');
            $table->char('source_fingerprint', 64)->nullable();
            $table->json('raw_values')->nullable();
            $table->json('mapped_values')->nullable();
            $table->json('normalized_values')->nullable();
            $table->json('validation_errors')->nullable();
            $table->json('warnings')->nullable();
            $table->string('validation_status', 16)->default('pending');
            $table->string('execution_status', 16)->default('pending');
            $table->char('match_key_hash', 64)->nullable();
            $table->string('resolved_target_type')->nullable();
            $table->string('resolved_target_id', 191)->nullable();
            $table->string('change_type', 16)->nullable();
            $table->json('preview')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->json('execution_history')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['import_id', 'source_sheet', 'source_row_number'], 'smart_import_rows_source_unique');
            $table->index(['import_id', 'validation_status'], 'smart_import_rows_validation_index');
            $table->index(['import_id', 'execution_status'], 'smart_import_rows_execution_index');
            $table->index(['import_id', 'match_key_hash'], 'smart_import_rows_match_key_index');
        });

        Schema::create('smart_import_presets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('importer_key', 100);
            $table->string('importer_version', 100);
            $table->string('scope_key', 191);
            $table->string('name', 120);
            $table->char('source_fingerprint', 64);
            $table->json('mapping');
            $table->json('options')->nullable();
            $table->string('created_by_type', 191)->nullable();
            $table->string('created_by_id', 191)->nullable();
            $table->timestamps();

            $table->unique(['importer_key', 'scope_key', 'name'], 'smart_import_presets_scope_unique');
            $table->index(['importer_key', 'importer_version'], 'smart_import_presets_definition_index');
            $table->index(['created_by_type', 'created_by_id'], 'smart_import_presets_actor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_import_presets');
        Schema::dropIfExists('smart_import_rows');
        Schema::dropIfExists('smart_imports');
    }
};
