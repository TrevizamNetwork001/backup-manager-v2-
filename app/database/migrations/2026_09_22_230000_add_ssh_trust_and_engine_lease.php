<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('ssh_host_key_algorithm', 100)->nullable();
            $table->string('ssh_host_key_fingerprint', 100)->nullable();
            $table->timestamp('ssh_host_key_trusted_at')->nullable();
            $table->foreignId('ssh_host_key_trusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ssh_observed_algorithm', 100)->nullable();
            $table->string('ssh_observed_fingerprint', 100)->nullable();
            $table->timestamp('ssh_observed_at')->nullable();
        });

        Schema::table('backup_executions', function (Blueprint $table) {
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->string('worker_id', 64)->nullable();
            $table->index(['status', 'heartbeat_at']);
        });
    }

    public function down(): void
    {
        Schema::table('backup_executions', function (Blueprint $table) {
            $table->dropIndex(['status', 'heartbeat_at']);
            $table->dropColumn(['claimed_at', 'heartbeat_at', 'worker_id']);
        });
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ssh_host_key_trusted_by');
            $table->dropColumn(['ssh_host_key_algorithm', 'ssh_host_key_fingerprint',
                'ssh_host_key_trusted_at', 'ssh_observed_algorithm', 'ssh_observed_fingerprint', 'ssh_observed_at']);
        });
    }
};
