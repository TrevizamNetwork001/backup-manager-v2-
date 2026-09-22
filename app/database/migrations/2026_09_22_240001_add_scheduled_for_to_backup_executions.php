<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_executions', function (Blueprint $table) {
            $table->timestamp('scheduled_for')->nullable();
            $table->unique(['device_backup_policy_id', 'scheduled_for'], 'backup_executions_occurrence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('backup_executions', function (Blueprint $table) {
            $table->dropUnique('backup_executions_occurrence_unique');
            $table->dropColumn('scheduled_for');
        });
    }
};
