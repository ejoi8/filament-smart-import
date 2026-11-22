<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_import_outbox', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('import_id')->constrained('smart_imports')->restrictOnDelete();
            $table->unsignedInteger('attempt_id')->nullable();
            $table->unsignedInteger('recovery_count')->default(0);
            $table->string('terminal_status', 24);
            $table->char('event_key', 64)->unique();
            $table->unsignedSmallInteger('payload_version')->default(1);
            $table->json('payload');
            $table->string('delivery_status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['delivery_status', 'available_at'], 'smart_import_outbox_delivery_index');
            $table->index(['import_id', 'attempt_id'], 'smart_import_outbox_import_attempt_index');
        });

        Schema::create('smart_import_notifications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('outbox_id')->constrained('smart_import_outbox')->restrictOnDelete();
            $table->string('notifiable_type', 191);
            $table->string('notifiable_id', 191);
            $table->string('channel', 32)->default('database');
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['outbox_id', 'notifiable_type', 'notifiable_id', 'channel'],
                'smart_import_notifications_event_recipient_unique',
            );
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'smart_import_notifications_recipient_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_import_notifications');
        Schema::dropIfExists('smart_import_outbox');
    }
};
