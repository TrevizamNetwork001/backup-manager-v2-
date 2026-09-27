<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// STABILIZATION-1 (P2 finding, both audits): ftp_account_id is filtered by
// FtpAccountDeletionService/FtpAdminController but was never indexed —
// PostgreSQL does not auto-index foreign keys.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_executions', function (Blueprint $table) {
            $table->index('ftp_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('backup_executions', function (Blueprint $table) {
            $table->dropIndex(['ftp_account_id']);
        });
    }
};
