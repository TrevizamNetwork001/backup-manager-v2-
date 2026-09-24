<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('backup_executions', function (Blueprint $table) {
            $table->foreignId('ftp_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('ftp_claim_token', 32)->nullable()->unique();
            $table->string('received_filename', 255)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processing_at')->nullable();
        });
        // SQLite rebuilds the table for ALTER and loses the WHERE predicate of this older index.
        \Illuminate\Support\Facades\DB::statement('DROP INDEX IF EXISTS backup_executions_one_running_device');
        \Illuminate\Support\Facades\DB::statement("CREATE UNIQUE INDEX backup_executions_one_running_device ON backup_executions (device_id) WHERE status = 'running'");
    }

    public function down(): void
    {
        Schema::table('backup_executions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ftp_account_id');
            $table->dropUnique(['ftp_claim_token']);
            $table->dropColumn(['ftp_claim_token', 'received_filename', 'received_at', 'processing_at']);
        });
    }
};
