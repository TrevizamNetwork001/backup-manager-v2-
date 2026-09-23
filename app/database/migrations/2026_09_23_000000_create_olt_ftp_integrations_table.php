<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('olt_ftp_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('test_association_id')->nullable()->constrained('device_backup_policies')->nullOnDelete();
            $table->foreignId('test_execution_id')->nullable()->constrained('backup_executions')->nullOnDelete();
            $table->timestamp('olt_confirmed_at')->nullable();
            $table->timestamp('account_updated_at')->nullable();
            $table->string('ftp_host')->nullable();
            $table->string('management_ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olt_ftp_integrations');
    }
};
