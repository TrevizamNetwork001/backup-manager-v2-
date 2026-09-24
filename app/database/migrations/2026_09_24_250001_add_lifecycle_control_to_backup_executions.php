<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_executions', function (Blueprint $table) {
            $table->unsignedInteger('max_attempts')->default(3)->after('attempt');
            $table->timestamp('next_attempt_at')->nullable()->after('max_attempts');
            $table->timestamp('cancellation_requested_at')->nullable()->after('next_attempt_at');
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::table('backup_executions', function (Blueprint $table) {
            $table->dropIndex(['status', 'next_attempt_at']);
            $table->dropColumn(['max_attempts', 'next_attempt_at', 'cancellation_requested_at']);
        });
    }
};
