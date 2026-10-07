<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->boolean('backup_copy_enabled')->default(false);
            $table->string('backup_copy_chat_id', 100)->nullable();
            $table->unsignedBigInteger('backup_copy_thread_id')->nullable();
            // Só backups validados depois disso são enviados (ligar o recurso não despeja o histórico).
            $table->timestamp('backup_copy_since')->nullable();
        });

        // Sem chave estrangeira de propósito: a retenção pode apagar o artefato e isso não pode ser bloqueado.
        Schema::create('telegram_backup_sends', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('backup_artifact_id')->unique();
            $table->string('status', 20)->default('pending'); // pending | sent | failed | skipped
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_backup_sends');
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->dropColumn(['backup_copy_enabled', 'backup_copy_chat_id', 'backup_copy_thread_id', 'backup_copy_since']);
        });
    }
};
